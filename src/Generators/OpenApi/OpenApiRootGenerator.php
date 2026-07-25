<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Generators\OpenApi;

use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Generators\OpenApi\Concerns\ResolvesSpecOptions;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;
use Zuqongtech\LaravelAnvil\Support\OpenApiLocator;
use Zuqongtech\LaravelAnvil\Support\OpenApiTypeMapper;
use Zuqongtech\LaravelAnvil\Support\OpenApiYamlSerializer;

/**
 * Assembles the root OpenAPI 3.1 specification document.
 *
 * Per-model pass (generate) drives the schema + path generators. The
 * finalization pass (finalize) writes the root document once and optionally
 * publishes Swagger UI.
 *
 * Split-file mode references external files via JSON-Pointer fragments that
 * target the real inner key of each file; single-file mode inlines everything.
 *
 * Everything is written under the version directory resolved by OpenApiLocator,
 * so v1 and v2 specs coexist and are served independently.
 */
final class OpenApiRootGenerator implements Generator
{
    use ResolvesSpecOptions;

    /** @var array<string, array<string, mixed>> Accumulated schemas (single-file mode) */
    private array $mergedSchemas = [];

    /** @var array<string, array<string, mixed>> Accumulated paths (single-file mode) */
    private array $mergedPaths = [];

    /** @var list<string> Tags collected across all models */
    private array $collectedTags = [];

    /**
     * Dependencies are default-constructible so this generator works whether it
     * is resolved through the container (autowired) or built with a bare `new`.
     */
    public function __construct(
        private readonly OpenApiSchemaGenerator $schemaGenerator = new OpenApiSchemaGenerator,
        private readonly OpenApiPathGenerator $pathGenerator = new OpenApiPathGenerator,
        private readonly OpenApiYamlSerializer $serializer = new OpenApiYamlSerializer,
    ) {}

    #[\Override]
    public function supports(GenerationOptions $options): bool
    {
        return $this->specEnabled($options);
    }

    #[\Override]
    public function getName(): string
    {
        return 'OpenApiRoot';
    }

    #[\Override]
    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $splitFiles = $this->splitFiles();
        $results = [];

        $this->collectedTags[] = Str::headline(Str::plural($meta->model));

        $schemaResults = $this->schemaGenerator->generate($meta, $options);
        foreach ($schemaResults as $result) {
            if (! $splitFiles && isset($result['schema_key'])) {
                $this->mergedSchemas[$result['schema_key']] = $result['schema_def'];
            }
            $results[] = $result;
        }

        $pathResults = $this->pathGenerator->generate($meta, $options);
        foreach ($pathResults as $result) {
            if (! $splitFiles && isset($result['path_key'])) {
                $this->mergedPaths[$result['path_key']] = $result['path_def'];
            }
            $results[] = $result;
        }

