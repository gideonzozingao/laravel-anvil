<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Console;

use Illuminate\Console\Command;
use Livewire\Component;
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
 *   php artisan anvil:generate-web --stack=livewire
 *   php artisan anvil:generate-web --layout=layouts.app --no-layout --force
 *   php artisan anvil:generate-web --per-page=25 --skip-models
 *
 * The web scaffold reuses the same Services and FormRequests as the API path, so
 * those are generated alongside it. Models are generated too unless
 * --skip-models is passed, which keeps the command standalone.
 *
 * It shares the entire generation pipeline with anvil:generate via
 * RunsGenerationPipeline — a separate command, not a separate engine.
 */
class GenerateWebCommand extends Command
{
    use RunsGenerationPipeline;

    /** @var list<string> */
    private const STACKS = ['blade', 'livewire'];

    /** @var list<int> */
    private const PER_PAGE_OPTIONS = [10, 15, 25, 50, 100];

    protected $signature = 'anvil:generate-web
                            {--stack=blade   : Frontend stack — "blade" (Blade + Tailwind) or "livewire" (Blade + Livewire)}
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
                            {--skip-models   : Do not (re)generate models; assume they already exist}
                            {--no-inverse    : Skip inverse relationship detection when models are generated}
                            {--force         : Overwrite existing files without prompting}
                            {--backup        : Backup existing files before overwriting}
                            {--dry-run       : Preview without writing files}';

    protected $description = 'Generate a web scaffold (resource controllers, Blade views and web routes) from the database';

    public function handle(): int
    {
        $stack = strtolower((string) $this->option('stack'));

        if (! in_array($stack, self::STACKS, true)) {
            $this->error(sprintf('Unknown --stack "%s". Use one of: %s.', $stack, implode(', ', self::STACKS)));

            return self::FAILURE;
        }

        $perPage = (string) $this->option('per-page');

        if (! ctype_digit($perPage) || (int) $perPage < 1) {
            $this->error('--per-page must be a positive integer.');

            return self::FAILURE;
        }

        // Livewire 3 is a hard requirement for that stack; failing here beats
        // generating components that cannot be resolved.
        if ($stack === 'livewire' && ! class_exists(Component::class)) {
            $this->error('The livewire stack requires livewire/livewire. Install it with: composer require livewire/livewire');

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

        $this->applyRuntimeConfig((int) $perPage);
        $this->summarise($stack, (int) $perPage);

        return $this->runPipeline($this->buildOptions($stack));
    }

    /**
     * Push the view-shaping options into config, where ViewGenerator,
     * WebControllerGenerator and LivewireComponentGenerator read them.
     */
    private function applyRuntimeConfig(int $perPage): void
    {
        $values = [
            'web.per_page' => $perPage,
            'web.per_page_options' => self::PER_PAGE_OPTIONS,
        ];

        if ($layout = $this->option('layout')) {
            $values['web.layout'] = (string) $layout;
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

    private function buildOptions(string $stack): GenerationOptions
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

    private function summarise(string $stack, int $perPage): void
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
            ['Rows per page', $perPage.' (options: '.implode(', ', self::PER_PAGE_OPTIONS).')'],
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

        $this->newLine();
    }
}
