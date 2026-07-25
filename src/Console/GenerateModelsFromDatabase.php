<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Console;

use Illuminate\Console\Command;
use Zuqongtech\LaravelAnvil\Console\Concerns\RunsGenerationPipeline;
use Zuqongtech\LaravelAnvil\Generators\ListenerGenerator;
use Zuqongtech\LaravelAnvil\Support\ConfigValidator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;

/**
 * Generate the core Laravel application scaffold from live database
 * introspection: models, controllers, resources, requests, services,
 * repositories, factories, seeders, migrations, events, listeners and tests.
 *
 * Two things this command no longer does:
 *
 *   • the versioned JSON API and the OpenAPI specification → anvil:api
 *     (aliased as anvil:openapi)
 *   • the web CRUD front end                               → anvil:generate-web
 *
 * All three commands run the identical pipeline via RunsGenerationPipeline.
 *
 * The --api / --openapi* flags remain declared below only as a deprecation
 * shim: they print a warning and forward to anvil:api. Delete the DEPRECATED
 * block and the two private methods at the bottom for a clean break in the next
 * major — but note that GenerationOptions::fromCommand() reads those option
 * names, so guard its lookups with $command->hasOption() first.
 */
class GenerateModelsFromDatabase extends Command
{
    use RunsGenerationPipeline;

    /**
     * Legacy options now owned by anvil:api. Presence of any of these triggers
     * the deprecation path.
     *
     * @var list<string>
     */
    private const DEPRECATED_API_OPTIONS = [
        'api',
        'openapi',
        'openapi-single-file',
        'openapi-ui',
    ];

    /** @var list<string> */
    private const LISTENER_STYLES = [
        ListenerGenerator::STYLE_PER_EVENT,
        ListenerGenerator::STYLE_SUBSCRIBER,
    ];

    protected $signature = 'anvil:generate
                            {--all                       : Generate every artifact type}
                            {--models                    : Eloquent models (always on)}
                            {--controllers               : Resource controllers}
                            {--resources                 : API resource classes}
                            {--observers                 : Model observers}
                            {--policies                  : Authorization policies}
                            {--form-requests             : StoreXxx / UpdateXxx form requests}
                            {--services                  : Service classes with lifecycle hooks}
                            {--repositories              : Repository interface + Eloquent implementation}
                            {--gates                     : Gate definitions appended to AuthServiceProvider}
                            {--api-routes                : Plain apiResource routes appended to routes/api.php (unversioned; see anvil:api for the versioned scaffold)}
                            {--factories                 : Model factories with Faker-inferred definitions}
                            {--seeders                   : Database seeders}
                            {--migrations                : Reverse-engineered Schema::create() migrations}
                            {--events                    : Created / Updated / Deleted event classes}
                            {--listeners                 : Handlers for the generated events (implies --events)}
                            {--listener-style=per-event  : One class per event, or one subscriber per model: per-event|subscriber}
                            {--queued-listeners          : Generated listeners implement ShouldQueue (per-event style only)}
                            {--tests                     : Feature test classes for all CRUD endpoints}
                            {--namespace=App\\Models     : Namespace for generated models}
                            {--connection=               : Database connection to introspect}
                            {--schema=                   : Schema(s) to generate from: name, csv list, or "all" (default: connection default)}
                            {--tables=*                  : Limit generation to specific tables}
                            {--ignore=*                  : Exclude specific tables}
                            {--only=*                    : Alias for --tables}
                            {--path=app                  : Base path for generated models}
                            {--force                     : Overwrite existing files without prompting}
                            {--backup                    : Backup existing files before overwriting}
                            {--dry-run                   : Preview without writing files}
                            {--with-phpdoc               : Add PHPDoc blocks to models}
                            {--with-inverse              : Generate inverse relationships}
                            {--with-constraints          : Embed constraint metadata in model comments}
                            {--validate-fk               : Validate all foreign key references}
                            {--analyze-constraints       : Show constraint summary before generation}
                            {--show-recommendations      : Show schema optimisation suggestions}
                            {--api                       : [DEPRECATED] Use anvil:api}
                            {--api-version=1             : [DEPRECATED] Use anvil:api --api-version}
                            {--openapi                   : [DEPRECATED] Use anvil:openapi}
                            {--openapi-format=yaml       : [DEPRECATED] Use anvil:openapi --format}
                            {--openapi-single-file       : [DEPRECATED] Use anvil:openapi --single-file}
                            {--openapi-ui                : [DEPRECATED] Use anvil:openapi --ui}';

    protected $description = 'Generate the core Laravel application scaffold from live database introspection';

