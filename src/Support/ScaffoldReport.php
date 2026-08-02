<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

/**
 * Groups scaffold results by artifact type and renders the summary block that
 * anvil:forge, anvil:forge-webapp and anvil:forge-auth all print.
 *
 * The counting logic lived inline in each command's report method, which is why
 * they drifted: the web pipeline grouped by type and the auth scaffolder listed
 * every item flat, and one of them silently dropped results whose status it did
 * not recognise. This is the single implementation, kept framework-free so the
 * grouping can be tested without an output buffer.
 */
final class ScaffoldReport
{
    /**
     * Statuses in the order they are rendered on a summary line, mapped to the
     * icon and noun each is printed with. `success` carries no noun — a bare
     * count reads as "created or updated", matching the existing output.
     *
     * @var array<string, array{icon: string, noun: string}>
     */
    private const STATUSES = [
        'success' => ['icon' => '✅', 'noun' => ''],
        'merged' => ['icon' => '🔀', 'noun' => 'merged'],
        'updated' => ['icon' => '🔄', 'noun' => 'updated'],
        'reused' => ['icon' => '♻️ ', 'noun' => 'reused'],
        'skipped' => ['icon' => '⏭️ ', 'noun' => 'skipped'],
        'dry-run' => ['icon' => '🔸', 'noun' => 'previewed'],
        'missing' => ['icon' => '❌', 'noun' => 'missing'],
        'failed' => ['icon' => '❌', 'noun' => 'failed'],
    ];

    /**
     * Labels that camelCase splitting alone gets wrong.
     *
     * @var array<string, string>
     */
    private const LABEL_OVERRIDES = [
        'two factor' => 'Two-factor',
        'rbac' => 'RBAC',
        'api' => 'API',
        'open api' => 'OpenAPI',
        'db' => 'DB',
        'ui' => 'UI',
        'two fa' => 'Two-factor',
    ];

    /** @var array<string, array<string, int>> type => status => count, in first-seen order */
    private array $groups = [];

    /** @var array<string, int> status => count */
    private array $totals = [];

    /** @var array<string, int> unrecognised status => count */
    private array $unrecognised = [];

    private function __construct() {}

    /**
     * @param  array<int, mixed>  $results  each entry either a result array with a
     *                                      'type' key, or a list of such arrays
     */
    public static function fromResults(array $results): self
    {
        $report = new self;

        foreach ($results as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            // A generator may return one artifact or a list of them for a single
            // subject. Flatten both shapes rather than assuming either.
            $artifacts = isset($entry['type']) || isset($entry['status'])
                ? [$entry]
                : $entry;

            foreach ($artifacts as $artifact) {
                if (is_array($artifact)) {
                    $report->record($artifact);
                }
            }
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    private function record(array $artifact): void
    {
        $type = $artifact['type'] ?? null;
        $type = is_string($type) && $type !== '' ? $type : 'Unknown';

        $status = $artifact['status'] ?? 'success';
        $status = is_string($status) && $status !== '' ? $status : 'success';

        $this->groups[$type] ??= [];
        $this->groups[$type][$status] = ($this->groups[$type][$status] ?? 0) + 1;
        $this->totals[$status] = ($this->totals[$status] ?? 0) + 1;

        // An unrecognised status used to be counted into a bucket no summary line
        // printed, so the totals disagreed with the itemised list. Track them so
        // the caller can say so out loud.
        if (! array_key_exists($status, self::STATUSES)) {
            $this->unrecognised[$status] = ($this->unrecognised[$status] ?? 0) + 1;
        }
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function groups(): array
    {
        return $this->groups;
    }

    /**
     * @return array<string, int>
     */
    public function totals(): array
    {
        return $this->totals;
    }

    public function count(string $status): int
    {
        return $this->totals[$status] ?? 0;
    }

    public function total(): int
    {
        return array_sum($this->totals);
    }

    public function isEmpty(): bool
    {
        return $this->groups === [];
    }

    public function hasFailures(): bool
    {
        return $this->count('failed') > 0;
    }

    /**
     * Statuses no summary line knows how to render, with their counts.
     *
     * @return array<string, int>
     */
    public function unrecognised(): array
    {
        return $this->unrecognised;
    }

    /**
     * One line per artifact type, in first-seen order:
     *
     *   "   Component: ✅ 8"
     *   "   View: ⏭️  8 skipped"
     *   "   Migration: ✅ 1  ⏭️  1 skipped"
     *
     * @return list<string>
     */
    public function summaryLines(string $indent = '   '): array
    {
        $lines = [];

        foreach ($this->groups as $type => $statuses) {
            $parts = [];

            foreach (self::STATUSES as $status => $format) {
                $count = $statuses[$status] ?? 0;

                if ($count === 0) {
                    continue;
                }

                $parts[] = trim($format['icon'].' '.$count.' '.$format['noun']);
            }

            foreach ($statuses as $status => $count) {
                if (! array_key_exists($status, self::STATUSES) && $count > 0) {
                    $parts[] = '❓ '.$count.' '.$status;
                }
            }

            if ($parts === []) {
                continue;
            }

            $lines[] = $indent.$type.': '.implode('  ', $parts);
        }

        return $lines;
    }

    /**
     * The bold one-liner under the itemised list:
     * "12 written   9 skipped   0 previewed   2 failed"
     */
    public function totalsLine(): string
    {
        $segments = [
            $this->count('success').' written',
            $this->count('skipped').' skipped',
            $this->count('dry-run').' previewed',
            $this->count('failed').' failed',
        ];

        $line = implode('   ', $segments);

        if ($this->unrecognised !== []) {
            $line .= '   '.array_sum($this->unrecognised).' unreported';
        }

        return $line;
    }

    public static function icon(string $status): string
    {
        return trim(self::STATUSES[$status]['icon'] ?? '❓');
    }

    /**
     * Turn a part or generator class name into a label fit for the plan block:
     * EmailVerificationPart => "Email verification", TwoFactorPart => "Two-factor".
     */
    public static function humanise(string $class): string
    {
        $base = str_contains($class, '\\')
            ? substr($class, (int) strrpos($class, '\\') + 1)
            : $class;

        foreach (['Part', 'Generator', 'Command'] as $suffix) {
            if (str_ends_with($base, $suffix) && $base !== $suffix) {
                $base = substr($base, 0, -strlen($suffix));
                break;
            }
        }

        // Split on camelCase and acronym boundaries: "OpenAPISpec" => Open API Spec.
        $spaced = (string) preg_replace(
            ['/([a-z0-9])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/'],
            ['$1 $2', '$1 $2'],
            $base,
        );

        $spaced = trim((string) preg_replace('/[\s_-]+/', ' ', $spaced));

        if ($spaced === '') {
            return $class;
        }

        $key = strtolower($spaced);

        if (isset(self::LABEL_OVERRIDES[$key])) {
            return self::LABEL_OVERRIDES[$key];
        }

        $words = explode(' ', $spaced);
        $first = array_shift($words);

        $rest = array_map(
            static fn (string $word): string => self::LABEL_OVERRIDES[strtolower($word)] ?? (strtoupper($word) === $word && strlen($word) > 1 ? $word : strtolower($word)),
            $words,
        );

        return ucfirst($first).($rest === [] ? '' : ' '.implode(' ', $rest));
    }
}
