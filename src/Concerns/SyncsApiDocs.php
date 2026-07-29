<?php

namespace Zuqongtech\LaravelAnvil\Concerns;

use Throwable;
use Zuqongtech\LaravelAnvil\DocsSync\SyncConfig;
use Zuqongtech\LaravelAnvil\DocsSync\SyncOptions;
use Zuqongtech\LaravelAnvil\DocsSync\SyncReport;

/**
 * Adds `--docs-sync` / `--docs-check` to a generation command.
 *
 * Mix into GenerateCommand and call `syncApiDocs()` from finalize, AFTER the
 * pipeline has run. Ordering is not optional: sync reads the `{Model}` entity
 * schemas the OpenAPI generators write, so running it first would read stale
 * column types -- or none at all on a first generate.
 *
 * The flag exists so the common case is one command:
 *
 *     php artisan anvil:generate --api --openapi --docs-sync
 *
 * which regenerates from the database, then folds hand edits that survived back
 * into the spec.
 */
trait SyncsApiDocs
{
    /**
     * Option definitions to add to the command signature.
     *
     * @return list<string>
     */
    public static function docsSyncOptions(): array
    {
        return [
            'docs-sync : After generating, reconcile the spec with hand-edited resources and form requests',
            'docs-check : After generating, fail if the spec does not match the code (never writes)',
        ];
    }

    protected function wantsDocsSync(): bool
    {
        return (bool) $this->option('docs-sync') || (bool) $this->option('docs-check');
    }

    /**
     * @return int exit-code contribution: 0 when clean, 1 when a check failed
     */
    protected function syncApiDocs(): int
    {
        if (! $this->wantsDocsSync()) {
            return 0;
        }

        $this->newLine();
        $this->line('<options=bold>Reconciling OpenAPI spec with code</>');

        $version = $this->hasOption('api-version') ? $this->option('api-version') : null;

        $options = SyncOptions::fromArray([
            'models' => $this->docsSyncModels(),
            'version' => $version,
            'check' => (bool) $this->option('docs-check'),
            'roots' => SyncConfig::roots(),
        ]);

        try {
            // Same version the generators just wrote, so a `--api-version=v2` run
            // reconciles v2's spec rather than whatever api_version defaults to.
            $report = SyncConfig::synchronizer($version ?: null)->sync($options);
        } catch (Throwable $e) {
            $this->components->error('Docs sync failed: '.$e->getMessage());

            return 1;
        }

        foreach ($report->withStatus(SyncReport::SYNCED) as $entry) {
            $this->line("  <fg=cyan>{$entry['component']}</> <fg=gray>updated</>");
        }

        foreach ($report->withStatus(SyncReport::STALE) as $entry) {
            $this->line("  <fg=yellow>{$entry['component']}</> <fg=gray>stale</>");
        }

        foreach ([SyncReport::SKIPPED, SyncReport::FAILED] as $status) {
            foreach ($report->groupedReasons($status) as $reason => $count) {
                $colour = $status === SyncReport::FAILED ? 'red' : 'yellow';
                $this->line("  <fg={$colour}>{$status}</> ({$count}) {$reason}");
            }
        }

        if ($breaking = $report->breakingChanges()) {
            $this->components->warn(sprintf('%d breaking payload change(s) -- consider a new API version.', count($breaking)));

            foreach (array_slice($breaking, 0, 10) as $change) {
                $this->line("    <fg=red>!</> {$change->path}: <fg=gray>{$change->detail}</>");
            }
        }

        $this->line('  <options=bold>'.$report->summaryLine().'</>');

        return $report->exitCode();
    }

    /**
     * Reuse whatever table/model arguments the generation command already took, so
     * `--docs-sync` is scoped the same way the generate run was.
     *
     * @return list<string>
     */
    protected function docsSyncModels(): array
    {
        foreach (['table', 'tables', 'model', 'models'] as $name) {
            if (! $this->hasArgument($name)) {
                continue;
            }

            $value = $this->argument($name);

            if ($value === null || $value === []) {
                continue;
            }

            return array_values(array_map(strval(...), is_array($value) ? $value : [$value]));
        }

        return [];
    }
}
