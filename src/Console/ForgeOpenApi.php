<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Console;

use Illuminate\Console\Command;
use Zuqongtech\LaravelAnvil\Console\Concerns\ConfiguresGeneratedCache;
use Zuqongtech\LaravelAnvil\Console\Concerns\RunsGenerationPipeline;
use Zuqongtech\LaravelAnvil\Support\ApiVersionProfile;
use Zuqongtech\LaravelAnvil\Support\ConfigValidator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\KeyCase;
use Zuqongtech\LaravelAnvil\Support\OpenApiLocator;

/**
 * Generates the versioned JSON API scaffold and the OpenAPI 3.1 specification.
 *
 *   php artisan anvil:generate-api                             # scaffold + spec
 *   php artisan anvil:generate-api --no-spec                   # scaffold only
 *   php artisan anvil:generate-openapi --spec-only             # spec only
 *   php artisan anvil:generate-api --api-version=2 --case=camel --pagination=25
 *   php artisan anvil:generate-api --auth=passport --throttle=120,1 --ui
 *
 * Everything the version needs is produced under a version-scoped namespace:
 *
 *   App\Http\Controllers\Api\V2\{Model}Controller
 *   App\Http\Requests\Api\V2\{Model}\{Index,Store,Update}Request
 *   App\Http\Resources\Api\V2\{Model}Resource + {Model}Collection
 *   openapi/v2/openapi.yaml (+ schemas/, paths/)
 *
 * Services are deliberately NOT versioned by default — business logic belongs in
 * one place. --versioned-services emits a thin subclass instead of a copy.
 */

class ForgeOpenApi extends Command
{
    use ConfiguresGeneratedCache;
    use RunsGenerationPipeline;



    protected $aliases = 'anvil:forge-openapi';
    protected $description = 'Generate a versioned JSON API scaffold and an OpenAPI 3.1 specification from live database introspection';
    protected $signature = 'anvil:forge-api
                            {--api-version=1         : Version of the API scaffold (1, 2, v2 …)}
                            {--prefix=api            : Route prefix for the versioned group}
                            {--auth=sanctum          : Auth scheme: sanctum|passport|jwt|token|none}
                            {--guard=                : Explicit guard name (defaults to the guard implied by --auth)}
                            {--middleware=*          : Additional middleware for the API route group}
                            {--throttle=60,1         : Rate limiter for the API route group; "none" to disable}
                            {--case=                 : Payload key casing both directions: snake|camel|studly|kebab|none}
                            {--request-case=         : Inbound casing only (overrides --case)}
                            {--response-case=        : Outbound casing only (overrides --case)}
                            {--pagination=15         : Default page size for index endpoints}
                            {--pagination-max=100    : Maximum page size a client may request}
                            {--pagination-param=     : Page-size query parameter (default: per_page, cased)}
                            {--hidden=*              : Columns to omit from every response (repeatable)}
                            {--flat-requests         : Do not group request classes in per-model subdirectories}
                            {--versioned-services    : Generate a per-version service subclass (default: share one service)}
                            {--no-force-json         : Do not generate the ForceJsonResponse middleware + provider}
                            {--no-resources          : Do not generate API resource classes}
                            {--no-tests              : Do not generate feature tests}
                            {--no-spec               : Skip the OpenAPI specification}
                            {--spec-only             : Only generate the specification (no API scaffold)}
                            {--format=yaml           : Specification format: yaml or json}
                            {--single-file           : Merge all schemas and paths into one document}
                            {--output=openapi        : Root directory the specification is written to}
                            {--flat                  : Write to the output root instead of a per-version subdirectory}
                            {--ui                    : Publish a static Swagger UI for this version}
                            {--ui-version=5.17.14    : Swagger UI version to publish}
                            {--route=docs            : Route the interactive docs are served from}
                            {--security=             : OpenAPI security scheme (defaults from --auth)}
                            {--server=*              : Server URL for the spec "servers" block}
                            {--title=                : Specification title (defaults to the app name)}
                            {--description=          : Specification description}
                            {--namespace=App\\Models : Namespace of the models the scaffold references}
                            {--connection=           : Database connection to introspect}
                            {--schema=               : Schema(s) to introspect: name, csv list, or "all"}
                            {--tables=*              : Limit generation to specific tables}
                            {--ignore=*              : Exclude specific tables}
                            {--only=*                : Alias for --tables}
                            {--path=app              : Base path used to resolve the referenced models}
                            {--force                 : Overwrite existing files without prompting}
                            {--backup                : Backup existing files before overwriting}
                            {--dry-run               : Preview without writing files}
                            {--no-cache              : Force caching off, overriding anvil.cache.enabled}
                            {--cache                 : Generate services that cache query results}
                            {--cache-store=          : Cache store to use (default: the app default store)}
                            {--cache-ttl=            : TTL seconds — "300" for every profile, or "single=300,list=60"}
                            {--cache-stale=          : Seconds a stale value may be served while refreshing; 0 disables}
                            {--cache-scope=          : Result isolation: auth|tenant|none (default: auth)}
                            {--cache-profile=        : Default volatility profile for every model}
                            {--cache-jitter=         : TTL randomisation as a fraction, e.g. 0.1 for +/-10%}
                            {--cache-bypass          : Allow callers to request uncached reads (never in production)}
                            {--cache-model=*         : Per-model override: "Category:reference", "PriceHistory:off"}
                            {--etag                  : Emit ETag/If-None-Match handling and document 304 in the spec}
                            ';
    /**
     * Auth scheme => [route middleware, default OpenAPI security scheme].
     *
     * The single point where the API's runtime auth and its documented auth are
     * tied together, so the two cannot drift apart.
     *
     * @var array<string, array{0: ?string, 1: string}>
     */
    private const AUTH_SCHEMES = [
        'sanctum' => ['auth:sanctum', 'sanctum'],
        'passport' => ['auth:api', 'passport'],
        'jwt' => ['auth:api', 'bearer'],
        'token' => ['auth:sanctum', 'apikey'],
        'none' => [null, 'none'],
    ];

