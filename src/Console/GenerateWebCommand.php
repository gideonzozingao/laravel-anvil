<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Console;

use Illuminate\Console\Command;
use Zuqongtech\LaravelAnvil\Console\Concerns\InstallsFrontendAssets;
use Zuqongtech\LaravelAnvil\Console\Concerns\RunsGenerationPipeline;
use Zuqongtech\LaravelAnvil\Support\ConfigValidator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;

/**
 * Dedicated command for the web scaffold: resource controllers
 * (App\Http\Controllers\Web), Blade views (index/create/edit/show/_form plus a
 * runtime-discovered navigation), and Route::resource entries in routes/web.php.
 *
 *   php artisan anvil:generate-web --tables=posts --tables=comments
 *   php artisan anvil:generate-web                        # every non-ignored table
 *   php artisan anvil:generate-web --stack=livewire --install-assets
 *   php artisan anvil:generate-web --assets-mode=vite --tailwind-version=4
 *   php artisan anvil:generate-web --layout=layouts.app --no-layout --force
 *   php artisan anvil:generate-web --per-page=25 --skip-models
 *
 * The web scaffold reuses the same Services and FormRequests as the API path, so
 * those are generated alongside it. Models are generated too unless
 * --skip-models is passed, which keeps the command standalone.
 *
 * It shares the entire generation pipeline with anvil:generate via
 * RunsGenerationPipeline — a separate command, not a separate engine.
 *
 * Frontend dependencies are handled by InstallsFrontendAssets, which runs as a
 * preflight BEFORE the pipeline. That ordering is not cosmetic: a Composer
 * install cannot take effect in the process that performs it, because the
 * autoloader is already built and the providers already registered.
 */
class GenerateWebCommand extends Command
{
    use InstallsFrontendAssets;
    use RunsGenerationPipeline;

    /** @var list<string> */
    private const STACKS = ['blade', 'livewire'];

    /** @var list<string> */
    private const ASSET_MODES = ['cdn', 'vite', 'none'];

    /** @var list<int> */
    private const TAILWIND_VERSIONS = [3, 4];

    /** @var list<int> */
    private const PER_PAGE_OPTIONS = [10, 15, 25, 50, 100];

    private const PER_PAGE_CEILING = 500;

    /**
     * Frontend flags live in the signature, not in getOptions(): Laravel builds
     * the definition from $signature via configureUsingFluentDefinition() and
     * never consults getOptions() on a signature-based command.
     */
    protected $signature = 'anvil:forge-webapp
                            {--stack=blade   : Frontend stack — "blade" (Blade + Tailwind) or "livewire" (Blade + Livewire 3)}
                            {--tables=*      : Limit generation to specific tables}
                            {--only=*        : Alias for --tables}
                            {--ignore=*      : Exclude specific tables}
                            {--connection=   : Database connection to introspect}
                            {--schema=       : Schema(s) to generate from: name, csv list, or "all"}
                            {--namespace=App\\Models : Namespace of the models the scaffold references}
                            {--path=app      : Base path for generated models}
                            {--layout=       : Blade layout the views extend (overrides anvil.web.layout)}
                            {--no-layout     : Do not generate a base layout — you already have one}
                            {--no-nav        : Do not generate the sidebar navigation partial}
                            {--per-page=15   : Default rows per page in generated listings}
                            {--assets-mode=  : How views load Tailwind: cdn | vite | none (overrides anvil.web.frontend.mode)}
                            {--install-assets : Install every frontend dependency the selected stack needs}
                            {--with-livewire : Install Livewire 3 if the project does not already have it}
                            {--with-tailwind : Install and wire Tailwind CSS if the project does not already have it}
                            {--tailwind-version= : Tailwind major version to install when missing (3 or 4)}
                            {--no-package-manager : Write config files but print the composer/npm commands instead of running them}
                            {--skip-asset-check : Bypass the frontend preflight entirely}
                            {--skip-models   : Do not (re)generate models; assume they already exist}
                            {--no-inverse    : Skip inverse relationship detection when models are generated}
                            {--force         : Overwrite existing files without prompting}
                            {--backup        : Backup existing files before overwriting}
                            {--dry-run       : Preview without writing files}';

    protected $description = 'Generate a web scaffold (resource controllers, Blade views and web routes) from the database';

