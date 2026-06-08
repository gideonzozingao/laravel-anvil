<?php

namespace Zuqongtech\LaravelAnvil\Generators\OpenApi;

use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;
use Zuqongtech\LaravelAnvil\Support\OpenApiTypeMapper;

/**
 * Assembles the root OpenAPI 3.1 specification document.
 *
 * Per-model pass (generate) collects schemas + paths via the schema/path
 * generators. The finalization pass (finalize) writes the root document once
 * and optionally publishes Swagger UI.
 *
 * Split-file mode references external files via JSON-Pointer fragments that
 * target the real inner key of each file (the schema name, or the URL path),
 * so both schemas and operations resolve correctly in Swagger UI. Single-file
 * mode inlines everything into one self-contained document.
 */
final class OpenApiRootGenerator implements Generator
{
    /** @var array<string, array<string, mixed>> Accumulated schemas (single-file mode) */
    private array $mergedSchemas = [];

    /** @var array<string, array<string, mixed>> Accumulated paths (single-file mode) */
    private array $mergedPaths = [];

    /** @var list<string> Tags collected across all models */
    private array $collectedTags = [];

    #[\Override]
    public function supports(GenerationOptions $options): bool
    {
        return $options->openApi ?? false;
    }

    #[\Override]
    public function getName(): string
    {
        return 'OpenApiRoot';
    }

    #[\Override]
    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $splitFiles = config('anvil.openapi.split_files', true);
        $results = [];

        $this->collectedTags[] = Str::headline(Str::plural($meta->model));

        $schemaResults = $this->schemaGenerator->generate($meta, $options);
        foreach ((array) $schemaResults as $result) {
            if (! $splitFiles && isset($result['schema_key'])) {
                $this->mergedSchemas[$result['schema_key']] = $result['schema_def'];
            }
            $results[] = $result;
        }

        $pathResults = $this->pathGenerator->generate($meta, $options);
        foreach ((array) $pathResults as $result) {
            if (! $splitFiles && isset($result['path_key'])) {
                $this->mergedPaths[$result['path_key']] = $result['path_def'];
            }
            $results[] = $result;
        }

