<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Zuqongtech\LaravelAnvil\Support\DatabaseInspector;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Reports the schema shapes that break code generation, before they break it.
 *
 *   php artisan anvil:doctor
 *   php artisan anvil:doctor --tables=users --tables=vehicle_bookings
 *   php artisan anvil:doctor --json
 *   php artisan anvil:doctor --strict     # non-zero exit on any error
 *
 * Every check here exists because the failure it catches is expensive to diagnose
 * from the other end: a fatal redeclaration, an unparseable namespace, a login
 * that throws about bcrypt. The schema knows all of it up front.
 */
class DoctorCommand extends Command
{
    protected $description = 'Check the schema for shapes that break code generation';

    protected $signature = 'anvil:doctor
                            {--connection=  : Database connection to inspect}
                            {--schema=      : Schema(s) to inspect: name, csv list, or "all"}
                            {--tables=*     : Limit the check to specific tables}
                            {--ignore=*     : Exclude specific tables}
                            {--data         : Also run checks that read row data (password hashes)}
                            {--strict       : Exit non-zero when any error is found}
                            {--json         : Output machine-readable JSON}';

    private const ERROR = 'error';

    private const WARNING = 'warning';

    private const NOTE = 'note';

    /** Names that are not legal PHP namespace segments. */
    private const RESERVED_WORDS = [
        'public',
        'private',
        'protected',
        'static',
        'class',
        'interface',
        'trait',
        'enum',
        'function',
        'const',
        'namespace',
        'use',
        'new',
        'return',
        'list',
        'array',
        'default',
        'match',
        'fn',
        'readonly',
        'never',
        'void',
        'null',
        'true',
        'false',
        'int',
        'float',
        'string',
        'bool',
        'object',
        'iterable',
        'callable',
        'mixed',
        'parent',
        'self',
        'echo',
        'print',
        'exit',
        'die',
        'for',
        'foreach',
        'while',
        'do',
        'if',
        'else',
        'switch',
        'case',
    ];

    /** Model methods a generated relation or accessor must not shadow. */
    private const ELOQUENT_API = [
        'save',
        'delete',
        'update',
        'fill',
        'refresh',
        'replicate',
        'query',
        'casts',
        'toArray',
        'toJson',
        'getKey',
        'getTable',
        'getAttribute',
        'setAttribute',
        'newQuery',
        'touch',
        'restore',
        'trashed',
        'forceDelete',
        'push',
    ];

    /** @var list<array{severity: string, table: string, check: string, message: string, fix: string}> */
    private array $findings = [];

    public function handle(): int
    {
        $connection = (string) ($this->option('connection') ?: config('database.default'));

        try {
            $inspector = new DatabaseInspector($connection);
        } catch (\Throwable $e) {
            $this->error('Could not connect to the database: '.$e->getMessage());

            return self::FAILURE;
        }

        $tables = $this->introspect($inspector);

        if ($tables === []) {
            $this->components->warn('No tables matched.');

            return self::SUCCESS;
        }

        foreach ($tables as $meta) {
            $this->checkPrimaryKey($meta);
            $this->checkDuplicateForeignKeys($meta, $tables);
            $this->checkUnindexedForeignKeys($meta);
            $this->checkDanglingForeignKeys($meta, $tables);
            $this->checkReservedNames($meta);
            $this->checkColumnCollisions($meta);
            $this->checkAuthenticatable($meta, $connection);
            $this->checkEnumCandidates($meta);
            $this->checkWidth($meta);
        }

        $this->checkSchemaNames($tables);

        return $this->report(count($tables), $connection);
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

        $tables = [];

        foreach ($inspector->getAllSchemaTables($schema) as $row) {
            $table = (string) ($row['table'] ?? '');

            if ($table === '' || in_array($table, $ignore, true)) {
                continue;
            }

            if ($only !== [] && ! in_array($table, $only, true)) {
                continue;
            }

            try {
                $tables[$table] = ModelMetadata::fromTable($table, $inspector, $row['schema'] ?? $schema);
            } catch (\Throwable $e) {
                $this->add(self::ERROR, $table, 'introspection', 'Could not read the table: '.$e->getMessage(), 'Check permissions on this table.');
            }
        }

        ksort($tables);

        return $tables;
    }

