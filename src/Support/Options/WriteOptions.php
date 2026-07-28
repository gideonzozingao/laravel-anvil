<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Options;

/**
 * HOW to write: overwrite, back up, or preview.
 *
 * Three booleans that every generator and every writer consults, and whose
 * interactions are easy to get subtly wrong — a dry run over an existing file
 * must report "would skip", not "would write", which is a rule currently restated
 * in ScaffoldWriter, FileWriter and each OpenAPI generator independently.
 */
final readonly class WriteOptions extends OptionBag
{
    public function __construct(
        public bool $force = false,
        public bool $backup = false,
        public bool $dryRun = false,
    ) {}

    /** Whether anything will actually reach disk. */
    public function writes(): bool
    {
        return ! $this->dryRun;
    }

    /**
     * The outcome for one target, decided in one place.
     *
     * Returns 'skipped', 'dry-run' or 'write'. The ordering matters: existence is
     * checked before the dry-run branch, so a preview of an existing file reports
     * what the real run would do rather than what it would like to do.
     */
    public function outcomeFor(bool $exists): string
    {
        if ($exists && ! $this->force) {
            return 'skipped';
        }

        return $this->dryRun ? 'dry-run' : 'write';
    }

    public function backsUp(bool $exists): bool
    {
        return $exists && $this->backup && $this->writes();
    }

    public function describe(): string
    {
        return match (true) {
            $this->dryRun => 'dry run — nothing written',
            $this->force && $this->backup => 'overwrite, with backups',
            $this->force => 'overwrite existing files',
            default => 'skip existing files',
        };
    }
}