    /** @var list<string> */
    private const SECURITY_SCHEMES = ['sanctum', 'passport', 'bearer', 'apikey', 'none'];

    /** @var list<string> */
    private const SPEC_FORMATS = ['yaml', 'json'];

    public function handle(): int
    {
        if ($this->option('no-spec') && $this->option('spec-only')) {
            $this->error('--no-spec and --spec-only are mutually exclusive — there would be nothing to generate.');

            return self::FAILURE;
        }

        if (($flagError = $this->validateFlags()) !== null) {
            $this->error($flagError);

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

        // MUST precede buildOptions() and summarise(): the generators,
        // OpenApiLocator and ApiVersionProfile all read these values.
        $this->applyRuntimeConfig();

        $options = $this->buildOptions();

        if (($problem = $this->assertOptionsUnderstood($options)) !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $this->summarise();

        $status = $this->runPipeline($options);

        if ($status === self::SUCCESS && ! $this->option('dry-run')) {
            $this->reportOutcome();
        }

        return $status;
    }

    /**
     * Validate the free-text options up front, so a typo fails before any file
     * is written rather than producing a subtly broken scaffold.
     */
    private function validateFlags(): ?string
    {
        $auth = $this->normalisedAuth();

        if (! array_key_exists($auth, self::AUTH_SCHEMES)) {
            return sprintf('Unknown --auth "%s". Expected one of: %s.', $auth, implode(', ', array_keys(self::AUTH_SCHEMES)));
        }

        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, self::SPEC_FORMATS, true)) {
            return sprintf('Unknown --format "%s". Expected one of: %s.', $format, implode(', ', self::SPEC_FORMATS));
        }

        $security = (string) $this->option('security');

        if ($security !== '' && ! in_array(strtolower($security), self::SECURITY_SCHEMES, true)) {
            return sprintf('Unknown --security "%s". Expected one of: %s.', $security, implode(', ', self::SECURITY_SCHEMES));
        }

        foreach (['case', 'request-case', 'response-case'] as $option) {
            $value = strtolower((string) $this->option($option));

            if ($value !== '' && ! in_array($value, KeyCase::ALL, true)) {
                return sprintf('Unknown --%s "%s". Expected one of: %s.', $option, $value, implode(', ', KeyCase::ALL));
            }
        }

        foreach (['pagination', 'pagination-max'] as $option) {
            $value = (string) $this->option($option);

            if (! ctype_digit($value) || (int) $value < 1) {
                return "--{$option} must be a positive integer.";
            }
        }

        if ((int) $this->option('pagination-max') < (int) $this->option('pagination')) {
            return '--pagination-max must be greater than or equal to --pagination.';
        }

        $throttle = (string) $this->option('throttle');

        if ($throttle !== '' && $throttle !== 'none' && preg_match('/^\d+(,\d+)?$/', $throttle) !== 1) {
            return '--throttle must look like "60" or "60,1", or be "none" to disable rate limiting.';
        }

        if (trim((string) $this->option('output'), '/') === '') {
            return '--output must name a directory relative to the project root.';
        }

        return null;
    }

