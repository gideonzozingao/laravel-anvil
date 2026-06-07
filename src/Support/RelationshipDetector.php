<?php

namespace Zuqongtech\LaravelAnvil\Support;

class RelationshipDetector
{
    protected array $allTables = [];

    protected array $foreignKeyMap = [];

    public function __construct(protected DatabaseInspector $inspector) {}

    /**
     * Build foreign key map for all tables
     */
    public function buildForeignKeyMap(array $tables): void
    {
        $this->allTables = $tables;
        $this->foreignKeyMap = [];

        foreach ($tables as $table) {
            $foreignKeys = $this->inspector->getForeignKeys($table);
            $this->foreignKeyMap[$table] = $foreignKeys;
        }
    }

    /**
     * Get inverse relationships for a table
     */
    public function getInverseRelationships(string $table): array
    {
        $inverseRelations = [];

        // Find all tables that reference this table
        foreach ($this->foreignKeyMap as $sourceTable => $foreignKeys) {
            foreach ($foreignKeys as $fk) {
                if ($fk['referenced_table'] === $table) {
                    $modelName = Helpers::tableToModelName($sourceTable);
                    $methodName = Helpers::getInverseRelationName($modelName);

                    $inverseRelations[] = [
                        'method' => $methodName,
                        'model' => $modelName,
                        'source_table' => $sourceTable,
                        'foreign_key' => $fk['column'],
                        'local_key' => $fk['referenced_column'],
                        'type' => 'hasMany',
                    ];
                }
            }
        }

        return $inverseRelations;
    }

    /**
     * Detect if relationship should be hasOne instead of hasMany
     */
    public function shouldBeHasOne(string $sourceTable, string $foreignKey): bool
    {
        // Check if the foreign key is unique
        $indexes = $this->inspector->getIndexes($sourceTable);

        foreach ($indexes as $index) {
            if ($index['unique'] && $index['column'] === $foreignKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect many-to-many relationships
     */
    public function detectManyToMany(string $table): array
    {
        $manyToMany = [];

        // A pivot table typically:
        // 1. Has exactly 2 foreign keys
        // 2. May have additional columns like timestamps
        // 3. Often named as model1_model2

        $foreignKeys = $this->foreignKeyMap[$table] ?? [];

        if (count($foreignKeys) === 2) {
            $columns = $this->inspector->getColumns($table);
            $nonForeignColumns = [];

            foreach ($columns as $column) {
                $isForeign = false;
                foreach ($foreignKeys as $fk) {
                    if ($column['name'] === $fk['column']) {
                        $isForeign = true;
                        break;
                    }
                }

                // Count columns that aren't foreign keys and aren't timestamps
                if (! $isForeign && ! Helpers::isTimestampColumn($column['name'])) {
                    $nonForeignColumns[] = $column['name'];
                }
            }

            // If there are minimal additional columns, it's likely a pivot
            if (count($nonForeignColumns) <= 2) {
                $manyToMany = [
                    'pivot_table' => $table,
                    'model1' => Helpers::tableToModelName($foreignKeys[0]['referenced_table']),
                    'model2' => Helpers::tableToModelName($foreignKeys[1]['referenced_table']),
                    'foreign_key1' => $foreignKeys[0]['column'],
                    'foreign_key2' => $foreignKeys[1]['column'],
                    'table1' => $foreignKeys[0]['referenced_table'],
                    'table2' => $foreignKeys[1]['referenced_table'],
                ];
            }
        }

        return $manyToMany;
    }

    /**
     * Get all pivot tables
     */
    public function getPivotTables(): array
    {
        $pivotTables = [];

        foreach ($this->allTables as $table) {
            $manyToMany = $this->detectManyToMany($table);
            if ($manyToMany !== []) {
                $pivotTables[] = $manyToMany;
            }
        }

        return $pivotTables;
    }

    /**
     * Detect polymorphic relationships
     */
    public function detectPolymorphic(string $table): array
    {
        $polymorphic = [];
        $columns = $this->inspector->getColumns($table);
        $columnNames = array_column($columns, 'name');

        // Look for *_type and *_id pairs
        foreach ($columnNames as $columnName) {
            if (str_ends_with((string) $columnName, '_type')) {
                $prefix = substr((string) $columnName, 0, -5);
                $idColumn = $prefix.'_id';

                if (in_array($idColumn, $columnNames)) {
                    $polymorphic[] = [
                        'name' => $prefix,
                        'type_column' => $columnName,
                        'id_column' => $idColumn,
                    ];
                }
            }
        }

        return $polymorphic;
    }

    /**
     * Get relationship summary for a table
     */
    public function getRelationshipSummary(string $table): array
    {
        return [
            'belongs_to' => $this->foreignKeyMap[$table] ?? [],
            'has_many' => $this->getInverseRelationships($table),
            'many_to_many' => $this->detectManyToMany($table),
            'polymorphic' => $this->detectPolymorphic($table),
        ];
    }

    /**
     * Determine relationship cardinality
     */
    public function determineCardinality(string $sourceTable, string $targetTable): string
    {
        $foreignKeys = $this->foreignKeyMap[$sourceTable] ?? [];

        foreach ($foreignKeys as $fk) {
            if ($fk['referenced_table'] === $targetTable) {
                return $this->shouldBeHasOne($sourceTable, $fk['column']) ? 'one' : 'many';
            }
        }

        return 'many';
    }

    /**
     * Check if table is a pivot table
     */
    public function isPivotTable(string $table): bool
    {
        $manyToMany = $this->detectManyToMany($table);

        return $manyToMany !== [];
    }

    /**
     * Get relationship method name for many-to-many
     */
    public function getManyToManyMethodName(string $relatedTable): string
    {
        return Helpers::getInverseRelationName(
            Helpers::tableToModelName($relatedTable)
        );
    }

    /**
     * Validate foreign key references
     */
    public function validateForeignKeys(): array
    {
        $issues = [];

        foreach ($this->foreignKeyMap as $table => $foreignKeys) {
            foreach ($foreignKeys as $fk) {
                // Check if referenced table exists
                if (! in_array($fk['referenced_table'], $this->allTables)) {
                    $issues[] = [
                        'table' => $table,
                        'column' => $fk['column'],
                        'issue' => 'Referenced table does not exist',
                        'referenced_table' => $fk['referenced_table'],
                    ];
                }

                // Check if referenced column exists
                $referencedColumns = $this->inspector->getColumns($fk['referenced_table']);
                $referencedColumnNames = array_column($referencedColumns, 'name');

                if (! in_array($fk['referenced_column'], $referencedColumnNames)) {
                    $issues[] = [
                        'table' => $table,
                        'column' => $fk['column'],
                        'issue' => 'Referenced column does not exist',
                        'referenced_table' => $fk['referenced_table'],
                        'referenced_column' => $fk['referenced_column'],
                    ];
                }
            }
        }

        return $issues;
    }
}
