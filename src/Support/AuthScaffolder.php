<?php

namespace Zuqongtech\LaravelAnvil\Support;

use Illuminate\Support\Str;

/**
 * Generates a full authentication + authorization layer as Livewire 3
 * components from the users table and its role/permission relationships.
 *
 * All generated file contents are nowdoc templates with %TOKEN% placeholders
 * resolved via strtr() — no PHP interpolation, so generated `$vars` survive
 * verbatim. Optional feature blocks (lockout, 2FA, verification) are toggled
 * by blanking their tokens and skipping their files.
 */
class AuthScaffolder
{
    /** @var list<array{type:string,name:string,status:string,reason?:string}> */
    protected array $results = [];

    /** @var list<string> */
    protected array $userColumns = [];

    protected bool $rbac = false;

    public function __construct(
        protected DatabaseInspector $inspector,
        protected array $config,
    ) {
        $this->userColumns = array_column(
            $inspector->getColumns($config['users_table'], $config['schema'] ?? null),
            'name',
        );

        $tables = array_column($inspector->getAllSchemaTables($config['schema'] ?? null), 'table');
        $this->rbac = in_array($config['roles_table'], $tables, true)
            && in_array($config['permissions_table'], $tables, true);
    }

    public function rbacDetected(): bool
    {
        return $this->rbac;
    }

    protected function has(string $column): bool
    {
        return in_array($column, $this->userColumns, true);
    }

    // =======================================================================
    // Orchestration
    // =======================================================================

    public function generate(): array
    {
        // Shell
        if ($this->config['layout'] === null) {
            $this->write(resource_path('views/layouts/guest.blade.php'), $this->guestLayout(), 'Layout', 'layouts/guest.blade.php');
        }
        $this->write(base_path('routes/auth.php'), $this->routesFile(), 'Routes', 'routes/auth.php');

        // Core components + views
        $this->component('Login', $this->loginComponent(), $this->loginView());
        $this->component('Register', $this->registerComponent(), $this->registerView());
        $this->component('ForgotPassword', $this->forgotComponent(), $this->forgotView());
        $this->component('ResetPassword', $this->resetComponent(), $this->resetView());

        if ($this->config['verification']) {
            $this->component('VerifyEmail', $this->verifyComponent(), $this->verifyView());
        }
        if ($this->config['two_factor']) {
            $this->component('TwoFactorChallenge', $this->twoFactorComponent(), $this->twoFactorView());
            $this->write(app_path('Services/TwoFactorAuthenticationService.php'), $this->twoFactorService(), 'Service', 'TwoFactorAuthenticationService');
            $this->write($this->migrationPath('add_two_factor_columns_to_'.$this->config['users_table'].'_table'), $this->twoFactorMigration(), 'Migration', '2fa columns');
        }

        // Authorization
        $this->write(app_path('Models/Concerns/InteractsWithAuthorization.php'), $this->authorizationTrait(), 'Trait', 'InteractsWithAuthorization');
        $this->write(app_path('Http/Middleware/EnsureUserHasRole.php'), $this->roleMiddleware(), 'Middleware', 'EnsureUserHasRole');
        $this->write(app_path('Http/Middleware/EnsureUserHasPermission.php'), $this->permissionMiddleware(), 'Middleware', 'EnsureUserHasPermission');
        $this->write(app_path('Providers/AnvilAuthServiceProvider.php'), $this->gateProvider(), 'Provider', 'AnvilAuthServiceProvider');

        return $this->results;
    }

    protected function component(string $class, string $php, string $view): void
    {
        $dir = str_replace('\\', '/', substr((string) $this->config['namespace'], strlen('App\\')));
        $this->write(app_path("{$dir}/{$class}.php"), $php, 'Component', "{$class}");
        $slug = Str::kebab($class);
        $this->write(resource_path("views/livewire/auth/{$slug}.blade.php"), $view, 'View', "auth/{$slug}");
    }

    // =======================================================================
    // Token map
    // =======================================================================

    protected function render(string $template): string
    {
        return strtr($template, $this->tokens());
    }