    /**
     * Push this command's options into the runtime config.
     *
     * Two destinations:
     *   anvil.api.* / anvil.openapi.*      — route, spec and gate settings
     *   anvil.api.versions.{vN}.*          — the per-version shape profile that
     *                                        ApiVersionProfile resolves
     *
     * openapi.enabled / openapi.ui / runtime.dry_run / runtime.force are the
     * gates the generators fall back to when GenerationOptions doesn't carry a
     * field under the key spelling used here — see ResolvesSpecOptions.
     *
     * Everything is written under BOTH historical roots (anvil.* and
     * laravel-anvil.*) to match how the service provider merges the config file.
     */
    private function applyRuntimeConfig(): void
    {
        [, $defaultSecurity] = self::AUTH_SCHEMES[$this->normalisedAuth()];

        $values = [
            'api.version' => $this->versionSegment(),
            'api.prefix' => trim((string) $this->option('prefix'), '/'),
            'api.auth' => $this->normalisedAuth(),
            'api.guard' => $this->option('guard') ?: null,
            'api.middleware' => $this->routeMiddleware(),
            'api.pagination' => (int) $this->option('pagination'),
            'api.force_json' => ! $this->option('no-force-json'),

            'openapi.enabled' => $this->generatesSpec(),
            'openapi.ui' => (bool) $this->option('ui'),
            'openapi.format' => strtolower((string) $this->option('format')),
            'openapi.split_files' => ! $this->option('single-file'),
            'openapi.output_path' => trim((string) $this->option('output'), '/'),
            'openapi.versioned_output' => ! $this->option('flat'),
            'openapi.api_version' => $this->versionSegment(),
            'openapi.security' => strtolower((string) ($this->option('security') ?: $defaultSecurity)),
            'openapi.servers' => array_map(strval(...), $this->option('server')),
            'openapi.title' => $this->option('title') ?: null,
            'openapi.description' => $this->option('description') ?: null,
            'openapi.docs.route' => trim((string) $this->option('route'), '/'),
            'openapi.docs.ui_version' => (string) $this->option('ui-version'),

            'runtime.dry_run' => (bool) $this->option('dry-run'),
            'runtime.force' => (bool) $this->option('force'),
        ];

        foreach ($this->versionProfileValues() as $key => $value) {
            $values[$key] = $value;
        }

        foreach ($values as $key => $value) {
            config(["anvil.{$key}" => $value, "laravel-anvil.{$key}" => $value]);
        }
    }

    /**
     * The per-version shape settings, written only when the flag was actually
     * supplied — otherwise a bare `anvil:generate-api --api-version=2` would
     * overwrite v2's configured profile with this command's flag defaults.
     *
     * @return array<string, mixed>
     */
    private function versionProfileValues(): array
    {
        $version = $this->versionSegment();
        $prefix = "api.versions.{$version}";

        $case = strtolower((string) $this->option('case'));
        $requestCase = strtolower((string) $this->option('request-case')) ?: $case;
        $responseCase = strtolower((string) $this->option('response-case')) ?: $case;

        $values = [];

        if ($requestCase !== '') {
            $values["{$prefix}.case.request"] = $requestCase;
        }

        if ($responseCase !== '') {
            $values["{$prefix}.case.response"] = $responseCase;
        }

        // --pagination has a default, so it is always "supplied"; that is fine,
        // it is also the documented default for the version.
        $values["{$prefix}.pagination.default"] = (int) $this->option('pagination');
        $values["{$prefix}.pagination.max"] = (int) $this->option('pagination-max');

        if (($param = (string) $this->option('pagination-param')) !== '') {
            $values["{$prefix}.pagination.param"] = $param;
        }

        if (($hidden = $this->option('hidden')) !== []) {
            $values["{$prefix}.hidden"] = array_map(strval(...), $hidden);
        }

        if ($this->option('flat-requests')) {
            $values["{$prefix}.group_by_model"] = false;
        }

        if ($this->option('versioned-services')) {
            $values["{$prefix}.versioned_services"] = true;
        }

        return $values;
    }

    /**
     * The middleware stack for the generated versioned route group.
     *
     * @return list<string>
     */
    private function routeMiddleware(): array
    {
        [$authMiddleware] = self::AUTH_SCHEMES[$this->normalisedAuth()];

        if ($authMiddleware !== null && ($guard = (string) $this->option('guard')) !== '') {
            $authMiddleware = 'auth:' . $guard;
        }

        $throttle = (string) $this->option('throttle');

        return array_values(array_filter([
            'api',
            $authMiddleware,
            $throttle !== '' && $throttle !== 'none' ? 'throttle:' . $throttle : null,
            ...array_map(strval(...), $this->option('middleware')),
        ]));
    }