    // -----------------------------------------------------------------------
    // Checks
    // -----------------------------------------------------------------------

    private function checkPrimaryKey(ModelMetadata $meta): void
    {
        if ($meta->primaryKey === null && $meta->compositePrimaryKey === []) {
            $this->add(
                self::WARNING,
                $meta->table,
                'primary-key',
                'No primary key. Route model binding, show/edit pages and spec path parameters have nothing to key on.',
                'Add a primary key, or expect read-only scaffolding for this table.',
            );

            return;
        }

        if (count($meta->compositePrimaryKey) > 1) {
            $this->add(
                self::NOTE,
                $meta->table,
                'primary-key',
                'Composite primary key ('.implode(', ', $meta->compositePrimaryKey).'). Eloquent has no first-class support.',
                'Generated models set $incrementing = false; route binding needs a custom resolveRouteBinding().',
            );
        }
    }

    /**
     * The fatal from this thread: two FKs from one table to the same parent
     * produce two identically named hasMany methods.
     *
     * @param  array<string, ModelMetadata>  $all
     */
    private function checkDuplicateForeignKeys(ModelMetadata $meta, array $all): void
    {
        $byParent = [];

        foreach ($meta->foreignKeys as $fk) {
            $parent = (string) ($fk['referenced_table'] ?? '');

            if ($parent !== '') {
                $byParent[$parent][] = (string) ($fk['column'] ?? '?');
            }
        }

        foreach ($byParent as $parent => $columns) {
            if (count($columns) < 2) {
                continue;
            }

            $this->add(
                self::WARNING,
                $meta->table,
                'duplicate-fk',
                sprintf(
                    '%d foreign keys to %s (%s). The inverse relations on %s collide unless they are qualified.',
                    count($columns),
                    $parent,
                    implode(', ', $columns),
                    Str::studly(Str::singular($parent)),
                ),
                'Anvil qualifies these from the column name (customerVehicleBookings, …). Verify the generated names read well.',
            );
        }
    }

    private function checkUnindexedForeignKeys(ModelMetadata $meta): void
    {
        $indexed = [];

        foreach ($meta->indexes as $index) {
            $columns = array_column($index['columns'] ?? [], 'name');

            if ($columns !== []) {
                // A composite index covers lookups on its leading column.
                $indexed[] = (string) $columns[0];
            }
        }

        foreach ($meta->compositePrimaryKey as $column) {
            $indexed[] = $column;
        }

        if ($meta->primaryKey !== null) {
            $indexed[] = $meta->primaryKey;
        }

        foreach ($meta->foreignKeys as $fk) {
            $column = (string) ($fk['column'] ?? '');

            if ($column === '' || in_array($column, $indexed, true)) {
                continue;
            }

            $this->add(
                self::NOTE,
                $meta->table,
                'fk-index',
                "Foreign key {$column} has no index. Every eager load and join on this relation is a sequential scan.",
                "CREATE INDEX ON {$meta->table} ({$column});",
            );
        }
    }

    /**
     * @param  array<string, ModelMetadata>  $all
     */
    private function checkDanglingForeignKeys(ModelMetadata $meta, array $all): void
    {
        foreach ($meta->foreignKeys as $fk) {
            $parent = (string) ($fk['referenced_table'] ?? '');

            if ($parent === '' || isset($all[$parent])) {
                continue;
            }

            $this->add(
                self::WARNING,
                $meta->table,
                'dangling-fk',
                sprintf('%s references %s, which is not in the generation set.', $fk['column'] ?? '?', $parent),
                'The belongsTo will point at a model that is never generated. Include the table, or add it to ignore_tables on both sides.',
            );
        }
    }

