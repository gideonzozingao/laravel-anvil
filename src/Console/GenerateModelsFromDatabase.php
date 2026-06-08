<?php

namespace Zuqongtech\LaravelAnvil\Console;

use Illuminate\Console\Command;
use Zuqongtech\LaravelAnvil\Console\Concerns\RunsGenerationPipeline;
use Zuqongtech\LaravelAnvil\Support\ConfigValidator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;

/**
 * Generate a complete Laravel application scaffold from live database
 * introspection (models, controllers, resources, requests, services, OpenAPI,
 * versioned API, …).
 *
 * The web scaffold (controllers + Blade views + web routes) is NOT handled here
 * — it has its own dedicated command, anvil:generate-web. Both commands share
 * the same underlying pipeline via the RunsGenerationPipeline trait.
 */
class GenerateModelsFromDatabase extends Command
{
    use RunsGenerationPipeline;

    protected $signature = 'anvil:generate
                            {--all                    : Generate every artifact type}
                            {--models                 : Eloquent models (always on)}
                            {--controllers            : Resource controllers}
                            {--resources              : API resource classes}
                            {--observers              : Model observers}
                            {--policies               : Authorization policies}
                            {--form-requests          : StoreXxx / UpdateXxx form requests}
                            {--services               : Service classes with lifecycle hooks}
                            {--repositories           : Repository interface + Eloquent implementation}
                            {--gates                  : Gate definitions appended to AuthServiceProvider}
                            {--api-routes             : apiResource routes appended to routes/api.php}
                            {--factories              : Model factories with Faker-inferred definitions}
                            {--seeders                : Database seeders}
                            {--migrations             : Reverse-engineered Schema::create() migrations}
                            {--events                 : Created / Updated / Deleted event classes}
                            {--tests                  : Feature test classes for all CRUD endpoints}
                            {--api                    : Generate a versioned JSON API scaffold with ForceJson enforcement}
                            {--api-version=1          : Version number for --api scaffold (e.g. 1, 2, v2)}
                            {--openapi                : Generate OpenAPI 3.1 specification}
                            {--openapi-format=yaml    : Output format: yaml (default) or json}
                            {--openapi-single-file    : Merge all schemas and paths into one file}
                            {--openapi-ui             : Publish Swagger UI to public/docs/}
                            {--namespace=App\\Models  : Namespace for generated models}
                            {--connection=            : Database connection to introspect}
                            {--tables=*               : Limit generation to specific tables}
                            {--ignore=*               : Exclude specific tables}
                            {--only=*                 : Alias for --tables}
                            {--path=app               : Base path for generated models}
                            {--force                  : Overwrite existing files without prompting}
                            {--backup                 : Backup existing files before overwriting}
                            {--dry-run                : Preview without writing files}
                            {--with-phpdoc            : Add PHPDoc blocks to models}
                            {--with-inverse           : Generate inverse relationships}
                            {--with-constraints       : Embed constraint metadata in model comments}
                            {--validate-fk            : Validate all foreign key references}
                            {--analyze-constraints    : Show constraint summary before generation}
                            {--show-recommendations   : Show schema optimisation suggestions}';

    protected $description = 'Generate a complete Laravel application scaffold from live database introspection';

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

        $options = GenerationOptions::fromCommand($this);

        if ($this->option('only')) {
            $options = GenerationOptions::fromArray(array_merge(
                $options->toArray(),
                ['tables' => array_merge($options->tables, $this->option('only'))],
            ));
        }

        // Warn about --api mutual exclusions
        if ($options->api && $options->controllers) {
            $this->warn('⚠️  --controllers is ignored when --api is set; versioned API controllers will be generated instead.');
        }

        return $this->runPipeline($options);
    }
}