    /**
     * Build the pipeline options.
     *
     * Keys are written in the DTO's camelCase property style and then run through
     * alignKeys(), which rewrites them to whatever spelling GenerationOptions
     * actually accepts (it wants open_api, form_requests, dry_run, …).
     */
    private function buildOptions(): GenerationOptions
    {
        $scaffold = ! $this->option('spec-only');

        $tables = array_values(array_unique(array_merge(
            array_map(strval(...), $this->option('tables')),
            array_map(strval(...), $this->option('only')),
        )));

        return GenerationOptions::fromArray($this->alignKeys([
            // ── What this command owns ───────────────────────────────────────
            'api' => $scaffold,
            'apiVersion' => $this->apiVersion(),
            'openApi' => $this->generatesSpec(),
            'openApiFormat' => strtolower((string) $this->option('format')),
            'openApiSingleFile' => (bool) $this->option('single-file'),
            'openApiUi' => (bool) $this->option('ui'),

            // ── Implied by the versioned scaffold ────────────────────────────
            // The SHARED service is generated (controllers depend on it);
            // requests and resources are NOT, because the versioned generators
            // own those and gate on $options->api. Leaving these true would
            // produce two sets of classes for the same models.
            'services' => $scaffold,
            'formRequests' => false,
            'resources' => false,
            'tests' => $scaffold && ! $this->option('no-tests'),

            // ── Explicitly not this command's job: anvil:generate owns these ─
            'all' => false,
            'models' => false,
            'controllers' => false,
            'observers' => false,
            'policies' => false,
            'repositories' => false,
            'gates' => false,
            'factories' => false,
            'seeders' => false,
            'migrations' => false,
            'events' => false,
            'apiRoutes' => false,

            // ── Targeting & write behaviour ──────────────────────────────────
            'namespace' => (string) $this->option('namespace'),
            'connection' => $this->option('connection') ?: null,
            // 'schemas', not 'schema' — the DTO's key is plural and
            // normalizeSchemas() accepts a CSV string, an array or null.
            'schemas' => $this->option('schema') ?: [],
            'tables' => $tables,
            'ignore' => array_map(strval(...), $this->option('ignore')),
            'path' => (string) $this->option('path'),
            'force' => (bool) $this->option('force'),
            'backup' => (bool) $this->option('backup'),
            'dryRun' => (bool) $this->option('dry-run'),
        ]));
    }

    /**
     * Rewrite payload keys to the spelling GenerationOptions accepts.
     *
     * fromArray() consumes exactly what toArray() emits, so the live DTO is the
     * authority on whether a field is "openApi", "openapi" or "open_api".
     * Comparison happens on a normalised form — lowercased, underscores stripped
     * — which collapses all three spellings into the same bucket.
     *
     * This exists because passing snake_case keys silently produced a DTO with
     * every multi-word flag left at its default, so the spec pipeline no-opped
     * while reporting success. Delete it once GenerationOptions takes typed
     * ApiOptions / OpenApiOptions objects by constructor.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function alignKeys(array $payload): array
    {
        static $index = null;

        if ($index === null) {
            $index = [];

            try {
                foreach (array_keys(GenerationOptions::fromArray([])->toArray()) as $key) {
                    $index[strtolower(str_replace('_', '', (string) $key))] = $key;
                }
            } catch (\Throwable) {
                $index = [];   // Can't introspect the DTO — pass the payload through.
            }
        }

        $aligned = [];

        foreach ($payload as $name => $value) {
            $normalised = strtolower(str_replace('_', '', $name));
            $aligned[$index[$normalised] ?? $name] = $value;
        }

        return $aligned;
    }

    /**
     * Catch a DTO that silently dropped the flags this command depends on.
     *
     * The spec generators fall back to anvil.openapi.enabled, so a dropped
     * openApi flag is survivable and only warns. The API scaffold generators
     * read $options->api directly, so a dropped api flag is fatal — without this
     * check the run would report success and write nothing.
     */
    private function assertOptionsUnderstood(GenerationOptions $options): ?string
    {
        $hint = sprintf(
            'Inspect the accepted keys with: php artisan tinker --execute="print_r(array_keys(\\%s::fromArray([])->toArray()));"',
            GenerationOptions::class,
        );

        if (! $this->option('spec-only') && ! ($options->api ?? false)) {
            return 'GenerationOptions did not accept the "api" flag, so the versioned API scaffold would be skipped. ' . $hint;
        }

        if ($this->generatesSpec() && ! ($options->openApi ?? false)) {
            $this->components->warn(
                'GenerationOptions did not accept the "openApi" flag; the spec generators are falling back to '
                    . 'anvil.openapi.enabled. ' . $hint
            );
        }

        $version = $options->apiVersion ?? '';

        if ($version !== '' && OpenApiLocator::normaliseVersion($version) !== $this->versionSegment()) {
            $this->components->warn(sprintf(
                'GenerationOptions resolved apiVersion to "%s" but --api-version asked for "%s"; generated code may '
                    . 'not match the spec directory. %s',
                $version,
                $this->versionSegment(),
                $hint,
            ));
        }

        return null;
    }

