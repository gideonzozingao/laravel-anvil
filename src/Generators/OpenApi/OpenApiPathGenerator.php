<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Generators\OpenApi;

use Illuminate\Support\Str;
use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Generators\OpenApi\Concerns\ResolvesSpecOptions;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;
use Zuqongtech\LaravelAnvil\Support\OpenApiLocator;
use Zuqongtech\LaravelAnvil\Support\OpenApiYamlSerializer;

/**
 * Generates OpenAPI 3.1 path definitions for a model's REST endpoints.
 *
 * Split-file mode writes one file per path under openapi/{version}/paths/;
 * single-file mode returns raw path arrays to be merged by OpenApiRootGenerator.
 *
 * ---------------------------------------------------------------------------
 * BASE PATH OWNERSHIP
 * ---------------------------------------------------------------------------
 * OpenAPI defines the effective request URL as:
 *
 *     servers[].url  +  path key
 *
 * Every client (Swagger UI, Redoc, openapi-generator, Postman import)
 * concatenates the two. So the API base path — "/api/v7" — must appear in
 * EXACTLY ONE of them. When it appears in both you get:
 *
 *     servers[0].url : http://localhost:3055/api/v7
 *     path key       : /api/v7/api-keys
 *     request        : http://localhost:3055/api/v7/api/v7/api-keys  -> 404
 *
 * This class resolves ownership through `anvil.openapi.base_path_location`:
 *
 *   'server' — servers[].url carries the base path; path keys are relative.
 *   'paths'  — servers[].url is host-only; path keys carry the base path.
 *   'auto'   — (default) inspect the configured server URLs and decide.
 *
 * `OpenApiRootGenerator` MUST read the same config value when it writes
 * `servers`, or the two halves will disagree and produce either a doubled
 * prefix or a missing one.
 */
