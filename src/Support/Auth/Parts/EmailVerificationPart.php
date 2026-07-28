<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Auth\Parts;

use Zuqongtech\LaravelAnvil\Support\Auth\AuthContext;
use Zuqongtech\LaravelAnvil\Support\Auth\Contracts\ScaffoldPart;
use Zuqongtech\LaravelAnvil\Support\Auth\ScaffoldWriter;
use Zuqongtech\LaravelAnvil\Support\Auth\Ui\FormKit;

/**
 * The "check your inbox" screen.
 *
 * Two defensive corrections. resend() previously called
 * $user->sendEmailVerificationNotification() with no null check after using
 * $user?->hasVerifiedEmail() one line above — so an expired session produced a
 * fatal instead of a redirect to login. And the method assumed the User model
 * implements MustVerifyEmail; when it does not, the notification method simply
 * does not exist, and the failure surfaces as an unrelated-looking Error. The
 * generated code now checks the contract and says what is wrong.
 */
final readonly class EmailVerificationPart implements ScaffoldPart
{
    public function __construct(private FormKit $ui = new FormKit) {}

    public function supports(AuthContext $context): bool
    {
        return $context->verification;
    }

    public function name(): string
    {
        return 'EmailVerification';
    }

    public function emit(AuthContext $context, ScaffoldWriter $writer): void
    {
        $writer->component('VerifyEmail', $this->component($writer), $this->view($writer));

        // The whole flow reads and writes this column; without it the feature is
        // decorative and hasVerifiedEmail() is meaningless.
        if (! $context->has('email_verified_at')) {
            $writer->migration(
                'add_email_verified_at_to_' . $context->usersTable . '_table',
                $writer->tokens()->render($this->migration()),
                'email_verified_at column',
            );
        }
    }

    /**
     * @return list<string>
     */
    public function notes(AuthContext $context): array
    {
        return [
            'Implement MustVerifyEmail on the User model:  class User extends Authenticatable implements '
                . 'MustVerifyEmail  — without it the resend button cannot work.',
            "Cast the column:  'email_verified_at' => 'datetime'  in the User model's \$casts.",
            "Protect routes that require a verified address with the 'verified' middleware.",
        ];
    }

    private function component(ScaffoldWriter $writer): string
    {
        return $writer->tokens()->render(<<<'PHP'
<?php

namespace %AUTH_NS%;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('%LAYOUT%')]
class VerifyEmail extends Component
{
    /**
     * Set once a link has been sent, so the button can disable itself.
     *
     * Locked: a client-writable flag here would let someone re-enable the button
     * and bypass the visual half of the rate limit. The server-side limiter is
     * the real control, but the two should not disagree.
     */
    #[Locked]
    public bool $sent = false;

    public function resend()
    {
        $user = Auth::guard('%GUARD%')->user();

        // Session expired between render and click.
        if (! $user) {
            return $this->redirect(route('login'), navigate: true);
        }

        if ($user->hasVerifiedEmail()) {
            return $this->redirectIntended(default: '/', navigate: true);
        }

        // Without the contract there is no sendEmailVerificationNotification()
        // to call, and the resulting Error names a method rather than the cause.
        if (! $user instanceof MustVerifyEmail) {
            throw ValidationException::withMessages([
                'email' => __('Email verification is not enabled on this account model. Implement MustVerifyEmail on the User model.'),
            ]);
        }

        $this->ensureIsNotRateLimited();

        $user->sendEmailVerificationNotification();

        $this->sent = true;

        session()->flash('status', __('A fresh verification link has been sent to your email.'));

        return null;
    }

    /**
     * Mirrors the throttle on the verification.send route so a Livewire click
     * cannot bypass it.
     */
    protected function ensureIsNotRateLimited(): void
    {
        $key = Str::transliterate('verify-email|'.Auth::guard('%GUARD%')->id());

        if (RateLimiter::tooManyAttempts($key, 6)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => __('Too many requests. Please try again in :seconds seconds.', ['seconds' => $seconds]),
            ]);
        }

        RateLimiter::hit($key, 60);
    }

    public function logout()
    {
        Auth::guard('%GUARD%')->logout();
        session()->invalidate();
        session()->regenerateToken();

        return $this->redirect('/', navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.verify-email');
    }
}
PHP);
    }

    private function view(ScaffoldWriter $writer): string
    {
        $spinner = $this->ui->icon('spinner', 'h-4 w-4 animate-spin');
        $refresh = $this->ui->icon('refresh', 'h-4 w-4');
        $notice = $this->ui->alert(
            'info',
            'Click the link in the email to activate your account. Check your spam folder if it has not arrived.',
        );

        $body = <<<BLADE
{$notice}

    <div class="flex flex-col gap-3">
        <button wire:click="resend" class="btn-primary" wire:loading.attr="disabled" wire:target="resend"
                @disabled(\$sent)>
            <span wire:loading.remove wire:target="resend" class="inline-flex items-center gap-2">
                {$refresh}
                {{ \$sent ? 'Link sent' : 'Resend verification link' }}
            </span>
            <span wire:loading.flex wire:target="resend" class="items-center gap-2">
                {$spinner}
                Sending…
            </span>
        </button>

        <button wire:click="logout" class="btn-ghost text-sm">Sign out</button>
    </div>
BLADE;

        return $writer->tokens()->render($this->ui->card(
            'mail-open',
            'Verify your email',
            'We have sent a verification link to your inbox.',
            $body,
        ));
    }

    private function migration(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('%USERS_TABLE%', function (Blueprint $table): void {
            if (! Schema::hasColumn('%USERS_TABLE%', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('%USERS_TABLE%', function (Blueprint $table): void {
            $table->dropColumn('email_verified_at');
        });
    }
};
PHP;
    }
}