        return $results;
    }

    // -----------------------------------------------------------------------
    // Root spec builders
    // -----------------------------------------------------------------------

    /**
     * Build a root spec that references split files via JSON-Pointer fragments.
     *
     * Each schema file (e.g. schemas/Post.yaml) wraps its definition under a
     * top-level key (`Post:`); each path file (e.g. paths/api_v1_posts.yaml)
     * wraps operations under the real URL path (`/api/v1/posts:`). We read those
     * real keys back off disk and reference them precisely:
     *
     *   components.schemas.Post:
     *     $ref: './schemas/Post.yaml#/Post'
     *
     *   paths./api/v1/posts:
     *     $ref: './paths/api_v1_posts.yaml#/~1api~1v1~1posts'
     *
     * @return array<string, mixed>
     */
    protected function buildSplitRootSpec(string $outputPath, string $ext): array
    {
        $spec = $this->baseSpec();

        // ── Schema files → component schema refs (fragment-targeted) ─────────
        foreach (glob("{$outputPath}/schemas/*.{$ext}") ?: [] as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);

            foreach ($this->topLevelKeys($file, $ext) as $schemaKey) {
                $spec['components']['schemas'][$schemaKey] = [
                    '$ref' => "./schemas/{$name}.{$ext}#/{$this->pointer($schemaKey)}",
                ];
            }
        }

        // Shared component schemas have no external file — inline them.
        $spec['components']['schemas'] = array_merge(
            $spec['components']['schemas'] ?? [],
            $this->sharedSchemas(),
        );

        // ── Path files → path item refs keyed by the REAL URL path ──────────
        foreach (glob("{$outputPath}/paths/*.{$ext}") ?: [] as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);

            foreach ($this->topLevelKeys($file, $ext) as $pathKey) {
                // OpenAPI requires the map key to be the actual URL template.
                $spec['paths'][$pathKey] = [
                    '$ref' => "./paths/{$name}.{$ext}#/{$this->pointer($pathKey)}",
                ];
            }
        }

        return $spec;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildMergedSpec(): array
    {
        $spec = $this->baseSpec();

        $spec['components']['schemas'] = array_merge(
            $this->mergedSchemas,
            $this->sharedSchemas(),
        );

        $spec['paths'] = $this->mergedPaths;

        return $spec;
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseSpec(): array
    {
        $title = config('anvil.openapi.title', config('app.name', 'Laravel API'));
        $version = config('anvil.openapi.api_version', 'v1');
        $appUrl = config('anvil.openapi.api_url', config('app.url', 'http://localhost'));
        $security = config('anvil.openapi.security', 'sanctum');

        $securitySchemes = [];
        if ($security === 'sanctum') {
            $securitySchemes['sanctum'] = [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'Token',
                'description' => 'Laravel Sanctum bearer token. Obtain via POST /api/v1/login.',
            ];
        } elseif ($security === 'passport') {
            $securitySchemes['passport'] = [
                'type' => 'oauth2',
                'description' => 'Laravel Passport OAuth2',
                'flows' => [
                    'password' => [
                        'tokenUrl' => "{$appUrl}/oauth/token",
                        'scopes' => [],
                    ],
                ],
            ];
        }

        $tags = array_values(array_unique($this->collectedTags));
        $tagObjects = array_map(fn ($t) => ['name' => $t], $tags);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => $title,
                'version' => $version,
                'description' => "Auto-generated OpenAPI 3.1 specification for the {$title} API.\n\nGenerated by [zuqongtech/laravel-anvil](https://github.com/zuqongtech/laravel-anvil).",
                'contact' => [
                    'name' => config('anvil.openapi.contact_name', ''),
                    'email' => config('anvil.openapi.contact_email', ''),
                ],
                'license' => [
                    'name' => 'MIT',
                ],
            ],
            'servers' => [
                [
                    'url' => "{$appUrl}/api/{$version}",
                    'description' => 'Primary API server',
                ],
            ],
            'tags' => $tagObjects,
            'paths' => [],
            'components' => [
                'securitySchemes' => $securitySchemes,
                'schemas' => [],
                'responses' => $this->sharedResponses(),
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Split-file ref helpers
    // -----------------------------------------------------------------------

    /**
     * Read the top-level keys of a generated split file (schema name or URL path).
     *
     * @return list<string>
     */
    protected function topLevelKeys(string $file, string $ext): array
    {
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return [];
        }

        $data = $ext === 'json'
            ? (json_decode($raw, true) ?: [])
            : (Yaml::parse($raw) ?: []);

        return is_array($data) ? array_map('strval', array_keys($data)) : [];
    }

    /**
     * Escape a key for use as a JSON Pointer reference fragment.
     * RFC 6901: "~" → "~0", "/" → "~1".
     */
    protected function pointer(string $key): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $key);
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
            'PaginationMeta' => OpenApiTypeMapper::paginationMetaSchema(),
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
                'content' => [
                    'application/json' => [
                        'schema' => OpenApiTypeMapper::unauthenticatedSchema(),
                    ],
                ],
            ],
            'ValidationError' => [
                'description' => 'Unprocessable Entity — validation failed',
                'content' => [
                    'application/json' => [
                        'schema' => OpenApiTypeMapper::validationErrorSchema(),
                    ],
                ],
            ],
            'NotFound' => [
                'description' => 'Not Found — the requested resource does not exist',
                'content' => [
                    'application/json' => [
                        'schema' => OpenApiTypeMapper::notFoundSchema(),
                    ],
                ],
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Swagger UI publisher (static file mode — see DocsController for serving)
    // -----------------------------------------------------------------------

    protected function publishSwaggerUi(string $specPath, string $outputPath): array
    {
        $publicDir = public_path('docs');
        $htmlPath = "{$publicDir}/index.html";
        $relSpecPath = '../'.ltrim(str_replace(base_path(), '', $specPath), '/');

        if (! is_dir($publicDir)) {
            mkdir($publicDir, 0755, true);
        }

        file_put_contents($htmlPath, $this->buildSwaggerUiHtml($relSpecPath));

        return [
            'type' => 'SwaggerUI',
            'name' => 'public/docs/index.html',
            'path' => $htmlPath,
            'status' => 'success',
            'url' => config('app.url').'/docs',
        ];
    }

    protected function buildSwaggerUiHtml(string $specUrl): string
    {
        $title = config('anvil.openapi.title', config('app.name', 'API Docs'));
        $version = '5.17.14';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{$title} — API Documentation</title>
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@{$version}/swagger-ui.css" />
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
    #swagger-ui .topbar { display: none; }
    .custom-header {
      background: #1a1a2e; color: #fff; padding: 16px 24px;
      display: flex; align-items: center; gap: 12px;
      font-size: 18px; font-weight: 600; letter-spacing: 0.5px;
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
