<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

use Illuminate\Support\Str;

/**
 * Generates a full authentication + authorization layer as Livewire 3 components
 * from the users table and its role/permission relationships.
 *
 * All generated file contents are nowdoc templates with %TOKEN% placeholders
 * resolved via strtr() — no PHP interpolation, so generated `$vars`, `{{ }}` and
 * `@directives` survive verbatim. Optional feature blocks (lockout, 2FA,
 * verification) are toggled by blanking their tokens and skipping their files.
 *
 * The views share one design system with the web scaffold: the same brand palette,
 * Inter, `card` / `form-input` / `btn-primary` tokens. Every screen is assembled
 * from authCard() plus the field helpers, so restyling auth is a handful of edits
 * rather than one per view.
 */
class AuthScaffolder
{
    /** @var list<array{type: string, name: string, status: string, reason?: string}> */
    protected array $results = [];

    /** @var list<string> */
    protected array $userColumns = [];

    protected bool $rbac = false;

    public function __construct(
        protected DatabaseInspector $inspector,
        protected array $config,
    ) {
        $this->userColumns = array_map(strval(...), array_column(
            $inspector->getColumns($config['users_table'], $config['schema'] ?? null),
            'name',
        ));

        $tables = array_map(strval(...), array_column(
            $inspector->getAllSchemaTables($config['schema'] ?? null),
            'table',
        ));

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

    protected function enabled(string $feature): bool
    {
        return (bool) ($this->config[$feature] ?? false);
    }

    // =======================================================================
    // Orchestration
    // =======================================================================

    /**
     * @return list<array{type: string, name: string, status: string, reason?: string}>
     */
    public function generate(): array
    {
        if ($this->config['layout'] === null) {
            $this->write(resource_path('views/layouts/guest.blade.php'), $this->guestLayout(), 'Layout', 'layouts/guest');
        }

        $this->write(base_path('routes/auth.php'), $this->routesFile(), 'Routes', 'routes/auth.php');

        $this->component('Login', $this->loginComponent(), $this->loginView());
        $this->component('Register', $this->registerComponent(), $this->registerView());
        $this->component('ForgotPassword', $this->forgotComponent(), $this->forgotView());
        $this->component('ResetPassword', $this->resetComponent(), $this->resetView());

        if ($this->enabled('verification')) {
            $this->component('VerifyEmail', $this->verifyComponent(), $this->verifyView());
        }

        if ($this->enabled('two_factor')) {
            $this->component('TwoFactorChallenge', $this->twoFactorComponent(), $this->twoFactorView());
            // Without a settings screen nothing can ever write two_factor_secret,
            // which makes the challenge unreachable and the feature decorative.
            $this->component('TwoFactorSettings', $this->twoFactorSettingsComponent(), $this->twoFactorSettingsView());
            $this->write(app_path('Services/TwoFactorAuthenticationService.php'), $this->twoFactorService(), 'Service', 'TwoFactorAuthenticationService');

            if (! $this->has('two_factor_secret')) {
                $this->write(
                    $this->migrationPath('add_two_factor_columns_to_'.$this->config['users_table'].'_table'),
                    $this->twoFactorMigration(),
                    'Migration',
                    '2fa columns',
                );
            }
        }

        // login() stamps failed_login_attempts / locked_until / last_login_at,
        // so on a stock users table the first SUCCESSFUL login would throw.
        if ($this->enabled('lockout') && ! $this->has('locked_until')) {
            $this->write(
                $this->migrationPath('add_login_security_columns_to_'.$this->config['users_table'].'_table'),
                $this->lockoutMigration(),
                'Migration',
                'lockout columns',
            );
        }

        $this->write(app_path('Models/Concerns/InteractsWithAuthorization.php'), $this->authorizationTrait(), 'Trait', 'InteractsWithAuthorization');
        $this->write(app_path('Http/Middleware/EnsureUserHasRole.php'), $this->roleMiddleware(), 'Middleware', 'EnsureUserHasRole');
        $this->write(app_path('Http/Middleware/EnsureUserHasPermission.php'), $this->permissionMiddleware(), 'Middleware', 'EnsureUserHasPermission');
        $this->write(app_path('Providers/AnvilAuthServiceProvider.php'), $this->gateProvider(), 'Provider', 'AnvilAuthServiceProvider');

        return $this->results;
    }

    protected function component(string $class, string $php, string $view): void
    {
        $dir = str_replace('\\', '/', substr((string) $this->config['namespace'], strlen('App\\')));
        $this->write(app_path("{$dir}/{$class}.php"), $php, 'Component', $class);

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

    /**
     * @return array<string, string>
     */
    protected function tokens(): array
    {
        $lockout = $this->enabled('lockout');
        $twofa = $this->enabled('two_factor');

        $scalars = [
            '%AUTH_NS%' => (string) $this->config['namespace'],
            '%USER_FQN%' => 'App\\Models\\User',
            '%USER%' => 'User',
            '%GUARD%' => (string) $this->config['guard'],
            '%LAYOUT%' => (string) ($this->config['layout'] ?? 'layouts.guest'),
            '%DEFAULT_ROLE%' => (string) ($this->config['default_role'] ?? ''),
            '%ROLES_TABLE%' => (string) $this->config['roles_table'],
            '%PERMISSIONS_TABLE%' => (string) $this->config['permissions_table'],
            '%USERS_TABLE%' => (string) $this->config['users_table'],
            '%LOCK_THRESHOLD%' => '5',
            '%LOCK_MINUTES%' => '15',
            '%THROTTLE_MAX%' => '5',
        ];

        // Optional blocks contain scalar tokens of their own (%GUARD%,
        // %LOCK_THRESHOLD%). strtr() is single-pass and does NOT re-scan text it
        // just substituted, so those must be resolved before the blocks enter
        // the map — otherwise they land in the output verbatim.
        $block = static fn (string $s): string => strtr($s, $scalars);

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
        return $this->render(strtr(<<<'BLADE'
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name', 'Sign in') }}</title>

    {{-- Resolved before first paint, so there is no flash of the wrong theme. --}}
    <script>
        (function () {
            try {
                var pref = localStorage.getItem('anvil-theme') || 'system';
                var dark = pref === 'dark' || (pref === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', dark);
            } catch (e) {}
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
                    colors: {
                        brand: {
                            50: '#eef2ff', 100: '#e0e7ff', 200: '#c7d2fe', 300: '#a5b4fc',
                            400: '#818cf8', 500: '#6366f1', 600: '#4f46e5', 700: '#4338ca',
                            800: '#3730a3', 900: '#312e81',
                        },
                    },
                    boxShadow: {
                        card: '0 1px 2px 0 rgb(16 24 40 / 0.04), 0 1px 3px 0 rgb(16 24 40 / 0.06)',
                        lift: '0 10px 30px -12px rgb(16 24 40 / 0.18)',
                    },
                },
            },
        };
    </script>

    {{-- @verbatim is required: this stylesheet is full of braces and @-rules that
         Blade would otherwise try to compile as directives and expressions. --}}
    @verbatim
    <style type="text/tailwindcss">
        @layer base {
            body { @apply antialiased; }
        }

        @layer components {
            .card { @apply rounded-2xl border border-gray-200 bg-white shadow-lift dark:border-gray-800 dark:bg-gray-900; }

            .form-label { @apply mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300; }
            .form-error { @apply mt-1.5 flex items-start gap-1 text-sm text-red-600 dark:text-red-400; }
            .form-hint { @apply mt-1.5 text-xs text-gray-500 dark:text-gray-500; }

            .form-input {
                @apply w-full rounded-xl border-gray-300 bg-white py-2.5 text-sm text-gray-900 shadow-sm transition
                       placeholder:text-gray-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15
                       dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100 dark:placeholder:text-gray-600;
            }
            .form-input-icon { @apply pl-10; }
            .form-input-action { @apply pr-11; }
            .form-input-error { @apply border-red-400 focus:border-red-500 focus:ring-red-500/15 dark:border-red-500/60; }

            .input-affix { @apply pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400 dark:text-gray-500; }
            .input-action { @apply absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 transition hover:text-gray-600 focus:outline-none dark:hover:text-gray-300; }

            .btn { @apply inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold transition
                          focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60
                          dark:focus:ring-offset-gray-900; }
            .btn-primary { @apply btn w-full bg-brand-600 text-white shadow-sm hover:bg-brand-700 active:bg-brand-800 focus:ring-brand-500; }
            .btn-secondary { @apply btn border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 focus:ring-gray-400
                                    dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800; }
            .btn-danger { @apply btn w-full bg-red-600 text-white hover:bg-red-700 focus:ring-red-500; }
            .btn-ghost { @apply btn text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200; }

            .link { @apply font-medium text-brand-600 transition hover:text-brand-800 dark:text-brand-400 dark:hover:text-brand-300; }
            .badge { @apply inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium; }

            .alert { @apply flex items-start gap-3 rounded-xl border px-4 py-3 text-sm; }
            .alert-error { @apply alert border-red-200 bg-red-50 text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300; }
            .alert-ok { @apply alert border-green-200 bg-green-50 text-green-800 dark:border-green-500/30 dark:bg-green-500/10 dark:text-green-300; }
            .alert-info { @apply alert border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-300; }
            .alert-warn { @apply alert border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200; }

            .icon-tile { @apply mb-5 inline-flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400; }

            .strength-bar { @apply h-1.5 flex-1 rounded-full bg-gray-200 transition-colors dark:bg-gray-800; }
            .check-item { @apply flex items-center gap-1.5 text-xs text-gray-400 transition-colors dark:text-gray-500; }
            .check-item[data-met="1"] { @apply text-green-600 dark:text-green-400; }
        }
    </style>
    @endverbatim
</head>
<body class="h-full bg-gray-50 font-sans text-gray-800 antialiased dark:bg-gray-950 dark:text-gray-200">
    {{-- Ambient background: a soft brand wash, no images to load. --}}
    <div aria-hidden="true" class="pointer-events-none fixed inset-0 overflow-hidden">
        <div class="absolute -top-40 left-1/2 h-80 w-[46rem] -translate-x-1/2 rounded-full bg-brand-200/40 blur-3xl dark:bg-brand-500/10"></div>
        <div class="absolute -bottom-40 right-0 h-72 w-72 rounded-full bg-indigo-100/60 blur-3xl dark:bg-indigo-500/5"></div>
    </div>

    <div class="relative flex min-h-full flex-col items-center justify-center px-4 py-12">
%THEME_TOGGLE%
        <a href="{{ url('/') }}" class="mb-7 flex items-center gap-2.5 text-lg font-bold tracking-tight text-gray-900 dark:text-white">
            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-[#1a1a2e] text-base text-white shadow-sm">&#9874;</span>
            {{ config('app.name', 'Application') }}
        </a>

        <div class="w-full max-w-md">
            {{ $slot }}
        </div>

        <p class="mt-8 text-xs text-gray-400 dark:text-gray-600">
            &copy; {{ date('Y') }} {{ config('app.name', 'Application') }}
        </p>
    </div>

    <script>
    (function () {
        // Password reveal. Delegated, so it survives Livewire re-renders.
        document.addEventListener('click', function (e) {
            var toggle = e.target.closest('[data-reveal]');
            if (!toggle) { return; }

            var input = document.getElementById(toggle.getAttribute('data-reveal'));
            if (!input) { return; }

            var showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            toggle.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            toggle.querySelectorAll('[data-reveal-icon]').forEach(function (icon) {
                icon.classList.toggle('hidden');
            });
        });

        // Theme toggle.
        document.addEventListener('click', function (e) {
            if (!e.target.closest('[data-theme-toggle]')) { return; }

            var dark = document.documentElement.classList.toggle('dark');
            try { localStorage.setItem('anvil-theme', dark ? 'dark' : 'light'); } catch (err) {}
        });

        // Password strength. Purely client-side feedback — the authoritative
        // rules live in Password::defaults() on the server.
        var RULES = [
            { key: 'length', test: function (v) { return v.length >= 8; } },
            { key: 'case',   test: function (v) { return /[a-z]/.test(v) && /[A-Z]/.test(v); } },
            { key: 'number', test: function (v) { return /\d/.test(v); } },
            { key: 'symbol', test: function (v) { return /[^A-Za-z0-9]/.test(v); } }
        ];
        var COLORS = ['bg-gray-200 dark:bg-gray-800', 'bg-red-500', 'bg-amber-500', 'bg-yellow-500', 'bg-green-500'];
        var LABELS = ['', 'Weak', 'Fair', 'Good', 'Strong'];

        document.addEventListener('input', function (e) {
            var input = e.target.closest('[data-strength]');
            if (!input) { return; }

            var meter = document.getElementById(input.getAttribute('data-strength'));
            if (!meter) { return; }

            var value = input.value || '';
            var score = 0;

            RULES.forEach(function (rule) {
                var met = rule.test(value);
                if (met) { score++; }

                var item = meter.querySelector('[data-check="' + rule.key + '"]');
                if (item) { item.setAttribute('data-met', met ? '1' : '0'); }
            });

            meter.querySelectorAll('[data-bar]').forEach(function (bar, i) {
                bar.className = 'strength-bar ' + (i < score && value ? COLORS[score] : COLORS[0]);
            });

            var label = meter.querySelector('[data-strength-label]');
            if (label) { label.textContent = value ? LABELS[score] : ''; }
        });
    })();
    </script>
</body>
</html>
BLADE, ['%THEME_TOGGLE%' => $this->themeToggle()]));
    }

    protected function themeToggle(): string
    {
        if (! ($this->config['dark_mode'] ?? false)) {
            return '';
        }

        return <<<'BLADE'
        <button type="button" data-theme-toggle
                class="absolute right-4 top-4 inline-flex h-9 w-9 items-center justify-center rounded-xl text-gray-400 transition hover:bg-white hover:text-gray-600 hover:shadow-sm dark:hover:bg-gray-900 dark:hover:text-gray-300"
                aria-label="Toggle dark mode">
            <svg class="h-5 w-5 dark:hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
            <svg class="hidden h-5 w-5 dark:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
        </button>

BLADE;
    }

    // =======================================================================
    // Shared view building blocks
    // =======================================================================

    /**
     * The shell every auth screen shares: icon tile, heading, sub-heading, a
     * status region, the body, and an optional footer line.
     */
    protected function authCard(string $icon, string $heading, string $subheading, string $body, string $footer = ''): string
    {
        $footerBlock = $footer === '' ? '' : <<<BLADE


    <p class="mt-7 border-t border-gray-100 pt-5 text-center text-sm text-gray-500 dark:border-gray-800 dark:text-gray-400">
        {$footer}
    </p>
BLADE;

        $iconSvg = $this->icon($icon);

        return <<<BLADE
<div class="card p-6 sm:p-8">
    <div class="icon-tile">{$iconSvg}</div>

    <h1 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-white">{$heading}</h1>
    <p class="mt-1.5 mb-6 text-sm text-gray-500 dark:text-gray-400">{$subheading}</p>

    @if (session('status'))
        <div class="alert-ok mb-5" role="status">
            {$this->icon('check-circle', 'mt-0.5 h-5 w-5 shrink-0')}
            <span>{{ session('status') }}</span>
        </div>
    @endif

{$body}{$footerBlock}
</div>
BLADE;
    }

    /**
     * A text input with an optional leading icon, error state and hint.
     */
    protected function textField(
        string $model,
        string $label,
        string $type = 'text',
        string $autocomplete = 'off',
        ?string $icon = null,
        bool $autofocus = false,
        string $hint = '',
        string $placeholder = '',
    ): string {
        $iconMarkup = $icon === null ? '' : '<span class="input-affix">'.$this->icon($icon, 'h-4 w-4').'</span>';
        $iconClass = $icon === null ? '' : ' form-input-icon';
        $focus = $autofocus ? ' autofocus' : '';
        $placeholderAttr = $placeholder === '' ? '' : ' placeholder="'.$placeholder.'"';
        $hintMarkup = $hint === '' ? '' : "\n            <p class=\"form-hint\">{$hint}</p>";

        return <<<BLADE
        <div>
            <label class="form-label" for="{$model}">{$label}</label>
            <div class="relative">
                {$iconMarkup}
                <input wire:model="{$model}" id="{$model}" name="{$model}" type="{$type}"
                       autocomplete="{$autocomplete}" required{$focus}{$placeholderAttr}
                       @error('{$model}') aria-invalid="true" aria-describedby="{$model}-error" @enderror
                       class="form-input{$iconClass} @error('{$model}') form-input-error @enderror">
            </div>{$hintMarkup}
            @error('{$model}')
                <p class="form-error" id="{$model}-error">
                    {$this->icon('exclamation', 'mt-0.5 h-3.5 w-3.5 shrink-0')}
                    <span>{{ \$message }}</span>
                </p>
            @enderror
        </div>
BLADE;
    }

    /**
     * A password input with a reveal toggle, and optionally a strength meter with
     * a live requirement checklist.
     */
    protected function passwordField(
        string $model,
        string $label,
        string $autocomplete = 'current-password',
        bool $strength = false,
        bool $autofocus = false,
    ): string {
        $strengthAttr = $strength ? " data-strength=\"{$model}-meter\"" : '';
        $focus = $autofocus ? ' autofocus' : '';
        $meter = $strength ? $this->strengthMeter($model) : '';

        return <<<BLADE
        <div>
            <label class="form-label" for="{$model}">{$label}</label>
            <div class="relative">
                <span class="input-affix">{$this->icon('lock', 'h-4 w-4')}</span>
                <input wire:model="{$model}" id="{$model}" name="{$model}" type="password"
                       autocomplete="{$autocomplete}" required{$focus}{$strengthAttr}
                       @error('{$model}') aria-invalid="true" aria-describedby="{$model}-error" @enderror
                       class="form-input form-input-icon form-input-action @error('{$model}') form-input-error @enderror">
                <button type="button" data-reveal="{$model}" class="input-action" aria-label="Show password" tabindex="-1">
                    <span data-reveal-icon>{$this->icon('eye', 'h-4 w-4')}</span>
                    <span data-reveal-icon class="hidden">{$this->icon('eye-off', 'h-4 w-4')}</span>
                </button>
            </div>{$meter}
            @error('{$model}')
                <p class="form-error" id="{$model}-error">
                    {$this->icon('exclamation', 'mt-0.5 h-3.5 w-3.5 shrink-0')}
                    <span>{{ \$message }}</span>
                </p>
            @enderror
        </div>
BLADE;
    }

    protected function strengthMeter(string $model): string
    {
        $items = [
            'length' => '8+ characters',
            'case' => 'Upper & lowercase',
            'number' => 'A number',
            'symbol' => 'A symbol',
        ];

        $checks = '';

        foreach ($items as $key => $text) {
            $checks .= <<<BLADE

                    <li class="check-item" data-check="{$key}" data-met="0">
                        {$this->icon('check', 'h-3 w-3 shrink-0')}{$text}
                    </li>
BLADE;
        }

        return <<<BLADE

            <div id="{$model}-meter" class="mt-2.5">
                <div class="flex items-center gap-2">
                    <div class="flex flex-1 gap-1">
                        <span data-bar class="strength-bar"></span>
                        <span data-bar class="strength-bar"></span>
                        <span data-bar class="strength-bar"></span>
                        <span data-bar class="strength-bar"></span>
                    </div>
                    <span data-strength-label class="w-12 text-right text-xs font-medium text-gray-500 dark:text-gray-400"></span>
                </div>
                <ul class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1">{$checks}
                </ul>
            </div>
BLADE;
    }

    /**
     * A submit button with a spinner bound to the given Livewire action.
     */
    protected function submitButton(string $action, string $label, string $busyLabel, string $class = 'btn-primary'): string
    {
        return <<<BLADE
        <button type="submit" class="{$class}" wire:loading.attr="disabled" wire:target="{$action}">
            <span wire:loading.remove wire:target="{$action}">{$label}</span>
            <span wire:loading.flex wire:target="{$action}" class="items-center gap-2">
                {$this->icon('spinner', 'h-4 w-4 animate-spin')}
                {$busyLabel}
            </span>
        </button>
BLADE;
    }

    /**
     * Inline SVGs — no icon-font request, no build step.
     */
    protected function icon(string $name, string $class = 'h-5 w-5'): string
    {
        $paths = [
            'lock' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>',
            'login' => '<path stroke-linecap="round" stroke-linejoin="round" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>',
            'user-plus' => '<path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>',
            'mail' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>',
            'mail-open' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 19V9l9-6 9 6v10a2 2 0 01-2 2H5a2 2 0 01-2-2zm0-10l9 6 9-6"/>',
            'user' => '<path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>',
            'key' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.74 5.74L12 16l-1 1H9v2H7v2H4a1 1 0 01-1-1v-3l6.26-6.26A6 6 0 1121 9z"/>',
            'shield' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>',
            'refresh' => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>',
            'eye' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>',
            'eye-off' => '<path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>',
            'check' => '<path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>',
            'check-circle' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>',
            'exclamation' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>',
            'info' => '<path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>',
            'spinner' => '<circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" stroke="none" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>',
        ];

        $path = $paths[$name] ?? $paths['info'];

        return '<svg class="'.$class.'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">'.$path.'</svg>';
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
        $email = $this->textField('email', 'Email', 'email', 'email', 'mail', autofocus: true, placeholder: 'you@example.com');
        $password = $this->passwordField('password', 'Password');
        $submit = $this->submitButton('login', 'Sign in', 'Signing in…');
        $alertIcon = $this->icon('exclamation', 'mt-0.5 h-5 w-5 shrink-0');

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

        return $this->render($this->authCard(
            'login',
            'Sign in',
            'Welcome back. Enter your credentials to continue.',
            $body,
            'New here? <a href="{{ route(\'register\') }}" class="link" wire:navigate>Create an account</a>',
        ));
    }

    // =======================================================================
    // Register
    // =======================================================================

    protected function registerComponent(): string
    {
        // Only validate and persist "name" when the table actually has it.
        $nameProp = $this->has('name') ? "    public string \$name = '';\n\n" : '';
        $nameRule = $this->has('name') ? "\n            'name' => ['required', 'string', 'max:255']," : '';
        $nameData = $this->has('name') ? "\n            'name' => \$validated['name']," : '';

        $roleAssign = $this->rbac && $this->has('role_id')
            ? "\n        \$roleId = \\App\\Models\\Role::where('name', '%DEFAULT_ROLE%')->value('id');\n\n        if (\$roleId) {\n            \$data['role_id'] = \$roleId;\n        }\n"
            : '';

        $statusField = $this->has('status') ? "\n        \$data['status'] = 'active';\n" : '';

        $verifyRedirect = $this->enabled('verification')
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
%NAME_PROP%    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function register()
    {
        $validated = $this->validate([%NAME_RULE%
            'email' => ['required', 'string', 'email', 'max:255', 'unique:%USERS_TABLE%,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $data = [%NAME_DATA%
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
            '%NAME_PROP%' => $nameProp,
            '%NAME_RULE%' => $nameRule,
            '%NAME_DATA%' => $nameData,
            '%ROLE_ASSIGN%' => $roleAssign,
            '%STATUS_FIELD%' => $statusField,
            '%VERIFY_REDIRECT%' => $verifyRedirect,
        ]));
    }

    protected function registerView(): string
    {
        $name = $this->has('name')
            ? $this->textField('name', 'Full name', 'text', 'name', 'user', autofocus: true, placeholder: 'Ada Lovelace')."\n\n"
            : '';

        $email = $this->textField(
            'email',
            'Email',
            'email',
            'email',
            'mail',
            autofocus: ! $this->has('name'),
            placeholder: 'you@example.com',
        );

        $password = $this->passwordField('password', 'Password', 'new-password', strength: true);
        $confirm = $this->passwordField('password_confirmation', 'Confirm password', 'new-password');
        $submit = $this->submitButton('register', 'Create account', 'Creating…');

        $body = <<<BLADE
    <form wire:submit="register" class="space-y-4">
{$name}{$email}

{$password}

{$confirm}

        <p class="pt-1 text-xs leading-relaxed text-gray-500 dark:text-gray-500">
            By creating an account you agree to our terms of service and privacy policy.
        </p>

{$submit}
    </form>
BLADE;

        return $this->render($this->authCard(
            'user-plus',
            'Create account',
            'A few details and you are ready to go.',
            $body,
            'Already registered? <a href="{{ route(\'login\') }}" class="link" wire:navigate>Sign in</a>',
        ));
    }

    // =======================================================================
    // Forgot / reset password
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

    public function sendResetLink(): void
    {
        $this->validate();

        Password::broker(config('auth.defaults.passwords'))->sendResetLink(['email' => $this->email]);

        // Always the same neutral message: a per-account response would let an
        // attacker enumerate which addresses are registered.
        session()->flash('status', __('If that email is registered, a reset link is on its way.'));

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
        $email = $this->textField('email', 'Email', 'email', 'email', 'mail', autofocus: true, placeholder: 'you@example.com');
        $submit = $this->submitButton('sendResetLink', 'Email reset link', 'Sending…');

        $body = <<<BLADE
    <form wire:submit="sendResetLink" class="space-y-4">
{$email}

{$submit}
    </form>
BLADE;

        return $this->render($this->authCard(
            'key',
            'Forgot password',
            'Enter your email and we will send you a reset link.',
            $body,
            '<a href="{{ route(\'login\') }}" class="link" wire:navigate>Back to sign in</a>',
        ));
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

        // Cast explicitly: request()->string() returns a Stringable, and
        // assigning that to a typed string property is a TypeError.
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
                    'remember_token' => Str::random(60),
                ])->save();

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
        $email = $this->textField('email', 'Email', 'email', 'email', 'mail');
        $password = $this->passwordField('password', 'New password', 'new-password', strength: true, autofocus: true);
        $confirm = $this->passwordField('password_confirmation', 'Confirm password', 'new-password');
        $submit = $this->submitButton('resetPassword', 'Reset password', 'Resetting…');

        $body = <<<BLADE
    <form wire:submit="resetPassword" class="space-y-4">
{$email}

{$password}

{$confirm}

{$submit}
    </form>
BLADE;

        return $this->render($this->authCard(
            'lock',
            'Reset password',
            'Choose a new password for your account.',
            $body,
        ));
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
    /** Set once a link has been sent, so the button can disable itself. */
    public bool $sent = false;

    public function resend(): void
    {
        $user = Auth::guard('%GUARD%')->user();

        if ($user?->hasVerifiedEmail()) {
            $this->redirectIntended(default: '/', navigate: true);

            return;
        }

        $user->sendEmailVerificationNotification();

        $this->sent = true;

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
        $spinner = $this->icon('spinner', 'h-4 w-4 animate-spin');
        $refresh = $this->icon('refresh', 'h-4 w-4');

        // The verify route is throttled at 6/min; disabling the button after a
        // send avoids an avoidable 429.
        $body = <<<BLADE
    <div class="alert-info mb-5">
        {$this->icon('info', 'mt-0.5 h-5 w-5 shrink-0')}
        <span>Click the link in the email to activate your account. Check your spam folder if it has not arrived.</span>
    </div>

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

        return $this->render($this->authCard(
            'mail-open',
            'Verify your email',
            'We have sent a verification link to your inbox.',
            $body,
        ));
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

        $code = trim(str_replace(' ', '', $this->code));
        $passed = false;

        // decrypt() throws if APP_KEY has rotated since the secret was stored;
        // treat that as a failed attempt rather than a 500.
        try {
            $passed = app(TwoFactorAuthenticationService::class)->verify(decrypt($user->two_factor_secret), $code);
        } catch (\Throwable) {
            $passed = false;
        }

        // Recovery-code fallback, single use.
        if (! $passed && $user->two_factor_recovery_codes) {
            try {
                $codes = json_decode(decrypt($user->two_factor_recovery_codes), true) ?: [];
            } catch (\Throwable) {
                $codes = [];
            }

            if (in_array($code, $codes, true)) {
                $passed = true;

                $user->forceFill([
                    'two_factor_recovery_codes' => encrypt(json_encode(array_values(array_diff($codes, [$code])))),
                ])->save();
            }
        }

        if (! $passed) {
            throw ValidationException::withMessages(['code' => __('That code is not valid.')]);
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
        $submit = $this->submitButton('verify', 'Verify', 'Verifying…');
        $errorIcon = $this->icon('exclamation', 'mt-0.5 h-3.5 w-3.5 shrink-0');

        $body = <<<BLADE
    <form wire:submit="verify" class="space-y-4">
        <div>
            <label class="form-label" for="code">Authentication code</label>
            <input wire:model="code" id="code" name="code" type="text" inputmode="numeric"
                   autocomplete="one-time-code" maxlength="20" autofocus placeholder="000000"
                   @error('code') aria-invalid="true" aria-describedby="code-error" @enderror
                   class="form-input text-center font-mono text-lg tracking-[0.4em] @error('code') form-input-error @enderror">
            @error('code')
                <p class="form-error" id="code-error">
                    {$errorIcon}
                    <span>{{ \$message }}</span>
                </p>
            @enderror
        </div>

{$submit}
    </form>

    <details class="mt-6 text-sm">
        <summary class="cursor-pointer select-none font-medium text-gray-500 transition hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
            Lost access to your device?
        </summary>
        <p class="mt-2 text-gray-500 dark:text-gray-400">
            Enter one of the recovery codes you saved when you enabled two-factor authentication. Each code works once.
        </p>
    </details>
BLADE;

        return $this->render($this->authCard(
            'shield',
            'Two-factor authentication',
            'Enter the 6-digit code from your authenticator app.',
            $body,
        ));
    }

    // =======================================================================
    // Two-factor settings (enable / confirm / disable)
    // =======================================================================

    protected function twoFactorSettingsComponent(): string
    {
        return $this->render(<<<'PHP'
<?php

namespace %AUTH_NS%;

use App\Services\TwoFactorAuthenticationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Enable, confirm and disable two-factor authentication.
 *
 * The secret is stored encrypted but NOT trusted until the user proves they can
 * produce a code from it (two_factor_confirmed_at). Confirming before trusting is
 * what stops someone locking themselves out with a mis-scanned QR code.
 */
#[Layout('%LAYOUT%')]
class TwoFactorSettings extends Component
{
    public bool $enabled = false;

    public bool $confirming = false;

    public string $code = '';

    public string $password = '';

    /** @var list<string> */
    public array $recoveryCodes = [];

    public ?string $provisioningUri = null;

    public function mount(): void
    {
        $user = Auth::guard('%GUARD%')->user();

        $this->enabled = $user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null;
    }

    public function enable(): void
    {
        $user = Auth::guard('%GUARD%')->user();
        $service = app(TwoFactorAuthenticationService::class);

        $secret = $service->generateSecret();
        $codes = $service->recoveryCodes();

        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode($codes)),
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->provisioningUri = $service->provisioningUri($user->email, $secret);
        $this->recoveryCodes = $codes;
        $this->confirming = true;
    }

    public function confirm(): void
    {
        $this->validate(['code' => ['required', 'string']]);

        $user = Auth::guard('%GUARD%')->user();

        if (! app(TwoFactorAuthenticationService::class)->verify(decrypt($user->two_factor_secret), trim(str_replace(' ', '', $this->code)))) {
            throw ValidationException::withMessages(['code' => __('That code is not valid. Wait for the next one and try again.')]);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->enabled = true;
        $this->confirming = false;
        $this->code = '';

        session()->flash('status', __('Two-factor authentication is now enabled.'));
    }

    /**
     * Disabling requires the account password: someone with a hijacked session
     * should not be able to strip the second factor.
     */
    public function disable(): void
    {
        $this->validate(['password' => ['required', 'string']]);

        $user = Auth::guard('%GUARD%')->user();

        if (! Hash::check($this->password, $user->password)) {
            throw ValidationException::withMessages(['password' => __('That password is incorrect.')]);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->reset('enabled', 'confirming', 'code', 'password', 'recoveryCodes', 'provisioningUri');

        session()->flash('status', __('Two-factor authentication has been disabled.'));
    }

    public function render()
    {
        return view('livewire.auth.two-factor-settings');
    }
}
PHP);
    }

    protected function twoFactorSettingsView(): string
    {
        $confirmSubmit = $this->submitButton('confirm', 'Confirm and enable', 'Confirming…');
        $disableSubmit = $this->submitButton('disable', 'Disable two-factor', 'Disabling…', 'btn-danger');
        $enableSubmit = $this->submitButton('enable', 'Enable two-factor', 'Preparing…');
        $errorIcon = $this->icon('exclamation', 'mt-0.5 h-3.5 w-3.5 shrink-0');

        $body = <<<BLADE
    @if (\$enabled)
        <div class="alert-ok mb-6" role="status">
            {$this->icon('check-circle', 'mt-0.5 h-5 w-5 shrink-0')}
            <span>Two-factor authentication is <strong>enabled</strong> on this account.</span>
        </div>

        <form wire:submit="disable" class="space-y-4">
            <div>
                <label class="form-label" for="password">Confirm your password to disable</label>
                <div class="relative">
                    <span class="input-affix">{$this->icon('lock', 'h-4 w-4')}</span>
                    <input wire:model="password" id="password" type="password" autocomplete="current-password" required
                           class="form-input form-input-icon @error('password') form-input-error @enderror">
                </div>
                @error('password')
                    <p class="form-error">{$errorIcon}<span>{{ \$message }}</span></p>
                @enderror
            </div>

{$disableSubmit}
        </form>
    @elseif (\$confirming)
        <div class="alert-info mb-5">
            {$this->icon('info', 'mt-0.5 h-5 w-5 shrink-0')}
            <span>Scan the code with your authenticator app, then enter the 6-digit code it shows.</span>
        </div>

        <div class="mb-5 flex justify-center">
            {{-- Rendered by an external service so no QR library is needed. Swap
                 for bacon/bacon-qr-code if outbound calls are not acceptable. --}}
            <img class="rounded-xl border border-gray-200 bg-white p-2 dark:border-gray-800"
                 width="200" height="200" alt="Two-factor QR code"
                 src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data={{ urlencode(\$provisioningUri) }}">
        </div>

        <details class="mb-5">
            <summary class="cursor-pointer select-none text-sm font-medium text-gray-500 transition hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                Cannot scan it?
            </summary>
            <p class="mt-2 break-all rounded-xl bg-gray-50 p-3 font-mono text-xs text-gray-600 dark:bg-gray-950 dark:text-gray-400">
                {{ \$provisioningUri }}
            </p>
        </details>

        <div class="alert-warn mb-5 flex-col items-start">
            <p class="font-semibold">Save your recovery codes</p>
            <p class="mt-1 text-xs">Each code works once if you lose your device. They will not be shown again.</p>
            <ul class="mt-3 grid w-full grid-cols-2 gap-1 font-mono text-xs">
                @foreach (\$recoveryCodes as \$recoveryCode)
                    <li>{{ \$recoveryCode }}</li>
                @endforeach
            </ul>
        </div>

        <form wire:submit="confirm" class="space-y-4">
            <div>
                <label class="form-label" for="code">Authentication code</label>
                <input wire:model="code" id="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                       maxlength="6" autofocus placeholder="000000"
                       class="form-input text-center font-mono text-lg tracking-[0.4em] @error('code') form-input-error @enderror">
                @error('code')
                    <p class="form-error">{$errorIcon}<span>{{ \$message }}</span></p>
                @enderror
            </div>

{$confirmSubmit}
        </form>
    @else
        <p class="mb-6 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
            Add a second step to sign-in using an authenticator app such as Google Authenticator, 1Password or Authy.
            You will be asked for a code each time you sign in.
        </p>

        <form wire:submit="enable">
{$enableSubmit}
        </form>
    @endif
BLADE;

        return $this->render($this->authCard(
            'shield',
            'Two-factor authentication',
            'Protect your account with a second step.',
            $body,
        ));
    }

    // =======================================================================
    // Two-factor service + migrations
    // =======================================================================

    protected function twoFactorService(): string
    {
        return $this->render(<<<'PHP'
<?php

namespace App\Services;

use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Thin wrapper over pragmarx/google2fa for TOTP secrets, provisioning URLs,
 * verification and recovery codes.
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

    /** otpauth:// provisioning URI — render as a QR code. */
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
            ->map(fn (): string => Str::random(10).'-'.Str::random(10))
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
        Schema::table('%USERS_TABLE%', function (Blueprint $table): void {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('%USERS_TABLE%', function (Blueprint $table): void {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
PHP);
    }

    protected function lockoutMigration(): string
    {
        return $this->render(<<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns the generated Login component writes on every attempt. Without them a
 * successful sign-in throws, so this migration must run before using the
 * scaffold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('%USERS_TABLE%', function (Blueprint $table): void {
            $table->unsignedTinyInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
            // 45 characters: the longest IPv6 form, including an IPv4 tail.
            $table->string('last_login_ip', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('%USERS_TABLE%', function (Blueprint $table): void {
            $table->dropColumn(['failed_login_attempts', 'locked_until', 'last_login_at', 'last_login_ip']);
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
 * Role/permission helpers backed by the schema's own roles + permissions tables
 * (single role via role_id → roles, permissions via role_permissions).
 *
 * Add `use InteractsWithAuthorization;` to your User model. Assumes a Role model
 * with a permissions() relationship and a name column.
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
 * Registers a Gate for every row in the permissions table and grants super-users
 * a blanket pass. Wrapped defensively so it is a no-op before the permissions
 * table exists (during the first migrate, for instance).
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
            // Table missing or DB unavailable during boot — skip silently.
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
        $verify = $this->enabled('verification') ? <<<'PHP'

    Route::get('verify-email', VerifyEmail::class)->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', function (\Illuminate\Foundation\Auth\EmailVerificationRequest $request) {
        $request->fulfill();

        return redirect()->intended('/');
    })->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
PHP : '';

        $twofaGuest = $this->enabled('two_factor')
            ? "\n    Route::get('two-factor-challenge', TwoFactorChallenge::class)->name('two-factor.login');"
            : '';

        $twofaAuth = $this->enabled('two_factor')
            ? "\n    Route::get('settings/two-factor', TwoFactorSettings::class)->name('two-factor.settings');"
            : '';

        $twofaImport = $this->enabled('two_factor')
            ? "\nuse %AUTH_NS%\\TwoFactorChallenge;\nuse %AUTH_NS%\\TwoFactorSettings;"
            : '';

        $verifyImport = $this->enabled('verification') ? "\nuse %AUTH_NS%\\VerifyEmail;" : '';

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

Route::middleware('guest')->group(function (): void {
    Route::get('login', Login::class)->name('login');
    Route::get('register', Register::class)->name('register');
    Route::get('forgot-password', ForgotPassword::class)->name('password.request');
    Route::get('reset-password/{token}', ResetPassword::class)->name('password.reset');%TWOFA_ROUTE%
});

Route::middleware('auth')->group(function (): void {%VERIFY_ROUTES%%TWOFA_SETTINGS%

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
            '%TWOFA_ROUTE%' => $twofaGuest,
            '%TWOFA_SETTINGS%' => $twofaAuth,
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
        $exists = file_exists($path);

        // Checked before the dry-run branch: a dry run over an existing file
        // would otherwise report "would write" when the real run would skip.
        if ($exists && ! $this->config['force']) {
            $this->results[] = ['type' => $type, 'name' => $name, 'status' => 'skipped', 'reason' => 'exists'];

            return;
        }

        if ($this->config['dry_run']) {
            $this->results[] = [
                'type' => $type,
                'name' => $name,
                'status' => 'dry-run',
                'reason' => $exists ? 'would overwrite' : 'would create',
            ];

            return;
        }

        try {
            if ($exists && $this->config['backup']) {
                @copy($path, $path.'.'.date('YmdHis').'.bak');
            }

            $dir = dirname($path);

            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            throw_if(file_put_contents($path, $content) === false, \RuntimeException::class, 'file_put_contents() returned false');

            $this->results[] = [
                'type' => $type,
                'name' => $name,
                'status' => 'success',
                'reason' => $exists ? 'overwritten' : 'created',
            ];
        } catch (\Throwable $e) {
            $this->results[] = ['type' => $type, 'name' => $name, 'status' => 'failed', 'reason' => $e->getMessage()];
        }
    }

    // =======================================================================
    // Post-install guidance
    // =======================================================================

    /**
     * @return list<string>
     */
    public function postInstallNotes(): array
    {
        $notes = [
            "Require the auth routes: add  require __DIR__.'/auth.php';  to routes/web.php",
            'Add  use App\\Models\\Concerns\\InteractsWithAuthorization;  to your User model, and use the trait in the class body.',
            'Register the gate provider in bootstrap/providers.php:  App\\Providers\\AnvilAuthServiceProvider::class',
            "Alias the middleware in bootstrap/app.php withMiddleware():  \$middleware->alias(['role' => EnsureUserHasRole::class, 'permission' => EnsureUserHasPermission::class]);",
            'Ensure App\\Models\\Role has a permissions() belongsToMany relationship (via role_permissions) and a name column.',
        ];

        if ($this->enabled('two_factor')) {
            $notes[] = 'Install the TOTP library:  composer require pragmarx/google2fa';
            $notes[] = 'Link to the setup screen from your account area:  route(\'two-factor.settings\')';
            $notes[] = 'The QR code is rendered by api.qrserver.com. For an offline render, install bacon/bacon-qr-code and swap the <img> in livewire/auth/two-factor-settings.blade.php.';
        }

        if ($this->enabled('verification')) {
            $notes[] = 'Implement MustVerifyEmail on the User model to enforce email verification.';
        }

        if ($this->enabled('lockout') && ! $this->has('locked_until')) {
            $notes[] = 'Run  php artisan migrate  before signing in: login writes failed_login_attempts, locked_until, last_login_at and last_login_ip.';
        }

        if (! $this->rbac) {
            $notes[] = 'Roles/permissions tables were not both found — the RBAC helpers assume App\\Models\\Role and App\\Models\\Permission exist. Generate them with anvil:generate first.';
        }

        return $notes;
    }
}