    public function handle(): int
    {
        $stack = $this->resolveStack();
        $assetsMode = $this->resolveAssetsMode();
        $perPage = $this->resolvePerPage();
        $tailwindVersion = $this->resolveTailwindVersion();

        // Any of the four returning null means the input was rejected and the
        // reason has already been printed.
        if ($stack === null || $assetsMode === null || $perPage === null || $tailwindVersion === null) {
            return self::FAILURE;
        }

        $this->info('🔧 Validating configuration...');
        $validator = new ConfigValidator;

        if (! $validator->validate()) {
            $this->displayValidationErrors($validator);

            return self::FAILURE;
        }

        if ($validator->hasWarnings()) {
            $this->displayValidationWarnings($validator);
        }

        $this->info("✅ Configuration valid.\n");

        // Frontend dependencies are settled before a single file is written, so
        // we never emit Livewire components into a project that cannot run them.
        // A non-null return is the exit code — including SUCCESS for
        // "installed, now re-run me".
        if (($exit = $this->preflightFrontendAssets($stack, $assetsMode, $tailwindVersion)) !== null) {
            return $exit;
        }

        $this->applyRuntimeConfig($perPage, $assetsMode);
        $this->summarise($stack, $perPage, $assetsMode);

        return $this->runPipeline($this->buildOptions($stack, $assetsMode));
    }

    // -----------------------------------------------------------------------
    // Input resolution
    // -----------------------------------------------------------------------

    /**
     * Validates the RAW option value.
     *
     * The previous implementation normalised first — `=== 'livewire' ? … :
     * 'blade'` — which made the subsequent in_array() guard unreachable and
     * turned `--stack=lievwire` into a silent fall-through to blade.
     */
    private function resolveStack(): ?string
    {
        $stack = strtolower(trim((string) $this->option('stack')));

        if ($stack === '') {
            return 'blade';
        }

        if (! in_array($stack, self::STACKS, true)) {
            $this->components->error(sprintf(
                'Unknown --stack "%s". Use one of: %s.',
                $stack,
                implode(', ', self::STACKS),
            ));

            return null;
        }

        return $stack;
    }

    private function resolveAssetsMode(): ?string
    {
        $raw = $this->option('assets-mode');

        if ($raw === null || trim((string) $raw) === '') {
            $configured = strtolower(trim((string) config('anvil.web.frontend.mode', 'cdn')));

            // A bad config value is the maintainer's problem, not the operator's:
            // fall back rather than fail the run, but say so.
            if (! in_array($configured, self::ASSET_MODES, true)) {
                if ($configured !== '') {
                    $this->components->warn(sprintf(
                        'anvil.web.frontend.mode is "%s", which is not one of %s. Falling back to "cdn".',
                        $configured,
                        implode(', ', self::ASSET_MODES),
                    ));
                }

                return 'cdn';
            }

            return $configured;
        }

        $mode = strtolower(trim((string) $raw));

        if (! in_array($mode, self::ASSET_MODES, true)) {
            $this->components->error(sprintf(
                'Unknown --assets-mode "%s". Use one of: %s.',
                $mode,
                implode(', ', self::ASSET_MODES),
            ));

            return null;
        }

        return $mode;
    }

    private function resolvePerPage(): ?int
    {
        $raw = trim((string) $this->option('per-page'));

        if (! ctype_digit($raw) || (int) $raw < 1) {
            $this->components->error('--per-page must be a positive integer.');

            return null;
        }

        $perPage = (int) $raw;

        if ($perPage > self::PER_PAGE_CEILING) {
            $this->components->error(sprintf(
                '--per-page of %d is above the ceiling of %d. Generated listings would time out before they render.',
                $perPage,
                self::PER_PAGE_CEILING,
            ));

            return null;
        }

        return $perPage;
    }

    private function resolveTailwindVersion(): ?int
    {
        $raw = $this->option('tailwind-version');

        if ($raw === null || trim((string) $raw) === '') {
            $configured = (int) config('anvil.web.frontend.tailwind_version', 4);

            return in_array($configured, self::TAILWIND_VERSIONS, true) ? $configured : 4;
        }

        $version = (int) preg_replace('/\D+/', '', (string) $raw);

        if (! in_array($version, self::TAILWIND_VERSIONS, true)) {
            $this->components->error(sprintf(
                'Unsupported --tailwind-version "%s". Use one of: %s.',
                (string) $raw,
                implode(', ', array_map(strval(...), self::TAILWIND_VERSIONS)),
            ));

            return null;
        }

        return $version;
    }

    // -----------------------------------------------------------------------
    // Runtime configuration
    // -----------------------------------------------------------------------

    /**
     * Push the view-shaping options into config, where ViewGenerator,
     * WebControllerGenerator and LivewireComponentGenerator read them.
     */
    private function applyRuntimeConfig(int $perPage, string $assetsMode): void
    {
        $values = [
            'web.per_page' => $perPage,
            'web.per_page_options' => $this->perPageOptions($perPage),
            'web.frontend.mode' => $assetsMode,
        ];

        $layout = (string) ($this->option('layout') ?? '');

        // Compared against '' rather than checked for truthiness, so a layout
        // literally named "0" is not silently discarded.
        if ($layout !== '') {
            $values['web.layout'] = $layout;
        }

        if ($this->option('no-layout')) {
            $values['web.generate_layout'] = false;
        }

        if ($this->option('no-nav')) {
            $values['web.generate_nav'] = false;
        }

        foreach ($values as $key => $value) {
            config(["anvil.{$key}" => $value, "laravel-anvil.{$key}" => $value]);
        }
    }

