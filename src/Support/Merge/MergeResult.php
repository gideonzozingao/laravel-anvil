<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Merge;

/**
 * The outcome of a three-way merge.
 *
 * `$contents` is always usable output: when conflicts are present it contains
 * conflict markers, so callers may write it verbatim and let the developer
 * resolve, or refuse to write and report instead. That choice belongs to the
 * caller, not here.
 */
final readonly class MergeResult
{
    /**
     * @param  list<int>  $conflictLines  1-indexed line numbers of the opening
     *                                    marker of each conflict in $contents
     */
    public function __construct(
        public string $contents,
        public array $conflictLines = [],
        public bool $degraded = false,
    ) {}

    public static function clean(string $contents): self
    {
        return new self($contents);
    }

    public function hasConflicts(): bool
    {
        return $this->conflictLines !== [];
    }

    public function conflictCount(): int
    {
        return count($this->conflictLines);
    }

    /**
     * True when the inputs exceeded the size limit and the whole file was
     * emitted as a single conflict rather than merged line by line.
     */
    public function isDegraded(): bool
    {
        return $this->degraded;
    }
}
