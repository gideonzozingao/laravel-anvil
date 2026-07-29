<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

/**
 * Builds the cross-table relationship picture: who points at whom, which of
 * those are really 1:1, which tables are pure pivots, and which foreign keys are
 * broken.
 *
 * Everything here is schema-aware. buildForeignKeyMap() accepts either the
 * {schema, table} pairs the pipeline already enumerates, or a plain list of table
 * names (legacy callers) — but pass the pairs: without the schema, an inverse
 * relationship on a cross-schema child resolves into the parent's namespace and
 * generates a ::class reference to a class that was never written.
 */
class RelationshipDetector
{
    /** @var list<array{schema: ?string, table: string}> */
    protected array $tables = [];

    /**
     * Foreign keys per table, keyed by schema-qualified lookup key.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    protected array $foreignKeyMap = [];

    /**
     * Lookup key => {schema, table}. Contains both the qualified key and a bare
     * table-name fallback, so a foreign key that does not report a schema can
     * still be resolved to a real table.
     *
     * @var array<string, array{schema: ?string, table: string}>
     */
    protected array $lookup = [];

    /** @var array<string, array<string, mixed>> */
    protected array $metadataCache = [];

    protected ?string $defaultSchema = null;

    public function __construct(protected DatabaseInspector $inspector) {}

    /**
     * Build the foreign key map for the given tables.
     *
     * @param  list<string>|list<array{schema: ?string, table: string}>  $tables
     */
    public function buildForeignKeyMap(array $tables): void
    {
        $this->tables = [];
        $this->foreignKeyMap = [];
        $this->lookup = [];
        $this->metadataCache = [];
        $this->defaultSchema = $this->resolveDefaultSchema();

        foreach ($tables as $entry) {
            $pair = $this->normalizePair($entry);

            if ($pair === null) {
                continue;
            }

            $key = $this->key($pair['schema'], $pair['table']);

            if (isset($this->lookup[$key])) {
                continue;
            }

            $this->tables[] = $pair;
            $this->lookup[$key] = $pair;

            // Bare-name fallback: first table wins. Only consulted when a foreign
            // key gives us no schema at all.
            $bare = $this->key(null, $pair['table']);
            $this->lookup[$bare] ??= $pair;
        }

        foreach ($this->tables as $pair) {
            $metadata = $this->metadata($pair);

            $this->foreignKeyMap[$this->key($pair['schema'], $pair['table'])] = $this->normalizeForeignKeys(
                $metadata['foreign_keys'] ?? [],
                $pair,
            );
        }
    }

    /**
     * Inverse (child → parent becomes parent → children) relationships for a table.
     *
     * Each row carries everything ModelBuilder::addInverseRelationship() needs:
     * the child table (better pluralisation than the model name), its schema (for
     * cross-schema FQCN resolution) and whether the FK is unique (hasOne vs hasMany).
     *
     * @return list<array<string, mixed>>
     */
    public function getInverseRelationships(string $table, ?string $schema = null): array
    {
        $inverseRelations = [];

        foreach ($this->foreignKeyMap as $key => $foreignKeys) {
            $source = $this->lookup[$key] ?? null;

            if ($source === null) {
                continue;
            }

            foreach ($foreignKeys as $fk) {
                if (! $this->referencesTable($fk, $table, $schema)) {
                    continue;
                }

                $sourceTable = $source['table'];
                $sourceSchema = $source['schema'];
                $foreignKey = (string) ($fk['column'] ?? '');

                if ($foreignKey === '') {
                    continue;
                }

                $modelName = Helpers::tableToModelName($sourceTable);
                $unique = $this->shouldBeHasOne($sourceTable, $foreignKey, $sourceSchema);

                $inverseRelations[] = [
                    // A preference only — ModelBuilder / RelationNamer decide the
                    // final name once they know how many keys point this way.
                    'method' => Helpers::getInverseRelationName($modelName),
                    'model' => $modelName,
                    'source_table' => $sourceTable,
                    // 'table' and 'schema' are the names ModelBuilder reads.
                    'table' => $sourceTable,
                    'schema' => $sourceSchema,
                    'source_schema' => $sourceSchema,
                    'foreign_key' => $foreignKey,
                    'local_key' => (string) ($fk['referenced_column'] ?? 'id'),
                    'unique' => $unique,
                    'type' => $unique ? 'hasOne' : 'hasMany',
                ];
            }
        }

        return $inverseRelations;
    }