    /**
     * The generated listings render a per-page <select> from this list, so a
     * custom --per-page has to appear in it or the dropdown opens showing a
     * value the user never chose.
     *
     * @return list<int>
     */
    private function perPageOptions(int $perPage): array
    {
        $options = self::PER_PAGE_OPTIONS;

        if (! in_array($perPage, $options, true)) {
            $options[] = $perPage;
            sort($options);
        }

        return array_values($options);
    }

    private function buildOptions(string $stack, string $assetsMode): GenerationOptions
    {
        $tables = array_values(array_unique(array_merge(
            array_map(strval(...), $this->option('tables') ?? []),
            array_map(strval(...), $this->option('only') ?? []),
        )));

        // web / form_requests / services are set explicitly: the scaffold depends
        // on the latter two. Models are on unless skipped, so the command
        // produces working CRUD from nothing.
        return GenerationOptions::fromArray([
            'models' => ! $this->option('skip-models'),
            'web' => true,
            'stack' => $stack,
            'assets_mode' => $assetsMode,
            'form_requests' => true,
            'services' => true,
            'force' => (bool) $this->option('force'),
            'backup' => (bool) $this->option('backup'),
            'dry_run' => (bool) $this->option('dry-run'),
            'with_phpdoc' => true,
            'with_inverse' => ! $this->option('no-inverse'),
            'namespace' => $this->option('namespace'),
            'path' => $this->option('path'),
            'connection' => $this->option('connection'),
            'schemas' => $this->option('schema'),
            'tables' => $tables,
            'ignore' => array_map(strval(...), $this->option('ignore') ?? []),
        ]);
    }

    // -----------------------------------------------------------------------
    // Reporting
    // -----------------------------------------------------------------------

    private function summarise(string $stack, int $perPage, string $assetsMode): void
    {
        $layout = (string) config('anvil.web.layout', 'layouts.anvil');
        $layoutPath = resource_path('views/'.str_replace('.', '/', $layout).'.blade.php');
        $generatesLayout = (bool) config('anvil.web.generate_layout', true);

        $layoutState = match (true) {
            file_exists($layoutPath) => $layout.' (exists, left alone)',
            $generatesLayout => $layout.' (will be generated)',
            default => $layout.' — MISSING and generation disabled',
        };

        $rows = [
            ['Stack', $stack === 'livewire' ? 'Blade + Livewire 3' : 'Blade + Tailwind'],
            ['Controllers', (string) config('anvil.web.controller_namespace', 'App\\Http\\Controllers\\Web')],
            ['Routes', (string) config('anvil.web.route_file', 'routes/web.php')],
            ['Middleware', implode(', ', (array) config('anvil.web.middleware', ['web', 'auth']))],
            ['Layout', $layoutState],
            ['Navigation', config('anvil.web.generate_nav', true) ? 'generated (runtime-discovered links)' : 'skipped'],
            ['Assets', $this->describeAssetsMode($assetsMode)],
            ['Rows per page', $perPage.' (options: '.implode(', ', $this->perPageOptions($perPage)).')'],
            ['Models', $this->option('skip-models') ? 'assumed to exist' : 'generated'],
        ];

        if ($this->option('dry-run')) {
            $rows[] = ['Mode', 'dry run — no files will be written'];
        }

        $this->info('🌐 Anvil — Web Scaffold');
        $this->table(['', ''], $rows);

        // A custom layout that does not exist yet, with generation turned off,
        // produces views that render a missing view. Say so before writing them.
        if (! file_exists($layoutPath) && ! $generatesLayout) {
            $this->components->warn(sprintf(
                'The views will extend "%s", which does not exist at %s. Create it, drop --no-layout, or pass a '
                    .'different --layout.',
                $layout,
                str_replace(base_path().'/', '', $layoutPath),
            ));
        }

        if ($assetsMode === 'cdn') {
            $this->components->warn(
                'The Tailwind Play CDN compiles styles in the browser and is not for production. '
                    .'Run `php artisan anvil:frontend --install`, then regenerate with --assets-mode=vite.',
            );
        }

        if ($assetsMode === 'none') {
            $this->components->warn('--assets-mode=none: the layout will load no CSS. Generated views render unstyled.');
        }

        $this->newLine();
    }

    private function describeAssetsMode(string $assetsMode): string
    {
        return match ($assetsMode) {
            'vite' => 'Vite build (@vite directive in the layout)',
            'none' => 'none — you wire up your own CSS',
            default => 'Tailwind Play CDN (development only)',
        };
    }
}
