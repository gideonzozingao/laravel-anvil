<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Console\Concerns;

use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Zuqongtech\LaravelAnvil\Support\ScaffoldReport;

/**
 * The house style for every Anvil scaffold command.
 *
 * anvil:forge-webapp and anvil:forge-auth were each rendering their own preamble,
 * table, summary and tail, so the two looked like different tools: the web command
 * printed a connection line, a generation plan, a progress bar and a grouped
 * summary; the auth command printed a table, twenty-three flat lines and a wall of
 * bullets. This concern owns the frames so a new command gets the same output for
 * free, and a change to the style lands everywhere at once.
 *
 * Order is part of the style. Heading, table, warnings, connection, plan, progress,
 * summary, tail, next steps, done. The auth command used to print its warnings
 * above the table, which pushed the configuration it was warning about off the top
 * of a short terminal.
 *
 * @mixin Command
 */
trait RendersScaffoldOutput
{
    protected ?ProgressBar $scaffoldProgress = null;

    /**
     * "🔐 Anvil — Authentication Scaffold"
     */
    protected function renderHeading(string $icon, string $title): void
    {
        $this->info($icon.' Anvil — '.$title);
    }

    /**
     * The two-column configuration table. Rows are [label, value] pairs; a null or
     * empty value is dropped rather than printed as a blank row.
     *
     * @param  array<int, array{0: string, 1: string|null}>  $rows
     */
    protected function renderConfigTable(array $rows): void
    {
        $filtered = [];

        foreach ($rows as $row) {
            $value = $row[1] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $filtered[] = [$row[0], $value];
        }

        if ($filtered === []) {
            return;
        }

        $this->table(['', ''], $filtered);
    }

    /**
     * Non-fatal gaps, rendered after the table so the configuration they refer to
     * is still on screen.
     *
     * @param  list<string>  $warnings
     */
    protected function renderWarnings(array $warnings): void
    {
        if ($warnings === []) {
            return;
        }

        $this->newLine();

        foreach ($warnings as $warning) {
            $this->components->warn($warning);
        }

        $this->newLine();
    }

    /**
     * "🔍 Connection [pgsql] — driver: pgsql — database: auto_vault"
     */
    protected function renderConnectionLine(string $connection, string $driver, string $database): void
    {
        $this->info(sprintf('🔍 Connection [%s] — driver: %s — database: %s', $connection, $driver, $database));
    }

    /**
     * "🌐 Web scaffold [livewire] — Livewire components, Blade wrappers and web routes"
     */
    protected function renderModeLine(string $icon, string $label, string $variant, string $detail): void
    {
        $this->info(sprintf('%s %s [%s] — %s', $icon, $label, $variant, $detail));
    }

    /**
     * The plan block: a comma-separated list of what will run, then aligned
     * "Key : Value" detail lines.
     *
     * @param  list<string>  $items
     * @param  array<string, string|null>  $details
     */
    protected function renderGenerationPlan(array $items, array $details = []): void
    {
        $this->newLine();

        if ($items !== []) {
            $this->info('📋 Generation plan: '.implode(', ', $items));
        }

        $details = array_filter(
            $details,
            static fn (?string $value): bool => $value !== null && $value !== '',
        );

        if ($details !== []) {
            $width = max(array_map(mb_strlen(...), array_keys($details)));

            foreach ($details as $key => $value) {
                $this->line(sprintf('   %s : %s', $this->pad((string) $key, $width), $value));
            }
        }

        $this->newLine();
    }

    /**
     * Progress bar in the same format the generation pipeline uses.
     */
    protected function startProgress(int $steps, string $message = 'Starting...'): void
    {
        if ($steps <= 0) {
            return;
        }

        $this->scaffoldProgress = $this->output->createProgressBar($steps);
        $this->scaffoldProgress->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
        $this->scaffoldProgress->setMessage($message);
        $this->scaffoldProgress->start();
    }

    protected function advanceProgress(?string $message = null): void
    {
        if ($this->scaffoldProgress === null) {
            return;
        }

        if ($message !== null) {
            $this->scaffoldProgress->setMessage($message);
        }

        $this->scaffoldProgress->advance();
    }

    protected function finishProgress(): void
    {
        if ($this->scaffoldProgress === null) {
            return;
        }

        $this->scaffoldProgress->finish();
        $this->scaffoldProgress = null;
        $this->newLine(2);
    }