    /**
     * True when the child's foreign key can only ever match one row, making the
     * inverse a hasOne rather than a hasMany.
     *
     * Three cases count: a single-column unique index on the FK, a single-column
     * unique constraint on the FK, and the FK being the table's whole primary key
     * (the shared-primary-key 1:1 pattern, e.g. user_profiles.user_id).
     */
    public function shouldBeHasOne(string $sourceTable, string $foreignKey, ?string $schema = null): bool
    {
        $metadata = $this->metadata(['schema' => $schema, 'table' => $sourceTable]);
        $needle = [strtolower($foreignKey)];

        foreach ($metadata['indexes'] ?? [] as $index) {
            if (empty($index['unique']) && empty($index['is_unique'])) {
                continue;
            }

            if ($this->columnNames($index) === $needle) {
                return true;
            }
        }

        foreach ($metadata['unique_constraints'] ?? [] as $constraint) {
            if ($this->columnNames($constraint) === $needle) {
                return true;
            }
        }

        $composite = array_map(strtolower(...), array_map(strval(...), $metadata['composite_primary_key'] ?? []));

        if ($composite === $needle) {
            return true;
        }

        $primaryKey = $metadata['primary_key'] ?? null;

        if ($composite === [] && $primaryKey !== null && strcasecmp((string) $primaryKey, $foreignKey) === 0) {
            return true;
        }

        return false;
    }

    /**
     * Detect a many-to-many pivot table.
     *
     * A pivot typically has exactly two foreign keys and almost nothing else
     * beyond timestamps.
     *
     * @return array<string, mixed>
     */
    public function detectManyToMany(string $table, ?string $schema = null): array
    {
        $pair = $this->resolvePair($table, $schema) ?? ['schema' => $schema, 'table' => $table];
        $foreignKeys = $this->foreignKeyMap[$this->key($pair['schema'], $pair['table'])]
            ?? $this->normalizeForeignKeys($this->metadata($pair)['foreign_keys'] ?? [], $pair);

        if (count($foreignKeys) !== 2) {
            return [];
        }

        $columns = $this->metadata($pair)['columns'] ?? [];
        $foreignColumns = array_map(
            static fn (array $fk): string => strtolower((string) ($fk['column'] ?? '')),
            $foreignKeys,
        );

        $nonForeignColumns = [];

        foreach ($columns as $column) {
            $name = (string) ($column['name'] ?? '');

            if ($name === '' || in_array(strtolower($name), $foreignColumns, true)) {
                continue;
            }

            if (Helpers::isTimestampColumn($name)) {
                continue;
            }

            // A surrogate `id` on a pivot is common and does not disqualify it.
            if (strtolower($name) === 'id') {
                continue;
            }

            $nonForeignColumns[] = $name;
        }

        if (count($nonForeignColumns) > 2) {
            return [];
        }

        return [
            'pivot_table' => $pair['table'],
            'pivot_schema' => $pair['schema'],
            'model1' => Helpers::tableToModelName((string) $foreignKeys[0]['referenced_table']),
            'model2' => Helpers::tableToModelName((string) $foreignKeys[1]['referenced_table']),
            'foreign_key1' => (string) $foreignKeys[0]['column'],
            'foreign_key2' => (string) $foreignKeys[1]['column'],
            'table1' => (string) $foreignKeys[0]['referenced_table'],
            'table2' => (string) $foreignKeys[1]['referenced_table'],
            'schema1' => $foreignKeys[0]['referenced_schema'] ?? null,
            'schema2' => $foreignKeys[1]['referenced_schema'] ?? null,
            'extra_columns' => $nonForeignColumns,
        ];
    }

    /**
     * All pivot tables in the mapped set.
     *
     * @return list<array<string, mixed>>
     */
    public function getPivotTables(): array
    {
        $pivotTables = [];

        foreach ($this->tables as $pair) {
            $manyToMany = $this->detectManyToMany($pair['table'], $pair['schema']);

            if ($manyToMany !== []) {
                $pivotTables[] = $manyToMany;
            }
        }

        return $pivotTables;
    }

