<?php

namespace Zuqongtech\LaravelAnvil\Console;

use Illuminate\Console\Command;
use Zuqongtech\LaravelAnvil\Console\Concerns\RunsGenerationPipeline;
use Zuqongtech\LaravelAnvil\Support\ConfigValidator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;

/**
 * Dedicated command for the web scaffold: resource controllers
 * (App\Http\Controllers\Web), Blade views (index/create/edit/show/_form + a
 * runtime-discovered navigation), and Route::resource entries in routes/web.php.
 *
 *   php artisan anvil:generate-web --tables=posts --tables=comments
 *   php artisan anvil:generate-web                 # every (non-ignored) table
 *   php artisan anvil:generate-web --skip-models   # assume models already exist
 *   php artisan anvil:generate-web --layout=layouts.app --force
 *
 * The web scaffold reuses the same Services and FormRequests as the API path,
 * so those are always generated alongside it. Models are generated too (so the
 * command is fully standalone) unless --skip-models is passed.
 *
 * It shares the entire generation pipeline with anvil:generate via
 * RunsGenerationPipeline — this is a separate command, not a separate engine.
 */
class GenerateWebCommand extends Command
{
    use RunsGenerationPipeline;

    protected $signature = 'anvil:generate-web
                            {--stack=blade   : Frontend stack — "blade" (Blade + Tailwind) or "livewire" (Blade + Livewire)}
                            {--tables=*      : Limit generation to specific tables}
                            {--only=*        : Alias for --tables}
                            {--ignore=*      : Exclude specific tables}
                            {--connection=   : Database connection to introspect}
                            {--schema=       : Schema(s) to generate from: name, csv list, or "all"}
                            {--namespace=App\\Models : Namespace of the models the scaffold references}
                            {--path=app      : Base path for generated models}
                            {--layout=       : Blade layout the views should @extends (overrides config anvil.web.layout)}
                            {--skip-models   : Do not (re)generate models; assume they already exist}
                            {--no-inverse    : Skip inverse relationship detection when models are generated}
                            {--force         : Overwrite existing files without prompting}
                            {--backup        : Backup existing files before overwriting}
                            {--dry-run       : Preview without writing files}';

    protected $description = 'Generate a web scaffold (resource controllers, Blade views and web routes) from the database';

    public function handle(): int
    {
        $this->info('🔧 Validating configuration...');
        $validator = new ConfigValidator;

        if (! $validator->validate()) {
            $this->displayValidationErrors($validator);

            return Command::FAILURE;
        }

        if ($validator->hasWarnings()) {
            $this->displayValidationWarnings($validator);
        }

        $this->info("✅ Configuration valid.\n");

        $stack = strtolower((string) $this->option('stack'));
        if (! in_array($stack, ['blade', 'livewire'], true)) {
            $this->error("Unknown --stack '{$stack}'. Use 'blade' or 'livewire'.");

            return Command::FAILURE;
        }

        // Allow overriding the layout the generated views extend, without
        // touching config/anvil.php.
        if ($layout = $this->option('layout')) {
            config(['anvil.web.layout' => $layout]);
        }

        $tables = array_merge(
            $this->option('tables') ?? [],
            $this->option('only') ?? [],
        );

        // Build a web-focused options object. web/form_requests/services are set
        // explicitly (the web scaffold depends on the latter two); models are on
        // unless skipped so the command produces a working CRUD from scratch.
        $options = GenerationOptions::fromArray([
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
            'ignore' => $this->option('ignore') ?? [],
        ]);

        $this->info('🌐 Anvil — Web Scaffold ('.($stack === 'livewire' ? 'Blade + Livewire' : 'Blade + Tailwind').')');
        $this->newLine();

        return $this->runPipeline($options);
    }
}
