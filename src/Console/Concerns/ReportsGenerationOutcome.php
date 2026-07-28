<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Console\Concerns;

use Illuminate\Console\Command;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\GenerationReport;

/**
 * The reporting half of the generation pipeline.
 *
 * Extracted from RunsGenerationPipeline, which had grown a 60-line
 * displaySummary() that tallied statuses, discarded every failure reason, and
 * then returned success regardless. Reporting is a separate concern from running,
 * and keeping it separate is what makes "35 failed with no explanation" a thing
 * that cannot recur unnoticed.
 *
 * @mixin Command
 */
trait ReportsGenerationOutcome
{
    /**
     * Print the models block, the per-type block, and any failures.
     *
     * Replaces the tallying section of displaySummary(). The scaffold-specific
     * sections — API notes, web notes, pivot tables — stay where they are; they
     * describe intent rather than outcome.
     */
    protected function displayReport(GenerationReport $report, GenerationOptions $options): void
    {
        $this->line("\n   Models:");
        $this->line(sprintf(
            '      ✅ %d created/updated   ⏭️  %d skipped%s',
            $report->models['success'] ?? 0,
            $report->models['skipped'] ?? 0,
            ($report->models['failed'] ?? 0) > 0 ? sprintf('   ❌ %d failed', $report->models['failed']) : '',
        ));

        foreach ($report->typeLines() as $line) {
            $this->line('   '.$line);
        }

        if ($report->hasFailures()) {
            $this->displayFailures($report);
        }
    }

    /**
     * Failures, grouped.
     *
     * Thirty-five copies of one message is no more useful than none. The count
     * plus a couple of example targets is what makes it obvious whether the cause
     * is systemic or per-table — and the systemic case gets said explicitly,
     * because "❌ 35" reads like thirty-five problems when it is usually one.
     */
    private function displayFailures(GenerationReport $report): void
    {
        $this->newLine();
        $this->components->error(sprintf(
            '%d artifact(s) failed, in %d distinct case(s):',
            $report->failureCount(),
            count($report->failures),
        ));

        foreach ($report->failures as $failure) {
            $this->line(sprintf(
                '   <fg=red>×%-3d</> <options=bold>%s</> — %s',
                $failure['count'],
                $failure['type'],
                $failure['reason'],
            ));

            if ($failure['examples'] !== []) {
                $this->line('        <fg=gray>e.g. '.implode(', ', $failure['examples']).'</>');
            }
        }

        if ($report->hasSystemicFailure()) {
            $this->newLine();
            $this->components->warn(sprintf(
                'Every %s failed. That is one fault in the generator, not %d schema problems — fix the first '
                    .'reason above and the rest go with it.',
                implode(' and every ', $report->systemicTypes()),
                $report->failureCount(),
            ));
        }
    }

    /**
     * The exit code for the run.
     *
     * runPipeline() previously returned Command::SUCCESS unconditionally, so a run
     * that wrote nothing usable still exited 0 and nothing downstream noticed. A
     * partial failure is a failure.
     *
     * --ignore-failures is honoured when the command declares it, for workflows
     * that regenerate everything and genuinely tolerate a few losses. The flag is
     * checked through the definition rather than assumed, so this trait works in a
     * command whose signature has not been updated.
     */
    protected function resolveExitCode(GenerationReport $report): int
    {
        if (! $report->hasFailures()) {
            return Command::SUCCESS;
        }

        if ($this->getDefinition()->hasOption('ignore-failures') && (bool) $this->option('ignore-failures')) {
            $this->newLine();
            $this->components->warn(sprintf(
                'Exiting 0 because --ignore-failures was passed, despite %d failure(s).',
                $report->failureCount(),
            ));

            return Command::SUCCESS;
        }

        $this->newLine();
        $this->line(sprintf(
            '  <fg=gray>Exiting %d because %d artifact(s) failed. Pass --ignore-failures to exit 0 anyway.</>',
            Command::FAILURE,
            $report->failureCount(),
        ));

        return Command::FAILURE;
    }

    /**
     * Record a table that threw outright.
     *
     * generateArtifacts() caught \Exception and printed a message, which had two
     * consequences. An Error subclass — a TypeError, or a call to an undefined
     * method — is not an Exception, so it escaped the catch and killed the whole
     * run instead of costing one table. And nothing was appended to the results,
     * so the report never saw the failure and the exit code stayed 0 with errors
     * visible on screen.
     *
     * @return array{table: string, model: array<string, mixed>, artifacts: list<array<string, mixed>>}
     */
    protected function recordTableFailure(string $label, \Throwable $e): array
    {
        $this->newLine();
        $this->components->error(sprintf('%s: %s', $label, $e->getMessage()));

        if ($this->output->isVerbose()) {
            $this->line(sprintf('  <fg=gray>%s:%d</>', $e->getFile(), $e->getLine()));
        }

        return [
            'table' => $label,
            'model' => [
                'table' => $label,
                'status' => 'failed',
                // The class name matters: "Error" vs "QueryException" is the
                // difference between a package bug and a schema problem.
                'reason' => sprintf('%s: %s', class_basename($e), $e->getMessage()),
            ],
            'artifacts' => [],
        ];
    }
}
