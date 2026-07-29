<?php

namespace Zuqongtech\LaravelAnvil\Console;

use Illuminate\Console\Command;
use Throwable;
use Zuqongtech\LaravelAnvil\DocsSync\DocsSynchronizer;
use Zuqongtech\LaravelAnvil\DocsSync\SchemaChange;
use Zuqongtech\LaravelAnvil\DocsSync\SyncConfig;
use Zuqongtech\LaravelAnvil\DocsSync\SyncOptions;
use Zuqongtech\LaravelAnvil\DocsSync\SyncReport;

/**
 * Reconciles the OpenAPI spec with hand-edited resources and form requests.
 *
 * A thin adapter over DocsSynchronizer: it parses flags, renders the report and
 * returns an exit code. All merge safety rules live in the synchroniser so the
 * command, the `--docs-sync` pipeline flag and the local auto-sync hook cannot
 * drift apart in behaviour.
 */
class DocsSyncCommand extends Command
{
    /**
     * NOTE: the API version flag is `--api-version`, NOT `--version`.
     *
     * `--version` / `-V` is registered globally by Symfony's Application. Defining
     * it here throws "An option named \"version\" already exists." the moment
     * mergeApplicationDefinition() runs, and `artisan anvil:docs-sync --version=v1`
     * never even reaches the command -- Application::doRun() intercepts the flag,
     * prints the framework version and exits.
     *
     * `--api-version` also matches what anvil:generate-api already uses, which is
     * the name SyncsApiDocs reads.
     */
    protected $signature = 'anvil:docs-sync
        {model?* : Limit to these models or tables (e.g. Vehicle users)}
        {--api-version= : Limit to one API version and read that version\'s spec (e.g. v1)}
        {--only= : Limit to "requests" or "responses" (default: both)}
        {--check : Report drift and exit non-zero; never writes. For CI.}
        {--breaking-only : With --check, only fail on breaking drift}
        {--dry-run : Show what would change without writing}
        {--diff : Print per-property drift}
        {--adopt : Take ownership of components sync does not manage yet}
        {--no-prune : Never remove properties from the spec}
        {--install-hook : Install a pre-commit hook that runs --check}';

    protected $description = 'Sync hand-edited request/response payloads back into the OpenAPI spec';

    public function handle(): int
    {
        if ($this->option('install-hook')) {
            return $this->installHook();
        }

        $options = SyncOptions::fromArray([
            'models' => (array) $this->argument('model'),
            'version' => $this->option('api-version'),
            'only' => $this->option('only') ?: SyncOptions::ONLY_ALL,
            'check' => (bool) $this->option('check'),
            'breakingOnly' => (bool) $this->option('breaking-only'),
            'dryRun' => (bool) $this->option('dry-run'),
            'diff' => (bool) $this->option('diff'),
            'adopt' => (bool) $this->option('adopt'),
            'noPrune' => (bool) $this->option('no-prune'),
            'roots' => SyncConfig::roots(),
        ]);

        try {
            $synchronizer = $this->synchronizer();
            $report = $synchronizer->sync($options);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return 1;
        }

        $this->render($report, $options);

        return $report->exitCode($options->breakingOnly);
    }

    /**
     * Built per invocation, never resolved from the container.
     *
     * A bound DocsSynchronizer would be a singleton whose spec directory was fixed
     * when it was constructed, so `--api-version=v2` would silently reconcile v1's
     * spec. Custom readers are declared in anvil.openapi.sync.readers instead, which
     * keeps the version a per-run decision.
     */
    protected function synchronizer(): DocsSynchronizer
    {
        return SyncConfig::synchronizer($this->option('api-version') ?: null);
    }

    private function render(SyncReport $report, SyncOptions $options): void
    {
        $this->newLine();

        foreach ([SyncReport::SYNCED, SyncReport::STALE] as $status) {
            foreach ($report->withStatus($status) as $entry) {
                $verb = $status === SyncReport::SYNCED ? 'updated' : ($options->check ? 'stale' : 'would update');
                $this->line(sprintf('  <fg=cyan>%s</> <fg=gray>%s</> %s', $entry['component'], $verb, $this->severityTag($entry['changes'])));

                if ($options->diff || $options->check || $options->dryRun) {
                    foreach ($entry['changes'] as $change) {
                        $this->line('    '.$this->colourise($change));
                    }
                }

                foreach ($entry['notes'] as $note) {
                    $this->line("    <fg=yellow>note</> {$note}");
                }
            }
        }

        if ($unchanged = $report->count(SyncReport::UNCHANGED)) {
            $this->line("  <fg=gray>{$unchanged} component(s) already up to date</>");
        }

        // Grouped, because a systemic cause should read as one line, not 35.
        foreach ([SyncReport::SKIPPED => 'yellow', SyncReport::FAILED => 'red'] as $status => $colour) {
            foreach ($report->groupedReasons($status) as $reason => $count) {
                $this->line("  <fg={$colour}>{$status}</> ({$count}) {$reason}");
            }
        }

        $this->newLine();

        if ($written = $report->written()) {
            foreach ($written as $path) {
                $this->line('  <fg=green>wrote</> '.$this->relative($path));
            }

            $this->newLine();
        }

        $breaking = $report->breakingChanges();

        if ($breaking !== []) {
            $this->components->warn(sprintf(
                '%d breaking change(s) detected. Consider a new API version rather than mutating this one.',
                count($breaking),
            ));
        }

        $this->line('  <options=bold>'.$report->summaryLine().'</>');

        if ($options->check && $report->hasDrift()) {
            $this->newLine();
            $this->components->error('The OpenAPI spec is out of date. Run: php artisan anvil:docs-sync');
        }
    }

    /** @param list<SchemaChange> $changes */
    private function severityTag(array $changes): string
    {
        if ($changes === []) {
            return '';
        }

        $breaking = count(array_filter($changes, static fn (SchemaChange $c): bool => $c->isBreaking()));

        return $breaking > 0
            ? "<fg=red>({$breaking} breaking / ".count($changes).' changes)</>'
            : '<fg=gray>('.count($changes).' additive)</>';
    }

    private function colourise(SchemaChange $change): string
    {
        $colour = match ($change->severity) {
            SchemaChange::BREAKING => 'red',
            SchemaChange::ADDITIVE => 'green',
            default => 'gray',
        };

        $marker = match ($change->severity) {
            SchemaChange::BREAKING => '!',
            SchemaChange::ADDITIVE => '+',
            default => '~',
        };

        return "<fg={$colour}>{$marker}</> {$change->path}: <fg=gray>{$change->detail}</>";
    }

    private function relative(string $path): string
    {
        if (! function_exists('base_path')) {
            return $path;
        }

        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /**
     * Install a pre-commit hook running `--check`, so the spec cannot go stale
     * unnoticed between CI runs.
     */
    private function installHook(): int
    {
        $directory = base_path('.git/hooks');

        if (! is_dir($directory)) {
            $this->components->error('No .git/hooks directory found -- is this a git repository?');

            return 1;
        }

        $path = $directory.DIRECTORY_SEPARATOR.'pre-commit';
        $marker = '# >>> anvil docs-sync >>>';

        if (is_file($path) && str_contains((string) file_get_contents($path), $marker)) {
            $this->components->info('Hook already installed.');

            return 0;
        }

        $snippet = <<<SH
        {$marker}
        php artisan anvil:docs-sync --check || {
            echo "OpenAPI spec is out of date. Run: php artisan anvil:docs-sync"
            exit 1
        }
        # <<< anvil docs-sync <<<
        SH;

        $existing = is_file($path) ? rtrim((string) file_get_contents($path)) : '#!/bin/sh';

        if (@file_put_contents($path, $existing."\n\n".$snippet."\n") === false) {
            $this->components->error("Unable to write {$path}");

            return 1;
        }

        @chmod($path, 0o755);
        $this->components->info("Installed pre-commit hook at {$this->relative($path)}");

        return 0;
    }
}