    private function summarise(): void
    {
        $profile = ApiVersionProfile::for($this->apiVersion());
        [, $defaultSecurity] = self::AUTH_SCHEMES[$this->normalisedAuth()];

        $rows = [];

        if (! $this->option('spec-only')) {
            $rows[] = ['API scaffold', OpenApiLocator::apiBasePath($this->apiVersion())];
            $rows[] = ['Middleware', implode(', ', $this->routeMiddleware())];
            $rows[] = ['Force JSON', $this->option('no-force-json') ? 'no' : 'yes'];
            $rows[] = ['Payload case', sprintf(
                'in: %s, out: %s',
                $profile->requestCase(),
                $profile->responseCase(),
            )];
            $rows[] = ['Pagination', sprintf(
                '%d per page (max %d) via ?%s=',
                $profile->perPageDefault(),
                $profile->perPageMax(),
                $profile->perPageParam(),
            )];
            $rows[] = ['Hidden fields', implode(', ', $profile->hiddenFields()) ?: 'none'];
            $rows[] = ['Requests', $profile->requestNamespace('{Model}')];
            $rows[] = ['Resources', $profile->resourceNamespace()];
            $rows[] = ['Services', $profile->get('versioned_services', false)
                ? 'per-version subclass'
                : 'shared (App\\Services)'];
        }

        if ($this->generatesSpec()) {
            $rows[] = ['Specification', sprintf(
                'OpenAPI 3.1 (%s, %s)',
                strtolower((string) $this->option('format')),
                $this->option('single-file') ? 'single file' : 'split files',
            )];
            $rows[] = ['Spec directory', $this->relativeToBase(OpenApiLocator::specDir($this->apiVersion())) . '/'];
            $rows[] = ['Security scheme', strtolower((string) ($this->option('security') ?: $defaultSecurity))];
            $rows[] = ['Swagger UI', $this->option('ui')
                ? $this->relativeToBase(OpenApiLocator::publicDocsDir($this->apiVersion())) . '/ (swagger-ui ' . $this->option('ui-version') . ')'
                : 'not published'];
        }

        if ($this->option('dry-run')) {
            $rows[] = ['Mode', 'dry run — no files will be written'];
        }

        $this->table(['', ''], $rows);
        $this->newLine();
    }

    /**
     * Report where things landed, and say so plainly when they didn't.
     */
    private function reportOutcome(): void
    {
        if (! $this->generatesSpec()) {
            return;
        }

        $version = $this->apiVersion();
        $this->newLine();

        if (! OpenApiLocator::specExists($version)) {
            $this->components->warn(sprintf(
                'The pipeline finished but no spec exists at %s. Check that the OpenAPI generators resolve their '
                    . 'output through OpenApiLocator::specDir().',
                $this->relativeToBase(OpenApiLocator::specFile($version)),
            ));

            return;
        }

        $counts = OpenApiLocator::fileCounts($version);

        $this->line(sprintf(
            '  <fg=green>✓</> %s written <fg=gray>(%d schema, %d path files)</>',
            $this->relativeToBase(OpenApiLocator::specFile($version)),
            $counts['schemas'],
            $counts['paths'],
        ));

        $this->line('  Docs: <options=bold>' . OpenApiLocator::docsUrl($version) . '</>');
    }

    private function relativeToBase(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), '/');
    }

    private function generatesSpec(): bool
    {
        return ! $this->option('no-spec');
    }

    private function normalisedAuth(): string
    {
        return strtolower(trim((string) $this->option('auth')));
    }

    /** Digits only, so "2", "v2" and "V2" all normalise to "2". */
    private function apiVersion(): string
    {
        $digits = preg_replace('/\D/', '', (string) $this->option('api-version'));

        return ($digits === null || $digits === '') ? '1' : $digits;
    }

    private function versionSegment(): string
    {
        return 'v' . $this->apiVersion();
    }
}
