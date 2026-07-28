<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Auth\Parts;

use Zuqongtech\LaravelAnvil\Support\Auth\AuthContext;
use Zuqongtech\LaravelAnvil\Support\Auth\Contracts\ScaffoldPart;
use Zuqongtech\LaravelAnvil\Support\Auth\ScaffoldWriter;
use Zuqongtech\LaravelAnvil\Support\Auth\Ui\FormKit;

/**
 * The password reset flow: request a link, then consume it.
 *
 * TWO THINGS THE ORIGINAL DID NOT DO
 *
 * A reset is usually triggered BECAUSE something went wrong — a shared password,
 * a phished credential, a stolen laptop. Rotating remember_token alone leaves the
 * attacker's active session and any API tokens working, which makes the reset
 * feel effective while changing nothing. The callback now clears the user's other
 * sessions and revokes their API tokens.
 *
 * And sendResetLink() had no rate limit of its own. Laravel's broker throttles
 * per-address (passwords.users.throttle, 60s by default), but nothing stopped one
 * host from walking an address list — using the application as a mailer.
 */
final readonly class PasswordResetPart implements ScaffoldPart
{
    public function __construct(private FormKit $ui = new FormKit) {}

    public function supports(AuthContext $context): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'PasswordReset';
    }

    public function emit(AuthContext $context, ScaffoldWriter $writer): void
    {
        $writer->component('ForgotPassword', $this->forgotComponent($writer), $this->forgotView($writer));
        $writer->component('ResetPassword', $this->resetComponent($context, $writer), $this->resetView($writer));
    }

    /**
     * @return list<string>
     */
    public function notes(AuthContext $context): array
    {
        $notes = [
            'Reset revokes the user\'s other sessions. That requires the database session driver — with the file or '
                . 'cookie driver the clean-up is skipped silently.',
        ];

        if ($context->twoFactor) {
            $notes[] = 'A reset does NOT clear two-factor enrolment, by design: someone who can read the mailbox '
                . 'should not thereby be able to strip the second factor.';
        }

        return $notes;
    }

    // -----------------------------------------------------------------------
    // Forgot password
    // -----------------------------------------------------------------------

    private function forgotComponent(ScaffoldWriter $writer): string
    {
        return $writer->tokens()->render(<<<'PHP'
<?php

namespace %AUTH_NS%;

use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('%LAYOUT%')]
class ForgotPassword extends Component
{
    #[Validate('required|string|email')]
    public string $email = '';

    public function sendResetLink(): void
    {
        $this->validate();
        $this->ensureIsNotRateLimited();

        Password::broker(config('auth.defaults.passwords'))->sendResetLink(['email' => $this->email]);

        // The response is identical whether or not the address exists, and the
        // return value of sendResetLink() is deliberately ignored: branching on it
        // would tell an attacker which addresses are registered.
        session()->flash('status', __('If that email is registered, a reset link is on its way.'));

        $this->reset('email');
    }

    /**
     * Per-origin, not per-address. The broker already throttles a single address;
     * this is what stops one host walking a list of them.
     */
    protected function ensureIsNotRateLimited(): void
    {
        $key = Str::transliterate('password-reset|'.request()->ip());

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => __('Too many requests. Please try again in :minutes minutes.', [
                    'minutes' => ceil($seconds / 60),
                ]),
            ]);
        }

        RateLimiter::hit($key, 600);
    }

    public function render()
    {
        return view('livewire.auth.forgot-password');
    }
}
PHP);
    }

    private function forgotView(ScaffoldWriter $writer): string
    {
        $email = $this->ui->field('email', 'Email', 'email', 'email', 'mail', autofocus: true, placeholder: 'you@example.com');
        $submit = $this->ui->submit('sendResetLink', 'Email reset link', 'Sending…');

        $body = <<<BLADE
    <form wire:submit="sendResetLink" class="space-y-4">
{$email}

{$submit}
    </form>
BLADE;

        return $writer->tokens()->render($this->ui->card(
            'key',
            'Forgot password',
            'Enter your email and we will send you a reset link.',
            $body,
            '<a href="{{ route(\'login\') }}" class="link" wire:navigate>Back to sign in</a>',
        ));
    }

    // -----------------------------------------------------------------------
    // Reset password
    // -----------------------------------------------------------------------

    private function resetComponent(AuthContext $context, ScaffoldWriter $writer): string
    {
        // Only emitted when the lockout columns exist: a reset is a legitimate way
        // out of a lockout, so clearing the counter is right — but only if there
        // is a column to clear.
        $clearLockout = $context->lockout
            ? "\n                    'failed_login_attempts' => 0,\n                    'locked_until' => null,"
            : '';

        return $writer->tokens()->render($this->resetTemplate(), [
            '%CLEAR_LOCKOUT%' => $clearLockout,
        ]);
    }

    private function resetTemplate(): string
    {
        return <<<'PHP'
<?php

namespace %AUTH_NS%;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('%LAYOUT%')]
class ResetPassword extends Component
{
    /**
     * Locked so the browser cannot rewrite them between mount and submit. The
     * broker validates the pair anyway, but a token that cannot be swapped
     * client-side is one less thing to reason about.
     */
    #[Locked]
    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;

