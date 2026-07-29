<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Zuqongtech\LaravelAnvil\Support\OpenApiLocator;

/**
 * Serves interactive API documentation (Swagger UI) and the underlying OpenAPI
 * specification, per API version.
 *
 * Routes (registered by LaravelAnvilServiceProvider when
 * config('anvil.openapi.docs.enabled') is true):
 *
 *   GET {prefix}                       → Swagger UI for the default version
 *   GET {prefix}/{version}             → Swagger UI for v1, v2, …
 *   GET {prefix}/{version}/openapi.yaml → the root spec, BUNDLED
 *   GET {prefix}/{version}/{file}      → any split $ref file, raw
 *
 * SPEC SOURCE — local disk by default.
 *
 *   The previous implementation fetched the spec over HTTP from
 *   config('app.url') . '/docs/{version}/…', i.e. it made an HTTP request to the
 *   very application serving the request. That fails whenever app.url does not
 *   match the address actually being served (http://localhost vs
 *   127.0.0.1:3053), fails again in CI and in any container without loopback
 *   HTTP, and forced the browser into a cross-origin fetch for the spec.
 *
 *   Files are now read from the version directory resolved by OpenApiLocator.
 *   Set anvil.openapi.docs.remote_base to a URL to restore remote sourcing for
 *   the genuine use case — a spec published to a CDN or another service.
 *
 * WHY BUNDLE THE ROOT SPEC
 *   In split-file mode the root references external files
 *   (./schemas/X.yaml#/X, ./paths/Y.yaml#/…). Operations inside those path files
 *   use absolute internal pointers like #/components/schemas/X, which only
 *   resolve against the document they live in — and a path file has no
 *   `components` section, so Swagger UI throws "JSON Pointer evaluation failed
 *   … 'components'". Bundling inlines the external files into one
 *   self-contained document so every #/components/… ref resolves. In
 *   single-file mode there are no external refs and bundling is a no-op.
 *
 * DOCS PAGE — Blade view, not an inline HTML string.
 *
 *   The Swagger UI shell used to be a PHP heredoc baked into this controller.
 *   That made three things impossible without editing package source: gating
 *   the docs route behind auth/a Gate (nothing stopped a fully static
 *   equivalent, but there was no *reason* to route through Laravel at all if
 *   the page never touched the framework), inheriting the host app's own
 *   layout/branding, and swapping the renderer (Redoc, Scalar, …) without a
 *   parallel controller method per renderer.
 *
 *   render() now resolves a view name from config, defaulting to the
 *   package's own 'anvil::docs.show'. A consumer runs
 *   `php artisan vendor:publish --tag=anvil-views` to get an editable copy
 *   under resources/views/vendor/anvil/docs/show.blade.php — Laravel's view
 *   finder prefers that copy automatically, no config change required.
 *
 *   anvil.openapi.docs.asset_base is now actually consumed here (previously
 *   InstallSwaggerUi told the operator to set it, but this controller ignored
 *   it and always pointed at the unpkg CDN regardless). When set, the view
 *   loads swagger-ui.css/js from that local path instead.
 */
class DocsController
{
    // -----------------------------------------------------------------------
    // Actions
    // -----------------------------------------------------------------------

