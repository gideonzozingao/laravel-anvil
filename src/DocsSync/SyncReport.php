<?php

namespace Zuqongtech\LaravelAnvil\DocsSync;

/**
 * Outcome of a sync run: what changed, what was skipped and why, and the exit code.
 *
 * Grouping matters more than volume here. A run over 35 models that reports "35
 * failed" tells you nothing; one that says "35 skipped: not managed -- run with
 * --adopt" tells you exactly what to do next.
 */
final class SyncReport
{
    public const SYNCED = 'synced';

    public const UNCHANGED = 'unchanged';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    public const STALE = 'stale';

    /** @var list<array{status: string, component: string, source: string, reason: string, changes: list<SchemaChange>, notes: list<string>}> */
    private array $entries = [];

    /** @var list<string> */
    private array $written = [];

    public function __construct(public readonly bool $checkMode = false) {}

    /**
     * @param  list<SchemaChange>  $changes
     * @param  list<string>  $notes
     */
    public function add(string $status, string $component, string $source, string $reason = '', array $changes = [], array $notes = []): void
    {
        $this->entries[] = compact('status', 'component', 'source', 'reason', 'changes', 'notes');
    }

    /** @param list<string> $paths */
    public function recordWrites(array $paths): void
    {
        $this->written = array_values(array_unique([...$this->written, ...$paths]));
    }

    /** @return list<string> */
    public function written(): array
    {
        return $this->written;
    }

    /** @return list<array{status: string, component: string, source: string, reason: string, changes: list<SchemaChange>, notes: list<string>}> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return list<array{status: string, component: string, source: string, reason: string, changes: list<SchemaChange>, notes: list<string>}> */
    public function withStatus(string $status): array
    {
        return array_values(array_filter($this->entries, static fn (array $e): bool => $e['status'] === $status));
    }

    public function count(string $status): int
    {
        return count($this->withStatus($status));
    }

    /** @return list<SchemaChange> */
    public function allChanges(): array
    {
        $changes = [];

        foreach ($this->entries as $entry) {
            $changes = [...$changes, ...$entry['changes']];
        }

        return $changes;
    }

    /** @return list<SchemaChange> */
    public function breakingChanges(): array
    {
        return array_values(array_filter($this->allChanges(), static fn (SchemaChange $c): bool => $c->isBreaking()));
    }

    public function hasDrift(): bool
    {
        foreach ($this->entries as $entry) {
            if (($entry['status'] === self::STALE || $entry['status'] === self::SYNCED) && $entry['changes'] !== []) {
                return true;
            }
        }

        return false;
    }

    public function hasFailures(): bool
    {
        return $this->count(self::FAILED) > 0;
    }

    /**
     * Reasons grouped with counts, so a systemic problem reads as one line.
     *
     * @return array<string, int>
     */
    public function groupedReasons(string $status): array
    {
        $grouped = [];

        foreach ($this->withStatus($status) as $entry) {
            $reason = $entry['reason'] === '' ? '(no reason given)' : $entry['reason'];
            $grouped[$reason] = ($grouped[$reason] ?? 0) + 1;
        }

        arsort($grouped);

        return $grouped;
    }

    /**
     * Exit code. Non-zero on any failure, and on drift in check mode -- so CI fails
     * when the spec is stale, but a normal sync that fixes drift succeeds.
     */
    public function exitCode(bool $breakingOnly = false): int
    {
        if ($this->hasFailures()) {
            return 1;
        }

        if (! $this->checkMode) {
            return 0;
        }

        if ($breakingOnly) {
            return $this->breakingChanges() === [] ? 0 : 1;
        }

        return $this->hasDrift() ? 1 : 0;
    }

    public function summaryLine(): string
    {
        $parts = [];

        foreach ([self::SYNCED, self::STALE, self::UNCHANGED, self::SKIPPED, self::FAILED] as $status) {
            $count = $this->count($status);

            if ($count > 0) {
                $parts[] = "{$count} {$status}";
            }
        }

        return $parts === [] ? 'nothing to do' : implode(', ', $parts);
    }
}