    /**
     * Detect polymorphic *_type / *_id column pairs.
     *
     * @return list<array{name: string, type_column: string, id_column: string}>
     */
    public function detectPolymorphic(string $table, ?string $schema = null): array
    {
        $pair = $this->resolvePair($table, $schema) ?? ['schema' => $schema, 'table' => $table];
        $columnNames = array_map(
            strval(...),
            array_column($this->metadata($pair)['columns'] ?? [], 'name'),
        );
        $lowered = array_map(strtolower(...), $columnNames);

        $polymorphic = [];

        foreach ($columnNames as $columnName) {
            if (! str_ends_with($columnName, '_type')) {
                continue;
            }

            $prefix = substr($columnName, 0, -5);
            $idColumn = $prefix.'_id';

            if (in_array(strtolower($idColumn), $lowered, true)) {
                $polymorphic[] = [
                    'name' => $prefix,
                    'type_column' => $columnName,
                    'id_column' => $idColumn,
                ];
            }
        }

        return $polymorphic;
    }

    /**
     * Validate every mapped foreign key against the tables actually present.
     *
     * @return list<array<string, mixed>>
     */
    public function validateForeignKeys(): array
    {
        $issues = [];

        foreach ($this->foreignKeyMap as $key => $foreignKeys) {
            $source = $this->lookup[$key] ?? null;

            if ($source === null) {
                continue;
            }

            $label = $this->label($source['schema'], $source['table']);

            foreach ($foreignKeys as $fk) {
                $referencedTable = (string) ($fk['referenced_table'] ?? '');
                $referencedColumn = (string) ($fk['referenced_column'] ?? '');
                $target = $this->resolvePair($referencedTable, $fk['referenced_schema'] ?? null);

                if ($target === null) {
                    $issues[] = [
                        'table' => $label,
                        'schema' => $source['schema'],
                        'column' => (string) ($fk['column'] ?? ''),
                        'issue' => 'Referenced table does not exist (or is outside the selected schema/tables)',
                        'referenced_table' => $referencedTable,
                    ];

                    // Do NOT fall through to the column check: introspecting a
                    // table that is not there throws, which used to abort the run.
                    continue;
                }

                if ($referencedColumn === '') {
                    continue;
                }

                $referencedColumnNames = array_map(
                    strtolower(...),
                    array_map(strval(...), array_column($this->metadata($target)['columns'] ?? [], 'name')),
                );

                if (! in_array(strtolower($referencedColumn), $referencedColumnNames, true)) {
                    $issues[] = [
                        'table' => $label,
                        'schema' => $source['schema'],
                        'column' => (string) ($fk['column'] ?? ''),
                        'issue' => 'Referenced column does not exist',
                        'referenced_table' => $referencedTable,
                        'referenced_column' => $referencedColumn,
                    ];
                }
            }
        }

        return $issues;
    }