        // Cast explicitly: query() returns mixed, and assigning null to a typed
        // string property is a TypeError.
        $this->email = (string) request()->query('email', '');
    }

    public function resetPassword()
    {
        $this->validate([
            'token' => ['required'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::broker(config('auth.defaults.passwords'))->reset(
            [
                'email' => $this->email,
                'password' => $this->password,
                'password_confirmation' => $this->password_confirmation,
                'token' => $this->token,
            ],
            function ($user): void {
                $user->forceFill([
                    'password' => Hash::make($this->password),
                    // Invalidates every "remember me" cookie.
                    'remember_token' => Str::random(60),%CLEAR_LOCKOUT%
                ])->save();

                $this->revokeOtherSessions($user);
                $this->revokeApiTokens($user);

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            $this->addError('email', __($status));

            return null;
        }

        session()->flash('status', __($status));

        return $this->redirect(route('login'), navigate: true);
    }

    /**
     * A reset is usually a response to a compromise. Leaving the attacker's
     * session alive makes it theatre.
     *
     * Only possible on the database session driver — there is no way to enumerate
     * file or cookie sessions for one user.
     */
    protected function revokeOtherSessions($user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        try {
            $table = config('session.table', 'sessions');

            if (! Schema::hasTable($table)) {
                return;
            }

            DB::table($table)
                ->where('user_id', $user->getAuthIdentifier())
                ->where('id', '!=', session()->getId())
                ->delete();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Sanctum personal access tokens survive a password change unless revoked. */
    protected function revokeApiTokens($user): void
    {
        if (! method_exists($user, 'tokens')) {
            return;
        }

        try {
            $user->tokens()->delete();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function render()
    {
        return view('livewire.auth.reset-password');
    }
}
PHP;
    }

    private function resetView(ScaffoldWriter $writer): string
    {
        $email = $this->ui->field('email', 'Email', 'email', 'email', 'mail');
        $password = $this->ui->password('password', 'New password', 'new-password', strength: true, autofocus: true);
        $confirm = $this->ui->password('password_confirmation', 'Confirm password', 'new-password');
        $submit = $this->ui->submit('resetPassword', 'Reset password', 'Resetting…');
        $notice = $this->ui->alert(
            'info',
            'Resetting your password signs you out everywhere else and revokes any API tokens.',
        );

        $body = <<<BLADE
{$notice}

    <form wire:submit="resetPassword" class="space-y-4">
{$email}

{$password}

{$confirm}

{$submit}
    </form>
BLADE;

        return $writer->tokens()->render($this->ui->card(
            'lock',
            'Reset password',
            'Choose a new password for your account.',
            $body,
        ));
    }
}