    protected function tokens(): array
    {
        $lockout = $this->config['lockout'];
        $twofa = $this->config['two_factor'];

        // Scalar tokens first — these are the values that also appear *inside*
        // the optional blocks below.
        $scalars = [
            '%AUTH_NS%' => $this->config['namespace'],
            '%USER_FQN%' => 'App\\Models\\User',
            '%USER%' => 'User',
            '%GUARD%' => $this->config['guard'],
            '%LAYOUT%' => $this->config['layout'] ?? 'layouts.guest',
            '%DEFAULT_ROLE%' => (string) ($this->config['default_role'] ?? ''),
            '%ROLES_TABLE%' => $this->config['roles_table'],
            '%PERMISSIONS_TABLE%' => $this->config['permissions_table'],
            '%LOCK_THRESHOLD%' => '5',
            '%LOCK_MINUTES%' => '15',
            '%THROTTLE_MAX%' => '5',
        ];

        // Optional blocks may themselves contain scalar tokens (e.g. %GUARD%,
        // %LOCK_THRESHOLD%). strtr() is single-pass and will NOT re-scan text it
        // has just substituted in, so we must resolve those tokens here, before
        // the blocks enter the map. Otherwise they land verbatim in the output.
        $block = fn (string $s): string => strtr($s, $scalars);

        return $scalars + [
            '%LOCKOUT_CHECK%' => $lockout ? $block($this->lockoutCheckBlock()) : '',
            '%LOCKOUT_ON_FAIL%' => $lockout ? '            $this->registerFailedAttempt($user);' : '',
            '%LOCKOUT_RESET%' => $lockout ? "'failed_login_attempts' => 0, 'locked_until' => null, " : '',
            '%LOCKOUT_METHOD%' => $lockout ? $block($this->lockoutMethod()) : '',
            '%TFA_GATE%' => $twofa ? $block($this->twoFactorGateBlock()) : '',
        ];
    }

    // =======================================================================
    // Guest layout
    // =======================================================================

    protected function guestLayout(): string
    {
        return $this->render(<<<'BLADE'
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name', 'Sign in') }}</title>
    <script>
        (function () {
            try {
                var p = localStorage.getItem('anvil-theme') || 'system';
                document.documentElement.classList.toggle('dark', p === 'dark' || (p === 'system' && matchMedia('(prefers-color-scheme: dark)').matches));
            } catch (e) {}
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: {
                fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
                colors: { brand: { 50:'#eef2ff',100:'#e0e7ff',300:'#a5b4fc',500:'#6366f1',600:'#4f46e5',700:'#4338ca' } },
            } },
        };
    </script>
    <style type="text/tailwindcss">
        @layer components {
            .card { @apply bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm; }
            .form-label { @apply block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5; }
            .form-input { @apply w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-950 px-3 py-2 text-sm text-gray-900 dark:text-gray-100 shadow-sm transition focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none; }
            .btn-primary { @apply inline-flex w-full items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900 disabled:opacity-50; }
            .link { @apply font-medium text-brand-600 dark:text-brand-400 hover:text-brand-800 dark:hover:text-brand-300; }
            .alert-error { @apply rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300; }
            .alert-ok { @apply rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-500/30 dark:bg-green-500/10 dark:text-green-300; }
        }
    </style>
</head>
<body class="h-full bg-gray-50 font-sans text-gray-800 antialiased dark:bg-gray-950 dark:text-gray-200">
    <div class="flex min-h-full flex-col items-center justify-center px-4 py-12">
        <a href="{{ url('/') }}" class="mb-6 flex items-center gap-2 text-lg font-bold text-gray-900 dark:text-white">
            <span class="text-brand-600 dark:text-brand-400">&#9874;</span> {{ config('app.name', 'Application') }}
        </a>
        <div class="w-full max-w-md">
            {{ $slot }}
        </div>
        <p class="mt-8 text-xs text-gray-400 dark:text-gray-600">&copy; {{ date('Y') }} {{ config('app.name', 'Application') }}</p>
    </div>
</body>
</html>
BLADE);
    }

    // =======================================================================
    // Login
    // =======================================================================

    protected function lockoutCheckBlock(): string
    {
        return <<<'PHP'

        if ($user && $user->locked_until && $user->locked_until->isFuture()) {
            throw ValidationException::withMessages([
                'email' => __('This account is temporarily locked. Try again :time.', ['time' => $user->locked_until->diffForHumans()]),
            ]);
        }
PHP;
    }