    /**
     * The mapped {schema, table} pairs.
     *
     * @return list<array{schema: ?string, table: string}>
     */
    public function getTables(): array
    {
        return $this->tables;
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * @param  mixed  $entry
     * @return array{schema: ?string, table: string}|null
     */
    protected function normalizePair($entry): ?array
    {
        if (is_string($entry)) {
            return $entry === '' ? null : ['schema' => null, 'table' => $entry];
        }

        if (! is_array($entry)) {
            return null;
        }

        $table = (string) ($entry['table'] ?? '');

        if ($table === '') {
            return null;
        }

        $schema = $entry['schema'] ?? null;

        if ($schema !== null && trim((string) $schema) === '') {
            $schema = null;
        }

        return ['schema' => $schema === null ? null : (string) $schema, 'table' => $table];
    }

    /**
     * Normalise the inspector's foreign keys and make sure every row carries a
     * referenced_schema, resolving it from the mapped tables when the driver did
     * not report one. Cross-schema FQCN resolution depends on this.
     *
     * @param  array<int, array<string, mixed>>  $foreignKeys
     * @param  array{schema: ?string, table: string}  $source
     * @return list<array<string, mixed>>
     */
    protected function normalizeForeignKeys(array $foreignKeys, array $source): array
    {
        $normalized = [];

        foreach ($foreignKeys as $fk) {
            if (! is_array($fk) || ($fk['column'] ?? '') === '') {
                continue;
            }

            $referencedTable = (string) ($fk['referenced_table'] ?? '');
            $referencedSchema = $fk['referenced_schema'] ?? null;

            if ($referencedSchema !== null && trim((string) $referencedSchema) === '') {
                $referencedSchema = null;
            }

            if ($referencedSchema === null && $referencedTable !== '') {
                $target = $this->resolvePair($referencedTable, null);

                // Fall back to the child's own schema: a FK with no schema
                // information is overwhelmingly a same-schema reference.
                $referencedSchema = $target['schema'] ?? $source['schema'];
            }

            $fk['referenced_schema'] = $referencedSchema === null ? null : (string) $referencedSchema;
            $fk['referenced_column'] = (string) ($fk['referenced_column'] ?? 'id');
            $fk['source_table'] = $source['table'];
            $fk['source_schema'] = $source['schema'];

            $normalized[] = $fk;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $fk
     */
    protected function referencesTable(array $fk, string $table, ?string $schema): bool
    {
        $referencedTable = (string) ($fk['referenced_table'] ?? '');

        if ($referencedTable === '' || strcasecmp($referencedTable, $table) !== 0) {
            return false;
        }

        $referencedSchema = $fk['referenced_schema'] ?? null;

        // If either side is unknown, the table-name match is all we have.
        if ($schema === null || $referencedSchema === null) {
            return true;
        }

        return $this->sameSchema((string) $referencedSchema, $schema);
    }

    protected function sameSchema(?string $a, ?string $b): bool
    {
        $a = ($a === null || trim($a) === '') ? $this->defaultSchema : trim($a);
        $b = ($b === null || trim($b) === '') ? $this->defaultSchema : trim($b);

        if ($a === null || $b === null) {
            return $a === $b;
        }

        return strcasecmp($a, $b) === 0;
    }

    /**
     * @return array{schema: ?string, table: string}|null
     */
    protected function resolvePair(string $table, ?string $schema): ?array
    {
        if ($table === '') {
            return null;
        }

        if ($schema !== null && trim($schema) !== '') {
            $exact = $this->lookup[$this->key($schema, $table)] ?? null;

            if ($exact !== null) {
                return $exact;
            }

            // The default schema is often reported inconsistently (present on the
            // FK, absent on the table listing, or vice versa).
            if ($this->sameSchema($schema, $this->defaultSchema)) {
                return $this->lookup[$this->key(null, $table)] ?? null;
            }

            return null;
        }

        return $this->lookup[$this->key(null, $table)] ?? null;
    }

    /**
     * Introspect a table once, tolerating failure so one unreadable table cannot
     * abort the whole relationship pass.
     *
     * @param  array{schema: ?string, table: string}  $pair
     * @return array<string, mixed>
     */
    protected function metadata(array $pair): array
    {
        $key = $this->key($pair['schema'] ?? null, (string) $pair['table']);

        if (isset($this->metadataCache[$key])) {
            return $this->metadataCache[$key];
        }

        try {
            $metadata = $this->inspector->getTableMetadata((string) $pair['table'], $pair['schema'] ?? null);
        } catch (\Throwable) {
            $metadata = [];
        }

        return $this->metadataCache[$key] = is_array($metadata) ? $metadata : [];
    }

    protected function key(?string $schema, string $table): string
    {
        return strtolower(($schema ?? '').'.'.$table);
    }

    protected function label(?string $schema, string $table): string
    {
        return ($schema !== null && ! $this->sameSchema($schema, $this->defaultSchema))
            ? $schema.'.'.$table
            : $table;
    }

    /**
     * Column names of an index or unique constraint, lowercased. Tolerates the
     * shapes the various drivers hand back: a list of ['name' => ...], a list of
     * plain strings, or a single 'column' string.
     *
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    protected function columnNames(array $definition): array
    {
        $raw = $definition['columns'] ?? $definition['column'] ?? [];

        if (is_string($raw)) {
            $raw = [$raw];
        }

        $names = [];

        foreach ((array) $raw as $column) {
            $name = is_array($column) ? ($column['name'] ?? null) : $column;

            if (is_string($name) && $name !== '') {
                $names[] = strtolower($name);
            }
        }

        return $names;
    }

    protected function resolveDefaultSchema(): ?string
    {
        try {
            $schema = $this->inspector->defaultSchema();
        } catch (\Throwable) {
            return null;
        }

        return ($schema === null || trim($schema) === '') ? null : $schema;
    }
}
