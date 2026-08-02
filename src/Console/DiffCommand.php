<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Console;

use Illuminate\Console\Command;
use Zuqongtech\LaravelAnvil\Support\DatabaseInspector;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;
use Zuqongtech\LaravelAnvil\Support\SchemaManifest;

/**
 * What changed in the database since Anvil last ran?
 *
 *   php artisan anvil:diff                 # human-readable plan
 *   php artisan anvil:diff --json          # machine-readable
 *   php artisan anvil:diff --strict        # non-zero exit when anything drifted
 *   php artisan anvil:diff --accept        # record the current schema, generate nothing
 *
 * On a handful of tables this is a convenience. On a few hundred it is the
 * difference between regenerating everything and regenerating the four tables a
 * migration actually touched.
 *
 * --strict in CI catches the case that bites teams: someone shipped a migration
 * and did not regenerate, so the committed models, spec and TypeScript client all
 * describe a schema that no longer exists.
 */

class DiffCommand extends Command
{
    protected $description = 'Show what changed in the database since the last generation, and which artifacts are affected';
    protected $signature = 'anvil:diff
                            {--connection=  : Database connection to introspect}
                            {--schema=      : Schema(s) to inspect: name, csv list, or "all"}
                            {--tables=*     : Limit the comparison to specific tables}
                            {--ignore=*     : Exclude specific tables}
                            {--accept       : Record the current schema as the new baseline}
                            {--strict       : Exit non-zero if the schema has drifted}
                            {--json         : Output machine-readable JSON}';
    public function handle(): int
    {
        $connection = (string) ($this->option('connection') ?: config('database.default'));

        try {
            $inspector = new DatabaseInspector($connection);
        } catch (\Throwable $e) {
            $this->error('Could not connect to the database: ' . $e->getMessage());

            return self::FAILURE;
        }

        $manifest = SchemaManifest::load();
        $current = $this->introspect($inspector);

        if ($current === []) {
            $this->components->warn('No tables matched. Check --tables / --ignore and the ignore_tables config.');

            return self::SUCCESS;
        }

        $diff = $manifest->diff($current);
        $drifted = $diff['added'] !== [] || $diff['removed'] !== [] || $diff['changed'] !== [];

        if ($this->option('accept')) {
            return $this->accept($manifest, $current, $connection, $diff);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'baseline' => $manifest->generatedAt(),
                'connection' => $connection,
                'drifted' => $drifted,
                'diff' => $diff,
                'orphans' => $manifest->orphanedArtifacts($diff['removed']),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $drifted && $this->option('strict') ? self::FAILURE : self::SUCCESS;
        }

        $this->render($manifest, $diff, $drifted);

        return $drifted && $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string, ModelMetadata>
     */
    private function introspect(DatabaseInspector $inspector): array
    {
        $schema = $this->option('schema') ?: null;
        $only = array_map(strval(...), $this->option('tables') ?? []);

        $ignore = array_merge(
            (array) config('anvil.ignore_tables', []),
            array_map(strval(...), $this->option('ignore') ?? []),
        );

        $patterns = (array) config('anvil.ignore_table_patterns', []);
        $metadata = [];

        foreach ($inspector->getAllSchemaTables($schema) as $row) {
            $table = (string) ($row['table'] ?? '');

            if ($table === '' || in_array($table, $ignore, true)) {
                continue;
            }

            if ($only !== [] && ! in_array($table, $only, true)) {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (@preg_match((string) $pattern, $table) === 1) {
                    continue 2;
                }
            }

            try {
                $metadata[$table] = ModelMetadata::fromTable($table, $inspector, $row['schema'] ?? $schema);
            } catch (\Throwable $e) {
                $this->components->warn("Skipped {$table}: " . $e->getMessage());
            }
        }

        ksort($metadata);

        return $metadata;
    }

    /**
     * @param  array<string, ModelMetadata>  $current
     * @param  array<string, mixed>  $diff
     */
    private function accept(SchemaManifest $manifest, array $current, string $connection, array $diff): int
    {
        foreach ($diff['removed'] as $table) {
            $manifest->forget($table);
        }

        foreach ($current as $meta) {
            $manifest->record($meta);
        }

        if (! $manifest->save($connection)) {
            $this->error('Could not write ' . SchemaManifest::path());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Baseline recorded for %d table(s) in %s',
            count($current),
            str_replace(base_path() . '/', '', SchemaManifest::path()),
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $diff
     */
    private function render(SchemaManifest $manifest, array $diff, bool $drifted): void
    {
        $this->newLine();
        $this->line('  <fg=cyan;options=bold>⚒  Anvil — schema diff</>');

        if (! $manifest->exists()) {
            $this->newLine();
            $this->components->warn(
                'No baseline recorded yet, so every table reads as new. Run  php artisan anvil:diff --accept  '
                    . 'after your next generation to start tracking drift.'
            );
        } else {
            $this->line('  <fg=gray>Baseline: ' . $manifest->generatedAt() . '</>');
        }

        $this->newLine();

        if (! $drifted) {
            $this->line('  <fg=green>✔</> Schema matches the baseline. ' . count($diff['unchanged']) . ' table(s) unchanged.');
            $this->newLine();

            return;
        }

        foreach ($diff['added'] as $table) {
            $this->line('  <fg=green>+ ' . $table . '</> <fg=gray>new table</>');
            $this->line('      <fg=gray>' . $this->artifactsFor($table) . '</>');
        }

        foreach ($diff['removed'] as $table) {
            $this->line('  <fg=red>- ' . $table . '</> <fg=gray>dropped</>');
        }

        foreach ($diff['changed'] as $table => $change) {
            $this->line('  <fg=yellow>~ ' . $table . '</>');

            foreach ($change['columns'] as $column => [$before, $after]) {
                $line = match (true) {
                    $before === null => "column added: {$column} <fg=gray>({$after})</>",
                    $after === null => "column dropped: {$column}",
                    default => "column changed: {$column} <fg=gray>({$before} → {$after})</>",
                };

                $this->line('      ' . $line);
            }

            foreach ($change['keys'] as $key) {
                $this->line('      ' . $key);
            }

            foreach ($change['flags'] as $flag) {
                $this->line('      ' . $flag);
            }

            $this->line('      <fg=gray>' . $this->artifactsFor($table) . '</>');
        }

        // Files belonging to tables that no longer exist. Regeneration never
        // removes these: --force overwrites, it does not delete.
        $orphans = $manifest->orphanedArtifacts($diff['removed']);

        if ($orphans !== []) {
            $this->newLine();
            $this->line('  <options=bold>Orphaned artifacts</> <fg=gray>(tables dropped, files remain)</>');

            foreach ($orphans as $table => $paths) {
                $this->line('    ' . $table);

                foreach ($paths as $path) {
                    $this->line('      <fg=gray>' . $path . '</>');
                }
            }
        }

        $this->newLine();
        $this->line('  <options=bold>Next</>');
        $this->line('    php artisan anvil:generate --force ' . $this->tableFlags($diff));
        $this->line('    php artisan anvil:generate-api --force   <fg=gray>(regenerate the spec)</>');
        $this->line('    php artisan anvil:diff --accept          <fg=gray>(record the new baseline)</>');
        $this->newLine();
    }

    /**
     * The artifact families a table change touches. Deliberately coarse — the
     * point is "these are worth regenerating", not an exact file list.
     */
    private function artifactsFor(string $table): string
    {
        return 'affects: model, factory, request, resource, spec, TS client';
    }

    /**
     * @param  array<string, mixed>  $diff
     */
    private function tableFlags(array $diff): string
    {
        $tables = array_merge($diff['added'], array_keys($diff['changed']));

        if ($tables === [] || count($tables) > 8) {
            return '';
        }

        return implode(' ', array_map(static fn(string $t): string => "--tables={$t}", $tables));
    }
}