        return $results;
    }

    // -----------------------------------------------------------------------
    // Finalization — write the root spec once, after every model is processed
    // -----------------------------------------------------------------------

    public function finalize(GenerationOptions $options): array
    {
        if (! $this->specEnabled($options)) {
            return [];
        }

        $format = OpenApiLocator::format();
        $splitFiles = $this->splitFiles();
        $version = OpenApiLocator::configuredVersion();
        $outputPath = OpenApiLocator::specDir($version);
        $rootFile = OpenApiLocator::specFile($version, $format);

        if ($this->isDryRun($options)) {
            return [[
                'type' => $this->getName(),
                'name' => "{$version}/openapi.{$format}",
                'path' => $rootFile,
                'status' => 'dry-run',
            ]];
        }

        $spec = $splitFiles
            ? $this->buildSplitRootSpec($outputPath, $format, $version)
            : $this->buildMergedSpec($version);

        $this->serializer->writeFile($spec, $rootFile, $format);

        $results = [[
            'type' => $this->getName(),
            'name' => "{$version}/openapi.{$format}",
            'path' => $rootFile,
            'status' => 'success',
        ]];

        if ($this->publishesUi($options)) {
            $results[] = $this->publishSwaggerUi($version);
        }

        return $results;
    }

    // -----------------------------------------------------------------------
    // Root spec builders
    // -----------------------------------------------------------------------

    /**
     * Build a root spec that references split files via JSON-Pointer fragments.
     *
     * The refs stay relative ("./schemas/…"), which remains correct inside the
     * version directory.
     *
     * @return array<string, mixed>
     */
    protected function buildSplitRootSpec(string $outputPath, string $ext, string $version): array
    {
        $spec = $this->baseSpec($version);

        foreach (glob("{$outputPath}/schemas/*.{$ext}") ?: [] as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);

            foreach ($this->topLevelKeys($file, $ext) as $schemaKey) {
                $spec['components']['schemas'][$schemaKey] = [
                    '$ref' => "./schemas/{$name}.{$ext}#/{$this->pointer($schemaKey)}",
                ];
            }
        }

        $spec['components']['schemas'] = array_merge(
            $spec['components']['schemas'] ?? [],
            $this->sharedSchemas(),
        );

        foreach (glob("{$outputPath}/paths/*.{$ext}") ?: [] as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);

            foreach ($this->topLevelKeys($file, $ext) as $pathKey) {
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
    protected function buildMergedSpec(string $version): array
    {
        $spec = $this->baseSpec($version);

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
    protected function baseSpec(string $version): array
    {
        $title = config('anvil.openapi.title') ?: config('app.name', 'Laravel API');
        $description = config('anvil.openapi.description')
            ?: "Auto-generated OpenAPI 3.1 specification for the {$title} API ({$version})."
            ."\n\nGenerated by [zuqongtech/laravel-anvil](https://github.com/zuqongtech/laravel-anvil).";

        $tags = array_values(array_unique($this->collectedTags));
        $tagObjects = array_map(static fn (string $t): array => ['name' => $t], $tags);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => $title,
                'version' => $version,
                'description' => $description,
                'contact' => [
                    'name' => config('anvil.openapi.contact_name', ''),
                    'email' => config('anvil.openapi.contact_email', ''),
                ],
                'license' => [
                    'name' => 'MIT',
                ],
            ],
            'servers' => $this->buildServers($version),
            'tags' => $tagObjects,
            'paths' => [],
            'components' => [
                'securitySchemes' => $this->buildSecuritySchemes(),
                'schemas' => [],
                'responses' => $this->sharedResponses(),
            ],
        ];
    }

    /**
     * Honour --server when supplied, otherwise derive the versioned base URL.
     *
     * @return list<array<string, string>>
     */
    protected function buildServers(string $version): array
    {
        $configured = array_filter((array) config('anvil.openapi.servers', []));

        if ($configured !== []) {
            return array_values(array_map(
                static fn ($url): array => ['url' => rtrim((string) $url, '/')],
                $configured,
            ));
        }

        return [[
            'url' => OpenApiLocator::appUrl().OpenApiLocator::apiBasePath($version),
            'description' => "Primary API server ({$version})",
        ]];
    }

    /**
     * Mirrors the --auth / --security mapping in GenerateOpenApiCommand.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function buildSecuritySchemes(): array
    {
        $security = (string) config('anvil.openapi.security', 'sanctum');
        $appUrl = OpenApiLocator::appUrl();
        $base = OpenApiLocator::apiBasePath();

        return match ($security) {
            'sanctum' => [
                'sanctum' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'Token',
                    'description' => "Laravel Sanctum bearer token. Obtain via POST {$base}/login.",
                ],
            ],
            'passport' => [
                'passport' => [
                    'type' => 'oauth2',
                    'description' => 'Laravel Passport OAuth2',
                    'flows' => [
                        'password' => [
                            'tokenUrl' => "{$appUrl}/oauth/token",
                            'scopes' => [],
                        ],
                    ],
                ],
            ],
            'bearer' => [
                'bearer' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'JWT',
                    'description' => 'JWT bearer token.',
                ],
            ],
            'apikey' => [
                'apikey' => [
                    'type' => 'apiKey',
                    'in' => 'header',
                    'name' => 'X-API-Key',
                    'description' => 'Static API key sent in the X-API-Key header.',
                ],
            ],
            default => [],
        };
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

        return is_array($data) ? array_map(strval(...), array_keys($data)) : [];
    }

    /**
     * Escape a key for use as a JSON Pointer reference fragment.
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

    /**
     * @return array<string, string>
     */
    protected function publishSwaggerUi(string $version): array
    {
        $publicDir = OpenApiLocator::publicDocsDir($version);
        $htmlPath = "{$publicDir}/index.html";

        // Every version on disk, plus the one just written, so the UI's version
        // dropdown stays accurate without regenerating the older bundles.
        $versions = array_values(array_unique([...OpenApiLocator::availableVersions(), $version]));
        usort($versions, static fn (string $a, string $b): int => (int) ltrim($a, 'v') <=> (int) ltrim($b, 'v'));

        $urls = array_map(static fn (string $v): array => [
            'name' => $v,
            // Root-relative, NOT absolute. A static HTML file cannot know the
            // request host, so an absolute URL built from config('app.url')
            // bakes in whatever that happens to be — "http://localhost" while
            // the app is served on 127.0.0.1:3053, which fails to fetch and
            // trips CORS. The page resolves these against
            // window.location.origin at load time instead.
            'path' => '/'.OpenApiLocator::docsRoute($v).'/openapi.'.OpenApiLocator::format(),
        ], $versions);

        if (! is_dir($publicDir)) {
            mkdir($publicDir, 0755, true);
        }

        file_put_contents($htmlPath, $this->buildSwaggerUiHtml($urls, $version));

        return [
            'type' => 'SwaggerUI',
            'name' => ltrim(str_replace(public_path(), '', $htmlPath), '/'),
            'path' => $htmlPath,
            'status' => 'success',
            'url' => OpenApiLocator::docsUrl($version),
        ];
    }

    /**
     * The spec is loaded from the route DocsController serves, resolved against
     * the browser's own origin at load time — so the published bundle works on
     * localhost, 127.0.0.1:3053 and production without regeneration.
     *
     * Requires anvil.openapi.docs.enabled, since the spec itself comes from the
     * dynamic route (only that route bundles the split $ref files).
     *
     * @param  list<array{name: string, path: string}>  $urls
     */
    protected function buildSwaggerUiHtml(array $urls, string $primary): string
    {
        $title = config('anvil.openapi.title') ?: config('app.name', 'API Docs');
        $uiVersion = (string) config('anvil.openapi.docs.ui_version', '5.17.14');
        $urlsJson = json_encode($urls, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
          <meta charset="UTF-8" />
          <meta name="viewport" content="width=device-width, initial-scale=1.0" />
          <title>{$title} — API Documentation ({$primary})</title>
          <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@{$uiVersion}/swagger-ui.css" />
          <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
            #swagger-ui .topbar { background: #16213e; }
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
            ⚒ {$title} <span>API Documentation — {$primary} — generated by zuqongtech/laravel-anvil</span>
          </div>
          <div id="swagger-ui"></div>
          <script src="https://unpkg.com/swagger-ui-dist@{$uiVersion}/swagger-ui-bundle.js"></script>
          <script src="https://unpkg.com/swagger-ui-dist@{$uiVersion}/swagger-ui-standalone-preset.js"></script>
          <script>
            window.onload = () => {
              // Resolved here, not at generation time: the same published file
              // then works on any host or port.
              const origin = window.location.origin;
              const urls = {$urlsJson}.map(d => ({ name: d.name, url: origin + d.path }));

              SwaggerUIBundle({
                urls: urls,
                "urls.primaryName": '{$primary}',
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