    private function checkReservedNames(ModelMetadata $meta): void
    {
        foreach ($meta->columns as $column) {
            $name = (string) $column['name'];

            if (in_array(strtolower($name), self::RESERVED_WORDS, true)) {
                $this->add(
                    self::NOTE,
                    $meta->table,
                    'reserved-word',
                    "Column \"{$name}\" is a PHP reserved word.",
                    'Fine as an attribute; avoid generating a method or enum case from it.',
                );
            }
        }

        if (in_array(strtolower(Str::singular($meta->table)), self::RESERVED_WORDS, true)) {
            $this->add(
                self::ERROR,
                $meta->table,
                'reserved-word',
                'The model name derived from this table is a PHP reserved word — the class will not parse.',
                'Map it in anvil.naming.custom_model_names.',
            );
        }
    }

    private function checkColumnCollisions(ModelMetadata $meta): void
    {
        foreach ($meta->columns as $column) {
            $name = (string) $column['name'];
            $camel = Str::camel($name);

            if (in_array($camel, self::ELOQUENT_API, true)) {
                $this->add(
                    self::ERROR,
                    $meta->table,
                    'model-collision',
                    "Column \"{$name}\" maps to {$camel}(), which is an Eloquent method.",
                    'A generated relation or accessor with this name would override core behaviour. Rename the column, or exclude it.',
                );
            }
        }

        // A relation named after the child table colliding with a column here.
        $columns = array_map(static fn (array $c): string => Str::camel((string) $c['name']), $meta->columns);
        $duplicates = array_values(array_diff_assoc($columns, array_unique($columns)));

        foreach (array_unique($duplicates) as $duplicate) {
            $this->add(
                self::WARNING,
                $meta->table,
                'model-collision',
                "Two columns both camelise to \"{$duplicate}\".",
                'One will shadow the other in casts and resources. Rename one.',
            );
        }
    }

    /**
     * A users-shaped table needs the Authenticatable base class, and its stored
     * hashes need to match the configured hasher.
     */
    private function checkAuthenticatable(ModelMetadata $meta, string $connection): void
    {
        $columns = array_map(static fn (array $c): string => (string) $c['name'], $meta->columns);

        if (! in_array('password', $columns, true)) {
            return;
        }

        $this->add(
            self::NOTE,
            $meta->table,
            'authenticatable',
            'Has a password column, so Laravel may authenticate against it.',
            'The model must extend Illuminate\\Foundation\\Auth\\User, not Model, or SessionGuard throws a TypeError. '
                .'Add it to anvil.protected_models so regeneration cannot clobber it.',
        );

        if (! $this->option('data')) {
            return;
        }

        // Reading rows is opt-in: this is the only check that touches data.
        try {
            $rows = DB::connection($connection)
                ->table($meta->table)
                ->whereNotNull('password')
                ->limit(50)
                ->pluck('password');

            $bad = 0;

            foreach ($rows as $hash) {
                if (password_get_info((string) $hash)['algoName'] === 'unknown') {
                    $bad++;
                }
            }

            if ($bad > 0) {
                $this->add(
                    self::ERROR,
                    $meta->table,
                    'password-hash',
                    "{$bad} of the first ".count($rows).' password value(s) are not a recognised hash.',
                    'Login throws "This password does not use the Bcrypt algorithm". Rehash the rows, or check your factories are not seeding plaintext.',
                );
            }
        } catch (\Throwable $e) {
            $this->add(self::NOTE, $meta->table, 'password-hash', 'Could not sample password values: '.$e->getMessage(), '');
        }
    }

    /**
     * Columns that look like enums but are typed as free text: a status varchar
     * with a CHECK constraint, or a name that implies a fixed set.
     */
    private function checkEnumCandidates(ModelMetadata $meta): void
    {
        foreach ($meta->columns as $column) {
            $name = (string) $column['name'];
            $type = strtolower((string) ($column['type'] ?? ''));

            if (! str_contains($type, 'char') && ! str_contains($type, 'text')) {
                continue;
            }

            if (! preg_match('/(^|_)(status|state|type|kind|role|tier|level|stage)$/', $name)) {
                continue;
            }

            $this->add(
                self::NOTE,
                $meta->table,
                'enum-candidate',
                "\"{$name}\" looks like a fixed set but is stored as free text.",
                'A CHECK constraint or a native enum would let Anvil generate a backed enum, a cast and an "in:" rule.',
            );
        }
    }