    protected function lockoutMethod(): string
    {
        return <<<'PHP'

    protected function registerFailedAttempt(User $user): void
    {
        $attempts = (int) ($user->failed_login_attempts ?? 0) + 1;
        $payload = ['failed_login_attempts' => $attempts];

        if ($attempts >= %LOCK_THRESHOLD%) {
            $payload['locked_until'] = now()->addMinutes(%LOCK_MINUTES%);
            $payload['failed_login_attempts'] = 0;
        }

        $user->forceFill($payload)->save();
    }
PHP;
    }

    protected function twoFactorGateBlock(): string
    {
        return <<<'PHP'

        if (! is_null($user->two_factor_secret) && ! is_null($user->two_factor_confirmed_at)) {
            session(['login.2fa.id' => $user->getKey(), 'login.2fa.remember' => $this->remember]);
            Auth::guard('%GUARD%')->logout();

            return $this->redirect(route('two-factor.login'), navigate: true);
        }
PHP;
    }

    protected function loginComponent(): string
    {
        return $this->render(<<<'PHP'
<?php

namespace %AUTH_NS%;

use %USER_FQN%;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('%LAYOUT%')]
class Login extends Component
{
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
%LOCKOUT_CHECK%
        if (! Auth::guard('%GUARD%')->attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($this->throttleKey());
%LOCKOUT_ON_FAIL%
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($this->throttleKey());
        $user = Auth::guard('%GUARD%')->user();
        $user->forceFill([%LOCKOUT_RESET%'last_login_at' => now(), 'last_login_ip' => request()->ip()])->save();
%TFA_GATE%
        session()->regenerate();

        return $this->redirectIntended(default: '/', navigate: true);
    }

    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), %THROTTLE_MAX%)) {
            return;
        }

        event(new Lockout(request()));
        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
        ]);
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }
%LOCKOUT_METHOD%
    public function render()
    {
        return view('livewire.auth.login');
    }
}
PHP);
    }

    protected function loginView(): string
    {
        return $this->render(<<<'BLADE'
<div class="card p-6 sm:p-8">
    <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Sign in</h1>
    <p class="mt-1 mb-6 text-sm text-gray-500 dark:text-gray-400">Welcome back. Enter your credentials to continue.</p>

    @error('email') <div class="alert-error mb-4">{{ $message }}</div> @enderror
    @if (session('status')) <div class="alert-ok mb-4">{{ session('status') }}</div> @endif

    <form wire:submit="login" class="space-y-4">
        <div>
            <label class="form-label" for="email">Email</label>
            <input wire:model="email" id="email" type="email" autocomplete="email" required autofocus class="form-input">
        </div>
        <div>
            <label class="form-label" for="password">Password</label>
            <input wire:model="password" id="password" type="password" autocomplete="current-password" required class="form-input">
            @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div class="flex items-center justify-between">
            <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                <input wire:model="remember" type="checkbox" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500"> Remember me
            </label>
            <a href="{{ route('password.request') }}" class="link text-sm" wire:navigate>Forgot password?</a>
        </div>
        <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="login">
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in…</span>
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">
        No account? <a href="{{ route('register') }}" class="link" wire:navigate>Create one</a>
    </p>
</div>
BLADE);
    }

    // =======================================================================
    // Register
    // =======================================================================

    protected function registerComponent(): string
    {
        $roleAssign = $this->rbac && $this->has('role_id')
            ? "\n        \$roleId = \\App\\Models\\Role::where('name', '%DEFAULT_ROLE%')->value('id');\n        if (\$roleId) { \$data['role_id'] = \$roleId; }\n"
            : '';
        $statusField = $this->has('status') ? "\n        \$data['status'] = 'active';\n" : '';
        $verifyRedirect = $this->config['verification']
            ? "        return \$this->redirect(route('verification.notice'), navigate: true);"
            : "        return \$this->redirectIntended(default: '/', navigate: true);";

        return $this->render(strtr(<<<'PHP'
<?php

namespace %AUTH_NS%;

use %USER_FQN%;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('%LAYOUT%')]
class Register extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function register()
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:%USERS_TABLE%,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $data = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ];
%ROLE_ASSIGN%%STATUS_FIELD%
        $user = User::create($data);

        event(new Registered($user));

        Auth::guard('%GUARD%')->login($user);
        session()->regenerate();

%VERIFY_REDIRECT%
    }

    public function render()
    {
        return view('livewire.auth.register');
    }
}
PHP, [
            '%ROLE_ASSIGN%' => $roleAssign,
            '%STATUS_FIELD%' => $statusField,
            '%VERIFY_REDIRECT%' => $verifyRedirect,
            '%USERS_TABLE%' => $this->config['users_table'],
        ]));
    }

    protected function registerView(): string
    {
        return $this->render(<<<'BLADE'
<div class="card p-6 sm:p-8">
    <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Create account</h1>
    <p class="mt-1 mb-6 text-sm text-gray-500 dark:text-gray-400">Get started with a new account.</p>

    <form wire:submit="register" class="space-y-4">
        <div>
            <label class="form-label" for="name">Name</label>
            <input wire:model="name" id="name" type="text" autocomplete="name" required autofocus class="form-input">
            @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="form-label" for="email">Email</label>
            <input wire:model="email" id="email" type="email" autocomplete="email" required class="form-input">
            @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="form-label" for="password">Password</label>
            <input wire:model="password" id="password" type="password" autocomplete="new-password" required class="form-input">
            @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="form-label" for="password_confirmation">Confirm password</label>
            <input wire:model="password_confirmation" id="password_confirmation" type="password" autocomplete="new-password" required class="form-input">
        </div>
        <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="register">
            <span wire:loading.remove wire:target="register">Create account</span>
            <span wire:loading wire:target="register">Creating…</span>
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">
        Already registered? <a href="{{ route('login') }}" class="link" wire:navigate>Sign in</a>
    </p>
</div>
BLADE);
    }

    // =======================================================================
    // Forgot / Reset password
    // =======================================================================

    protected function forgotComponent(): string
    {
        return $this->render(<<<'PHP'
<?php

namespace %AUTH_NS%;

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('%LAYOUT%')]
class ForgotPassword extends Component
{
    #[Validate('required|string|email')]
    public string $email = '';

    public function sendResetLink()
    {
        $this->validate();

        $status = Password::broker(config('auth.defaults.passwords'))->sendResetLink(['email' => $this->email]);

        // Avoid user enumeration — always report the same neutral status.
        session()->flash('status', __('If that email exists, a reset link is on its way.'));
        $this->reset('email');
    }

    public function render()
    {
        return view('livewire.auth.forgot-password');
    }
}
PHP);
    }

    protected function forgotView(): string
    {
        return $this->render(<<<'BLADE'
<div class="card p-6 sm:p-8">
    <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Forgot password</h1>
    <p class="mt-1 mb-6 text-sm text-gray-500 dark:text-gray-400">We'll email you a link to reset it.</p>

    @if (session('status')) <div class="alert-ok mb-4">{{ session('status') }}</div> @endif

    <form wire:submit="sendResetLink" class="space-y-4">
        <div>
            <label class="form-label" for="email">Email</label>
            <input wire:model="email" id="email" type="email" required autofocus class="form-input">
            @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="btn-primary" wire:loading.attr="disabled">Email reset link</button>
    </form>

    <p class="mt-6 text-center text-sm"><a href="{{ route('login') }}" class="link" wire:navigate>Back to sign in</a></p>
</div>
BLADE);
    }

    protected function resetComponent(): string
    {
        return $this->render(<<<'PHP'
<?php

namespace %AUTH_NS%;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('%LAYOUT%')]
class ResetPassword extends Component
{
    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = request()->string('email', '');
    }

    public function resetPassword()
    {
        $this->validate([
            'token' => ['required'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::broker(config('auth.defaults.passwords'))->reset(
            ['email' => $this->email, 'password' => $this->password, 'password_confirmation' => $this->password_confirmation, 'token' => $this->token],
            function ($user) {
                $user->forceFill(['password' => Hash::make($this->password), 'remember_token' => Str::random(60)])->save();
                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            $this->addError('email', __($status));

            return;
        }

        session()->flash('status', __($status));

        return $this->redirect(route('login'), navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.reset-password');
    }
}
PHP);
    }

    protected function resetView(): string
    {
        return $this->render(<<<'BLADE'
<div class="card p-6 sm:p-8">
    <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Reset password</h1>
    <p class="mt-1 mb-6 text-sm text-gray-500 dark:text-gray-400">Choose a new password for your account.</p>

    <form wire:submit="resetPassword" class="space-y-4">
        <div>
            <label class="form-label" for="email">Email</label>
            <input wire:model="email" id="email" type="email" required class="form-input">
            @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="form-label" for="password">New password</label>
            <input wire:model="password" id="password" type="password" autocomplete="new-password" required class="form-input">
            @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="form-label" for="password_confirmation">Confirm password</label>
            <input wire:model="password_confirmation" id="password_confirmation" type="password" autocomplete="new-password" required class="form-input">
        </div>
        <button type="submit" class="btn-primary" wire:loading.attr="disabled">Reset password</button>
    </form>
</div>
BLADE);
    }

    // =======================================================================
    // Email verification
    // =======================================================================

    protected function verifyComponent(): string
    {
        return $this->render(<<<'PHP'
<?php

namespace %AUTH_NS%;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('%LAYOUT%')]
class VerifyEmail extends Component
{
    public function resend(): void
    {
        if (Auth::guard('%GUARD%')->user()?->hasVerifiedEmail()) {
            $this->redirectIntended(default: '/', navigate: true);

            return;
        }

        Auth::guard('%GUARD%')->user()->sendEmailVerificationNotification();
        session()->flash('status', __('A fresh verification link has been sent to your email.'));
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

    protected function verifyView(): string
    {
        return $this->render(<<<'BLADE'
<div class="card p-6 sm:p-8">
    <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Verify your email</h1>
    <p class="mt-1 mb-6 text-sm text-gray-500 dark:text-gray-400">
        We've sent a verification link to your inbox. Click it to activate your account.
    </p>

    @if (session('status')) <div class="alert-ok mb-4">{{ session('status') }}</div> @endif

    <div class="flex items-center gap-3">
        <button wire:click="resend" class="btn-primary" wire:loading.attr="disabled">Resend link</button>
        <button wire:click="logout" class="link text-sm">Log out</button>
    </div>
</div>
BLADE);
    }

    // =======================================================================
    // Two-factor challenge
    // =======================================================================

    protected function twoFactorComponent(): string
    {
        return $this->render(<<<'PHP'
<?php

namespace %AUTH_NS%;

use %USER_FQN%;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('%LAYOUT%')]
class TwoFactorChallenge extends Component
{
    #[Validate('required|string')]
    public string $code = '';

    public function mount()
    {
        if (! session()->has('login.2fa.id')) {
            return $this->redirect(route('login'), navigate: true);
        }
    }

    public function verify()
    {
        $this->validate();

        $user = User::find(session('login.2fa.id'));
        if (! $user) {
            return $this->redirect(route('login'), navigate: true);
        }

        $service = app(TwoFactorAuthenticationService::class);
        $code = trim(str_replace(' ', '', $this->code));
        $passed = $service->verify(decrypt($user->two_factor_secret), $code);

        // Recovery-code fallback (single-use)
        if (! $passed && $user->two_factor_recovery_codes) {
            $codes = json_decode(decrypt($user->two_factor_recovery_codes), true) ?: [];
            if (in_array($code, $codes, true)) {
                $passed = true;
                $user->forceFill([
                    'two_factor_recovery_codes' => encrypt(json_encode(array_values(array_diff($codes, [$code])))),
                ])->save();
            }
        }

        if (! $passed) {
            throw ValidationException::withMessages(['code' => __('The provided two-factor code was invalid.')]);
        }

        Auth::guard('%GUARD%')->login($user, (bool) session('login.2fa.remember'));
        session()->forget(['login.2fa.id', 'login.2fa.remember']);
        session()->regenerate();

        return $this->redirectIntended(default: '/', navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.two-factor-challenge');
    }
}
PHP);
    }

    protected function twoFactorView(): string
    {
        return $this->render(<<<'BLADE'
<div class="card p-6 sm:p-8">
    <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Two-factor authentication</h1>
    <p class="mt-1 mb-6 text-sm text-gray-500 dark:text-gray-400">
        Enter the 6-digit code from your authenticator app, or a recovery code.
    </p>

    <form wire:submit="verify" class="space-y-4">
        <div>
            <label class="form-label" for="code">Authentication code</label>
            <input wire:model="code" id="code" type="text" inputmode="numeric" autofocus autocomplete="one-time-code" class="form-input tracking-widest">
            @error('code') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="btn-primary" wire:loading.attr="disabled">Verify</button>
    </form>
</div>
BLADE);
    }

    // =======================================================================
    // Two-factor service + migration
    // =======================================================================

    protected function twoFactorService(): string
    {
        return $this->render(<<<'PHP'
<?php

namespace App\Services;

use PragmaRX\Google2FA\Google2FA;

/**
 * Thin wrapper over pragmarx/google2fa for TOTP secrets, provisioning URLs,
 * verification, and recovery codes.
 *
 * Requires: composer require pragmarx/google2fa
 */
class TwoFactorAuthenticationService
{
    public function __construct(private Google2FA $engine = new Google2FA) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    /** otpauth:// provisioning URI — render as a QR code client-side. */
    public function provisioningUri(string $holder, string $secret): string
    {
        return $this->engine->getQRCodeUrl(config('app.name', 'Application'), $holder, $secret);
    }

    public function verify(string $secret, string $code): bool
    {
        return (bool) $this->engine->verifyKey($secret, $code);
    }

    /** @return list<string> */
    public function recoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn () => \Illuminate\Support\Str::random(10).'-'.\Illuminate\Support\Str::random(10))
            ->all();
    }
}
PHP);
    }

    protected function twoFactorMigration(): string
    {
        return $this->render(<<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('%USERS_TABLE%', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('%USERS_TABLE%', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
PHP);
    }

    // =======================================================================
    // Authorization: trait, middleware, gate provider
    // =======================================================================

    protected function authorizationTrait(): string
    {
        return $this->render(<<<'PHP'
<?php

namespace App\Models\Concerns;

use App\Models\Role;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Role/permission helpers backed by the schema's own roles + permissions
 * tables (single role via role_id → roles, permissions via role_permissions).
 *
 * Add `use InteractsWithAuthorization;` to your User model. Assumes a Role
 * model with a `permissions()` relationship and a `name` column.
 */
trait InteractsWithAuthorization
{
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function hasRole(string|array $roles): bool
    {
        if (! $this->role) {
            return false;
        }

        return in_array($this->role->name, (array) $roles, true);
    }

    public function hasPermissionTo(string $permission): bool
    {
        if (($this->is_super_user ?? false) === true) {
            return true;
        }

        return (bool) $this->role?->permissions?->contains('name', $permission);
    }

    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermissionTo($permission)) {
                return true;
            }
        }

        return false;
    }
}
PHP);
    }

    protected function roleMiddleware(): string
    {
        return $this->render(<<<'PHP'
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! method_exists($user, 'hasRole') || ! $user->hasRole($roles)) {
            abort(403, 'You do not have the required role.');
        }

        return $next($request);
    }
}
PHP);
    }

    protected function permissionMiddleware(): string
    {
        return $this->render(<<<'PHP'
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user || ! method_exists($user, 'hasAnyPermission') || ! $user->hasAnyPermission($permissions)) {
            abort(403, 'You do not have the required permission.');
        }

        return $next($request);
    }
}
PHP);
    }

    protected function gateProvider(): string
    {
        return $this->render(<<<'PHP'
<?php

namespace App\Providers;

use App\Models\Permission;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Registers a Gate for every row in the permissions table and grants
 * super-users a blanket pass. Wrapped defensively so it is a no-op before the
 * permissions table exists (e.g. during the first migrate).
 */
class AnvilAuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::before(fn ($user) => ($user->is_super_user ?? false) === true ? true : null);

        try {
            if (! Schema::hasTable('%PERMISSIONS_TABLE%')) {
                return;
            }

            foreach (Permission::query()->pluck('name') as $permission) {
                Gate::define($permission, fn ($user) => method_exists($user, 'hasPermissionTo')
                    ? $user->hasPermissionTo($permission)
                    : false);
            }
        } catch (\Throwable) {
            // Table missing / DB unavailable during boot — skip silently.
        }
    }
}
PHP);
    }

    // =======================================================================
    // Routes
    // =======================================================================

    protected function routesFile(): string
    {
        $verify = $this->config['verification'] ? <<<'PHP'

    Route::get('verify-email', VerifyEmail::class)->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', function (\Illuminate\Foundation\Auth\EmailVerificationRequest $request) {
        $request->fulfill();

        return redirect()->intended('/');
    })->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
