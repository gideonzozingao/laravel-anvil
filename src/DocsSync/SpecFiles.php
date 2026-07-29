<?php

namespace Zuqongtech\LaravelAnvil\DocsSync;

use RuntimeException;
use Zuqongtech\LaravelAnvil\Contracts\SpecCodec;

/**
 * Reads and writes component schemas, transparently across single-file and
 * split-file spec layouts.
 *
 * In split mode the root document holds `$ref: './schemas/Vehicle.yaml#/Vehicle'`
 * and the real schema lives in that file under a top-level key. Sync must write
 * back to whichever file a component actually came from -- writing everything into
 * the root would silently convert a split spec into a single-file one and orphan
 * every schema file on disk.
 *
 * This class is the ONLY thing in the sync pipeline that touches the filesystem or
 * knows about layout. The synchroniser deals purely in component names.
 */
final class SpecFiles
{
    /** @var array<string, mixed>|null */
    private ?array $root = null;

    /** @var array<string, array{file: string, pointer: string}> */
    private array $externals = [];

    /** @var array<string, array<string, mixed>> loaded external documents, by path */
    private array $documents = [];

    /** @var array<string, bool> paths needing a write */
    private array $dirty = [];

    public function __construct(
        private readonly string $directory,
        private readonly SpecCodec $codec,
        private readonly string $rootFilename = 'openapi',
    ) {}

    public function exists(): bool
    {
        return is_file($this->rootPath());
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function rootPath(): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.$this->rootFilename.'.'.$this->codec->extension();
    }

    /**
     * All component schemas with external `$ref`s resolved, so callers see one flat
     * map regardless of layout.
     *
     * @return array<string, array<string, mixed>>
     */
    public function componentSchemas(): array
    {
        $root = $this->root();
        $schemas = $root['components']['schemas'] ?? [];

        if (! is_array($schemas)) {
            return [];
        }

        $resolved = [];

        foreach ($schemas as $name => $schema) {
            $name = (string) $name;

            if (! is_array($schema)) {
                continue;
            }

            $external = $this->externalTarget($schema);

            if ($external === null) {
                $resolved[$name] = $schema;

                continue;
            }

            $fragment = $this->readExternal($external['file'], $external['pointer']);

            if ($fragment === null) {
                continue;
            }

            $this->externals[$name] = $external;
            $resolved[$name] = $fragment;
        }

        return $resolved;
    }

    public function isSplitMode(): bool
    {
        if ($this->externals === []) {
            $this->componentSchemas();
        }

        return $this->externals !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function paths(): array
    {
        $paths = $this->root()['paths'] ?? [];

        return is_array($paths) ? $paths : [];
    }

    /**
     * Stage a component schema for writing, into whichever file it came from.
     *
     * @param  array<string, mixed>  $schema
     */
    public function putComponentSchema(string $name, array $schema): void
    {
        $external = $this->externals[$name] ?? null;

        if ($external !== null) {
            $document = $this->documents[$external['file']] ?? [];
            $key = ltrim($external['pointer'], '/');

            if ($key === '') {
                $this->documents[$external['file']] = $schema;
            } else {
                $document[$key] = $schema;
                $this->documents[$external['file']] = $document;
            }

            $this->dirty[$external['file']] = true;

            return;
        }

        $root = $this->root();
        $root['components'] ??= [];
        $root['components']['schemas'] ??= [];
        $root['components']['schemas'][$name] = $schema;
        $this->root = $root;
        $this->dirty[$this->rootPath()] = true;
    }

    /**
     * Create a component in the layout the spec already uses, so a split spec stays
     * split.
     *
     * @param  array<string, mixed>  $schema
     */
    public function createComponentSchema(string $name, array $schema): void
    {
        if ($this->isSplitMode()) {
            $relative = 'schemas/'.$name.'.'.$this->codec->extension();
            $file = $this->directory.DIRECTORY_SEPARATOR.'schemas'.DIRECTORY_SEPARATOR.$name.'.'.$this->codec->extension();

            $this->externals[$name] = ['file' => $file, 'pointer' => '/'.$name];
            $this->documents[$file] = [$name => $schema];
            $this->dirty[$file] = true;

            $root = $this->root();
            $root['components']['schemas'][$name] = ['$ref' => './'.$relative.'#/'.$name];
            $this->root = $root;
            $this->dirty[$this->rootPath()] = true;

            return;
        }

        $this->putComponentSchema($name, $schema);
    }

    /**
     * @return list<string> paths written
     */
    public function flush(): array
    {
        $written = [];

        foreach (array_keys($this->dirty) as $path) {
            $data = $path === $this->rootPath()
                ? ($this->root ?? [])
                : ($this->documents[$path] ?? []);

            $directory = dirname($path);

            throw_if(! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory), RuntimeException::class, "Unable to create spec directory: {$directory}");

            throw_if(@file_put_contents($path, $this->codec->encode($data)) === false, RuntimeException::class, "Unable to write spec file: {$path}");

            $written[] = $path;
        }

        $this->dirty = [];

        return $written;
    }

