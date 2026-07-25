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
 * The URL prefix and version both come from config, so --prefix and
 * --api-version on anvil:generate-api are reflected in the documented paths.
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
        $security = (string) config('anvil.openapi.security', 'sanctum');

        $slug = Str::plural(Str::kebab($meta->model));
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

            $safeKey = str_replace(['/', '{', '}'], ['_', '', ''], ltrim($pathKey, '/'));
            $path = "{$pathsDir}/{$safeKey}.{$format}";

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

        // Built by concatenation, not interpolation. "{{ $pkParam }}" does NOT
        // produce "{id}" — "{{ " is not the complex-interpolation opener ("{$"
        // is), so the braces stayed literal and the key became "{{ id }}",
        // which never matched the declared parameter name.
        $base = OpenApiLocator::apiBasePath($version);

        $collectionPath = $base.'/'.$slug;
        $itemPath = $base.'/'.$slug.'/{'.$pkParam.'}';

        $securityBlock = $security !== 'none'
            ? [[$security => []]]
            : [];

        $paths[$collectionPath] = [
            'get' => $this->buildIndexOperation($meta, $slug, $tag, $securityBlock),
            'post' => $this->buildStoreOperation($meta, $slug, $tag, $securityBlock),
        ];

        $paths[$itemPath] = [
            'get' => $this->buildShowOperation($meta, $slug, $tag, $pkParam, $securityBlock),
            'put' => $this->buildUpdateOperation($meta, $slug, $tag, $pkParam, $securityBlock),
            'patch' => $this->buildPatchOperation($meta, $slug, $tag, $pkParam, $securityBlock),
            'delete' => $this->buildDestroyOperation($meta, $slug, $tag, $pkParam, $securityBlock),
        ];

        if ($meta->softDeletes) {
            $paths[$itemPath.'/restore'] = [
                'patch' => $this->buildRestoreOperation($meta, $slug, $tag, $pkParam, $securityBlock),
            ];

            $paths[$itemPath.'/force'] = [
                'delete' => $this->buildForceDeleteOperation($meta, $slug, $tag, $pkParam, $securityBlock),
            ];
        }

        return $paths;
    }

    /** @return array<string, mixed> */
    protected function buildIndexOperation(ModelMetadata $meta, string $slug, string $tag, array $security): array
    {
        $model = $meta->model;

        return [
            'operationId' => "{$slug}.index",
            'summary' => "List all {$model}s",
            'description' => "Returns a paginated list of {$model} records.",
            'tags' => [$tag],
            'security' => $security,
            'parameters' => $this->paginationParameters(),
            'responses' => [
                '200' => [
                    'description' => "Paginated list of {$model} records",
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => "#/components/schemas/{$model}Collection"],
                        ],
                    ],
                ],
                '401' => ['$ref' => '#/components/responses/Unauthenticated'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function buildStoreOperation(ModelMetadata $meta, string $slug, string $tag, array $security): array
    {
        $model = $meta->model;

        return [
            'operationId' => "{$slug}.store",
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
            'responses' => [
                '201' => [
                    'description' => "{$model} created successfully",
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => "#/components/schemas/{$model}Resource"],
                        ],
                    ],
                ],
                '401' => ['$ref' => '#/components/responses/Unauthenticated'],
                '422' => ['$ref' => '#/components/responses/ValidationError'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function buildShowOperation(ModelMetadata $meta, string $slug, string $tag, string $pkParam, array $security): array
    {
        $model = $meta->model;

        return [
            'operationId' => "{$slug}.show",
            'summary' => "Get a {$model}",
            'description' => "Returns a single {$model} by its primary key.",
            'tags' => [$tag],
            'security' => $security,
            'parameters' => [$this->pkParameter($pkParam, $meta)],
            'responses' => [
                '200' => [
                    'description' => "{$model} found",
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => "#/components/schemas/{$model}Resource"],
                        ],
                    ],
                ],
                '401' => ['$ref' => '#/components/responses/Unauthenticated'],
                '404' => ['$ref' => '#/components/responses/NotFound'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function buildUpdateOperation(ModelMetadata $meta, string $slug, string $tag, string $pkParam, array $security): array
    {
        $model = $meta->model;

        return [
            'operationId' => "{$slug}.update",
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
            'responses' => [
                '200' => [
                    'description' => "{$model} updated",
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => "#/components/schemas/{$model}Resource"],
                        ],
                    ],
                ],
                '401' => ['$ref' => '#/components/responses/Unauthenticated'],
                '404' => ['$ref' => '#/components/responses/NotFound'],
                '422' => ['$ref' => '#/components/responses/ValidationError'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function buildPatchOperation(ModelMetadata $meta, string $slug, string $tag, string $pkParam, array $security): array
    {
        $model = $meta->model;

        return [
            'operationId' => "{$slug}.patch",
            'summary' => "Partially update a {$model}",
            'description' => "Updates only the supplied fields of an existing {$model} (PATCH semantics).",
            'tags' => [$tag],
            'security' => $security,
            'parameters' => [$this->pkParameter($pkParam, $meta)],
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'allOf' => [
                                ['$ref' => "#/components/schemas/{$model}Request"],
                                ['required' => []],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => [
                '200' => [
                    'description' => "{$model} partially updated",
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => "#/components/schemas/{$model}Resource"],
                        ],
                    ],
                ],
                '401' => ['$ref' => '#/components/responses/Unauthenticated'],
                '404' => ['$ref' => '#/components/responses/NotFound'],
                '422' => ['$ref' => '#/components/responses/ValidationError'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function buildDestroyOperation(ModelMetadata $meta, string $slug, string $tag, string $pkParam, array $security): array
    {
        $model = $meta->model;

        $description = $meta->softDeletes
            ? "Soft-deletes the {$model}. Use DELETE /force to permanently remove."
            : "Permanently deletes the {$model} record.";

        return [
            'operationId' => "{$slug}.destroy",
            'summary' => "Delete a {$model}",
            'description' => $description,
            'tags' => [$tag],
            'security' => $security,
            'parameters' => [$this->pkParameter($pkParam, $meta)],
            'responses' => [
                '204' => ['description' => 'No Content — deleted successfully'],
                '401' => ['$ref' => '#/components/responses/Unauthenticated'],
                '404' => ['$ref' => '#/components/responses/NotFound'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function buildRestoreOperation(ModelMetadata $meta, string $slug, string $tag, string $pkParam, array $security): array
    {
        $model = $meta->model;

        return [
            'operationId' => "{$slug}.restore",
            'summary' => "Restore a soft-deleted {$model}",
            'description' => "Restores a previously soft-deleted {$model} record.",
            'tags' => [$tag],
            'security' => $security,
            'parameters' => [$this->pkParameter($pkParam, $meta)],
            'responses' => [
                '200' => [
                    'description' => "{$model} restored",
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => "#/components/schemas/{$model}Resource"],
                        ],
                    ],
                ],
                '401' => ['$ref' => '#/components/responses/Unauthenticated'],
                '404' => ['$ref' => '#/components/responses/NotFound'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function buildForceDeleteOperation(ModelMetadata $meta, string $slug, string $tag, string $pkParam, array $security): array
    {
        $model = $meta->model;

        return [
            'operationId' => "{$slug}.forceDelete",
            'summary' => "Permanently delete a {$model}",
            'description' => "Permanently removes the {$model} record. Cannot be undone.",
            'tags' => [$tag],
            'security' => $security,
            'parameters' => [$this->pkParameter($pkParam, $meta)],
            'responses' => [
                '204' => ['description' => 'No Content — permanently deleted'],
                '401' => ['$ref' => '#/components/responses/Unauthenticated'],
                '404' => ['$ref' => '#/components/responses/NotFound'],
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Parameter helpers
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    protected function pkParameter(string $pkParam, ModelMetadata $meta): array
    {
        $pkCol = collect($meta->columns)->firstWhere('name', $meta->primaryKey ?? 'id');
        $type = ($pkCol && str_contains(strtolower($pkCol['type'] ?? ''), 'uuid'))
            ? ['type' => 'string', 'format' => 'uuid']
            : ['type' => 'integer', 'format' => 'int64'];

        return [
            'name' => $pkParam,
            'in' => 'path',
            'required' => true,
            'description' => "The {$meta->model} primary key",
            'schema' => $type,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    protected function paginationParameters(): array
    {
        $default = (int) config('anvil.api.pagination', 15);

        return [
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
                    'maximum' => 100,
                    'default' => max(1, $default),
                ],
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    protected function pkParamName(ModelMetadata $meta): string
    {
        return $meta->primaryKey ?? 'id';
    }
}
