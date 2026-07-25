<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

/**
 * Resolves every path and URL related to the generated OpenAPI specification.
 *
 * The spec is versioned on disk, one directory per API version:
 *
 *   openapi/
 *     v1/
 *       openapi.yaml
 *       schemas/…
 *       paths/…
 *     v2/
 *       openapi.yaml
 *       …
 *
 * and served per version:
 *
 *   /docs/v1                  Swagger UI for v1
 *   /docs/v1/openapi.yaml     the bundled root spec for v1
 *
 * Set anvil.openapi.versioned_output = false to keep the flat pre-versioning
 * layout (openapi/openapi.yaml served at /docs).
 *
 * Every generator, the console commands and DocsController resolve through this
 * class so a version bump cannot leave one of them writing to the old location.
 */
final class OpenApiLocator
{
    /** Any of 2, "2", "v2", "V2" → "v2". */
    public static function normaliseVersion(int|string|null $version): string
    {
        $digits = preg_replace('/\D/', '', (string) $version) ?? '';

        return 'v'.($digits === '' ? '1' : $digits);
    }

    /**
     * The version currently being generated or reported on. anvil:generate-api
     * writes this at runtime from --api-version; otherwise it comes from config.
     */
    public static function configuredVersion(): string
    {
        return self::normaliseVersion(
            config('anvil.openapi.api_version', config('laravel-anvil.api_version', 'v1')),
        );
    }

    /** 'yaml' or 'json' — never anything else. */
    public static function format(): string
    {
        return config('anvil.openapi.format', 'yaml') === 'json' ? 'json' : 'yaml';
    }

    public static function versioned(): bool
    {
        return (bool) config('anvil.openapi.versioned_output', true);
    }

    /** The configured output root, e.g. /app/openapi. */
    public static function rootPath(): string
    {
        return base_path(trim((string) config('anvil.openapi.output_path', 'openapi'), '/'));
    }

    /** The directory a single version's files live in. */
    public static function specDir(int|string|null $version = null): string
    {
        if (! self::versioned()) {
            return self::rootPath();
        }

        return self::rootPath().'/'.self::normaliseVersion($version ?? self::configuredVersion());
    }

    public static function schemasDir(int|string|null $version = null): string
    {
        return self::specDir($version).'/schemas';
    }

    public static function pathsDir(int|string|null $version = null): string
    {
        return self::specDir($version).'/paths';
    }

    public static function specFile(int|string|null $version = null, ?string $format = null): string
    {
        return self::specDir($version).'/openapi.'.($format ?? self::format());
    }

    /**
     * Versions that actually have a root spec on disk, ascending.
     *
     * @return list<string>
     */
    public static function availableVersions(): array
    {
        if (! self::versioned()) {
            return self::specExists() ? [self::configuredVersion()] : [];
        }

        $versions = [];

        foreach (glob(self::rootPath().'/v*', GLOB_ONLYDIR) ?: [] as $dir) {
            $version = basename($dir);

            if (preg_match('/^v\d+$/', $version) !== 1) {
                continue;
            }

            foreach (['yaml', 'json'] as $ext) {
                if (is_file("{$dir}/openapi.{$ext}")) {
                    $versions[] = $version;

                    break;
                }
            }
        }

        usort($versions, static fn (string $a, string $b): int => (int) ltrim($a, 'v') <=> (int) ltrim($b, 'v'));

        return $versions;
    }

    public static function specExists(int|string|null $version = null, ?string $format = null): bool
    {
        return is_file(self::specFile($version, $format));
    }

    /**
     * Count the split files for a version, for reporting.
     *
     * @return array{schemas: int, paths: int}
     */
    public static function fileCounts(int|string|null $version = null, ?string $format = null): array
    {
        $ext = $format ?? self::format();

        return [
            'schemas' => count(glob(self::schemasDir($version)."/*.{$ext}") ?: []),
            'paths' => count(glob(self::pathsDir($version)."/*.{$ext}") ?: []),
        ];
    }

    // ── Routing ─────────────────────────────────────────────────────────────

    /** The docs route without a leading slash, e.g. 'docs/v1'. */
    public static function docsRoute(int|string|null $version = null): string
    {
        $route = trim((string) config('anvil.openapi.docs.route', 'docs'), '/');

        if (! self::versioned()) {
            return $route;
        }

        return $route.'/'.self::normaliseVersion($version ?? self::configuredVersion());
    }

    public static function docsUrl(int|string|null $version = null): string
    {
        return self::appUrl().'/'.self::docsRoute($version);
    }

    /** The spec as served by DocsController, e.g. /docs/v1/openapi.yaml. */
    public static function specUrl(int|string|null $version = null, ?string $format = null): string
    {
        return self::docsUrl($version).'/openapi.'.($format ?? self::format());
    }

    /**
     * Where a static Swagger UI bundle is published.
     *
     * This MUST NOT match the docs route. Publishing to public/{route} makes the
     * directory exist on disk, and both `php artisan serve` and an nginx
     * try_files block then hand the request to the static handler instead of
     * PHP — so /docs returns the web server's own 404 for a directory with no
     * index, and DocsController never runs. Hence a separate default.
     */
    public static function publicDocsDir(int|string|null $version = null): string
    {
        $dir = public_path(trim((string) config('anvil.openapi.docs.public_path', 'api-docs'), '/'));

        if (! self::versioned()) {
            return $dir;
        }

        return $dir.'/'.self::normaliseVersion($version ?? self::configuredVersion());
    }

    /** The browser URL of the published static bundle, if any. */
    public static function staticDocsUrl(int|string|null $version = null): string
    {
        $path = trim((string) config('anvil.openapi.docs.public_path', 'api-docs'), '/');

        if (self::versioned()) {
            $path .= '/'.self::normaliseVersion($version ?? self::configuredVersion());
        }

        return self::appUrl().'/'.$path.'/index.html';
    }

    /** The API base path for a version, e.g. /api/v1 — mirrors anvil.api.prefix. */
    public static function apiBasePath(int|string|null $version = null): string
    {
        $prefix = trim((string) config('anvil.api.prefix', 'api'), '/');
        $version = self::normaliseVersion($version ?? self::configuredVersion());

        return '/'.$prefix.'/'.$version;
    }

    public static function appUrl(): string
    {
        return rtrim((string) config('app.url', 'http://localhost'), '/');
    }
}