PHP : '';

        $twofa = $this->config['two_factor']
            ? "\n    Route::get('two-factor-challenge', TwoFactorChallenge::class)->name('two-factor.login');"
            : '';

        $twofaImport = $this->config['two_factor'] ? "\nuse %AUTH_NS%\\TwoFactorChallenge;" : '';
        $verifyImport = $this->config['verification'] ? "\nuse %AUTH_NS%\\VerifyEmail;" : '';

        return $this->render(strtr(<<<'PHP'
<?php

use %AUTH_NS%\ForgotPassword;
use %AUTH_NS%\Login;
use %AUTH_NS%\Register;
use %AUTH_NS%\ResetPassword;%TWOFA_IMPORT%%VERIFY_IMPORT%
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
 * Authentication routes generated by anvil:generate-auth.
 * Require this file from routes/web.php:  require __DIR__.'/auth.php';
 */

Route::middleware('guest')->group(function () {
    Route::get('login', Login::class)->name('login');
    Route::get('register', Register::class)->name('register');
    Route::get('forgot-password', ForgotPassword::class)->name('password.request');
    Route::get('reset-password/{token}', ResetPassword::class)->name('password.reset');%TWOFA_ROUTE%
});

Route::middleware('auth')->group(function () {%VERIFY_ROUTES%
    Route::post('logout', function () {
        Auth::guard('%GUARD%')->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect('/');
    })->name('logout');
});
PHP, [
            '%TWOFA_IMPORT%' => $twofaImport,
            '%VERIFY_IMPORT%' => $verifyImport,
            '%TWOFA_ROUTE%' => $twofa,
            '%VERIFY_ROUTES%' => $verify,
        ]));
    }

    // =======================================================================
    // Write plumbing
    // =======================================================================

    protected function migrationPath(string $name): string
    {
        return database_path('migrations/'.date('Y_m_d_His').'_'.$name.'.php');
    }

    protected function write(string $path, string $content, string $type, string $name): void
    {
        if (file_exists($path) && ! $this->config['force'] && ! $this->config['dry_run']) {
            $this->results[] = ['type' => $type, 'name' => $name, 'status' => 'skipped', 'reason' => 'exists'];

            return;
        }

        if ($this->config['dry_run']) {
            $this->results[] = ['type' => $type, 'name' => $name, 'status' => 'dry-run'];

            return;
        }

        try {
            if (file_exists($path) && $this->config['backup']) {
                @copy($path, $path.'.'.date('YmdHis').'.bak');
            }
            $dir = dirname($path);
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            file_put_contents($path, $content);
            $this->results[] = ['type' => $type, 'name' => $name, 'status' => 'success'];
        } catch (\Throwable $e) {
            $this->results[] = ['type' => $type, 'name' => $name, 'status' => 'failed', 'reason' => $e->getMessage()];
        }
    }

    // =======================================================================
    // Post-install guidance
    // =======================================================================

    /** @return list<string> */
    public function postInstallNotes(): array
    {
        $notes = [
            "Require the auth routes: add  require __DIR__.'/auth.php';  to routes/web.php",
            'Add  use App\\Models\\Concerns\\InteractsWithAuthorization;  to your User model (and `use` the trait in the class body).',
            'Register the gate provider in bootstrap/providers.php: App\\Providers\\AnvilAuthServiceProvider::class',
            "Register middleware aliases in bootstrap/app.php withMiddleware(): \$middleware->alias(['role' => EnsureUserHasRole::class, 'permission' => EnsureUserHasPermission::class]);",
            'Ensure App\\Models\\Role has a permissions() belongsToMany relationship (via role_permissions) and a name column.',
        ];

        if ($this->config['two_factor']) {
            $notes[] = 'Install the TOTP library:  composer require pragmarx/google2fa';
            $notes[] = 'Run the 2FA migration:  php artisan migrate  (adds two_factor_* columns to '.$this->config['users_table'].')';
        }
        if ($this->config['verification']) {
            $notes[] = 'Implement MustVerifyEmail on the User model to enforce email verification.';
        }
        if (! $this->rbac) {
            $notes[] = 'Note: roles/permissions tables were not both found — RBAC helpers assume App\\Models\\Role & App\\Models\\Permission exist. Generate them with anvil:generate first.';
        }

        return $notes;
    }
}
