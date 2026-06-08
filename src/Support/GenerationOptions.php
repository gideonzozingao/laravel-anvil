<?php

namespace Zuqongtech\LaravelAnvil\Support;

use Illuminate\Console\Command;

/**
 * Immutable DTO carrying all generation options through the pipeline.
 *
 * Versioned API scaffold (--api / --api-version):
 *   $api        — generate versioned JSON API scaffold
 *   $apiVersion — version number (default: 1 → V1)
 *
 * Web scaffold (--web):
 *   $web — generate resource controllers (App\Http\Controllers\Web), Blade
 *          views and web routes; implies form-requests + services.
 *
 * OpenAPI spec generation (--openapi / --openapi-format / ...):
 *   $openApi           — master switch, enables all OpenAPI generators
 *   $openApiFormat     — 'yaml' (default) | 'json'
 *   $openApiSingleFile — merge everything into one openapi.yaml
 *   $openApiUi         — publish Swagger UI to public/docs/
 */
final class GenerationOptions
{
    public function __construct(
        // ── Original artifact flags ──────────────────────────────────────────
        public bool $models = true,
        public bool $controllers = false,
        public bool $resources = false,
        public bool $observers = false,
        public bool $policies = false,

        // ── Scaffolding flags ────────────────────────────────────────────────
        public bool $formRequests = false,
        public bool $services = false,
        public bool $repositories = false,
        public bool $gates = false,
        public bool $apiRoutes = false,
        public bool $factories = false,
        public bool $seeders = false,
        public bool $migrations = false,
        public bool $events = false,
        public bool $tests = false,

        // ── Versioned API scaffold flags ─────────────────────────────────────
        public bool $api = false,
        public int $apiVersion = 1,

        // ── Web scaffold flag ────────────────────────────────────────────────
        public bool $web = false,

        // ── OpenAPI flags ────────────────────────────────────────────────────
        public bool $openApi = false,
        public string $openApiFormat = 'yaml',
        public bool $openApiSingleFile = false,
        public bool $openApiUi = false,

        // ── Behaviour flags ──────────────────────────────────────────────────
        public bool $force = false,
        public bool $dryRun = false,
        public bool $backup = false,
        public bool $withPhpDoc = true,
        public bool $withInverse = true,
        public bool $withConstraints = false,
        public bool $validateFk = false,
        public bool $analyzeConstraints = false,
        public bool $showRecommendations = false,

        // ── Routing / binding ────────────────────────────────────────────────
        public ?string $namespace = null,
        public ?string $path = null,
        public ?string $connection = null,
        public array $tables = [],
        public array $ignore = [],
    ) {}

    // -----------------------------------------------------------------------
    // Factories
    // -----------------------------------------------------------------------

    public static function fromCommand(Command $command): self
    {
        $all = (bool) ($command->option('all') ?? false);

        // Resolve api-version — accept "v1", "V1", or bare "1"
        $rawVersion = $command->option('api-version') ?? '1';
        $apiVersion = (int) ltrim(strtolower((string) $rawVersion), 'v');
        if ($apiVersion < 1) {
            $apiVersion = 1;
        }

        $api = (bool) ($command->option('api') ?? false);
        $web = $command->hasOption('web') ? (bool) $command->option('web') : false;

        // --openapi-format default: yaml
        $format = $command->option('openapi-format') ?? 'yaml';
        if (! in_array($format, ['yaml', 'json'], true)) {
            $format = 'yaml';
        }

        return new self(
            // Original
            models: true,
            controllers: ! $api && ($all || (bool) ($command->option('controllers') ?? false)),
            resources: $all || (bool) ($command->option('resources') ?? false),
            observers: $all || (bool) ($command->option('observers') ?? false),
            policies: $all || (bool) ($command->option('policies') ?? false),

            // Scaffolding — the web scaffold reuses the same FormRequests and
            // Services as the API scaffold, so --web implies both.
            formRequests: $all || $api || $web || (bool) ($command->option('form-requests') ?? false),
            services: $all || $api || $web || (bool) ($command->option('services') ?? false),
            repositories: $all || (bool) ($command->option('repositories') ?? false),
            gates: $all || (bool) ($command->option('gates') ?? false),
            apiRoutes: $all || $api || (bool) ($command->option('api-routes') ?? false),
            factories: $all || (bool) ($command->option('factories') ?? false),
            seeders: $all || (bool) ($command->option('seeders') ?? false),
            migrations: $all || (bool) ($command->option('migrations') ?? false),
            events: $all || (bool) ($command->option('events') ?? false),
            tests: $all || $api || (bool) ($command->option('tests') ?? false),

            // Versioned API
            api: $api,
            apiVersion: $apiVersion,

            // Web
            web: $web,

            // OpenAPI
            openApi: $all || (bool) ($command->option('openapi') ?? false),
            openApiFormat: $format,
            openApiSingleFile: (bool) ($command->option('openapi-single-file') ?? false),
            openApiUi: (bool) ($command->option('openapi-ui') ?? false),

            // Behaviour
            force: (bool) ($command->option('force') ?? false),
            dryRun: (bool) ($command->option('dry-run') ?? false),
            backup: (bool) ($command->option('backup') ?? false),
            withPhpDoc: (bool) ($command->option('with-phpdoc') ?? true),
            withInverse: (bool) ($command->option('with-inverse') ?? true),
            withConstraints: (bool) ($command->option('with-constraints') ?? false),
            validateFk: (bool) ($command->option('validate-fk') ?? false),
            analyzeConstraints: (bool) ($command->option('analyze-constraints') ?? false),
            showRecommendations: (bool) ($command->option('show-recommendations') ?? false),

            // Routing
            namespace: $command->option('namespace'),
            path: $command->option('path'),
            connection: $command->option('connection'),
            tables: $command->option('tables') ?? [],
            ignore: $command->option('ignore') ?? [],
        );
    }

