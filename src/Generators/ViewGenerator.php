<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Generators;

use Illuminate\Support\Str;
use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\Helpers;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates the Blade views for the web scaffold (--web).
 *
 * Per model, under resources/views/{slug}/  (slug = kebab-plural):
 *   index.blade.php   — searchable, sortable, paginated table with row actions
 *   create.blade.php  — create form (includes _form)
 *   edit.blade.php    — edit form (includes _form)
 *   show.blade.php    — read-only detail grid
 *   _form.blade.php   — shared fields, inferred from column types
 *
 * Once per run (idempotent):
 *   layouts/anvil.blade.php     — Tailwind-CDN shell, flash + validation display
 *   layouts/_anvil-nav.blade.php — sidebar whose links are discovered at runtime
 *
 * Blade is produced from nowdoc templates with %TOKEN% placeholders, so PHP never
 * interpolates Blade's own $variables, {{ }} or @directives.
 *
 * The Blade stack is intentionally at feature parity with the Livewire stack:
 * search, sortable headers, per-page control, formatted cells, empty states and a
 * pagination summary. The difference should be the transport, not the product.
 */
final class ViewGenerator implements Generator
{
    private const SENSITIVE = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    public function supports(GenerationOptions $options): bool
    {
        return $options->web ?? false;
    }

    public function getName(): string
    {
        return 'View';
    }

    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $results = [
            $this->ensureLayout($options),
            $this->ensureNav($options),
        ];

        $slug = Helpers::modelToRouteName($meta->model);
        $pk = $this->resolvePk($meta);

        $base = [
            '%LAYOUT%' => (string) config('anvil.web.layout', 'layouts.anvil'),
            '%MODEL%' => $meta->model,
            '%TITLE%' => Str::headline($meta->model),
            '%TITLE_PLURAL%' => Str::headline(Str::pluralStudly($meta->model)),
            '%SLUG%' => $slug,
            '%VAR%' => lcfirst($meta->model),
            '%PLURAL_VAR%' => lcfirst(Str::pluralStudly($meta->model)),
            '%PK%' => $pk ?? '',
        ];

        $files = $options->isLivewire()
            ? [
                // Livewire stack: thin wrappers that mount the components. The
                // table/form/detail markup lives in the Livewire views.
                'index.blade.php' => $this->renderLivewireWrapper($base, 'index'),
                'create.blade.php' => $this->renderLivewireWrapper($base, 'create'),
                'edit.blade.php' => $this->renderLivewireWrapper($base, 'edit'),
                'show.blade.php' => $this->renderLivewireWrapper($base, 'show'),
            ]
            : [
                'index.blade.php' => $this->renderIndex($base, $meta, $pk),
                'create.blade.php' => $this->renderCreate($base),
                'edit.blade.php' => $this->renderEdit($base),
                'show.blade.php' => $this->renderShow($base, $meta, $pk),
                '_form.blade.php' => $this->renderForm($base, $this->formColumns($meta)),
            ];

        $dir = resource_path('views/'.$slug);

        foreach ($files as $name => $content) {
            $path = "{$dir}/{$name}";

            if (file_exists($path) && ! $options->force) {
                $results[] = $this->result($name, $path, 'skipped', 'already exists');

                continue;
            }

            if ($options->dryRun) {
                $results[] = $this->result($name, $path, 'dry-run');

                continue;
            }

            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($path, $content);
            $results[] = $this->result($name, $path, 'success');
        }

