<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Merge;

/**
 * One region of a three-way merge.
 *
 * Stable chunks are identical across all three inputs and pass through
 * untouched. Unstable chunks differ in at least one input and are resolved
 * by comparing the three slices.
 *
 * @internal
 */
final readonly class MergeChunk
{
    /**
     * @param  list<string>  $base
     * @param  list<string>  $local
     * @param  list<string>  $remote
     */
    private function __construct(
        public bool $stable,
        public array $base,
        public array $local,
        public array $remote,
    ) {}

    /**
     * @param  list<string>  $lines
     */
    public static function stable(array $lines): self
    {
        return new self(true, $lines, $lines, $lines);
    }

    /**
     * @param  list<string>  $base
     * @param  list<string>  $local
     * @param  list<string>  $remote
     */
    public static function unstable(array $base, array $local, array $remote): self
    {
        return new self(false, $base, $local, $remote);
    }
}