    /**
     * Render the Swagger UI page for a version.
     */
    public function ui(Request $request, ?string $version = null): Response
    {
        $available = $this->availableVersions();

        if ($available === []) {
            return $this->noSpecResponse();
        }

        $requested = $version !== null
            ? OpenApiLocator::normaliseVersion($version)
            : $this->defaultVersion($available);

        if (! in_array($requested, $available, true)) {
            return $this->unknownVersionResponse($requested, $available);
        }

        $origin = $request->getSchemeAndHttpHost();

        // Built from the request's own host, never from app.url — that mismatch
        // is what produced the CORS failure.
        $urls = array_map(fn (string $v): array => [
            'name' => $v,
            'url' => $origin.'/'.OpenApiLocator::docsRoute($v).'/openapi.'.$this->formatFor($v),
        ], $available);

        return new Response(
            $this->render($urls, $requested),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    /**
     * Serve the spec. The root document is bundled; any other split file is
     * served raw, which is useful when debugging a broken $ref.
     */
    public function spec(Request $request, string $file): Response
    {
        [$version, $relative] = $this->splitVersion($file);

        if ($relative === '' || str_ends_with($relative, '/')) {
            $relative .= 'openapi.'.$this->formatFor($version);
        }

        if (! $this->isSafeRelativePath($relative)) {
            return new Response('Invalid spec path.', 400, ['Content-Type' => 'text/plain']);
        }

        $isJson = str_ends_with($relative, '.json');
        $format = $isJson ? 'json' : 'yaml';

        $body = basename($relative) === 'openapi.'.$format
            ? $this->bundle($version, $relative, $format)
            : $this->read($version, $relative);

        if ($body === null) {
            return $this->missingFileResponse($version, $relative);
        }

        return new Response($body, 200, [
            'Content-Type' => $isJson ? 'application/json' : 'application/yaml',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'no-cache',
        ]);
    }

    // -----------------------------------------------------------------------
    // Bundler
    // -----------------------------------------------------------------------

    /**
     * Inline first-level external file refs (./schemas/*, ./paths/*) into the
     * root document. Internal #/… refs are left alone: once their targets are
     * inlined they resolve within the single bundled document.
     */
    protected function bundle(string $version, string $rootRelative, string $format): ?string
    {
        $raw = $this->read($version, $rootRelative);

        if ($raw === null) {
            return null;
        }

        try {
            $root = $this->parse($raw, $format);

            if ($root === []) {
                return $raw;
            }

            $dir = str_contains($rootRelative, '/') ? dirname($rootRelative) : '';

            foreach (['components.schemas', 'paths'] as $section) {
                $target = $section === 'paths' ? ($root['paths'] ?? null) : ($root['components']['schemas'] ?? null);

                if (! is_array($target)) {
                    continue;
                }

                foreach ($target as $key => $def) {
                    $resolved = $this->resolveExternalRef($def, $version, $dir, $format);

                    if ($resolved === null) {
                        continue;
                    }

                    if ($section === 'paths') {
                        $root['paths'][$key] = $resolved;
                    } else {
                        $root['components']['schemas'][$key] = $resolved;
                    }
                }
            }

            return $this->dump($root, $format) ?: $raw;
        } catch (\Throwable) {
            // Never let bundling break the docs — fall back to the raw root.
            return $raw;
        }
    }

    /**
     * If $def is a lone external {$ref: './file#/pointer'}, read and return the
     * referenced sub-tree. Null for non-refs and internal #/ refs.
     *
     * @return array<mixed>|null
     */
    protected function resolveExternalRef(mixed $def, string $version, string $dir, string $format): ?array
    {
        if (! is_array($def) || ! isset($def['$ref']) || count($def) !== 1) {
            return null;
        }

        $ref = $def['$ref'];

        if (! is_string($ref) || $ref === '' || str_starts_with($ref, '#')) {
            return null;
        }

        [$relFile, $fragment] = array_pad(explode('#', $ref, 2), 2, '');
        $relFile = preg_replace('#^\./#', '', (string) $relFile) ?? '';

        if ($relFile === '') {
            return null;
        }

        $path = $dir !== '' && $dir !== '.' ? $dir.'/'.$relFile : $relFile;

        if (! $this->isSafeRelativePath($path)) {
            return null;
        }

        $raw = $this->read($version, $path);

        if ($raw === null) {
            return null;
        }

        $resolved = $this->pointerGet($this->parse($raw, $format), (string) $fragment);

        return is_array($resolved) ? $resolved : null;
    }

    /**
     * Resolve an RFC 6901 JSON Pointer against a parsed document.
     *
     * @param  array<mixed>  $data
     */
    protected function pointerGet(array $data, string $fragment): mixed
    {
        $fragment = ltrim($fragment, '/');

        if ($fragment === '') {
            return $data;
        }

        $cursor = $data;

        foreach (explode('/', $fragment) as $token) {
            $token = str_replace(['~1', '~0'], ['/', '~'], $token);

            if (is_array($cursor) && array_key_exists($token, $cursor)) {
                $cursor = $cursor[$token];
            } else {
                return null;
            }
        }

        return $cursor;
    }

    // -----------------------------------------------------------------------
    // Reading — local disk, or a remote base when configured
    // -----------------------------------------------------------------------

    /**
     * Read a spec file for a version. $relative may include the version segment
     * ("v1/openapi.yaml") or not ("schemas/User.yaml").
     */
    protected function read(string $version, string $relative): ?string
    {
        $relative = $this->stripVersionSegment($version, $relative);

        if (($base = $this->remoteBase()) !== null) {
            return $this->fetch(rtrim($base, '/').'/'.OpenApiLocator::normaliseVersion($version).'/'.$relative);
        }

        $dir = realpath(OpenApiLocator::specDir($version));

        if ($dir === false) {
            return null;
        }

        $path = realpath($dir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative));

        // Containment check: a resolved path must stay inside the spec directory.
        if ($path === false || ! str_starts_with($path, $dir.DIRECTORY_SEPARATOR) || ! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    protected function fetch(string $url): ?string
    {
        try {
            $response = Http::timeout((int) config('anvil.openapi.docs.remote_timeout', 5))->get($url);

            return $response->successful() ? $response->body() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function remoteBase(): ?string
    {
        $base = config('anvil.openapi.docs.remote_base');

        return is_string($base) && $base !== '' ? $base : null;
    }

    // -----------------------------------------------------------------------
    // Versions & paths
    // -----------------------------------------------------------------------

    /** @return list<string> */
    protected function availableVersions(): array
    {
        if ($this->remoteBase() !== null) {
            // Nothing on disk to enumerate — trust the configured version.
            return [OpenApiLocator::configuredVersion()];
        }

        return OpenApiLocator::availableVersions();
    }

    /**
     * @param  list<string>  $available
     */
    protected function defaultVersion(array $available): string
    {
        $configured = OpenApiLocator::configuredVersion();

        return in_array($configured, $available, true) ? $configured : (string) end($available);
    }

    /**
     * Split a leading version segment off a route parameter.
     * "v2/schemas/User.yaml" → ['v2', 'schemas/User.yaml']
     *
     * @return array{0: string, 1: string}
     */
    protected function splitVersion(string $file): array
    {
        $file = ltrim($file, '/');

        if (OpenApiLocator::versioned()) {
            $segments = explode('/', $file, 2);

            if (preg_match('/^v\d+$/i', $segments[0]) === 1) {
                return [OpenApiLocator::normaliseVersion($segments[0]), $segments[1] ?? ''];
            }
        }

        return [OpenApiLocator::configuredVersion(), $file];
    }

    protected function stripVersionSegment(string $version, string $relative): string
    {
        $prefix = OpenApiLocator::normaliseVersion($version).'/';

        return str_starts_with($relative, $prefix) ? substr($relative, strlen($prefix)) : $relative;
    }

    /**
     * The format actually present for a version, preferring the configured one.
     */
    protected function formatFor(string $version): string
    {
        $preferred = OpenApiLocator::format();

        if ($this->remoteBase() !== null || OpenApiLocator::specExists($version, $preferred)) {
            return $preferred;
        }

        $other = $preferred === 'yaml' ? 'json' : 'yaml';

        return OpenApiLocator::specExists($version, $other) ? $other : $preferred;
    }

    protected function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' || $segment === '') {
                return false;
            }
        }

        return (bool) preg_match('/\.(ya?ml|json)$/i', $path);
    }

    // -----------------------------------------------------------------------
    // Parse / dump
    // -----------------------------------------------------------------------

    /**
     * @return array<mixed>
     */
    protected function parse(string $raw, string $format): array
    {
        if ($raw === '') {
            return [];
        }

        $data = $format === 'json' ? json_decode($raw, true) : Yaml::parse($raw);

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<mixed>  $data
     */
    protected function dump(array $data, string $format): string|false
    {
        if ($format === 'json') {
            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $flags = Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK;

        if (defined(Yaml::class.'::DUMP_EMPTY_ARRAY_AS_SEQUENCE')) {
            $flags |= Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE;
        }

        return Yaml::dump($data, 20, 2, $flags);
    }

    // -----------------------------------------------------------------------
    // Error responses — each says what to actually do next
    // -----------------------------------------------------------------------

    protected function noSpecResponse(): Response
    {
        $lines = [
            'No OpenAPI specification has been generated yet.',
            '',
            'Expected: '.OpenApiLocator::specFile(),
            'Generate: php artisan anvil:generate-apidocs --api-version=1 --force',
        ];

        return new Response(implode("\n", $lines), 404, ['Content-Type' => 'text/plain']);
    }

    /**
     * @param  list<string>  $available
     */
    protected function unknownVersionResponse(string $version, array $available): Response
    {
        $lines = [
            "No specification found for {$version}.",
            '',
            'Available: '.implode(', ', $available),
            'Generate:  php artisan anvil:generate-apidocs --api-version='.ltrim($version, 'v').' --force',
        ];

        return new Response(implode("\n", $lines), 404, ['Content-Type' => 'text/plain']);
    }

    protected function missingFileResponse(string $version, string $relative): Response
    {
        $source = $this->remoteBase() ?? OpenApiLocator::specDir($version);

        $lines = [
            "Spec file not found: {$relative}",
            '',
            'Looked in: '.$source,
            'Generate:  php artisan anvil:generate-apidocs --api-version='.ltrim($version, 'v').' --force',
        ];

        return new Response(implode("\n", $lines), 404, ['Content-Type' => 'text/plain']);
    }

    // -----------------------------------------------------------------------
    // View
    // -----------------------------------------------------------------------

    /**
     * Render the docs shell through Blade instead of a baked-in heredoc.
     *
     * config('anvil.openapi.docs.view') lets a consumer swap in their own
     * renderer (Redoc, Scalar, …) or a themed copy without touching package
     * source — the default 'anvil::docs.show' resolves to
     * resources/views/docs/show.blade.php in this package, and is overridden
     * automatically once `vendor:publish --tag=anvil-views` has run.
     *
     * @param  list<array{name: string, url: string}>  $urls
     */
    protected function render(array $urls, string $primary): string
    {
        $assetBase = config('anvil.openapi.docs.asset_base');

        return view(config('anvil.openapi.docs.view', 'anvil::docs.show'), [
            'urls' => $urls,
            'primary' => $primary,
            'title' => config('anvil.openapi.title') ?: config('app.name', 'API Docs'),
            'uiVersion' => (string) config('anvil.openapi.docs.ui_version', '5.17.14'),
            // Set by `php artisan anvil:install:swagger-ui` on success. Null
            // means "no local assets installed" — the view falls back to the
            // CDN rather than pointing at a path that doesn't exist.
            'assetBase' => is_string($assetBase) && $assetBase !== '' ? rtrim($assetBase, '/') : null,
        ])->render();
    }
}