    private function checkWidth(ModelMetadata $meta): void
    {
        $count = count($meta->columns);

        if ($count > 60) {
            $this->add(
                self::NOTE,
                $meta->table,
                'width',
                "{$count} columns. Generated forms, tables and request rules will be unwieldy.",
                'Consider splitting the table, or narrow the scaffold with hidden/read_only config.',
            );
        }

        if (! $meta->timestamps) {
            $this->add(
                self::NOTE,
                $meta->table,
                'timestamps',
                'No created_at/updated_at pair.',
                'Listings fall back to ordering by the primary key, and $timestamps is set to false.',
            );
        }
    }

    /**
     * @param  array<string, ModelMetadata>  $tables
     */
    private function checkSchemaNames(array $tables): void
    {
        $schemas = array_unique(array_filter(array_map(
            static fn (ModelMetadata $meta): ?string => $meta->schema,
            $tables,
        )));

        foreach ($schemas as $schema) {
            if (in_array(strtolower((string) $schema), self::RESERVED_WORDS, true)) {
                $this->add(
                    self::WARNING,
                    (string) $schema,
                    'reserved-word',
                    "Schema \"{$schema}\" is a PHP reserved word, so App\\Models\\".Str::studly((string) $schema).' is not a legal namespace.',
                    'Anvil suffixes it (PublicSchema). Regenerate every model together, since the FQCN changes on both sides of each relation.',
                );
            }
        }
    }

    // -----------------------------------------------------------------------
    // Output
    // -----------------------------------------------------------------------

    private function add(string $severity, string $table, string $check, string $message, string $fix): void
    {
        $this->findings[] = compact('severity', 'table', 'check', 'message', 'fix');
    }

    private function report(int $tableCount, string $connection): int
    {
        $errors = $this->countOf(self::ERROR);
        $warnings = $this->countOf(self::WARNING);
        $notes = $this->countOf(self::NOTE);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'connection' => $connection,
                'tables' => $tableCount,
                'errors' => $errors,
                'warnings' => $warnings,
                'notes' => $notes,
                'findings' => $this->findings,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $errors > 0 && $this->option('strict') ? self::FAILURE : self::SUCCESS;
        }

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>⚒  Anvil — schema doctor</>');
        $this->line("  <fg=gray>{$connection}, {$tableCount} table(s)</>");
        $this->newLine();

        if ($this->findings === []) {
            $this->line('  <fg=green>✔</> Nothing to report.');
            $this->newLine();

            return self::SUCCESS;
        }

        $grouped = [];

        foreach ($this->findings as $finding) {
            $grouped[$finding['table']][] = $finding;
        }

        ksort($grouped);

        foreach ($grouped as $table => $findings) {
            $this->line('  <options=bold>'.$table.'</>');

            foreach ($findings as $finding) {
                [$icon, $colour] = match ($finding['severity']) {
                    self::ERROR => ['✘', 'red'],
                    self::WARNING => ['▲', 'yellow'],
                    default => ['•', 'gray'],
                };

                $this->line("    <fg={$colour}>{$icon}</> {$finding['message']}");

                if ($finding['fix'] !== '') {
                    $this->line("      <fg=gray>{$finding['fix']}</>");
                }
            }

            $this->newLine();
        }

        $this->line(sprintf(
            '  %s   %s   %s',
            $errors > 0 ? "<fg=red>{$errors} error(s)</>" : '0 errors',
            $warnings > 0 ? "<fg=yellow>{$warnings} warning(s)</>" : '0 warnings',
            "<fg=gray>{$notes} note(s)</>",
        ));

        if (! $this->option('data')) {
            $this->line('  <fg=gray>Pass --data to also sample password hashes.</>');
        }

        $this->newLine();

        return $errors > 0 && $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }

    private function countOf(string $severity): int
    {
        return count(array_filter($this->findings, static fn (array $f): bool => $f['severity'] === $severity));
    }
}
