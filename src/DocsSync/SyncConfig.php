<?php

namespace Zuqongtech\LaravelAnvil\DocsSync;

use RuntimeException;
use Zuqongtech\LaravelAnvil\Contracts\ShapeReader;
use Zuqongtech\LaravelAnvil\Contracts\SpecCodec;
use Zuqongtech\LaravelAnvil\DocsSync\Codecs\JsonSpecCodec;
use Zuqongtech\LaravelAnvil\DocsSync\Codecs\YamlSpecCodec;

/**
 * Resolves everything sync needs from config, in one place.
 *
 * The package currently reads its config under two prefixes in different files
 * (`anvil.*` and `laravel-anvil.*`). Rather than pick one and quietly break the
 * other, this tries both, so sync works whichever the installed config file uses.
 * Worth collapsing to one prefix in the package eventually -- until then, the
 * fallback lives here rather than being scattered across the sync classes.
 */
final class SyncConfig
{
    private const PREFIXES = ['anvil', 'laravel-anvil'];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (! function_exists('config')) {
            return $default;
        }

        foreach (self::PREFIXES as $prefix) {
            $value = config("{$prefix}.{$key}");

            if ($value !== null) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * The API version whose spec is being synced.
     *
     * `openapi.api_version` is the version the generators last wrote, so it is the
     * right default: sync should reconcile the spec that exists, not a version that
     * has never been generated.
     */
    public static function apiVersion(?string $override = null): string
    {
        $version = $override ?? (string) self::get('openapi.api_version', self::get('api.version', 'v1'));
        $version = strtolower(trim($version));

        return $version === '' ? 'v1' : (str_starts_with($version, 'v') ? $version : 'v'.$version);
    }

    /**
     * Directory holding the spec's root document.
     *
     * Honours `openapi.versioned_output`. With it on, each version gets its own
     * directory (`openapi/v1/openapi.yaml` + `schemas/` + `paths/`), so sync must
     * descend into the version folder -- reading `output_path` alone would look at
     * `openapi/` and report "no spec found" on every versioned project.
     */
    public static function specDirectory(?string $version = null): string
    {
        $path = (string) self::get('openapi.output_path', 'openapi');

        $base = self::isAbsolute($path)
            ? rtrim($path, '/\\')
            : rtrim(function_exists('base_path') ? base_path($path) : $path, '/\\');

        if (! self::get('openapi.versioned_output', true)) {
            return $base;
        }

        return $base.DIRECTORY_SEPARATOR.self::apiVersion($version);
    }

    public static function codec(): SpecCodec
    {
        $format = strtolower((string) self::get('openapi.format', 'yaml'));

        return $format === 'json' ? new JsonSpecCodec : new YamlSpecCodec;
    }

    public static function rootFilename(): string
    {
        return (string) self::get('openapi.filename', 'openapi');
    }

    public static function specFiles(?string $version = null): SpecFiles
    {
        return new SpecFiles(self::specDirectory($version), self::codec(), self::rootFilename());
    }

    /**
     * The manifest sits beside the spec it describes, so a v1 sync cannot
     * invalidate v2's fingerprints.
     */
    public static function manifestPath(?string $version = null): string
    {
        return self::specDirectory($version).DIRECTORY_SEPARATOR.'.anvil-sync.json';
    }

    /**
     * Directories to scan for payload classes.
     *
     * Derived by default from the namespaces already configured for the resource and
     * form-request generators, so changing `generators.resources.namespace` moves
     * discovery with it instead of leaving sync scanning a directory nothing writes
     * to any more. Set `openapi.sync.roots` explicitly to override.
     *
     * @return list<array{path: string, kind: string}>
     */
    public static function roots(): array
    {
        $configured = self::get('openapi.sync.roots');

        if (is_array($configured) && $configured !== []) {
            return self::normaliseRoots($configured);
        }

        return self::normaliseRoots([
            ['path' => self::pathForNamespace(
                (string) self::get('generators.resources.namespace', 'App\\Http\\Resources'),
                'Http/Resources',
            ), 'kind' => CodeShape::RESPONSE],
            ['path' => self::pathForNamespace(
                (string) self::get('generators.form_requests.namespace', 'App\\Http\\Requests'),
                'Http/Requests',
            ), 'kind' => CodeShape::REQUEST],
        ]);
    }

    /**
     * Namespaces searched when resolving a bare `new Enum(Status::class)` in a
     * tokenised `rules()`.
     *
     * Needed because `enums.validation` is `rule`, so generated form requests
     * reference enum classes by their imported short name. When `rules()` executes
     * this never matters -- the object resolves itself. It matters only in the
     * tokenised fallback, where the source says `VehicleStatus` and nothing else.
     *
     * @return list<string>
     */
    public static function enumNamespaces(): array
    {
        $configured = self::get('openapi.sync.enum_namespaces');

        if (is_array($configured) && $configured !== []) {
            return array_values(array_map(static fn ($n): string => trim((string) $n, '\\'), $configured));
        }

        $fromEnums = self::get('enums.namespace');

        return $fromEnums === null ? ['App\\Enums'] : [trim((string) $fromEnums, '\\')];
    }

    /**
     * Custom readers, declared in config the same way custom_generators are.
     *
     * Config rather than a container binding, because a bound DocsSynchronizer is a
     * singleton whose spec directory is fixed at construction -- so `--api-version=v2`
     * would silently resolve a v1 synchroniser and reconcile the wrong spec. Declaring
     * the readers instead lets the version stay a per-invocation decision.
     *
     * Misconfiguration throws rather than being skipped. A reader that quietly never
     * runs is indistinguishable from a reader that runs and finds nothing.
     *
     * @return list<ShapeReader>
     */
    public static function readers(): array
    {
        $configured = self::get('openapi.sync.readers', []);
        $readers = [];

        foreach ((array) $configured as $reader) {
            if ($reader instanceof ShapeReader) {
                $readers[] = $reader;

                continue;
            }

            throw_if(! is_string($reader) || $reader === '', RuntimeException::class, 'anvil.openapi.sync.readers must contain class names or ShapeReader instances.');

            throw_unless(class_exists($reader), RuntimeException::class, "Reader class does not exist: {$reader}. Check anvil.openapi.sync.readers.");

            $instance = function_exists('app') ? app()->make($reader) : new $reader;

            throw_unless($instance instanceof ShapeReader, RuntimeException::class, $reader.' must implement '.ShapeReader::class.'. Check anvil.openapi.sync.readers.');

            $readers[] = $instance;
        }

        return $readers;
    }

    public static function prunes(): bool
    {
        return (bool) self::get('openapi.sync.prune', true);
    }

    public static function naming(): ComponentNaming
    {
        $overrides = self::get('openapi.sync.schema_names', []);

        return new ComponentNaming(is_array($overrides) ? array_map(strval(...), $overrides) : []);
    }

    /**
     * Build the fully-wired synchroniser.
     *
     * Always prefer this over constructing DocsSynchronizer by hand, including when
     * adding a custom reader -- pass it as $extraReaders. Hand-construction has to
     * repeat every constructor argument, so any argument added later is silently
     * omitted rather than erroring, and the feature it carries just stops working.
     *
     * @param  list<ShapeReader>  $extraReaders
     */
    public static function synchronizer(?string $version = null, array $extraReaders = []): DocsSynchronizer
    {
        return new DocsSynchronizer(
            spec: self::specFiles($version),
            discovery: new TargetDiscovery(self::roots()),
            naming: self::naming(),
            manifestPath: self::manifestPath($version),
            enumNamespaces: self::enumNamespaces(),
            extraReaders: [...$extraReaders, ...self::readers()],
        );
    }

    /**
     * Best-effort namespace -> filesystem path. Assumes the standard `App\` PSR-4
     * root, and falls back to the conventional location rather than guessing wildly
     * when the namespace is rooted elsewhere.
     */
    private static function pathForNamespace(string $namespace, string $fallback): string
    {
        $namespace = trim(str_replace('/', '\\', $namespace), '\\');
        $appPath = static fn (string $p): string => function_exists('app_path') ? app_path($p) : 'app/'.$p;

        if (str_starts_with($namespace, 'App\\')) {
            return $appPath(str_replace('\\', '/', substr($namespace, 4)));
        }

        return $appPath($fallback);
    }

    /**
     * @return list<array{path: string, kind: string}>
     */
    private static function normaliseRoots(array $roots): array
    {
        return array_values(array_filter(array_map(
            static function (mixed $root): ?array {
                if (is_string($root)) {
                    return ['path' => $root, 'kind' => CodeShape::RESPONSE];
                }

                if (! is_array($root) || ! isset($root['path'])) {
                    return null;
                }

                return [
                    'path' => (string) $root['path'],
                    'kind' => strtolower((string) ($root['kind'] ?? CodeShape::RESPONSE)) === CodeShape::REQUEST
                        ? CodeShape::REQUEST
                        : CodeShape::RESPONSE,
                ];
            },
            $roots,
        )));
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
