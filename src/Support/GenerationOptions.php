<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

use Illuminate\Console\Command;
use RuntimeException;

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
 *   $openApiUi         — publish a static Swagger UI
 *
 * A NOTE ON CONFIG READS
 *
 * Every config lookup here goes through cfg(), not config(). Laravel's
 * config($key, $default) only returns $default when the key is ABSENT — a key
 * present with a null value returns null, and the default is never consulted.
 * config/anvil.php is full of such keys:
 *
 *     'connection' => env('DB_INTROSPECTION_CONNECTION', null),
 *
 * which made getConnection(): string return null and throw a TypeError.
 */
final class GenerationOptions implements \Stringable
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
        public string $stack = 'blade',

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
        public array $schemas = [],

        // ── Appended fields ──────────────────────────────────────────────────
        // Added at the end so any positional construction keeps working.
        public bool $listeners = false,
        public string $listenerStyle = 'per-event',
        public bool $queuedListeners = false,
        public bool $skipModels = false,
    ) {}

    // -----------------------------------------------------------------------
    // Factories
    // -----------------------------------------------------------------------

    public static function fromCommand(Command $command): self
    {
        $all = (bool) self::opt($command, 'all');

        // Resolve api-version — accept "v1", "V1", or bare "1"
        $rawVersion = self::opt($command, 'api-version', '1');
        $apiVersion = max(1, (int) ltrim(strtolower((string) $rawVersion), 'v'));

        // hasOption() guards throughout: these flags are being removed from
        // anvil:generate, and an undeclared option makes option() throw.
        $api = (bool) self::opt($command, 'api');
        $web = (bool) self::opt($command, 'web');

        $format = (string) self::opt($command, 'openapi-format', 'yaml');

        if (! in_array($format, ['yaml', 'json'], true)) {
            $format = 'yaml';
        }

        $listeners = (bool) self::opt($command, 'listeners') || $all;
        $skipModels = (bool) self::opt($command, 'skip-models');

        return new self(
            models: ! $skipModels,
            controllers: ! $api && ($all || (bool) self::opt($command, 'controllers')),
            resources: $all || (bool) self::opt($command, 'resources'),
            observers: $all || (bool) self::opt($command, 'observers'),
            policies: $all || (bool) self::opt($command, 'policies'),

            // The web scaffold reuses the same FormRequests and Services as the
            // API scaffold, so --web implies both.
            formRequests: $all || $api || $web || (bool) self::opt($command, 'form-requests'),
            services: $all || $api || $web || (bool) self::opt($command, 'services'),
            repositories: $all || (bool) self::opt($command, 'repositories'),
            gates: $all || (bool) self::opt($command, 'gates'),
            apiRoutes: $all || $api || (bool) self::opt($command, 'api-routes'),
            factories: $all || (bool) self::opt($command, 'factories'),
            seeders: $all || (bool) self::opt($command, 'seeders'),
            migrations: $all || (bool) self::opt($command, 'migrations'),
            // A listener without its event class breaks Laravel's listener
            // discovery, so --listeners implies --events.
            events: $all || $listeners || (bool) self::opt($command, 'events'),
            tests: $all || $api || (bool) self::opt($command, 'tests'),

            api: $api,
            apiVersion: $apiVersion,

            web: $web,
            stack: (string) (self::opt($command, 'stack') ?: 'blade'),

            openApi: $all || (bool) self::opt($command, 'openapi'),
            openApiFormat: $format,
            openApiSingleFile: (bool) self::opt($command, 'openapi-single-file'),
            openApiUi: (bool) self::opt($command, 'openapi-ui'),

            force: (bool) self::opt($command, 'force'),
            dryRun: (bool) self::opt($command, 'dry-run'),
            backup: (bool) self::opt($command, 'backup'),
            withPhpDoc: (bool) self::opt($command, 'with-phpdoc', true),
            withInverse: (bool) self::opt($command, 'with-inverse', true),
            withConstraints: (bool) self::opt($command, 'with-constraints'),
            validateFk: (bool) self::opt($command, 'validate-fk'),
            analyzeConstraints: (bool) self::opt($command, 'analyze-constraints'),
            showRecommendations: (bool) self::opt($command, 'show-recommendations'),

            namespace: self::stringOrNull(self::opt($command, 'namespace')),
            path: self::stringOrNull(self::opt($command, 'path')),
            connection: self::stringOrNull(self::opt($command, 'connection')),
            tables: (array) (self::opt($command, 'tables') ?? []),
            ignore: (array) (self::opt($command, 'ignore') ?? []),
            schemas: self::normalizeSchemas(self::opt($command, 'schema')),

            listeners: $listeners,
            listenerStyle: (string) (self::opt($command, 'listener-style') ?: 'per-event'),
            queuedListeners: (bool) self::opt($command, 'queued-listeners'),
            skipModels: $skipModels,
        );
    }

    public static function withDefaults(): self
    {
        return new self(
            models: true,
            force: (bool) self::cfg('force_overwrite', false),
            dryRun: (bool) self::cfg('dry_run', false),
            backup: (bool) self::cfg('backup_existing', false),
            withPhpDoc: (bool) self::cfg('with_phpdoc', true),
            withInverse: (bool) self::cfg('with_inverse', true),
            validateFk: (bool) self::cfg('relationships.validate_foreign_keys', false),
            namespace: (string) self::cfg('namespace', 'App\\Models'),
            path: (string) self::cfg('target_path', 'app'),
            connection: self::stringOrNull(self::cfg('connection')),
            ignore: (array) self::cfg('ignore_tables', []),
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
            apiVersion: max(1, (int) ($options['api_version'] ?? 1)),
            web: $options['web'] ?? false,
            stack: $options['stack'] ?? 'blade',
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
            namespace: self::stringOrNull($options['namespace'] ?? null),
            path: self::stringOrNull($options['path'] ?? null),
            connection: self::stringOrNull($options['connection'] ?? null),
            tables: $options['tables'] ?? [],
            ignore: $options['ignore'] ?? [],
            schemas: self::normalizeSchemas($options['schemas'] ?? []),
            listeners: $options['listeners'] ?? false,
            listenerStyle: $options['listener_style'] ?? 'per-event',
            queuedListeners: $options['queued_listeners'] ?? false,
            skipModels: $options['skip_models'] ?? false,
        );
    }

    // -----------------------------------------------------------------------
    // Derived helpers
    // -----------------------------------------------------------------------

    public function getNamespace(): string
    {
        return $this->namespace ?: (string) self::cfg('namespace', 'App\\Models');
    }

    public function getPath(): string
    {
        return $this->path ?: (string) self::cfg('target_path', 'app');
    }

    /**
     * The connection to introspect.
     *
     * Resolution order: explicit flag, anvil config, Laravel's default. Each step
     * treats null and '' as "not set", which config($key, $default) does not:
     * 'connection' => env(..., null) is a key that EXISTS with a null value, so
     * the default was never applied and this method returned null against a
     * `: string` signature.
     */
    public function getConnection(): string
    {
        $connection = $this->connection
            ?: self::cfg('connection')
            ?: config('database.default');

        if (! is_string($connection) || $connection === '') {
            throw new RuntimeException(
                'No database connection could be resolved. Set database.default in config/database.php, '
                    .'anvil.connection in config/anvil.php, or pass --connection=name.'
            );
        }

        return $connection;
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
        $base = trim((string) self::cfg('api.defaults.namespaces.controllers', 'App\\Http\\Controllers\\Api'), '\\');

        return $base.'\\'.$this->getApiVersionString();
    }

    /**
     * Namespace for web scaffold controllers, e.g. "App\Http\Controllers\Web".
     */
    public function getWebControllerNamespace(): string
    {
        return (string) self::cfg('web.controller_namespace', 'App\\Http\\Controllers\\Web');
    }

    /**
     * Frontend stack for the web scaffold: 'blade' (default) or 'livewire'.
     */
    public function isLivewire(): bool
    {
        return strtolower($this->stack) === 'livewire';
    }

    /**
     * Namespace for generated Livewire components, e.g. "App\Livewire".
     */
    public function getLivewireNamespace(): string
    {
        return (string) self::cfg('web.livewire.namespace', 'App\\Livewire');
    }

    public function hasSpecificTables(): bool
    {
        return $this->tables !== [];
    }

    /**
     * The schema selection for this run as a clean list.
     * Empty means "use the connection's default schema".
     *
     * @return list<string>
     */
    public function getSchemaSelection(): array
    {
        return $this->schemas;
    }

    /** True when the user explicitly asked for one or more schemas (or 'all'). */
    public function hasSchemaSelection(): bool
    {
        return $this->schemas !== [];
    }

    /** True when generation should span more than one schema (or all of them). */
    public function isMultiSchema(): bool
    {
        return in_array('all', $this->schemas, true)
            || in_array('*', $this->schemas, true)
            || count($this->schemas) > 1;
    }

    /**
     * Normalize a --schema value (csv string, array, or null) into a list.
     *
     * @param  string|array<int, string>|null  $value
     * @return list<string>
     */
    protected static function normalizeSchemas(string|array|null $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return array_values(array_filter(
            array_map(static fn ($s): string => trim((string) $s), $value),
            static fn (string $s): bool => $s !== '',
        ));
    }

    public function hasIgnoredTables(): bool
    {
        return $this->ignore !== [];
    }

    /**
     * @return list<string>
     */
    public function getAllIgnoredTables(): array
    {
        // (array) cast: ignore_tables set to null in a published config would
        // otherwise make array_merge() throw.
        return array_values(array_unique(array_merge(
            (array) self::cfg('ignore_tables', []),
            $this->ignore,
        )));
    }

    public function hasAnyArtifacts(): bool
    {
        return $this->models || $this->controllers || $this->resources
            || $this->observers || $this->policies || $this->formRequests
            || $this->services || $this->repositories || $this->gates
            || $this->apiRoutes || $this->factories || $this->seeders
            || $this->migrations || $this->events || $this->listeners
            || $this->tests || $this->api || $this->web || $this->openApi;
    }

    /**
     * @return list<string>
     */
    public function getEnabledGenerators(): array
    {
        $map = [
            'Models' => $this->models && ! $this->skipModels,
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
            'Listeners' => $this->listeners,
            'Tests' => $this->tests,
            'OpenAPI' => $this->openApi,
        ];

        return array_keys(array_filter($map));
    }

    /**
     * @return array<string, mixed>
     */
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
            'stack' => $this->stack,
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
            'schemas' => $this->schemas,
            'listeners' => $this->listeners,
            'listener_style' => $this->listenerStyle,
            'queued_listeners' => $this->queuedListeners,
            'skip_models' => $this->skipModels,
        ];
    }

    public function getSummary(): string
    {
        $parts = [];
        $gens = $this->getEnabledGenerators();

        if ($gens !== []) {
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
        if ($this->listeners) {
            $parts[] = 'Listeners: '.$this->listenerStyle.($this->queuedListeners ? ' (queued)' : '');
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
        if ($this->tables !== []) {
            $parts[] = 'Tables: '.implode(', ', $this->tables);
        }

        return implode(' | ', $parts);
    }

    public function __toString(): string
    {
        return (string) json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Read an option that may not be declared on the calling command.
     *
     * Commands are being split apart, so a given flag exists on some of them and
     * not others; option() on an undeclared name throws InvalidArgumentException.
     */
    private static function opt(Command $command, string $name, mixed $default = null): mixed
    {
        if (! $command->hasOption($name)) {
            return $default;
        }

        $value = $command->option($name);

        return $value === null ? $default : $value;
    }

    /**
     * Config read that treats null and '' as "not set", unlike config(), whose
     * default only applies when the key is entirely absent.
     *
     * Both historical roots are consulted: the provider merges the same file
     * under anvil.* and laravel-anvil.*, and different parts of the package have
     * historically read one or the other.
     */
    private static function cfg(string $key, mixed $default = null): mixed
    {
        foreach (["anvil.{$key}", "laravel-anvil.{$key}"] as $candidate) {
            $value = config($candidate);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $default;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