    public static function withDefaults(): self
    {
        return new self(
            models: true,
            force: config('laravel-anvil.force_overwrite', false),
            dryRun: config('laravel-anvil.dry_run', false),
            backup: config('laravel-anvil.backup_existing', false),
            withPhpDoc: config('laravel-anvil.with_phpdoc', true),
            withInverse: config('laravel-anvil.with_inverse', true),
            validateFk: config('laravel-anvil.relationships.validate_foreign_keys', false),
            namespace: config('laravel-anvil.namespace', 'App\\Models'),
            path: config('laravel-anvil.target_path', 'app'),
            connection: config('laravel-anvil.connection'),
            ignore: config('laravel-anvil.ignore_tables', []),
        );
    }

    public static function fromArray(array $options): self
    {
        return new self(
            models: $options['models'] ?? true,
            controllers: $options['controllers'] ?? false,
            resources: $options['resources'] ?? false,
            observers: $options['observers'] ?? false,
            policies: $options['policies'] ?? false,
            formRequests: $options['form_requests'] ?? false,
            services: $options['services'] ?? false,
            repositories: $options['repositories'] ?? false,
            gates: $options['gates'] ?? false,
            apiRoutes: $options['api_routes'] ?? false,
            factories: $options['factories'] ?? false,
            seeders: $options['seeders'] ?? false,
            migrations: $options['migrations'] ?? false,
            events: $options['events'] ?? false,
            tests: $options['tests'] ?? false,
            api: $options['api'] ?? false,
            apiVersion: (int) ($options['api_version'] ?? 1),
            web: $options['web'] ?? false,
            openApi: $options['open_api'] ?? false,
            openApiFormat: $options['open_api_format'] ?? 'yaml',
            openApiSingleFile: $options['open_api_single_file'] ?? false,
            openApiUi: $options['open_api_ui'] ?? false,
            force: $options['force'] ?? false,
            dryRun: $options['dry_run'] ?? false,
            backup: $options['backup'] ?? false,
            withPhpDoc: $options['with_phpdoc'] ?? true,
            withInverse: $options['with_inverse'] ?? true,
            withConstraints: $options['with_constraints'] ?? false,
            validateFk: $options['validate_fk'] ?? false,
            analyzeConstraints: $options['analyze_constraints'] ?? false,
            showRecommendations: $options['show_recommendations'] ?? false,
            namespace: $options['namespace'] ?? null,
            path: $options['path'] ?? null,
            connection: $options['connection'] ?? null,
            tables: $options['tables'] ?? [],
            ignore: $options['ignore'] ?? [],
        );
    }

    // -----------------------------------------------------------------------
    // Derived helpers
    // -----------------------------------------------------------------------

    public function getNamespace(): string
    {
        return $this->namespace ?? config('laravel-anvil.namespace', 'App\\Models');
    }

    public function getPath(): string
    {
        return $this->path ?? config('laravel-anvil.target_path', 'app');
    }

    public function getConnection(): string
    {
        return $this->connection
            ?? config('laravel-anvil.connection')
            ?? config('database.default');
    }

    /**
     * Return the versioned string, e.g. "V1", "V2".
     */
    public function getApiVersionString(): string
    {
        return 'V'.$this->apiVersion;
    }

    /**
     * Return the lower-case version prefix used in route files, e.g. "v1".
     */
    public function getApiVersionSlug(): string
    {
        return 'v'.$this->apiVersion;
    }

