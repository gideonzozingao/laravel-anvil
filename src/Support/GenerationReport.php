<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

use Illuminate\Console\Command;

/**
 * Turns raw pipeline results into something an operator can act on.
 *
 * WHY THIS EXISTS
 *
 * displaySummary() tallied statuses and discarded everything else, so a run
 * ending in "LivewireComponent: 35 failed" gave no indication of why. Thirty-five
 * identical failures is the most diagnosable situation there is — one cause, one
 * message — and it was the one case the summary threw away.
 *
 * It also returned Command::SUCCESS unconditionally, so a run that generated
 * nothing usable reported success. In CI that is worse than a crash: a crash gets
 * noticed.
 *
 * Failures are GROUPED by type and reason. Printing thirty-five copies of the
 * same message is not better than printing none; the count plus two example
 * targets is what makes it obvious the cause is systemic rather than per-table.
 */
final readonly class GenerationReport
{
    /**
     * @param  array<string, array<string, int>>  $artifacts  type => status => count
     * @param  array<string, int>  $models  status => count
     * @param  list<array{type: string, reason: string, count: int, examples: list<string>}>  $failures
     */
    private function __construct(
        public array $models,
        public array $artifacts,
        public array $failures,
        public int $total,
    ) {}

    /**
     * @param  list<array{table?: string, model?: array<string, mixed>, artifacts?: array<mixed>}>  $results
     * @param  list<array<string, mixed>>  $finalResults
     */
    public static function fromResults(array $results, array $finalResults = []): self
    {
        $models = ['success' => 0, 'skipped' => 0, 'failed' => 0];
        $artifacts = [];
        $grouped = [];
        $total = 0;

        foreach ($results as $result) {
            $modelStatus = $result['model']['status'] ?? null;

            if (is_string($modelStatus)) {
                $models[$modelStatus] = ($models[$modelStatus] ?? 0) + 1;
                $total++;

                if ($modelStatus === 'failed') {
                    self::group($grouped, 'Model', $result['model'], (string) ($result['table'] ?? '?'));
                }
            }

            foreach (self::flatten($result['artifacts'] ?? []) as $artifact) {
                $type = (string) ($artifact['type'] ?? 'unknown');
                $status = (string) ($artifact['status'] ?? 'unknown');

                $artifacts[$type][$status] = ($artifacts[$type][$status] ?? 0) + 1;
                $total++;

                if ($status === 'failed') {
                    self::group($grouped, $type, $artifact, (string) ($result['table'] ?? $artifact['name'] ?? '?'));
                }
            }
        }

        foreach ($finalResults as $final) {
            $type = (string) ($final['type'] ?? 'unknown');
            $status = (string) ($final['status'] ?? 'unknown');

            $artifacts[$type][$status] = ($artifacts[$type][$status] ?? 0) + 1;
            $total++;

            if ($status === 'failed') {
                self::group($grouped, $type, $final, (string) ($final['name'] ?? '?'));
            }
        }

        // Most-frequent first: a systemic failure is usually the one worth reading.
        $failures = array_values($grouped);
        usort($failures, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return new self($models, $artifacts, $failures, $total);
    }

    /**
     * Results arrive in two shapes — a single artifact array, or a list of them —
     * because generators return whichever suits them. Normalising here rather
     * than at every read site is why the old summary had an isset($artifact['type'])
     * check inline.
     *
     * @param  array<mixed>  $artifacts
     * @return list<array<string, mixed>>
     */
    private static function flatten(array $artifacts): array
    {
        $flat = [];

        foreach ($artifacts as $artifact) {
            if (! is_array($artifact)) {
                continue;
            }

            if (isset($artifact['type'])) {
                $flat[] = $artifact;

                continue;
            }

            foreach ($artifact as $nested) {
                if (is_array($nested) && isset($nested['type'])) {
                    $flat[] = $nested;
                }
            }
        }

        return $flat;
    }

    /**
     * @param  array<string, array{type: string, reason: string, count: int, examples: list<string>}>  $grouped
     * @param  array<string, mixed>  $entry
     */
    private static function group(array &$grouped, string $type, array $entry, string $target): void
    {
        $reason = trim((string) ($entry['reason'] ?? $entry['message'] ?? ''));

        if ($reason === '') {
            // A failure with no reason is itself worth reporting: it means a
            // generator caught something and discarded the message.
            $reason = 'no reason recorded — the generator swallowed the exception';
        }

        $key = $type.'|'.$reason;

        if (! isset($grouped[$key])) {
            $grouped[$key] = ['type' => $type, 'reason' => $reason, 'count' => 0, 'examples' => []];
        }

        $grouped[$key]['count']++;

        if (count($grouped[$key]['examples']) < 3 && $target !== '?') {
            $grouped[$key]['examples'][] = $target;
        }
    }

    // -----------------------------------------------------------------------
    // Queries
    // -----------------------------------------------------------------------

    public function failureCount(): int
    {
        return array_sum(array_column($this->failures, 'count'));
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }

    /**
     * True when a whole artifact type failed for every model.
     *
     * That pattern almost never means thirty-five separate problems; it means one
     * problem in the generator, which is worth saying out loud rather than leaving
     * the operator to infer from a count.
     */
    public function hasSystemicFailure(): bool
    {
        foreach ($this->artifacts as $statuses) {
            $failed = $statuses['failed'] ?? 0;

            if ($failed > 1 && $failed === array_sum($statuses)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> Types where every attempt failed. */
    public function systemicTypes(): array
    {
        $types = [];

        foreach ($this->artifacts as $type => $statuses) {
            $failed = $statuses['failed'] ?? 0;

            if ($failed > 1 && $failed === array_sum($statuses)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * A run that failed to produce something it was asked for is not a success.
     *
     * The pipeline previously returned SUCCESS unconditionally, so "35 failed"
     * still exited 0 and nothing downstream noticed.
     */
    public function exitCode(): int
    {
        return $this->hasFailures() ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * One line per artifact type, in the existing format.
     *
     * @return list<string>
     */
    public function typeLines(): array
    {
        $lines = [];

        foreach ($this->artifacts as $type => $statuses) {
            $parts = [];

            foreach (['success' => '✅', 'merged' => '🔀', 'updated' => '🔄', 'skipped' => '⏭️', 'warning' => '⚠️', 'dry-run' => '🔸', 'failed' => '❌'] as $status => $icon) {
                $count = $statuses[$status] ?? 0;

                if ($count > 0) {
                    $label = in_array($status, ['success', 'failed'], true) ? '' : ' '.$status;
                    $parts[] = "{$icon} {$count}{$label}";
                }
            }

            $lines[] = sprintf('%s: %s', $type, implode('  ', $parts));
        }

        return $lines;
    }
}
