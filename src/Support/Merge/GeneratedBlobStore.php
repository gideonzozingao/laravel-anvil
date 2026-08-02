<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Merge;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;

/**
 * Keeps a copy of exactly what Anvil last wrote for each file.
 *
 * A three-way merge needs three inputs, and the one nobody has is the *base*:
 * what the generator produced before the developer edited it. A provenance
 * hash can tell you a file was edited; it cannot tell you what it looked like
 * beforehand, so it cannot drive a merge. This store closes that gap.
 *
 * Blobs mirror the project's own directory structure under the store root, so
 * `app/Models/Post.php` is at `storage/anvil/generated/app/Models/Post.php`.
 * That costs a little disk and buys a store you can read with `cat` and diff
 * with `diff` when a merge does something surprising.
 */
final readonly class GeneratedBlobStore
{
    public function __construct(
        private Filesystem $files,
        private string $root,
    ) {}

    public function has(string $relativePath): bool
    {
        return $this->files->exists($this->path($relativePath));
    }

    /**
     * The last generated contents, or null when this file has never been
     * generated — in which case there is no base and no merge is possible.
     */
    public function get(string $relativePath): ?string
    {
        $path = $this->path($relativePath);

        return $this->files->exists($path)
            ? $this->files->get($path)
            : null;
    }

    public function put(string $relativePath, string $contents): void
    {
        $path = $this->path($relativePath);

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $contents);
    }

    public function forget(string $relativePath): void
    {
        $path = $this->path($relativePath);

        if ($this->files->exists($path)) {
            $this->files->delete($path);
        }
    }

    /**
     * Drop every blob. Regeneration after this behaves as it did before merge
     * existed: no base, so no merge, so hand edits block an overwrite.
     */
    public function flush(): void
    {
        if ($this->files->isDirectory($this->root)) {
            $this->files->deleteDirectory($this->root);
        }
    }

    public function path(string $relativePath): string
    {
        return $this->root.DIRECTORY_SEPARATOR.$this->normalise($relativePath);
    }

    /**
     * Reject anything that would escape the store root. The relative path
     * reaching this class comes from a generator rather than from user input,
     * but a generator assembling a path from a table name is close enough to
     * user input to be worth checking.
     */
    private function normalise(string $relativePath): string
    {
        $clean = ltrim(str_replace('\\', '/', $relativePath), '/');

        throw_if($clean === '' || str_contains($clean, '../') || str_ends_with($clean, '/..'), InvalidArgumentException::class, "Refusing to store a blob for an unsafe path: [{$relativePath}].");

        return $clean;
    }
}