    /**
     * Namespace segment for versioned API controllers,
     * e.g. "App\Http\Controllers\Api\V1".
     */
    public function getApiControllerNamespace(): string
    {
        return 'App\\Http\\Controllers\\Api\\'.$this->getApiVersionString();
    }

    /**
     * Namespace for web scaffold controllers, e.g. "App\Http\Controllers\Web".
     */
    public function getWebControllerNamespace(): string
    {
        return config('anvil.web.controller_namespace', 'App\\Http\\Controllers\\Web');
    }

    public function hasSpecificTables(): bool
    {
        return ! empty($this->tables);
    }

    public function hasIgnoredTables(): bool
    {
        return ! empty($this->ignore);
    }

    public function getAllIgnoredTables(): array
    {
        return array_merge(
            config('laravel-anvil.ignore_tables', []),
            $this->ignore,
        );
    }

    public function hasAnyArtifacts(): bool
    {
        return $this->models || $this->controllers || $this->resources
            || $this->observers || $this->policies || $this->formRequests
            || $this->services || $this->repositories || $this->gates
            || $this->apiRoutes || $this->factories || $this->seeders
            || $this->migrations || $this->events || $this->tests
            || $this->api || $this->web || $this->openApi;
    }

    public function getEnabledGenerators(): array
    {
        $map = [
            'Models' => $this->models,
            'Controllers' => $this->controllers && ! $this->api,
            'ApiScaffold' => $this->api,
            'WebScaffold' => $this->web,
            'Resources' => $this->resources,
            'Observers' => $this->observers,
            'Policies' => $this->policies,
            'FormRequests' => $this->formRequests,
            'Services' => $this->services,
            'Repositories' => $this->repositories,
            'Gates' => $this->gates,
            'ApiRoutes' => $this->apiRoutes && ! $this->api,
            'Factories' => $this->factories,
            'Seeders' => $this->seeders,
            'Migrations' => $this->migrations,
            'Events' => $this->events,
            'Tests' => $this->tests,
            'OpenAPI' => $this->openApi,
        ];

        return array_keys(array_filter($map));
    }

    public function toArray(): array
    {
        return [
            'models' => $this->models,
            'controllers' => $this->controllers,
            'resources' => $this->resources,
            'observers' => $this->observers,
            'policies' => $this->policies,
            'form_requests' => $this->formRequests,
            'services' => $this->services,
            'repositories' => $this->repositories,
            'gates' => $this->gates,
            'api_routes' => $this->apiRoutes,
            'factories' => $this->factories,
            'seeders' => $this->seeders,
            'migrations' => $this->migrations,
            'events' => $this->events,
            'tests' => $this->tests,
            'api' => $this->api,
            'api_version' => $this->apiVersion,
            'web' => $this->web,
            'open_api' => $this->openApi,
            'open_api_format' => $this->openApiFormat,
            'open_api_single_file' => $this->openApiSingleFile,
            'open_api_ui' => $this->openApiUi,
            'force' => $this->force,
            'dry_run' => $this->dryRun,
            'backup' => $this->backup,
            'with_phpdoc' => $this->withPhpDoc,
            'with_inverse' => $this->withInverse,
            'with_constraints' => $this->withConstraints,
            'validate_fk' => $this->validateFk,
            'analyze_constraints' => $this->analyzeConstraints,
            'show_recommendations' => $this->showRecommendations,
            'namespace' => $this->namespace,
            'path' => $this->path,
            'connection' => $this->connection,
            'tables' => $this->tables,
            'ignore' => $this->ignore,
        ];
    }

    public function getSummary(): string
    {
        $parts = [];
        $gens = $this->getEnabledGenerators();

        if (! empty($gens)) {
            $parts[] = 'Generators: '.implode(', ', $gens);
        }
        if ($this->api) {
            $parts[] = 'API version: '.$this->getApiVersionString();
        }
        if ($this->web) {
            $parts[] = 'Web scaffold';
        }
        if ($this->openApi) {
            $parts[] = 'OpenAPI format: '.strtoupper($this->openApiFormat);
            $parts[] = $this->openApiSingleFile ? 'Single-file spec' : 'Split-file spec';
        }
        if ($this->force) {
            $parts[] = 'Force overwrite';
        }
        if ($this->dryRun) {
            $parts[] = 'Dry run';
        }
        if ($this->backup) {
            $parts[] = 'Backup enabled';
        }
        if (! empty($this->tables)) {
            $parts[] = 'Tables: '.implode(', ', $this->tables);
        }

        return implode(' | ', $parts);
    }

    public function __toString(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }
}