    public function handle(): int
    {
        $style = strtolower((string) $this->option('listener-style'));

        if (! in_array($style, self::LISTENER_STYLES, true)) {
            $this->error(sprintf(
                'Unknown --listener-style "%s". Expected one of: %s.',
                $style,
                implode(', ', self::LISTENER_STYLES),
            ));

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

        $status = $this->runPipeline($this->buildOptions());

        if ($status === self::SUCCESS && $this->usesDeprecatedApiOptions()) {
            $status = $this->delegateToApiCommand();
        }

        return $status;
    }

    /**
     * Build the core pipeline options.
     *
     * The API and OpenAPI switches are forced off regardless of what
     * fromCommand() read, so the legacy flags cannot cause this pipeline to
     * generate API artifacts — that work is delegated to anvil:api afterwards,
     * where it runs with the full set of API-shaping options.
     */
    private function buildOptions(): GenerationOptions
    {
        $options = GenerationOptions::fromCommand($this);

        $listeners = $this->option('listeners') || $this->option('all');
        $style = strtolower((string) $this->option('listener-style'));
        $queued = (bool) $this->option('queued-listeners');

        // A listener whose handle() references a missing event class breaks
        // Laravel's listener discovery outright, so never generate one without
        // the event it handles.
        $events = ($options->events ?? false) || $listeners;

        if ($listeners && ! ($options->events ?? false)) {
            $this->line('  <fg=gray>--listeners implies --events; event classes will be generated too.</>');
        }

        if ($queued && $style === ListenerGenerator::STYLE_SUBSCRIBER) {
            $this->components->warn(
                '--queued-listeners is ignored with --listener-style=subscriber; a subscriber\'s methods are plain '
                    .'callbacks. Queue the work inside them, or switch to the per-event style.'
            );
        }

        $this->applyListenerConfig($listeners, $style, $queued);

        return GenerationOptions::fromArray(array_merge($options->toArray(), [
            'api' => false,
            'openapi' => false,
            'openapi_single_file' => false,
            'openapi_ui' => false,
            'events' => $events,
            'listeners' => $listeners,
            'listener_style' => $style,
            'queued_listeners' => $queued,
            'tables' => array_values(array_unique(array_merge(
                $options->tables,
                array_map(strval(...), $this->option('only')),
            ))),
        ]));
    }

    /**
     * Mirror the listener flags onto the runtime config so ListenerGenerator
     * picks them up before GenerationOptions declares the matching fields.
     * Both historical config roots are written, matching how the service
     * provider merges the file. Remove once the DTO carries these.
     */
    private function applyListenerConfig(bool $listeners, string $style, bool $queued): void
    {
        $values = [
            'events.listeners' => $listeners,
            'events.listener_style' => $style,
            'events.queued_listeners' => $queued,
        ];

        foreach ($values as $key => $value) {
            config(["anvil.{$key}" => $value, "laravel-anvil.{$key}" => $value]);
        }
    }

    private function usesDeprecatedApiOptions(): bool
    {
        foreach (self::DEPRECATED_API_OPTIONS as $option) {
            if ($this->option($option)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Translate the legacy flags and hand off to anvil:api.
     */
    private function delegateToApiCommand(): int
    {
        $this->newLine();
        $this->components->warn(
            'The --api / --openapi* flags on anvil:generate are deprecated and will be removed in the next major. '
                .'Use anvil:api (aliased anvil:openapi) instead — it also exposes --auth, --prefix, --throttle and --security.'
        );

        $wantsScaffold = (bool) $this->option('api');
        $wantsSpec = (bool) $this->option('openapi');

        $arguments = [
            '--api-version' => (string) $this->option('api-version'),
            '--format' => (string) $this->option('openapi-format'),
            '--namespace' => (string) $this->option('namespace'),
            '--path' => (string) $this->option('path'),
            '--tables' => array_map(strval(...), array_merge($this->option('tables'), $this->option('only'))),
            '--ignore' => array_map(strval(...), $this->option('ignore')),
        ];

        if ($connection = $this->option('connection')) {
            $arguments['--connection'] = (string) $connection;
        }

        if ($schema = $this->option('schema')) {
            $arguments['--schema'] = (string) $schema;
        }

        // Boolean flags must be omitted entirely rather than passed as false.
        $flags = [
            '--spec-only' => ! $wantsScaffold,
            '--no-spec' => ! $wantsSpec,
            '--single-file' => (bool) $this->option('openapi-single-file'),
            '--ui' => (bool) $this->option('openapi-ui'),
            '--force' => (bool) $this->option('force'),
            '--backup' => (bool) $this->option('backup'),
            '--dry-run' => (bool) $this->option('dry-run'),
        ];

        foreach (array_keys(array_filter($flags)) as $flag) {
            $arguments[$flag] = true;
        }

        return $this->call('anvil:api', $arguments);
    }
}
