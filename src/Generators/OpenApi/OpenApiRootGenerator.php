<?php

namespace Zuqongtech\LaravelAnvil\Generators\OpenApi;

use Illuminate\Support\Str;
use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;
use Zuqongtech\LaravelAnvil\Support\OpenApiTypeMapper;
use Zuqongtech\LaravelAnvil\Support\OpenApiYamlSerializer;

/**
 * Assembles the root OpenAPI 3.1 specification document.
 *
 * Responsibilities:
 *  1. Builds the root openapi.yaml / openapi.json with info, servers,
 *     security schemes, and shared component responses (Unauthenticated,
 *     ValidationError, NotFound, PaginationMeta, PaginationLinks).
 *
 *  2. In split-file mode: writes $refs to paths/* and schemas/* files
 *     so the root document stays lean and each resource is independently
 *     editable.
 *
 *  3. In single-file mode: merges all schemas and paths collected from
 *     OpenApiSchemaGenerator and OpenApiPathGenerator into one document.
 *
 *  4. Optionally publishes a zero-dependency Swagger UI to public/docs/
 *     when --openapi-ui is passed.
 *
 * This generator is special: it does not process one table at a time.
 * The orchestrator calls generate() per model to collect data, then
 * the command calls finalize() once after all models are processed.
 * If finalize() is never called (e.g. dry-run), the root file is not
 * written — only the per-model files.
 */
final class OpenApiRootGenerator implements Generator
{
    /** @var array<string, array<string, mixed>> Accumulated schemas (single-file mode) */
    private array $mergedSchemas = [];

    /** @var array<string, array<string, mixed>> Accumulated paths (single-file mode) */
    private array $mergedPaths = [];

    /** @var list<string> Tags collected across all models */
    private array $collectedTags = [];

    public function __construct(
        private readonly OpenApiSchemaGenerator $schemaGenerator,
        private readonly OpenApiPathGenerator   $pathGenerator,
        private readonly OpenApiYamlSerializer  $serializer = new OpenApiYamlSerializer,
    ) {}

    public function supports(GenerationOptions $options): bool
    {
        return $options->openApi ?? false;
    }

    public function getName(): string
    {
        return 'OpenApiRoot';
    }

    /**
     * Per-model pass: delegates to schema + path generators and
     * collects data for single-file merge if needed.
     */
    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $splitFiles = config('laravel-anvil.openapi.split_files', true);
        $results    = [];

        // Collect tag
        $this->collectedTags[] = Str::headline(Str::plural($meta->model));

        // Schema generation
        $schemaResults = $this->schemaGenerator->generate($meta, $options);
        foreach ((array) $schemaResults as $result) {
            if (! $splitFiles && isset($result['schema_key'])) {
                $this->mergedSchemas[$result['schema_key']] = $result['schema_def'];
            }
            $results[] = $result;
        }

        // Path generation
        $pathResults = $this->pathGenerator->generate($meta, $options);
        foreach ((array) $pathResults as $result) {
            if (! $splitFiles && isset($result['path_key'])) {
                $this->mergedPaths[$result['path_key']] = $result['path_def'];
            }
            $results[] = $result;
        }

