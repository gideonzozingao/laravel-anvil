<?php

namespace Zuqongtech\LaravelAnvil\Support;

use Illuminate\Console\Command;

/**
 * Data Transfer Object for generation options.
 *
 * Encapsulates all CLI flags and config values into a single type-safe object
 * that is passed through the entire generation pipeline.
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

        // ── New artifact flags ───────────────────────────────────────────────
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
        // --api generates a fully versioned API scaffold:
        //   - API controller in App\Http\Controllers\Api\V{n}\
        //   - Versioned api.php routes (or routes/api/v{n}.php)
        //   - ForceJsonServiceProvider registered in bootstrap/app.php
        // --api-version controls the version number (default: 1 → V1)
        public bool $api = false,
        public int $apiVersion = 1,
        public bool $openApi = false,

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
    // Factory: CLI command
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

        return new self(
            // Original
            models: true,
            controllers: ! $api && ($all || (bool) ($command->option('controllers') ?? false)),
            resources: $all || (bool) ($command->option('resources') ?? false),
            observers: $all || (bool) ($command->option('observers') ?? false),
            policies: $all || (bool) ($command->option('policies') ?? false),

            // New
            formRequests: $all || $api || (bool) ($command->option('form-requests') ?? false),
            services: $all || $api || (bool) ($command->option('services') ?? false),
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
            openApi: $command->option('openapi') || $command->option('all'),

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

    // -----------------------------------------------------------------------
    // Factory: config defaults
    // -----------------------------------------------------------------------

    public static function withDefaults(): self
    {
        return new self(
            models: true,
            force: config('anvil.force_overwrite', false),
            dryRun: config('anvil.dry_run', false),
            backup: config('anvil.backup_existing', false),
            withPhpDoc: config('anvil.with_phpdoc', true),
            withInverse: config('anvil.with_inverse', true),
            validateFk: config('anvil.relationships.validate_foreign_keys', false),
            namespace: config('anvil.namespace', 'App\\Models'),
            path: config('anvil.target_path', 'app'),
            connection: config('anvil.connection'),
            ignore: config('anvil.ignore_tables', []),
        );
    }

    // -----------------------------------------------------------------------
    // Factory: array
    // -----------------------------------------------------------------------

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
        return $this->namespace ?? config('anvil.namespace', 'App\\Models');
    }

    public function getPath(): string
    {
        return $this->path ?? config('anvil.target_path', 'app');
    }

    public function getConnection(): string
    {
        return $this->connection
            ?? config('anvil.connection')
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
            config('anvil.ignore_tables', []),
            $this->ignore,
        );
    }

    public function hasAnyArtifacts(): bool
{
    return $this->models
        || $this->controllers || $this->resources
        || $this->observers || $this->policies
        || $this->formRequests || $this->services
        || $this->repositories || $this->gates
        || $this->apiRoutes || $this->factories
        || $this->seeders || $this->migrations
        || $this->events || $this->tests
        || $this->api || $this->openApi;  // ← added openApi
}

    public function getEnabledGenerators(): array
    {
        $map = [
    'Models'       => $this->models,
    'Controllers'  => $this->controllers && ! $this->api,
    'ApiScaffold'  => $this->api,
    'Resources'    => $this->resources,
    'Observers'    => $this->observers,
    'Policies'     => $this->policies,
    'FormRequests' => $this->formRequests,
    'Services'     => $this->services,
    'Repositories' => $this->repositories,
    'Gates'        => $this->gates,
    'ApiRoutes'    => $this->apiRoutes && ! $this->api,
    'Factories'    => $this->factories,
    'Seeders'      => $this->seeders,
    'Migrations'   => $this->migrations,
    'Events'       => $this->events,
    'Tests'        => $this->tests,
    'OpenAPI'      => $this->openApi,   // ← added
];

        return array_keys(array_filter($map));
    }

    public function isModelsOnly(): bool
    {
        return $this->models && array_filter(
            $this->toArray(),
            fn ($v, $k) => $v === true && $k !== 'models' && str_ends_with($k, 's'),
            ARRAY_FILTER_USE_BOTH
        ) === [];
    }

    // -----------------------------------------------------------------------
    // Serialisation
    // -----------------------------------------------------------------------

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
            'open_api'             => $this->openApi,
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
         if ($this->openApi) {
            $parts[] = 'OpenAPI docs';
        }
        if ($this->force) {
            $parts[] = 'Force overwrite';
        }
        if ($this->dryRun) {
            $parts[] = 'Dry run mode';
        }
        if ($this->backup) {
            $parts[] = 'Backup enabled';
        }
        if ($this->hasSpecificTables()) {
            $parts[] = 'Tables: '.implode(', ', $this->tables);
        }
       
        return implode(' | ', $parts);
    }

    public function __toString(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }
}