        return $results;
    }

    // -----------------------------------------------------------------------
    // Layout (once)
    // -----------------------------------------------------------------------

    protected function ensureLayout(GenerationOptions $options): array
    {
        $layout = (string) config('anvil.web.layout', 'layouts.anvil');
        $path = resource_path('views/'.str_replace('.', '/', $layout).'.blade.php');

        if (! config('anvil.web.generate_layout', true)) {
            return $this->result(basename($path), $path, 'skipped', 'layout generation disabled');
        }

        if (file_exists($path) && ! $options->force) {
            return $this->result(basename($path), $path, 'skipped', 'already exists');
        }

        if ($options->dryRun) {
            return $this->result(basename($path), $path, 'dry-run');
        }

        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $this->layoutTemplate());

        return $this->result(basename($path), $path, 'success', 'layout created');
    }

    /**
     * The shell. Note the @verbatim around the Tailwind block: that stylesheet is
     * full of braces and @-rules, and without it Blade tries to compile
     * `@layer`, `@apply` and every `{ }` inside as directives and expressions.
     */
    protected function layoutTemplate(): string
    {
        return <<<'BLADE'
            <!DOCTYPE html>
            <html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <meta name="csrf-token" content="{{ csrf_token() }}">
                <title>@yield('title', config('app.name', 'Laravel'))</title>

                <link rel="preconnect" href="https://fonts.googleapis.com">
                <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
                <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

                <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
                <script>
                    tailwind.config = {
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
                                boxShadow: { card: '0 1px 2px 0 rgb(16 24 40 / 0.04), 0 1px 3px 0 rgb(16 24 40 / 0.06)' },
                            },
                        },
                    };
                </script>

                @verbatim
                <style type="text/tailwindcss">
                    @layer base {
                        body { @apply antialiased; }
                        [x-cloak] { display: none !important; }
                    }

                    @layer components {
                        .card { @apply rounded-xl border border-gray-200 bg-white shadow-card; }
                        .card-header { @apply flex flex-col gap-3 border-b border-gray-200 p-4 sm:flex-row sm:items-center sm:justify-between; }
                        .card-footer { @apply flex flex-col gap-3 border-t border-gray-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between; }

                        .form-label { @apply mb-1.5 block text-sm font-medium text-gray-700; }
                        .form-hint { @apply mt-1 text-xs text-gray-500; }
                        .form-error { @apply mt-1 text-sm text-red-600; }
                        .form-input,
                        .form-select,
                        .form-textarea { @apply w-full rounded-lg border-gray-300 bg-white text-sm text-gray-900 shadow-sm transition placeholder:text-gray-400 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30; }
                        .form-input-error { @apply border-red-400 focus:border-red-500 focus:ring-red-500/30; }

                        .btn { @apply inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50; }
                        .btn-primary { @apply btn bg-brand-600 text-white shadow-sm hover:bg-brand-700 focus:ring-brand-500; }
                        .btn-secondary { @apply btn border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 focus:ring-gray-400; }
                        .btn-danger { @apply btn bg-red-600 text-white shadow-sm hover:bg-red-700 focus:ring-red-500; }
                        .btn-sm { @apply px-3 py-1.5 text-xs; }

                        .icon-btn { @apply inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-brand-500/30; }
                        .icon-btn-danger { @apply hover:bg-red-50 hover:text-red-600; }

                        .badge { @apply inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700; }
                        .badge-success { @apply bg-green-100 text-green-700; }
                        .badge-muted { @apply bg-gray-100 text-gray-500; }

                        .table-th { @apply whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500; }
                        .table-td { @apply px-4 py-3 text-sm text-gray-700; }

                        .alert { @apply flex items-start gap-3 rounded-lg border px-4 py-3 text-sm; }
                        .link { @apply font-medium text-brand-600 transition hover:text-brand-800; }
                    }
                </style>
                @endverbatim
            </head>
            <body class="h-full bg-gray-50 font-sans text-gray-800">
                {{-- Collapsible sidebar; links are discovered at runtime --}}
                @include('layouts._anvil-nav')

                {{-- Main column — padding shifts to make room for the sidebar on desktop --}}
                <div id="anvil-main" class="flex min-h-full flex-col transition-[padding] duration-300 ease-in-out lg:pl-64">
                    <header class="sticky top-0 z-30 border-b border-gray-200 bg-white/90 backdrop-blur">
                        <div class="flex h-16 items-center gap-3 px-4 sm:px-6">
                            <button type="button" data-anvil-toggle class="icon-btn" aria-label="Toggle navigation">
                                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                                </svg>
                            </button>
                            <h1 class="truncate text-base font-semibold text-gray-800">@yield('title', config('app.name', 'Laravel'))</h1>
                        </div>
                    </header>

                    <main class="flex-1">
                        <div class="mx-auto w-full max-w-12xl px-4 py-6 sm:px-6 sm:py-8">
                            @if (session('success'))
                                <div class="alert mb-6 border-green-200 bg-green-50 text-green-800" role="status">
                                    <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    <span>{{ session('success') }}</span>
                                </div>
                            @endif

                            @if (session('error'))
                                <div class="alert mb-6 border-red-200 bg-red-50 text-red-800" role="alert">
                                    <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    <span>{{ session('error') }}</span>
                                </div>
                            @endif

                            @if ($errors->any())
                                <div class="alert mb-6 border-red-200 bg-red-50 text-red-800" role="alert">
                                    <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    <div>
                                        <p class="font-semibold">Please fix the following:</p>
                                        <ul class="mt-1 list-inside list-disc">
                                            @foreach ($errors->all() as $error)
                                                <li>{{ $error }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                </div>
                            @endif

                            @yield('content')
                        </div>
                    </main>
                </div>
            </body>
        </html>
        BLADE;
    }

    // -----------------------------------------------------------------------
    // Navigation partial (once) — resources discovered at runtime
    // -----------------------------------------------------------------------

    protected function ensureNav(GenerationOptions $options): array
    {
        $layout = (string) config('anvil.web.layout', 'layouts.anvil');
        $layoutDir = dirname(resource_path('views/'.str_replace('.', '/', $layout).'.blade.php'));
        $path = $layoutDir.'/_anvil-nav.blade.php';

        if (! config('anvil.web.generate_nav', true)) {
            return $this->result('_anvil-nav.blade.php', $path, 'skipped', 'nav disabled');
        }

        if (file_exists($path) && ! $options->force) {
            return $this->result('_anvil-nav.blade.php', $path, 'skipped', 'already exists');
        }

        if ($options->dryRun) {
            return $this->result('_anvil-nav.blade.php', $path, 'dry-run');
        }

        if (! is_dir($layoutDir)) {
            mkdir($layoutDir, 0755, true);
        }

        file_put_contents($path, $this->navTemplate($options->isLivewire()));

        return $this->result('_anvil-nav.blade.php', $path, 'success', 'nav created');
    }

    protected function navTemplate(bool $wireNavigate = false): string
    {
        $namespace = addslashes((string) config('anvil.web.controller_namespace', 'App\\Http\\Controllers\\Web'));
        $wireNav = $wireNavigate ? ' wire:navigate.hover' : '';

        $tpl = <<<'BLADE'
        @php
    /**
     * Anvil web navigation — links discovered at runtime from the registered
     * routes. Every "<resource>.index" route whose controller lives in the web
     * scaffold namespace becomes a nav item, so the sidebar stays correct as
     * resources are added or removed. Plain Blade; edit freely.
     */
    $anvilNavItems = collect(app('router')->getRoutes()->getRoutesByName())
        ->filter(fn ($route, $name) => str_ends_with($name, '.index')
            && str_contains((string) $route->getActionName(), '%NAMESPACE%'))
        ->map(function ($route, $name) {
            $base = \Illuminate\Support\Str::beforeLast($name, '.index');

            return (object) [
                'name'   => $name,
                'label'  => \Illuminate\Support\Str::headline(str_replace('-', ' ', $base)),
                'url'    => route($name),
                'active' => request()->routeIs($base . '.*'),
            ];
        })
        ->sortBy('label')
        ->values();
        @endphp

{{-- Backdrop (mobile only) --}}
<div id="anvil-backdrop" class="fixed inset-0 z-40 hidden bg-gray-900/50 backdrop-blur-sm lg:hidden"></div>

<aside id="anvil-sidebar"
       class="fixed inset-y-0 left-0 z-50 flex w-64 -translate-x-full transform flex-col bg-[#1a1a2e] text-gray-200
              transition-transform duration-300 ease-in-out lg:translate-x-0">
    <div class="flex h-16 shrink-0 items-center gap-2 border-b border-white/10 px-5">
        <a href="{{ url('/') }}"%WIRE_NAV% class="group flex items-center gap-2">
            <span class="text-2xl leading-none">&#9874;</span>
            <span class="font-bold tracking-wide text-white transition group-hover:text-brand-300">
                {{ config('app.name', 'Laravel') }}
            </span>
        </a>
        <button type="button" data-anvil-toggle
                class="ml-auto rounded-md p-1 text-gray-400 transition hover:bg-white/10 hover:text-white lg:hidden"
                aria-label="Close navigation">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
        <p class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-gray-500">Resources</p>

        @forelse ($anvilNavItems as $item)
            <a href="{{ $item->url }}"%WIRE_NAV% data-anvil-link
               @if ($item->active) aria-current="page" @endif
               class="group flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition
                      {{ $item->active ? 'bg-white/10 text-white' : 'text-gray-400 hover:bg-white/5 hover:text-white' }}">
                <span class="h-1.5 w-1.5 rounded-full transition
                             {{ $item->active ? 'bg-brand-400' : 'bg-gray-600 group-hover:bg-gray-400' }}"></span>
                <span class="truncate">{{ $item->label }}</span>
            </a>
        @empty
            <p class="px-3 py-2 text-sm text-gray-500">No resources yet.</p>
        @endforelse
    </nav>
    <div class="shrink-0 border-t border-white/10 px-5 py-3 text-xs text-gray-500">
        &#9874; Forged by Laravel Anvil
    </div>
</aside>

<script>
(function () {
    var sidebar  = document.getElementById('anvil-sidebar');
    var main     = document.getElementById('anvil-main');
    var backdrop = document.getElementById('anvil-backdrop');
    if (!sidebar || !main) { return; }

    var KEY = 'anvil-sidebar-open';
    var desktop = window.matchMedia('(min-width: 1024px)');

    function apply(open) {
        sidebar.classList.toggle('-translate-x-full', !open);
        sidebar.classList.toggle('translate-x-0', open);
        sidebar.classList.toggle('lg:-translate-x-full', !open);
        sidebar.classList.toggle('lg:translate-x-0', open);
        main.classList.toggle('lg:pl-64', open);
        main.classList.toggle('lg:pl-0', !open);
        if (backdrop) { backdrop.classList.toggle('hidden', !open); }
    }

    function setOpen(open, persist) {
        apply(open);
        if (persist && desktop.matches) {
            try { localStorage.setItem(KEY, open ? '1' : '0'); } catch (e) {}
        }
    }

    function desktopPref() {
        try { var s = localStorage.getItem(KEY); if (s !== null) { return s === '1'; } } catch (e) {}
        return true;
    }

    setOpen(desktop.matches ? desktopPref() : false, false);

    document.querySelectorAll('[data-anvil-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            setOpen(sidebar.classList.contains('-translate-x-full'), true);
        });
    });

    if (backdrop) {
        backdrop.addEventListener('click', function () { setOpen(false, false); });
    }

    document.querySelectorAll('[data-anvil-link]').forEach(function (a) {
        a.addEventListener('click', function () { if (!desktop.matches) { setOpen(false, false); } });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !desktop.matches) { setOpen(false, false); }
    });

    desktop.addEventListener('change', function (e) {
        setOpen(e.matches ? desktopPref() : false, false);
    });
})();
</script>
BLADE;

        return str_replace(['%NAMESPACE%', '%WIRE_NAV%'], [$namespace, $wireNav], $tpl);
    }

    // -----------------------------------------------------------------------
    // Livewire wrapper views
    // -----------------------------------------------------------------------

    protected function renderLivewireWrapper(array $base, string $kind): string
    {
        $tag = match ($kind) {
            'create' => '<livewire:%SLUG%.form />',
            'edit' => '<livewire:%SLUG%.form :record-id="$recordId" />',
            'show' => '<livewire:%SLUG%.show :record-id="$recordId" />',
            default => '<livewire:%SLUG%.index />',
        };

        $heading = match ($kind) {
            'create' => 'New %TITLE%',
            'edit' => 'Edit %TITLE%',
            'show' => '%TITLE%',
            default => '%TITLE_PLURAL%',
        };

        $tpl = <<<BLADE
@extends('%LAYOUT%')

@section('title', '{$heading}')

@section('content')
    {$tag}
@endsection
BLADE;

        return $this->apply($tpl, $base);
    }

    // -----------------------------------------------------------------------
    // Index
    // -----------------------------------------------------------------------

    protected function renderIndex(array $base, ModelMetadata $meta, ?string $pk): string
    {
        $columns = $this->tableColumns($meta, $pk);
        $searchable = $this->searchableColumns($meta);
        $perPageOptions = (array) config('anvil.web.per_page_options', [10, 15, 25, 50, 100]);

        $head = '';
        $row = '';

        foreach (array_values($columns) as $i => $col) {
            $label = $this->label($col);
            // Beyond the second column, collapse on small screens: a 5-column
            // table is unreadable on a phone and horizontal scroll is worse.
            $hide = $i >= 2 ? ' hidden lg:table-cell' : '';

            $head .= <<<HEAD
                            <th class="table-th{$hide}">
                                <a href="{{ route('%SLUG%.index', array_merge(request()->query(), ['sort' => '{$col}', 'direction' => (request('sort') === '{$col}' && request('direction') === 'asc') ? 'desc' : 'asc'])) }}"
                                   class="group inline-flex items-center gap-1 transition hover:text-gray-700">
                                    {$label}
                                    @if (request('sort') === '{$col}')
                                        <span class="text-brand-600">{!! request('direction') === 'desc' ? '&darr;' : '&uarr;' !!}</span>
                                    @else
                                        <span class="text-gray-300 opacity-0 transition group-hover:opacity-100">&uarr;</span>
                                    @endif
                                </a>
                            </th>
                    HEAD;

            $cell = $this->cellExpression($meta, $col);
            $row .= "                                <td class=\"table-td{$hide}\">{$cell}</td>\n";
        }

        $colspan = (string) (count($columns) + 1);

        $searchBox = $searchable === [] ? '' : <<<'SEARCH'
                <div class="relative w-full sm:w-72">
                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
                    </span>
                    <input type="search" name="q" value="{{ request('q') }}"
                           placeholder="Search %TITLE_PLURAL%…"
                           class="form-input pl-9">
                </div>
                SEARCH;

        $options = '';

        foreach ($perPageOptions as $option) {
            $option = (int) $option;
            $options .= "                        <option value=\"{$option}\" @selected(request('per_page') == {$option})>{$option}</option>\n";
        }

        $tpl = <<<'BLADE'
                @extends('%LAYOUT%')

                @section('title', '%TITLE_PLURAL%')

            @section('content')
                {{-- Header --}}
                <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-900">%TITLE_PLURAL%</h2>
                        <p class="mt-0.5 text-sm text-gray-500">
                            {{ $%PLURAL_VAR%->total() }} {{ \Illuminate\Support\Str::plural('record', $%PLURAL_VAR%->total()) }}
                        </p>
                    </div>
                    <a href="{{ route('%SLUG%.create') }}" class="btn-primary self-start sm:self-auto">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                        New %TITLE%
                    </a>
                </div>

                <div class="card">
                    {{-- Toolbar: search + per page, submitted as GET so the URL stays shareable --}}
                    <form method="GET" action="{{ route('%SLUG%.index') }}" class="card-header">
                        @if (request('sort'))
                            <input type="hidden" name="sort" value="{{ request('sort') }}">
                            <input type="hidden" name="direction" value="{{ request('direction') }}">
                        @endif

            %SEARCH_BOX%
                        <div class="flex items-center gap-2">
                            <label for="per_page" class="text-sm text-gray-500">Per page</label>
                            <select name="per_page" id="per_page" class="form-select w-auto py-1.5" onchange="this.form.submit()">
            %PER_PAGE_OPTIONS%                </select>
                            <button type="submit" class="btn-secondary btn-sm">Apply</button>
                            @if (request()->hasAny(['q', 'sort', 'per_page']))
                                <a href="{{ route('%SLUG%.index') }}" class="btn-secondary btn-sm">Reset</a>
                            @endif
                        </div>
                    </form>

                    {{-- Table --}}
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
            %TABLE_HEAD%                        <th class="table-th text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($%PLURAL_VAR% as $%VAR%)
                                    <tr class="odd:bg-white even:bg-gray-50/50 transition hover:bg-brand-50/40">
            %TABLE_ROW%                            <td class="table-td text-right">
                                            <div class="flex items-center justify-end gap-1">
                                                <a href="{{ route('%SLUG%.show', $%VAR%) }}" class="icon-btn" title="View {{ __('details') }}">
                                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                                    <span class="sr-only">View</span>
                                                </a>
                                                <a href="{{ route('%SLUG%.edit', $%VAR%) }}" class="icon-btn" title="Edit">
                                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                                    <span class="sr-only">Edit</span>
                                                </a>
                                                <form action="{{ route('%SLUG%.destroy', $%VAR%) }}" method="POST"
                                                    onsubmit="return confirm('Delete this %TITLE%? This cannot be undone.')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="icon-btn icon-btn-danger" title="Delete">
                                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                        <span class="sr-only">Delete</span>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="%COLSPAN%" class="px-4 py-16 text-center">
                                            <div class="mx-auto flex max-w-sm flex-col items-center">
                                                <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                                                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                                                </div>
                                                <p class="font-medium text-gray-700">No %TITLE_PLURAL% found</p>
                                                <p class="mt-1 text-sm text-gray-500">
                                                    @if (request('q'))
                                                        No results for &ldquo;{{ request('q') }}&rdquo;.
                                                        <a href="{{ route('%SLUG%.index') }}" class="link">Clear search</a>
                                                    @else
                                                        Get started by creating a new %TITLE%.
                                                    @endif
                                                </p>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{-- Footer: summary + pagination --}}
                    @if ($%PLURAL_VAR%->total() > 0)
                        <div class="card-footer">
                            <p class="text-sm text-gray-500">
                                Showing <span class="font-medium text-gray-700">{{ $%PLURAL_VAR%->firstItem() ?? 0 }}</span>
                                to <span class="font-medium text-gray-700">{{ $%PLURAL_VAR%->lastItem() ?? 0 }}</span>
                                of <span class="font-medium text-gray-700">{{ $%PLURAL_VAR%->total() }}</span>
                            </p>
                            <div>{{ $%PLURAL_VAR%->onEachSide(1)->withQueryString()->links() }}</div>
                        </div>
                    @endif
                </div>
            @endsection
            BLADE;

        return $this->apply($tpl, [
            '%TABLE_HEAD%' => $head,
            '%TABLE_ROW%' => $row,
            '%SEARCH_BOX%' => $searchBox,
            '%PER_PAGE_OPTIONS%' => $options,
            '%COLSPAN%' => $colspan,
        ] + $base);
    }

    /**
     * How a value is rendered in a table cell, by column type.
     *
     * Printing a raw attribute gives "1" for booleans, a full Carbon string for
     * timestamps, an empty cell for null, and an unbounded wall of text for a
     * long column.
     */
    protected function cellExpression(ModelMetadata $meta, string $column): string
    {
        $col = $this->column($meta, $column);
        $type = $this->inputType($col ?? ['name' => $column, 'type' => 'varchar']);
        $accessor = '$%VAR%->'.$column;

        return match ($type) {
            'checkbox' => '@if ('
                .$accessor.') <span class="badge badge-success">Yes</span>'
                .' @else <span class="badge badge-muted">No</span> @endif',
            'date' => '{{ optional('.$accessor.')->format(\'Y-m-d\') ?? \'—\' }}',
            'datetime-local' => '{{ optional('.$accessor.')->format(\'Y-m-d H:i\') ?? \'—\' }}',
            'number' => '{{ '.$accessor.' !== null ? (is_numeric('.$accessor.') ? number_format((float) '
                .$accessor.', str_contains((string) '.$accessor.', \'.\') ? 2 : 0) : '.$accessor.') : \'—\' }}',
            default => '{{ \Illuminate\Support\Str::limit((string) '.$accessor.', 60) ?: \'—\' }}',
        };
    }

    // -----------------------------------------------------------------------
    // Create / edit
    // -----------------------------------------------------------------------

    protected function renderCreate(array $base): string
    {
        $tpl = <<<'BLADE'
@extends('%LAYOUT%')

@section('title', 'New %TITLE%')

@section('content')
    <div class="mx-auto max-w-3xl">
        <div class="mb-6">
            <a href="{{ route('%SLUG%.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 transition hover:text-gray-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                Back to %TITLE_PLURAL%
            </a>
            <h2 class="mt-2 text-2xl font-bold text-gray-900">New %TITLE%</h2>
        </div>

        <form action="{{ route('%SLUG%.store') }}" method="POST" class="card p-6">
            @csrf

            <div class="grid grid-cols-1 gap-x-6 sm:grid-cols-2">
                @include('%SLUG%._form', ['record' => null])
            </div>

            <div class="mt-6 flex items-center justify-end gap-3 border-t border-gray-200 pt-5">
                <a href="{{ route('%SLUG%.index') }}" class="btn-secondary">Cancel</a>
                <button type="submit" class="btn-primary">Create %TITLE%</button>
            </div>
        </form>
    </div>
@endsection
BLADE;

        return $this->apply($tpl, $base);
    }

    protected function renderEdit(array $base): string
    {
        $tpl = <<<'BLADE'
@extends('%LAYOUT%')

@section('title', 'Edit %TITLE%')

@section('content')
    <div class="mx-auto max-w-3xl">
        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <a href="{{ route('%SLUG%.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 transition hover:text-gray-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    Back to %TITLE_PLURAL%
                </a>
                <h2 class="mt-2 text-2xl font-bold text-gray-900">Edit %TITLE%</h2>
            </div>
            <a href="{{ route('%SLUG%.show', $%VAR%) }}" class="btn-secondary self-start sm:self-auto">View</a>
        </div>

        <form action="{{ route('%SLUG%.update', $%VAR%) }}" method="POST" class="card p-6">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 gap-x-6 sm:grid-cols-2">
                @include('%SLUG%._form', ['record' => $%VAR%])
            </div>

            <div class="mt-6 flex items-center justify-end gap-3 border-t border-gray-200 pt-5">
                <a href="{{ route('%SLUG%.index') }}" class="btn-secondary">Cancel</a>
                <button type="submit" class="btn-primary">Save changes</button>
            </div>
        </form>
    </div>
@endsection
BLADE;

        return $this->apply($tpl, $base);
    }

    // -----------------------------------------------------------------------
    // Show
    // -----------------------------------------------------------------------

    protected function renderShow(array $base, ModelMetadata $meta, ?string $pk): string
    {
        $rows = '';

        foreach ($this->showColumns($meta) as $col) {
            $label = $this->label($col);
            $value = $this->detailExpression($meta, $col);

            $rows .= "                <div class=\"px-6 py-4\">\n"
                ."                    <dt class=\"text-xs font-medium uppercase tracking-wider text-gray-500\">{$label}</dt>\n"
                ."                    <dd class=\"mt-1 break-words text-sm text-gray-900\">{$value}</dd>\n"
                ."                </div>\n";
        }

        // Only render a key badge when the model actually has that attribute:
        // printing $record->id on a keyless table throws under strict attributes.
        $badge = $pk === null
            ? ''
            : '<span class="badge ml-2 font-mono">#{{ $%VAR%->'.$pk.' }}</span>';

        $tpl = <<<'BLADE'
@extends('%LAYOUT%')

@section('title', '%TITLE%')

@section('content')
    <div class="mx-auto max-w-3xl">
        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <a href="{{ route('%SLUG%.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 transition hover:text-gray-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    Back to %TITLE_PLURAL%
                </a>
                <h2 class="mt-2 flex items-center text-2xl font-bold text-gray-900">%TITLE%%PK_BADGE%</h2>
            </div>
            <div class="flex items-center gap-2 self-start sm:self-auto">
                <a href="{{ route('%SLUG%.edit', $%VAR%) }}" class="btn-primary">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    Edit
                </a>
                <form action="{{ route('%SLUG%.destroy', $%VAR%) }}" method="POST"
                      onsubmit="return confirm('Delete this %TITLE%? This cannot be undone.')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-danger">Delete</button>
                </form>
            </div>
        </div>

        <div class="card overflow-hidden">
            <dl class="grid grid-cols-1 divide-y divide-gray-100 sm:grid-cols-2 sm:divide-y-0 sm:[&>div]:border-b sm:[&>div]:border-gray-100">
%SHOW_ROWS%            </dl>
        </div>
    </div>
@endsection
BLADE;

        return $this->apply($tpl, [
            '%SHOW_ROWS%' => $rows,
            '%PK_BADGE%' => $badge,
        ] + $base);
    }

    /**
     * Detail-page rendering: same intent as cellExpression() but unbounded, since
     * a detail view is where you go to read the whole value.
     */
    protected function detailExpression(ModelMetadata $meta, string $column): string
    {
        $col = $this->column($meta, $column);
        $type = $this->inputType($col ?? ['name' => $column, 'type' => 'varchar']);
        $accessor = '$%VAR%->'.$column;

        return match ($type) {
            'checkbox' => '@if ('.$accessor.') <span class="badge badge-success">Yes</span>'
                .' @else <span class="badge badge-muted">No</span> @endif',
            'date' => '{{ optional('.$accessor.')->format(\'j M Y\') ?? \'—\' }}',
            'datetime-local' => '{{ optional('.$accessor.')->format(\'j M Y, H:i\') ?? \'—\' }}',
            'textarea' => '<span class="whitespace-pre-line">{{ '.$accessor.' ?: \'—\' }}</span>',
            'url' => '@if ('.$accessor.')<a href="{{ '.$accessor.' }}" class="link" target="_blank" rel="noopener">{{ '
                .$accessor.' }}</a>@else — @endif',
            'email' => '@if ('.$accessor.')<a href="mailto:{{ '.$accessor.' }}" class="link">{{ '
                .$accessor.' }}</a>@else — @endif',
            default => '{{ '.$accessor.' ?? \'—\' }}',
        };
    }

    // -----------------------------------------------------------------------
    // Shared form partial
    // -----------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $formCols
     */
    protected function renderForm(array $base, array $formCols): string
    {
        $header = <<<'BLADE'
{{--
    Shared form fields for %TITLE%.

    Included from create.blade.php with ['record' => null] and from
    edit.blade.php with ['record' => $%VAR%], so every field falls back through
    old() → $record → empty. The wrapping <form> and the grid live in those
    views; this partial is only the fields.
--}}

BLADE;

        $fields = '';

        foreach ($formCols as $col) {
            $fields .= $this->formField($col);
        }

        return $this->apply($header.$fields, $base);
    }

    /**
     * @param  array<string, mixed>  $col
     */
    protected function formField(array $col): string
    {
        $name = (string) $col['name'];
        $label = $this->label($name);
        $type = $this->inputType($col);
        $required = ($col['nullable'] ?? false) ? '' : ' required';
        $requiredMark = ($col['nullable'] ?? false)
            ? ''
            : ' <span class="text-red-500" aria-hidden="true">*</span>';

        $value = "old('{$name}', \$record->{$name} ?? null)";
        $errorClass = "@error('{$name}') form-input-error @enderror";
        $span = in_array($type, ['textarea', 'checkbox'], true) ? ' sm:col-span-2' : '';

        if ($type === 'checkbox') {
            // Hidden 0 first so an unchecked box still submits a value.
            return <<<BLADE
    <div class="mb-4{$span}">
        <label class="flex items-center gap-2">
            <input type="hidden" name="{$name}" value="0">
            <input type="checkbox" name="{$name}" value="1" @checked({$value})
                   class="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
            <span class="text-sm font-medium text-gray-700">{$label}</span>
        </label>
        @error('{$name}') <p class="form-error">{{ \$message }}</p> @enderror
    </div>

BLADE;
        }

        if ($type === 'textarea') {
            $control = <<<BLADE
<textarea name="{$name}" id="{$name}" rows="4" class="form-textarea {$errorClass}"{$required}>{{ {$value} }}</textarea>
BLADE;
        } elseif ($type === 'select') {
            $options = '';

            foreach ($this->enumValues($col) as $option) {
                $options .= "\n            <option value=\"{$option}\" @selected({$value} === '{$option}')>{$option}</option>";
            }

            $control = <<<BLADE
<select name="{$name}" id="{$name}" class="form-select {$errorClass}"{$required}>
            <option value="">&mdash; Select &mdash;</option>{$options}
        </select>
BLADE;
        } else {
            $step = $type === 'number' && $this->isDecimal($col) ? ' step="any"' : '';
            $control = <<<BLADE
<input type="{$type}" name="{$name}" id="{$name}" value="{{ {$value} }}"{$step} class="form-input {$errorClass}"{$required}>
BLADE;
        }

        return <<<BLADE
    <div class="mb-4{$span}">
        <label for="{$name}" class="form-label">{$label}{$requiredMark}</label>
        {$control}
        @error('{$name}') <p class="form-error">{{ \$message }}</p> @enderror
    </div>

BLADE;
    }

    // -----------------------------------------------------------------------
    // Column selection
    // -----------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    protected function formColumns(ModelMetadata $meta): array
    {
        $skip = array_merge(
            [$meta->primaryKey, 'created_at', 'updated_at', 'deleted_at', 'email_verified_at'],
            self::SENSITIVE,
            $meta->compositePrimaryKey ?? [],
        );

        return array_values(array_filter(
            $meta->columns,
            static fn (array $col): bool => ! in_array($col['name'], $skip, true),
        ));
    }

    /**
     * Up to five columns for the listing, key first.
     *
     * @return list<string>
     */
    protected function tableColumns(ModelMetadata $meta, ?string $pk): array
    {
        $skip = array_merge(['deleted_at', 'updated_at'], self::SENSITIVE);
        $names = [];

        if ($pk !== null) {
            $names[] = $pk;
        }

        foreach ($meta->columns as $col) {
            if (count($names) >= 5) {
                break;
            }

            $name = (string) $col['name'];

            if ($name === $pk || in_array($name, $skip, true)) {
                continue;
            }

            // Long text in a table cell is noise; it belongs on the detail page.
            if ($this->inputType($col) === 'textarea') {
                continue;
            }

            $names[] = $name;
        }

        if ($names === [] && $meta->columns !== []) {
            $names[] = (string) $meta->columns[0]['name'];
        }

        return $names;
    }

    /**
     * Text columns the search box scans. An empty result simply hides the box.
     *
     * @return list<string>
     */
    protected function searchableColumns(ModelMetadata $meta): array
    {
        $skip = array_merge(['created_at', 'updated_at', 'deleted_at', $meta->primaryKey], self::SENSITIVE);
        $names = [];

        foreach ($meta->columns as $col) {
            $name = (string) $col['name'];

            if (in_array($name, $skip, true)) {
                continue;
            }

            if ($this->isTextual($col)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    protected function showColumns(ModelMetadata $meta): array
    {
        return array_values(array_filter(
            array_map(strval(...), array_column($meta->columns, 'name')),
            static fn (string $name): bool => ! in_array($name, self::SENSITIVE, true),
        ));
    }

    /**
     * Resolve the primary key to a column that genuinely exists, so generated
     * views never reference a missing attribute (which throws under
     * Model::preventAccessingMissingAttributes()).
     */
    protected function resolvePk(ModelMetadata $meta): ?string
    {
        $columns = array_map(strval(...), array_column($meta->columns, 'name'));

        foreach (array_merge([$meta->primaryKey], $meta->compositePrimaryKey ?? [], ['id']) as $candidate) {
            if (is_string($candidate) && $candidate !== '' && in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return $columns[0] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function column(ModelMetadata $meta, string $name): ?array
    {
        foreach ($meta->columns as $col) {
            if ((string) $col['name'] === $name) {
                return $col;
            }
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Type inference
    // -----------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $col
     */
    protected function inputType(array $col): string
    {
        $name = strtolower((string) ($col['name'] ?? ''));
        $raw = strtolower((string) ($col['type'] ?? 'varchar'));
        $type = (string) preg_replace('/\(.*\)/', '', $raw);

        // Name-based checks first: an email column is a varchar, but "email" is
        // the input type that matters.
        if (str_contains($name, 'password')) {
            return 'password';
        }

        if (str_contains($name, 'email')) {
            return 'email';
        }

        if (str_contains($name, 'url') || str_contains($name, 'website') || str_contains($name, 'link')) {
            return 'url';
        }

        if (str_starts_with($raw, 'enum')) {
            return 'select';
        }

        return match (true) {
            $type === 'tinyint' && str_contains($raw, '(1)') => 'checkbox',
            in_array($type, ['boolean', 'bool'], true) => 'checkbox',
            in_array($type, ['text', 'mediumtext', 'longtext', 'tinytext', 'json', 'jsonb'], true) => 'textarea',
            in_array($type, ['int', 'integer', 'bigint', 'smallint', 'mediumint', 'decimal', 'numeric', 'float', 'double', 'real'], true) => 'number',
            $type === 'date' => 'date',
            in_array($type, ['datetime', 'timestamp', 'timestamptz', 'timestamp without time zone', 'timestamp with time zone'], true) => 'datetime-local',
            $type === 'time' => 'time',
            default => 'text',
        };
    }

    /**
     * @param  array<string, mixed>  $col
     */
    protected function isTextual(array $col): bool
    {
        $type = strtolower((string) preg_replace('/\(.*\)/', '', (string) ($col['type'] ?? '')));

        return in_array(trim($type), [
            'char',
            'character',
            'varchar',
            'character varying',
            'string',
            'text',
            'tinytext',
            'mediumtext',
            'longtext',
            'citext',
            'clob',
            'nchar',
            'nvarchar',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $col
     */
    protected function isDecimal(array $col): bool
    {
        $type = strtolower((string) preg_replace('/\(.*\)/', '', (string) ($col['type'] ?? '')));

        return in_array($type, ['decimal', 'numeric', 'float', 'double', 'real', 'money'], true);
    }

    /**
     * @param  array<string, mixed>  $col
     * @return list<string>
     */
    protected function enumValues(array $col): array
    {
        if (preg_match("/enum\('(.+?)'\)/i", (string) ($col['type'] ?? ''), $matches) === 1) {
            return array_map(trim(...), explode("','", $matches[1]));
        }

        return [];
    }

    protected function label(string $name): string
    {
        return Str::headline((string) preg_replace('/_id$/', '', $name));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Sequential token replacement. Block tokens (%TABLE_ROW% etc.) must precede
     * %VAR% / %SLUG% in the map: str_replace reprocesses earlier replacements, so
     * tokens inside an injected block are resolved by the later passes.
     *
     * @param  array<string, string>  $map
     */
    protected function apply(string $template, array $map): string
    {
        return str_replace(array_keys($map), array_values($map), $template);
    }

    /**
     * @return array<string, string>
     */
    protected function result(string $name, string $path, string $status, ?string $reason = null): array
    {
        $out = [
            'type' => $this->getName(),
            'name' => $name,
            'path' => $path,
            'status' => $status,
        ];

        if ($reason !== null) {
            $out['reason'] = $reason;
        }

        return $out;
    }
}