        return $results;
    }

    /**
     * Finalization pass — called once after all models are processed.
     * Writes the root spec file and optionally publishes Swagger UI.
     */
    public function finalize(GenerationOptions $options): array
    {
        if ($options->dryRun) {
            return [['type' => $this->getName(), 'name' => 'openapi.yaml', 'status' => 'dry-run']];
        }

        $format     = config('laravel-anvil.openapi.format', 'yaml');
        $splitFiles = config('laravel-anvil.openapi.split_files', true);
        $outputPath = base_path(config('laravel-anvil.openapi.output_path', 'openapi'));
        $ext        = $format === 'json' ? 'json' : 'yaml';
        $rootPath   = "{$outputPath}/openapi.{$ext}";

        $spec = $splitFiles
            ? $this->buildSplitRootSpec($outputPath, $ext)
            : $this->buildMergedSpec();

        $this->serializer->writeFile($spec, $rootPath, $format);

        $results = [
            [
                'type'   => $this->getName(),
                'name'   => "openapi.{$ext}",
                'path'   => $rootPath,
                'status' => 'success',
            ],
        ];

        // Swagger UI
        if (config('laravel-anvil.openapi.publish_ui', false) || ($options->openApiUi ?? false)) {
            $uiResult = $this->publishSwaggerUi($rootPath, $outputPath);
            $results[] = $uiResult;
        }

        return $results;
    }

    // -----------------------------------------------------------------------
    // Root spec builders
    // -----------------------------------------------------------------------

    /**
     * Build a root spec with $ref links to split files.
     *
     * @return array<string, mixed>
     */
    protected function buildSplitRootSpec(string $outputPath, string $ext): array
    {
        $spec = $this->baseSpec();

        // Scan and reference schema files
        $schemaFiles = glob("{$outputPath}/schemas/*.{$ext}") ?: [];
        foreach ($schemaFiles as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $spec['components']['schemas'][$name] = [
                '$ref' => "./schemas/{$name}.{$ext}",
            ];
        }

        // Add shared component schemas
        $spec['components']['schemas'] = array_merge(
            $spec['components']['schemas'] ?? [],
            $this->sharedSchemas(),
        );

        // Scan and reference path files
        $pathFiles = glob("{$outputPath}/paths/*.{$ext}") ?: [];
        foreach ($pathFiles as $file) {
            // Each path file contains { "/api/v1/posts": { get: ..., post: ... } }
            // We reference the entire file
            $name = pathinfo($file, PATHINFO_FILENAME);
            $spec['paths']["./{$name}.{$ext}"] = [
                '$ref' => "./paths/{$name}.{$ext}",
            ];
        }

        return $spec;
    }

    /**
     * Build a fully merged single-file spec.
     *
     * @return array<string, mixed>
     */
    protected function buildMergedSpec(): array
    {
        $spec = $this->baseSpec();

        // Merge all collected schemas
        $spec['components']['schemas'] = array_merge(
            $this->mergedSchemas,
            $this->sharedSchemas(),
        );

        // Merge all collected paths
        $spec['paths'] = $this->mergedPaths;

        return $spec;
    }

    /**
     * The base spec skeleton with info, servers, security, tags, and responses.
     *
     * @return array<string, mixed>
     */
    protected function baseSpec(): array
    {
        $title    = config('laravel-anvil.openapi.title', config('app.name', 'Laravel API'));
        $version  = config('laravel-anvil.openapi.api_version', 'v1');
        $appUrl   = config('laravel-anvil.openapi.api_url', config('app.url', 'http://localhost'));
        $security = config('laravel-anvil.openapi.security', 'sanctum');

        $securitySchemes = [];
        if ($security === 'sanctum') {
            $securitySchemes['sanctum'] = [
                'type'         => 'http',
                'scheme'       => 'bearer',
                'bearerFormat' => 'Token',
                'description'  => 'Laravel Sanctum bearer token. Obtain via POST /api/v1/login.',
            ];
        } elseif ($security === 'passport') {
            $securitySchemes['passport'] = [
                'type'        => 'oauth2',
                'description' => 'Laravel Passport OAuth2',
                'flows'       => [
                    'password' => [
                        'tokenUrl' => "{$appUrl}/oauth/token",
                        'scopes'   => [],
                    ],
                ],
            ];
        }

        $tags = array_values(array_unique($this->collectedTags));
        $tagObjects = array_map(fn ($t) => ['name' => $t], $tags);

        return [
            'openapi' => '3.1.0',
            'info'    => [
                'title'       => $title,
                'version'     => $version,
                'description' => "Auto-generated OpenAPI 3.1 specification for the {$title} API.\n\nGenerated by [zuqongtech/laravel-anvil](https://github.com/zuqongtech/laravel-anvil).",
                'contact'     => [
                    'name'  => config('laravel-anvil.openapi.contact_name', ''),
                    'email' => config('laravel-anvil.openapi.contact_email', ''),
                ],
                'license' => [
                    'name' => 'MIT',
                ],
            ],
            'servers' => [
                [
                    'url'         => "{$appUrl}/api/{$version}",
                    'description' => 'Primary API server',
                ],
            ],
            'tags'       => $tagObjects,
            'paths'      => [],
            'components' => [
                'securitySchemes' => $securitySchemes,
                'schemas'         => [],
                'responses'       => $this->sharedResponses(),
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Shared reusable components
    // -----------------------------------------------------------------------

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function sharedSchemas(): array
    {
        return [
            'PaginationMeta'  => OpenApiTypeMapper::paginationMetaSchema(),
            'PaginationLinks' => OpenApiTypeMapper::paginationLinksSchema(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function sharedResponses(): array
    {
        return [
            'Unauthenticated' => [
                'description' => 'Unauthenticated — missing or invalid credentials',
                'content'     => [
                    'application/json' => [
                        'schema' => OpenApiTypeMapper::unauthenticatedSchema(),
                    ],
                ],
            ],
            'ValidationError' => [
                'description' => 'Unprocessable Entity — validation failed',
                'content'     => [
                    'application/json' => [
                        'schema' => OpenApiTypeMapper::validationErrorSchema(),
                    ],
                ],
            ],
            'NotFound' => [
                'description' => 'Not Found — the requested resource does not exist',
                'content'     => [
                    'application/json' => [
                        'schema' => OpenApiTypeMapper::notFoundSchema(),
                    ],
                ],
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Swagger UI publisher
    // -----------------------------------------------------------------------

    protected function publishSwaggerUi(string $specPath, string $outputPath): array
    {
        $publicDir  = public_path('docs');
        $htmlPath   = "{$publicDir}/index.html";
        $relSpecPath = '../' . ltrim(str_replace(base_path(), '', $specPath), '/');

        if (! is_dir($publicDir)) {
            mkdir($publicDir, 0755, true);
        }

        $html = $this->buildSwaggerUiHtml($relSpecPath);
        file_put_contents($htmlPath, $html);

        return [
            'type'   => 'SwaggerUI',
            'name'   => 'public/docs/index.html',
            'path'   => $htmlPath,
            'status' => 'success',
            'url'    => config('app.url') . '/docs',
        ];
    }

    protected function buildSwaggerUiHtml(string $specUrl): string
    {
        $title   = config('laravel-anvil.openapi.title', config('app.name', 'API Docs'));
        $version = '5.17.14'; // Swagger UI CDN version

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{$title} — API Documentation</title>
  <meta name="description" content="Interactive API documentation powered by Swagger UI" />
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@{$version}/swagger-ui.css" />
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
    #swagger-ui .topbar { display: none; }
    .custom-header {
      background: #1a1a2e;
      color: #fff;
      padding: 16px 24px;
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 18px;
      font-weight: 600;
      letter-spacing: 0.5px;
    }
    .custom-header span { opacity: 0.6; font-size: 13px; font-weight: 400; }
  </style>
</head>
<body>
  <div class="custom-header">
    ⚒ {$title} <span>API Documentation — generated by zuqongtech/laravel-anvil</span>
  </div>
  <div id="swagger-ui"></div>
  <script src="https://unpkg.com/swagger-ui-dist@{$version}/swagger-ui-bundle.js"></script>
  <script src="https://unpkg.com/swagger-ui-dist@{$version}/swagger-ui-standalone-preset.js"></script>
  <script>
    window.onload = () => {
      SwaggerUIBundle({
        url: '{$specUrl}',
        dom_id: '#swagger-ui',
        presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
        layout: 'StandaloneLayout',
        deepLinking: true,
        displayRequestDuration: true,
        defaultModelsExpandDepth: 2,
        defaultModelExpandDepth: 2,
        tryItOutEnabled: true,
        filter: true,
        syntaxHighlight: { activate: true, theme: 'agate' },
      });
    };
  </script>
</body>
</html>
HTML;
    }
}