final readonly class OpenApiPathGenerator implements Generator
{
    use ResolvesSpecOptions;

    /**
     * Default-constructible so the generator works whether resolved through the
     * container (autowired) or built with a bare `new`.
     */
    public function __construct(
        private OpenApiYamlSerializer $serializer = new OpenApiYamlSerializer,
    ) {}

    #[\Override]
    public function supports(GenerationOptions $options): bool
    {
        return $this->specEnabled($options);
    }

    #[\Override]
    public function getName(): string
    {
        return 'OpenApiPath';
    }

    #[\Override]
    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $format = OpenApiLocator::format();
        $splitFiles = $this->splitFiles();
        $version = OpenApiLocator::configuredVersion();
        $pathsDir = OpenApiLocator::pathsDir($version);
        $security = $this->securityScheme();

        $slug = $this->resourceSlug($meta);
        $pkParam = $this->pkParamName($meta);
        $tag = Str::headline(Str::plural($meta->model));

        $paths = $this->buildPaths($meta, $slug, $pkParam, $tag, $version, $security);
        $results = [];

        foreach ($paths as $pathKey => $pathDef) {
            if (! $splitFiles) {
                $results[] = [
                    'type' => $this->getName(),
                    'name' => $pathKey,
                    'status' => 'merged',
                    'path_key' => $pathKey,
                    'path_def' => $pathDef,
                ];

                continue;
            }

            $path = "{$pathsDir}/{$this->pathFileName($pathKey)}.{$format}";

            if (file_exists($path) && ! $this->overwrites($options)) {
                $results[] = [
                    'type' => $this->getName(),
                    'name' => $pathKey,
                    'path' => $path,
                    'status' => 'skipped',
                    'reason' => 'already exists',
                ];

                continue;
            }

            if (! $this->isDryRun($options)) {
                $this->serializer->writeFile([$pathKey => $pathDef], $path, $format);
            }

            $results[] = [
                'type' => $this->getName(),
                'name' => $pathKey,
                'path' => $path,
                'status' => $this->isDryRun($options) ? 'dry-run' : 'success',
            ];
        }

        return $results;
    }

    // -----------------------------------------------------------------------
    // Path builders
    // -----------------------------------------------------------------------

    /**
     * Build every path key/definition pair for a model.
     *
     * Signature kept positional for backwards compatibility with existing
     * callers and tests. All five strings are derived from $meta plus config;
     * prefer letting generate() supply them rather than hand-assembling.
     *
     * @param  string  $slug  Kebab-plural resource segment, e.g. "api-keys"
     * @param  string  $pkParam  Route parameter name, e.g. "id"
     * @param  string  $tag  Human tag, e.g. "Api Keys"
     * @param  string  $version  API version used for base-path resolution
     * @param  string  $security  Security scheme name, or "none"
     * @return array<string, array<string, mixed>>
     */
    public function buildPaths(
        ModelMetadata $meta,
        string $slug,
        string $pkParam,
        string $tag,
        string $version,
        string $security,
    ): array {
        $paths = [];

        // Resolved ONCE, applied ONCE. See the class docblock for why this
        // cannot also live in servers[].url.
        $prefix = $this->resolvePathPrefix($version);

        // Built by concatenation, not interpolation. "{{ $pkParam }}" does NOT
        // produce "{id}" — "{{ " is not the complex-interpolation opener ("{$"
        // is), so the braces stay literal and the key becomes "{{ id }}",
        // which never matches the declared parameter name.
        $collectionPath = $this->joinPath($prefix, $slug);
        $itemPath = $this->joinPath($collectionPath, '{'.$pkParam.'}');

        // securityBlock is [] for 'none', which emits `security: []` — the
        // explicit "this operation needs no auth" override. Harmless when the
        // root document declares no global security, and correct when it does.
        $securityBlock = $security !== 'none'
            ? [[$security => []]]
            : [];

        $authenticated = $security !== 'none';

        $paths[$collectionPath] = [
            'get' => $this->buildIndexOperation($meta, $slug, $tag, $securityBlock, $authenticated),
            'post' => $this->buildStoreOperation($meta, $slug, $tag, $securityBlock, $authenticated),
        ];

        $paths[$itemPath] = [
            'get' => $this->buildShowOperation($meta, $slug, $tag, $pkParam, $securityBlock, $authenticated),
            'put' => $this->buildUpdateOperation($meta, $slug, $tag, $pkParam, $securityBlock, $authenticated),
            'patch' => $this->buildPatchOperation($meta, $slug, $tag, $pkParam, $securityBlock, $authenticated),
            'delete' => $this->buildDestroyOperation($meta, $slug, $tag, $pkParam, $securityBlock, $authenticated),
        ];

        if ($meta->softDeletes) {
            $paths[$this->joinPath($itemPath, 'restore')] = [
                'patch' => $this->buildRestoreOperation($meta, $slug, $tag, $pkParam, $securityBlock, $authenticated),
            ];

            $paths[$this->joinPath($itemPath, 'force')] = [
                'delete' => $this->buildForceDeleteOperation($meta, $slug, $tag, $pkParam, $securityBlock, $authenticated),
            ];
        }

        return $paths;
    }

    /**
     * @param  array<int, array<string, array<int, string>>>  $security
     * @return array<string, mixed>
     */
    private function buildIndexOperation(
        ModelMetadata $meta,
        string $slug,
        string $tag,
        array $security,
        bool $authenticated,
    ): array {
        $model = $meta->model;

        return [
            'operationId' => $this->operationId($slug, 'index'),
            'summary' => "List all {$model}s",
            'description' => "Returns a paginated list of {$model} records.",
            'tags' => [$tag],
            'security' => $security,
            'parameters' => $this->paginationParameters($meta),
            'responses' => $this->responses($authenticated, [
                '200' => [
                    'description' => "Paginated list of {$model} records",
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => "#/components/schemas/{$model}Collection"],
                        ],
                    ],
                ],
            ]),
        ];
    }

    /**
     * @param  array<int, array<string, array<int, string>>>  $security
     * @return array<string, mixed>
     */
    private function buildStoreOperation(
        ModelMetadata $meta,
        string $slug,
        string $tag,
        array $security,
        bool $authenticated,
    ): array {
        $model = $meta->model;

        return [
            'operationId' => $this->operationId($slug, 'store'),
            'summary' => "Create a {$model}",
            'description' => "Creates and persists a new {$model} record.",
            'tags' => [$tag],
            'security' => $security,
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => "#/components/schemas/{$model}Request"],
                    ],
                ],
            ],
            'responses' => $this->responses($authenticated, [
                '201' => [
                    'description' => "{$model} created successfully",
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => "#/components/schemas/{$model}Resource"],
                        ],
                    ],
                ],
            ], validation: true),
        ];
    }

    /**
     * @param  array<int, array<string, array<int, string>>>  $security
     * @return array<string, mixed>
     */
    private function buildShowOperation(
        ModelMetadata $meta,
        string $slug,
        string $tag,
        string $pkParam,
        array $security,
        bool $authenticated,
    ): array {
        $model = $meta->model;

        return [
            'operationId' => $this->operationId($slug, 'show'),
            'summary' => "Get a {$model}",
            'description' => "Returns a single {$model} by its primary key.",
            'tags' => [$tag],
            'security' => $security,
            'parameters' => [$this->pkParameter($pkParam, $meta)],
            'responses' => $this->responses($authenticated, [
                '200' => [
                    'description' => "{$model} found",
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => "#/components/schemas/{$model}Resource"],
                        ],
                    ],
                ],
            ], notFound: true),
        ];
    }

    /**
     * @param  array<int, array<string, array<int, string>>>  $security
     * @return array<string, mixed>
     */
    private function buildUpdateOperation(
        ModelMetadata $meta,
        string $slug,
        string $tag,
        string $pkParam,
        array $security,
        bool $authenticated,
    ): array {
        $model = $meta->model;

        return [
            'operationId' => $this->operationId($slug, 'update'),
            'summary' => "Replace a {$model}",
            'description' => "Fully replaces an existing {$model} record (PUT semantics — all fields required).",
            'tags' => [$tag],
            'security' => $security,
            'parameters' => [$this->pkParameter($pkParam, $meta)],
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => "#/components/schemas/{$model}Request"],
                    ],
                ],
            ],
            'responses' => $this->responses($authenticated, [
                '200' => [
                    'description' => "{$model} updated",
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => "#/components/schemas/{$model}Resource"],
                        ],
                    ],
                ],
            ], notFound: true, validation: true),
        ];
    }

    /**
     * PATCH previously used `allOf: [$ref Request, {required: []}]` to try to
     * relax the required list. That does not work: allOf INTERSECTS
     * constraints and cannot subtract them, so `{required: []}` is satisfied
     * by anything while the parent's `required` still applies. The operation
     * documented PATCH semantics but validated as PUT.
     *
     * JSON Schema has no un-require mechanism, so a genuinely optional-fields
     * body needs its own schema. See patchSchemaRef().
     *
     * @param  array<int, array<string, array<int, string>>>  $security
     * @return array<string, mixed>
     */
    private function buildPatchOperation(
        ModelMetadata $meta,
        string $slug,
        string $tag,
        string $pkParam,
        array $security,
        bool $authenticated,
    ): array {
        $model = $meta->model;

        return [
            'operationId' => $this->operationId($slug, 'patch'),
            'summary' => "Partially update a {$model}",
            'description' => "Updates only the supplied fields of an existing {$model} (PATCH semantics).",
            'tags' => [$tag],
            'security' => $security,
            'parameters' => [$this->pkParameter($pkParam, $meta)],
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => $this->patchSchemaRef($model)],
                    ],
                ],
            ],
            'responses' => $this->responses($authenticated, [
                '200' => [
                    'description' => "{$model} partially updated",
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => "#/components/schemas/{$model}Resource"],
                        ],
                    ],
                ],
            ], notFound: true, validation: true),
        ];
    }

    /**
     * @param  array<int, array<string, array<int, string>>>  $security
     * @return array<string, mixed>
     */
    private function buildDestroyOperation(
        ModelMetadata $meta,
        string $slug,
        string $tag,
        string $pkParam,
        array $security,
        bool $authenticated,
    ): array {
        $model = $meta->model;

        $description = $meta->softDeletes
            ? "Soft-deletes the {$model}. Use DELETE /force to permanently remove."
            : "Permanently deletes the {$model} record.";

        return [
            'operationId' => $this->operationId($slug, 'destroy'),
            'summary' => "Delete a {$model}",
            'description' => $description,
            'tags' => [$tag],
            'security' => $security,
            'parameters' => [$this->pkParameter($pkParam, $meta)],
            'responses' => $this->responses($authenticated, [
                '204' => ['description' => 'No Content — deleted successfully'],
            ], notFound: true),
        ];
    }

    /**
     * @param  array<int, array<string, array<int, string>>>  $security
     * @return array<string, mixed>
     */
    private function buildRestoreOperation(
        ModelMetadata $meta,
        string $slug,
        string $tag,
        string $pkParam,
        array $security,
        bool $authenticated,
    ): array {
        $model = $meta->model;

        return [
            'operationId' => $this->operationId($slug, 'restore'),
            'summary' => "Restore a soft-deleted {$model}",
            'description' => "Restores a previously soft-deleted {$model} record.",
            'tags' => [$tag],
            'security' => $security,
            'parameters' => [$this->pkParameter($pkParam, $meta)],
            'responses' => $this->responses($authenticated, [
                '200' => [
                    'description' => "{$model} restored",
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => "#/components/schemas/{$model}Resource"],
                        ],
                    ],
                ],
            ], notFound: true),
        ];
    }

    /**
     * @param  array<int, array<string, array<int, string>>>  $security
     * @return array<string, mixed>
     */
    private function buildForceDeleteOperation(
        ModelMetadata $meta,
        string $slug,
        string $tag,
        string $pkParam,
        array $security,
        bool $authenticated,
    ): array {
        $model = $meta->model;

        return [
            'operationId' => $this->operationId($slug, 'forceDelete'),
            'summary' => "Permanently delete a {$model}",
            'description' => "Permanently removes the {$model} record. Cannot be undone.",
            'tags' => [$tag],
            'security' => $security,
            'parameters' => [$this->pkParameter($pkParam, $meta)],
            'responses' => $this->responses($authenticated, [
                '204' => ['description' => 'No Content — permanently deleted'],
            ], notFound: true),
        ];
    }

    // -----------------------------------------------------------------------
    // Base-path resolution
    // -----------------------------------------------------------------------

    /**
     * The prefix that path keys are written relative to.
     *
     * Returns '' when servers[].url already carries the API base path, so the
     * client's `servers[].url + path key` concatenation lands on the real
     * Laravel route instead of doubling the prefix.
     */
    private function resolvePathPrefix(string $version): string
    {
        $base = $this->apiBasePath($version);

        if ($base === '/') {
            return '';
        }

        return match ($this->basePathLocation()) {
            'server' => '',
            'paths' => $base,
            default => $this->serverCarriesBasePath($base) ? '' : $base,
        };
    }

    private function basePathLocation(): string
    {
        $location = strtolower(trim((string) config('anvil.openapi.base_path_location', 'auto')));

        return in_array($location, ['server', 'paths', 'auto'], true) ? $location : 'auto';
    }

    /**
     * True when any declared server URL already ends with the base path.
     *
     * Templated URLs ("https://{host}/{basePath}") are treated as carrying it:
     * we cannot prove otherwise, and a missing prefix is a single obvious
     * misconfiguration while a doubled one silently breaks every operation.
     */
    private function serverCarriesBasePath(string $base): bool
    {
        foreach ($this->serverUrls() as $url) {
            if (str_contains($url, '{')) {
                return true;
            }

            $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
            $path = $this->normalizeSegment($path);

            if ($path === $base || str_ends_with($path, $base)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Server URLs as declared in config, falling back to app.url.
     *
     * @return list<string>
     */
    private function serverUrls(): array
    {
        $servers = config('anvil.openapi.servers', []);

        if (! is_array($servers) || $servers === []) {
            $servers = [['url' => (string) config('app.url', '')]];
        }

        $urls = [];

        foreach ($servers as $server) {
            $url = match (true) {
                is_array($server) => (string) ($server['url'] ?? ''),
                is_string($server) => $server,
                default => '',
            };

            if (trim($url) !== '') {
                $urls[] = trim($url);
            }
        }

        return $urls;
    }

    /** Normalized API base path, always leading-slashed and never trailing-slashed. */
    private function apiBasePath(string $version): string
    {
        return $this->normalizeSegment(OpenApiLocator::apiBasePath($version));
    }

    // -----------------------------------------------------------------------
    // Path assembly
    // -----------------------------------------------------------------------

    /**
     * Join segments into a single OpenAPI path key.
     *
     * Collapses repeated slashes, guarantees a leading slash, drops any
     * trailing one, and strips an immediately-doubled base path so that a
     * mis-set config degrades to a working spec rather than a 404.
     */
    private function joinPath(string ...$segments): string
    {
        $parts = array_filter(
            array_map(static fn (string $s): string => trim($s, '/'), $segments),
            static fn (string $s): bool => $s !== '',
        );

        $path = $this->normalizeSegment('/'.implode('/', $parts));

        return $this->stripDoubledPrefix($path, $this->apiBasePath(OpenApiLocator::configuredVersion()));
    }

    private function normalizeSegment(string $path): string
    {
        $path = (string) preg_replace('#/+#', '/', $path);
        $path = '/'.trim($path, '/');

        return $path;
    }

    /**
     * Defensive: "/api/v7/api/v7/api-keys" -> "/api/v7/api-keys".
     *
     * The repeat must end on a segment boundary. Without that check a base
     * path of "/api" would maul "/api/api-keys" into "/api-keys", because
     * "api-keys" happens to begin with the prefix text.
     */
    private function stripDoubledPrefix(string $path, string $prefix): string
    {
        if ($prefix === '' || $prefix === '/') {
            return $path;
        }

        $doubled = $prefix.$prefix;

        while (str_starts_with($path, $doubled)) {
            $remainder = substr($path, strlen($doubled));

            if ($remainder !== '' && ! str_starts_with($remainder, '/')) {
                break;
            }

            $path = substr($path, strlen($prefix));
        }

        return $path;
    }

    /**
     * Filesystem-safe name for a split path file.
     *
     * Dots are stripped as well as slashes and braces, so a dotted version
     * segment ("v1.0") cannot produce "api_v1.0_posts.yaml" and a confused
     * extension.
     */
    private function pathFileName(string $pathKey): string
    {
        $name = str_replace(['/', '{', '}', '.', ' '], ['_', '', '', '_', '_'], ltrim($pathKey, '/'));
        $name = (string) preg_replace('/[^A-Za-z0-9_\-]/', '', $name);
        $name = trim((string) preg_replace('/_+/', '_', $name), '_');

        return $name !== '' ? $name : 'root';
    }

    // -----------------------------------------------------------------------
    // Parameter helpers
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function pkParameter(string $pkParam, ModelMetadata $meta): array
    {
        return [
            'name' => $pkParam,
            'in' => 'path',
            'required' => true,
            'description' => "The {$meta->model} primary key",
            'schema' => $this->pkSchema($meta),
        ];
    }

    /**
     * Primary-key schema inferred from the column type.
     *
     * The previous rule was "uuid -> string, everything else -> integer",
     * which mis-documented ULID keys, string slugs and varchar business keys
     * as integer/int64. The test is now inverted: integer only when the
     * column type genuinely is one, string otherwise.
     *
     * @return array<string, mixed>
     */
    private function pkSchema(ModelMetadata $meta): array
    {
        $column = collect($meta->columns)
            ->firstWhere('name', $this->pkParamName($meta));

        // No column metadata at all: assume the Laravel default of an
        // auto-incrementing bigint rather than guessing string.
        if (! $column) {
            return ['type' => 'integer', 'format' => 'int64'];
        }

        $type = strtolower(trim((string) ($column['type'] ?? '')));

        if (str_contains($type, 'uuid')) {
            return ['type' => 'string', 'format' => 'uuid'];
        }

        if (str_contains($type, 'ulid')) {
            return [
                'type' => 'string',
                'minLength' => 26,
                'maxLength' => 26,
                'pattern' => '^[0-7][0-9A-HJKMNP-TV-Z]{25}$',
            ];
        }

        // Anchored so that "point" and "interval" are not read as integers,
        // while "bigint(20) unsigned" and "bigserial" are.
        if (preg_match('/^(tiny|small|medium|big)?(int|integer|serial)\b/', $type) === 1) {
            return ['type' => 'integer', 'format' => 'int64'];
        }

        return ['type' => 'string'];
    }

    /** @return array<int, array<string, mixed>> */
    private function paginationParameters(ModelMetadata $meta): array
    {
        $default = (int) config('anvil.api.pagination', 15);
        $max = (int) config('anvil.api.max_pagination', 100);
        $max = max(1, $max);

        $parameters = [
            [
                'name' => 'page',
                'in' => 'query',
                'required' => false,
                'description' => 'Page number',
                'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            ],
            [
                'name' => 'per_page',
                'in' => 'query',
                'required' => false,
                'description' => 'Records per page',
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => $max,
                    'default' => min($max, max(1, $default)),
                ],
            ],
        ];

        if ($meta->softDeletes && (bool) config('anvil.openapi.document_trashed_filter', true)) {
            $parameters[] = [
                'name' => 'trashed',
                'in' => 'query',
                'required' => false,
                'description' => 'Include or isolate soft-deleted records',
                'schema' => [
                    'type' => 'string',
                    'enum' => ['with', 'only'],
                ],
            ];
        }

        return $parameters;
    }

    // -----------------------------------------------------------------------
    // Response helpers
    // -----------------------------------------------------------------------

    /**
     * Assemble a response map, adding only the error responses that can
     * actually occur.
     *
     * 401 used to be emitted unconditionally, including when security was
     * 'none' — documenting an authentication failure on an endpoint with no
     * authentication.
     *
     * @param  array<string, array<string, mixed>>  $success
     * @return array<string, array<string, mixed>>
     */
    private function responses(
        bool $authenticated,
        array $success,
        bool $notFound = false,
        bool $validation = false,
    ): array {
        $responses = $success;

        if ($authenticated) {
            $responses['401'] = ['$ref' => '#/components/responses/Unauthenticated'];

            if ((bool) config('anvil.openapi.document_authorization', true)) {
                $responses['403'] = ['$ref' => '#/components/responses/Forbidden'];
            }
        }

        if ($notFound) {
            $responses['404'] = ['$ref' => '#/components/responses/NotFound'];
        }

        if ($validation) {
            $responses['422'] = ['$ref' => '#/components/responses/ValidationError'];
        }

        if ((bool) config('anvil.openapi.document_throttling', false)) {
            $responses['429'] = ['$ref' => '#/components/responses/TooManyRequests'];
        }

        ksort($responses, SORT_STRING);

        return $responses;
    }

    // -----------------------------------------------------------------------
    // Naming helpers
    // -----------------------------------------------------------------------

    /**
     * Single source of truth for the route parameter name.
     *
     * pkParameter() used to re-derive this independently, leaving two places
     * to change. Whatever generates the routes must agree with this method —
     * a model with $primaryKey = 'post_id' documents /posts/{post_id}, while
     * Laravel's implicit binding would give /posts/{post}.
     */
    private function pkParamName(ModelMetadata $meta): string
    {
        $key = trim($meta->primaryKey ?? '');

        return $key !== '' ? $key : 'id';
    }

    private function resourceSlug(ModelMetadata $meta): string
    {
        return Str::plural(Str::kebab($meta->model));
    }

    private function securityScheme(): string
    {
        $scheme = trim((string) config('anvil.openapi.security', 'sanctum'));

        return $scheme !== '' ? $scheme : 'none';
    }

    /**
     * Operation identifier.
     *
     * The dotted kebab form ("api-keys.index") is valid but is mangled by
     * several codegen toolchains, which use operationId as a method name.
     * Set anvil.openapi.operation_id_style to 'camel' for "apiKeysIndex".
     */
    private function operationId(string $slug, string $action): string
    {
        $style = strtolower(trim((string) config('anvil.openapi.operation_id_style', 'dot')));

        return match ($style) {
            'camel' => Str::camel($slug.'_'.Str::snake($action)),
            'snake' => Str::snake(str_replace('-', '_', $slug).'_'.Str::snake($action)),
            default => "{$slug}.{$action}",
        };
    }

    /**
     * Reference for the PATCH request body.
     *
     * Defaults to a dedicated "{Model}PatchRequest" schema — same properties
     * as "{Model}Request" but with an empty `required` list. The schema
     * generator must emit it; set anvil.openapi.patch_request_suffix to an
     * empty string to fall back to the strict request schema instead (which
     * will document PATCH as requiring every field).
     */
    private function patchSchemaRef(string $model): string
    {
        $suffix = trim((string) config('anvil.openapi.patch_request_suffix', 'PatchRequest'));

        if ($suffix === '') {
            return "#/components/schemas/{$model}Request";
        }

        return "#/components/schemas/{$model}{$suffix}";
    }
}