    public function hasPendingWrites(): bool
    {
        return $this->dirty !== [];
    }

    /**
     * Fingerprint of a component as it currently sits in the spec. Used to detect
     * direct human edits to a managed schema -- allowed, but worth reporting,
     * because that is how two sources of truth start disagreeing.
     */
    public function fingerprintFor(string $name): string
    {
        $schemas = $this->componentSchemas();
        $schema = $schemas[$name] ?? [];

        // The marker itself is excluded so adopting a schema does not look like an edit.
        unset($schema['x-anvil']);

        return sha1((string) json_encode($schema));
    }

    /**
     * A managed schema is one sync is allowed to rewrite. Absence of the marker
     * means a human authored it, so sync leaves it alone unless explicitly adopted.
     *
     * @param  array<string, mixed>  $schema
     */
    public static function isManaged(array $schema): bool
    {
        $anvil = $schema['x-anvil'] ?? null;

        return is_array($anvil) && ($anvil['managed'] ?? null) === true;
    }

    /**
     * Explicitly opted out: never adopt, never rewrite, even with --adopt.
     *
     * @param  array<string, mixed>  $schema
     */
    public static function isOptedOut(array $schema): bool
    {
        $anvil = $schema['x-anvil'] ?? null;

        return is_array($anvil) && ($anvil['managed'] ?? null) === false;
    }

    /**
     * @return array<string, mixed>
     */
    private function root(): array
    {
        if ($this->root !== null) {
            return $this->root;
        }

        $path = $this->rootPath();

        throw_unless(is_file($path), RuntimeException::class, "No spec found at {$path}. Run `php artisan anvil:generate --openapi` first.");

        return $this->root = $this->codec->decode((string) file_get_contents($path));
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{file: string, pointer: string}|null
     */
    private function externalTarget(array $schema): ?array
    {
        $ref = $schema['$ref'] ?? null;

        if (! is_string($ref) || $ref === '' || str_starts_with($ref, '#')) {
            return null;
        }

        [$file, $pointer] = array_pad(explode('#', $ref, 2), 2, '');
        $file = ltrim($file, './');

        if ($file === '') {
            return null;
        }

        return [
            'file' => $this->directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file),
            'pointer' => $pointer,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readExternal(string $file, string $pointer): ?array
    {
        if (! isset($this->documents[$file])) {
            if (! is_file($file)) {
                return null;
            }

            $this->documents[$file] = $this->codec->decode((string) file_get_contents($file));
        }

        $document = $this->documents[$file];
        $key = ltrim($pointer, '/');

        if ($key === '') {
            return $document;
        }

        $fragment = $document;

        foreach (explode('/', $key) as $segment) {
            if (! is_array($fragment) || ! array_key_exists($segment, $fragment)) {
                return null;
            }

            $fragment = $fragment[$segment];
        }

        return is_array($fragment) ? $fragment : null;
    }
}
