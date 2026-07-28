<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Auth\Parts;

use Zuqongtech\LaravelAnvil\Support\Auth\AuthContext;
use Zuqongtech\LaravelAnvil\Support\Auth\Contracts\ScaffoldPart;
use Zuqongtech\LaravelAnvil\Support\Auth\ScaffoldWriter;
use Zuqongtech\LaravelAnvil\Support\Auth\Ui\IconSet;

/**
 * The guest layout every auth screen extends.
 *
 * ASSET MODES
 *
 * The original layout hard-coded the Tailwind Play CDN plus a
 * `<style type="text/tailwindcss">` block. That block is a Play-CDN-only feature:
 * it is compiled in the browser. Switching the project to a real Vite build
 * therefore silently dropped every .card / .btn-primary / .form-input class and
 * left the auth screens unstyled — with nothing in the console to explain it.
 *
 * So the component layer is emitted once, and where it goes depends on
 * anvil.web.frontend.mode:
 *
 *   cdn  — inline in the layout under @verbatim, as before (dev convenience)
 *   vite — written to resources/css/anvil-auth.css and pulled in with @vite
 */
final readonly class GuestLayoutPart implements ScaffoldPart
{
    public function __construct(private IconSet $icons = new IconSet) {}

    public function supports(AuthContext $context): bool
    {
        // Nothing to do when the operator pointed --layout at their own view.
        return $context->generatesGuestLayout();
    }

    public function name(): string
    {
        return 'GuestLayout';
    }

    public function emit(AuthContext $context, ScaffoldWriter $writer): void
    {
        $vite = $this->usesVite();

        $writer->viewFile(
            'layouts/guest.blade.php',
            $writer->tokens()->render($this->layout(), [
                '%THEME_TOGGLE%' => $context->darkMode ? $this->themeToggle() : '',
                '%ASSET_HEAD%' => $vite ? $this->viteHead() : $this->cdnHead(),
            ]),
            'layouts/guest',
        );

        if ($vite) {
            $writer->file(
                resource_path('css/anvil-auth.css'),
                $this->stylesheet(),
                'Stylesheet',
                'css/anvil-auth.css',
            );
        }
    }

    /**
     * @return list<string>
     */
    public function notes(AuthContext $context): array
    {
        if ($this->usesVite()) {
            return [
                'Import the auth stylesheet from resources/css/app.css:  @import "./anvil-auth.css";  then run '
                    .'npm run build.',
            ];
        }

        return [
            'The guest layout uses the Tailwind Play CDN, which compiles styles in the browser and is not for '
                .'production. Run  php artisan anvil:frontend --install  and regenerate with '
                .'anvil.web.frontend.mode=vite.',
        ];
    }

    private function usesVite(): bool
    {
        return strtolower(trim((string) config('anvil.web.frontend.mode', 'cdn'))) === 'vite';
    }

    // -----------------------------------------------------------------------
    // Asset heads
    // -----------------------------------------------------------------------

    private function viteHead(): string
    {
        return <<<'BLADE'
    @vite(['resources/css/app.css', 'resources/js/app.js'])
BLADE;
    }

    private function cdnHead(): string
    {
        $stylesheet = $this->stylesheetBody();

        return <<<BLADE
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
{$stylesheet}
    </style>
    @endverbatim
BLADE;
    }

    /** Standalone CSS file for the Vite path, with the theme as @theme tokens. */
    private function stylesheet(): string
    {
        $body = $this->stylesheetBody();

        return <<<CSS
/*
 | Anvil auth component layer.
 |
 | Import from resources/css/app.css:
 |     @import "./anvil-auth.css";
 |
 | Tailwind 4 resolves @theme into CSS custom properties; on Tailwind 3 move the
 | brand palette and shadows into tailwind.config.js theme.extend instead.
 */

@theme {
    --font-sans: Inter, ui-sans-serif, system-ui, sans-serif;

    --color-brand-50: #eef2ff;
    --color-brand-100: #e0e7ff;
    --color-brand-200: #c7d2fe;
    --color-brand-300: #a5b4fc;
    --color-brand-400: #818cf8;
    --color-brand-500: #6366f1;
    --color-brand-600: #4f46e5;
    --color-brand-700: #4338ca;
    --color-brand-800: #3730a3;
    --color-brand-900: #312e81;

    --shadow-card: 0 1px 2px 0 rgb(16 24 40 / 0.04), 0 1px 3px 0 rgb(16 24 40 / 0.06);
    --shadow-lift: 0 10px 30px -12px rgb(16 24 40 / 0.18);
}

{$body}
CSS;
    }

    /** The @layer blocks, shared by both asset modes so they cannot diverge. */
    private function stylesheetBody(): string
    {
        return <<<'CSS'
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
CSS;
    }

    // -----------------------------------------------------------------------
    // Layout
    // -----------------------------------------------------------------------

    private function themeToggle(): string
    {
        $moon = $this->icons->render('moon', 'h-5 w-5 dark:hidden');
        $sun = $this->icons->render('sun', 'hidden h-5 w-5 dark:block');

        return <<<BLADE
        <button type="button" data-theme-toggle
                class="absolute right-4 top-4 inline-flex h-9 w-9 items-center justify-center rounded-xl text-gray-400 transition hover:bg-white hover:text-gray-600 hover:shadow-sm dark:hover:bg-gray-900 dark:hover:text-gray-300"
                aria-label="Toggle dark mode">
            {$moon}
            {$sun}
        </button>

BLADE;
    }

    private function layout(): string
    {
        return <<<'BLADE'
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

%ASSET_HEAD%
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

        // Copy-to-clipboard, used by the recovery-code list.
        document.addEventListener('click', function (e) {
            var trigger = e.target.closest('[data-copy]');
            if (!trigger) { return; }

            var text = trigger.getAttribute('data-copy');
            if (!navigator.clipboard) { return; }

            navigator.clipboard.writeText(text).then(function () {
                var original = trigger.getAttribute('data-copy-label') || trigger.textContent;
                trigger.setAttribute('data-copy-label', original);
                trigger.textContent = 'Copied';
                setTimeout(function () { trigger.textContent = original; }, 1500);
            });
        });

        // Password strength. Purely client-side feedback — the authoritative
        // rules live in Password::defaults() on the server.
        var RULES = [
            { key: 'length', test: function (v) { return v.length >= 12; } },
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
BLADE;
    }
}
