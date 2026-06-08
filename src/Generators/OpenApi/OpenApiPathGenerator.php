<?php

namespace Zuqongtech\LaravelAnvil\Generators\OpenApi;

use Illuminate\Support\Str;
use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\Helpers;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;
use Zuqongtech\LaravelAnvil\Support\OpenApiYamlSerializer;

/**
 * Generates OpenAPI 3.1 path definitions for a model's REST endpoints.
 *
 * For each model two path files are written:
 *
 *  openapi/paths/{slug}.yaml           →  GET  /v1/{slug}     (index)
 *                                         POST /v1/{slug}     (store)
 *
 *  openapi/paths/{slug}_{id}.yaml      →  GET    /v1/{slug}/{id}   (show)
 *                                         PUT    /v1/{slug}/{id}   (update)
 *                                         DELETE /v1/{slug}/{id}   (destroy)
 *
 * When soft-deletes are detected, two additional paths are written:
 *
 *  openapi/paths/{slug}_{id}_restore.yaml   →  PATCH /v1/{slug}/{id}/restore
 *  openapi/paths/{slug}_{id}_force.yaml     →  DELETE /v1/{slug}/{id}/force
 *
 * Path parameters, security schemes, and response $refs are all inferred
 * from the model metadata and the package config.
 *
 * Single-file mode: returns raw path arrays instead of writing files,
 * to be merged by OpenApiRootGenerator.
 */
final class OpenApiPathGenerator implements Generator
{
    public function __construct(
        private readonly OpenApiYamlSerializer $serializer = new OpenApiYamlSerializer,
    ) {}

    public function supports(GenerationOptions $options): bool
    {
        return $options->openApi ?? false;
    }

    public function getName(): string
    {
        return 'OpenApiPath';
    }

    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $format = config('anvil.openapi.format', 'yaml');
        $splitFiles = config('anvil.openapi.split_files', true);
        $outputPath = base_path(config('anvil.openapi.output_path', 'openapi'));
        $version = config('anvil.openapi.api_version', config('laravel-anvil.api_version', 'v1'));
        $security = config('anvil.openapi.security', 'sanctum');

        $slug = Str::plural(Str::kebab($meta->model));
        $pkParam = $this->pkParamName($meta);
        $tag = Str::headline(Str::plural($meta->model));

        $paths = $this->buildPaths($meta, $slug, $pkParam, $tag, $version, $security);
        $results = [];

        foreach ($paths as $pathKey => $pathDef) {
            $safeKey = str_replace(['/', '{', '}'], ['_', '', ''], ltrim($pathKey, '/'));

            if ($splitFiles) {
                $ext = $format === 'json' ? 'json' : 'yaml';
                $path = "{$outputPath}/paths/{$safeKey}.{$ext}";

                if (file_exists($path) && ! $options->force) {
                    $results[] = [
                        'type' => $this->getName(),
                        'name' => $pathKey,
                        'path' => $path,
                        'status' => 'skipped',
                        'reason' => 'already exists',
                    ];

                    continue;
                }

                if (! $options->dryRun) {
                    $this->serializer->writeFile([$pathKey => $pathDef], $path, $format);
                }

                $results[] = [
                    'type' => $this->getName(),
                    'name' => $pathKey,
                    'path' => $path,
                    'status' => 'success',
                ];
            } else {
                $results[] = [
                    'type' => $this->getName(),
                    'name' => $pathKey,
                    'status' => 'merged',
                    'path_key' => $pathKey,
                    'path_def' => $pathDef,
                ];
            }
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

        $collectionPath = "/api/{$version}/{$slug}";
        $itemPath = "/api/{$version}/{$slug}/{{$pkParam}}";

        $securityBlock = $security !== 'none'
            ? [[$security => []]]
            : [];

        // ── Collection: GET + POST ───────────────────────────────────────────
        $paths[$collectionPath] = [
            'get' => $this->buildIndexOperation($meta, $slug, $tag, $securityBlock),
            'post' => $this->buildStoreOperation($meta, $slug, $tag, $securityBlock),
        ];

        // ── Item: GET + PUT + PATCH + DELETE ─────────────────────────────────
        $paths[$itemPath] = [
            'get' => $this->buildShowOperation($meta, $slug, $tag, $pkParam, $securityBlock),
            'put' => $this->buildUpdateOperation($meta, $slug, $tag, $pkParam, $securityBlock),
            'patch' => $this->buildPatchOperation($meta, $slug, $tag, $pkParam, $securityBlock),
            'delete' => $this->buildDestroyOperation($meta, $slug, $tag, $pkParam, $securityBlock),
        ];

        // ── Soft-delete extras ────────────────────────────────────────────────
        if ($meta->softDeletes) {
            $restorePath = "/api/{$version}/{$slug}/{{$pkParam}}/restore";
            $forceDeletePath = "/api/{$version}/{$slug}/{{$pkParam}}/force";

            $paths[$restorePath] = [
                'patch' => $this->buildRestoreOperation($meta, $slug, $tag, $pkParam, $securityBlock),
            ];

            $paths[$forceDeletePath] = [
                'delete' => $this->buildForceDeleteOperation($meta, $slug, $tag, $pkParam, $securityBlock),
            ];
        }

        return $paths;
    }

    // ── Individual operations ─────────────────────────────────────────────────

    /** @return array<string, mixed> */
    protected function buildIndexOperation(
        ModelMetadata $meta,
        string $slug,
        string $tag,
        array $security,
    ): array {
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
    protected function buildStoreOperation(
        ModelMetadata $meta,
        string $slug,
        string $tag,
        array $security,
    ): array {
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
    protected function buildShowOperation(
        ModelMetadata $meta,
        string $slug,
        string $tag,
        string $pkParam,
        array $security,
    ): array {
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
    protected function buildUpdateOperation(
        ModelMetadata $meta,
        string $slug,
        string $tag,
        string $pkParam,
        array $security,
    ): array {
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
    protected function buildPatchOperation(
        ModelMetadata $meta,
        string $slug,
        string $tag,
        string $pkParam,
        array $security,
    ): array {
        $model = $meta->model;

        // Build a partial (all-optional) version of the request schema inline
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
                                ['required' => []],   // Override: no fields required for PATCH
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
    protected function buildDestroyOperation(
        ModelMetadata $meta,
        string $slug,
        string $tag,
        string $pkParam,
        array $security,
    ): array {
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
    protected function buildRestoreOperation(
        ModelMetadata $meta,
        string $slug,
        string $tag,
        string $pkParam,
        array $security,
    ): array {
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
    protected function buildForceDeleteOperation(
        ModelMetadata $meta,
        string $slug,
        string $tag,
        string $pkParam,
        array $security,
    ): array {
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
                'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 15],
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
