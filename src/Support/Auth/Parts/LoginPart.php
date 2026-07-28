<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Auth\Parts;

use Zuqongtech\LaravelAnvil\Support\Auth\AuthContext;
use Zuqongtech\LaravelAnvil\Support\Auth\Contracts\ScaffoldPart;
use Zuqongtech\LaravelAnvil\Support\Auth\Fragments\LockoutFragment;
use Zuqongtech\LaravelAnvil\Support\Auth\Fragments\TwoFactorGateFragment;
use Zuqongtech\LaravelAnvil\Support\Auth\ScaffoldWriter;
use Zuqongtech\LaravelAnvil\Support\Auth\Ui\FormKit;

/**
 * The login screen, and the lockout migration it depends on.
 *
 * Composes two fragments — lockout and the two-factor hand-off — rather than
 * reading feature flags out of a shared token map. The placeholders this
 * template declares are therefore visible right here, next to the code that
 * fills them.
 */
final readonly class LoginPart implements ScaffoldPart
{
    public function __construct(private FormKit $ui = new FormKit) {}

    public function supports(AuthContext $context): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'Login';
    }

    public function emit(AuthContext $context, ScaffoldWriter $writer): void
    {
        $lockout = new LockoutFragment($context);

        $writer->component('Login', $this->component($context, $writer), $this->view($writer));

        if ($context->needsLoginSecurityMigration()) {
            $writer->migration(
                'add_login_security_columns_to_'.$context->usersTable.'_table',
                $writer->tokens()->render($lockout->migration()),
                'login security columns',
            );
        }
    }

    /**
     * @return list<string>
     */
    public function notes(AuthContext $context): array
    {
        return (new LockoutFragment($context))->notes();
    }

    // -----------------------------------------------------------------------
    // Component
    // -----------------------------------------------------------------------

    private function component(AuthContext $context, ScaffoldWriter $writer): string
    {
        $lockout = new LockoutFragment($context);
        $twoFactor = new TwoFactorGateFragment($context);

        // last_login_* are written only when the columns exist or the migration
        // that creates them is being generated. Previously they were written
        // unconditionally while the migration was gated on lockout, so
        // --no-lockout on a stock table threw a QueryException on every
        // successful sign-in.
        //
        // The lockout reset fields are inlined here rather than left as a nested
        // %LOCKOUT_RESET% token: a fragment cannot reference another fragment,
        // and TokenMap now throws rather than emit one unresolved.
        $stamp = $context->stampsLastLogin()
            ? "\n        \$user->forceFill([".$lockout->resetFields()
            ."'last_login_at' => now(), 'last_login_ip' => request()->ip()])->save();"
            : '';

        $statusCheck = $context->has('status')
            ? <<<'PHP'

        // A deactivated account must not be able to sign in even with correct
        // credentials. The message is deliberately identical to a bad-credentials
        // failure so it cannot be used to probe account state.
        if ($user && isset($user->status) && $user->status !== 'active') {
            Auth::guard('%GUARD%')->logout();

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }
PHP
            : '';

        return $writer->tokens()->render($this->template(), [
            '%LOCKOUT_IMPORTS%' => $lockout->imports(),
            '%LOCKOUT_CHECK%' => $lockout->check(),
            '%LOCKOUT_ON_FAIL%' => $lockout->onFailure(),
            '%LOCKOUT_METHOD%' => $lockout->method(),
            '%LAST_LOGIN_STAMP%' => $stamp,
            '%STATUS_CHECK%' => $statusCheck,
            '%TFA_GATE%' => $twoFactor->gate(),
        ]);
    }

    private function template(): string
    {
        return <<<'PHP'
<?php

namespace %AUTH_NS%;

use %USER_FQN%;
use Illuminate\Auth\Events\Lockout;
%LOCKOUT_IMPORTS%use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('%LAYOUT%')]
class Login extends Component
{
    /**
     * Compared against when no user matches, so the miss path costs the same as
     * a wrong password. Without it, Auth::attempt() skips bcrypt entirely on a
     * miss and the response time reveals whether an address is registered.
     */
    private const TIMING_EQUALISER = '$2y$12$usesomesillystringforeveryresultingpasswordhashesnotmatching';

    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function login()
    {
        $this->validate();
        $this->ensureIsNotRateLimited();

        $user = User::where('email', $this->email)->first();

        if (! $user) {
            Hash::check($this->password, self::TIMING_EQUALISER);
        }
%LOCKOUT_CHECK%
        if (! Auth::guard('%GUARD%')->attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($this->throttleKey());
%LOCKOUT_ON_FAIL%
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($this->throttleKey());

        $user = Auth::guard('%GUARD%')->user();
%STATUS_CHECK%
        // Applies a raised bcrypt cost to existing users on their next sign-in;
        // without it, changing the work factor only affects new passwords.
        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => Hash::make($this->password)])->save();
        }
%LAST_LOGIN_STAMP%
%TFA_GATE%
        session()->regenerate();

        return $this->redirectIntended(default: '/', navigate: true);
    }

    protected function ensureIsNotRateLimited(): void
    {
        foreach ([$this->throttleKey() => %THROTTLE_MAX%, $this->ipThrottleKey() => %THROTTLE_MAX% * 4] as $key => $max) {
            if (! RateLimiter::tooManyAttempts($key, $max)) {
                continue;
            }

            event(new Lockout(request()));
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
            ]);
        }
    }

    /** Per-credential: one address hammered from many addresses. */
    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }

    /**
     * Per-origin: many addresses tried from one host. The email-scoped key alone
     * does not see credential stuffing, because every attempt uses a new address.
     */
    protected function ipThrottleKey(): string
    {
        return 'login-ip|'.request()->ip();
    }
%LOCKOUT_METHOD%
    public function render()
    {
        return view('livewire.auth.login');
    }
}
PHP;
    }

    // -----------------------------------------------------------------------
    // View
    // -----------------------------------------------------------------------

    private function view(ScaffoldWriter $writer): string
    {
        $email = $this->ui->field('email', 'Email', 'email', 'email', 'mail', autofocus: true, placeholder: 'you@example.com');
        $password = $this->ui->password('password', 'Password');
        $submit = $this->ui->submit('login', 'Sign in', 'Signing in…');
        $alertIcon = $this->ui->icon('exclamation', 'mt-0.5 h-5 w-5 shrink-0');

        $body = <<<BLADE
    @error('email')
        <div class="alert-error mb-5" role="alert">
            {$alertIcon}
            <span>{{ \$message }}</span>
        </div>
    @enderror

    <form wire:submit="login" class="space-y-4">
{$email}

{$password}

        <div class="flex items-center justify-between pt-1">
            <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                <input wire:model="remember" type="checkbox"
                       class="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-950">
                Remember me
            </label>
            <a href="{{ route('password.request') }}" class="link text-sm" wire:navigate>Forgot password?</a>
        </div>

{$submit}
    </form>
BLADE;

        return $writer->tokens()->render($this->ui->card(
            'login',
            'Sign in',
            'Welcome back. Enter your credentials to continue.',
            $body,
            'New here? <a href="{{ route(\'register\') }}" class="link" wire:navigate>Create an account</a>',
        ));
    }
}