    /**
     * The itemised list, one line per artifact, with the shared icon vocabulary.
     *
     * Unlike the per-table pipeline, an auth run produces a small set of uniquely
     * named artifacts, so naming each one is worth the lines. Suppressed entirely
     * on a large run to avoid burying the summary.
     *
     * @param  list<array{type?: string, name?: string, status?: string, reason?: string}>  $results
     */
    protected function renderItemisedResults(array $results, int $limit = 40): void
    {
        if ($results === [] || count($results) > $limit) {
            return;
        }

        $width = 0;

        foreach ($results as $result) {
            $width = max($width, mb_strlen((string) ($result['type'] ?? '')));
        }

        foreach ($results as $result) {
            $status = (string) ($result['status'] ?? 'success');

            $colour = match ($status) {
                'success' => 'green',
                'dry-run' => 'cyan',
                'skipped' => 'gray',
                'failed' => 'red',
                default => 'yellow',
            };

            $this->line(sprintf(
                '  <fg=%s>%s</> %s  %s%s',
                $colour,
                ScaffoldReport::icon($status),
                $this->pad((string) ($result['type'] ?? ''), $width),
                (string) ($result['name'] ?? ''),
                isset($result['reason']) ? " <fg=gray>({$result['reason']})</>" : '',
            ));
        }
    }

    /**
     * "📊 Summary" plus one grouped line per artifact type, then the bold totals.
     */
    protected function renderSummary(ScaffoldReport $report): void
    {
        $this->newLine();
        $this->info('📊 Summary');
        $this->newLine();

        foreach ($report->summaryLines() as $line) {
            $this->line($line);
        }

        $this->newLine();
        $this->line('   <options=bold>'.$report->totalsLine().'</>');

        // A status the summary cannot render is a bug, not a detail: the totals and
        // the itemised list would otherwise disagree with no explanation.
        foreach ($report->unrecognised() as $status => $count) {
            $this->components->warn(sprintf(
                '%d result(s) reported the unrecognised status "%s" and are not counted in any column above.',
                $count,
                $status,
            ));
        }
    }

    /**
     * The closing block: what was produced and where it lives.
     *
     * @param  array<string, string|null>  $lines
     */
    protected function renderCompletion(string $icon, string $title, array $lines = []): void
    {
        $this->newLine();
        $this->info($icon.' '.$title);

        $lines = array_filter($lines, static fn (?string $v): bool => $v !== null && $v !== '');

        if ($lines === []) {
            return;
        }

        $width = max(array_map(mb_strlen(...), array_keys($lines)));

        foreach ($lines as $key => $value) {
            $this->line(sprintf('   %s : %s', $this->pad((string) $key, $width), $value));
        }
    }

    /**
     * Next steps, grouped under the feature that asks for them.
     *
     * A flat list of twenty-three bullets is unreadable, and worse, gives no clue
     * which bullets matter for the feature you are debugging. Grouping keeps the
     * two-factor steps next to each other and lets an operator skip a whole block.
     *
     * @param  array<string, list<string>>  $groups
     */
    protected function renderNextSteps(array $groups): void
    {
        $groups = array_filter($groups, static fn (array $notes): bool => $notes !== []);

        if ($groups === []) {
            return;
        }

        $total = array_sum(array_map(count(...), $groups));

        $this->newLine();
        $this->line(sprintf('  <options=bold>Next steps</> <fg=gray>(%d)</>', $total));

        foreach ($groups as $heading => $notes) {
            $this->newLine();
            $this->line('  <fg=cyan>'.$heading.'</>');

            foreach ($notes as $note) {
                $this->line('   • '.$note);
            }
        }
    }

    protected function renderDone(bool $dryRun = false): void
    {
        $this->newLine();
        $this->info('✅ Done!');

        if ($dryRun) {
            $this->warn('🔸 Dry run — no files written.');
        }
    }

    /**
     * str_pad is byte-based, so it mis-aligns any label containing a multi-byte
     * character. Every heading in this output is a candidate.
     */
    protected function pad(string $value, int $width): string
    {
        $length = mb_strlen($value);

        return $length >= $width ? $value : $value.str_repeat(' ', $width - $length);
    }
}
