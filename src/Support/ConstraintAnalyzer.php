<?php

namespace Zuqongtech\LaravelAnvil\Support;

class ConstraintAnalyzer
{
    protected array $tableCache = [];

    public function __construct(protected DatabaseInspector $inspector) {}

    /**
     * Analyze all constraints for a table
     */
    public function analyzeTable(string $table): array
    {
        if (isset($this->tableCache[$table])) {
            return $this->tableCache[$table];
        }

        $metadata = $this->inspector->getTableMetadata($table);

        $analysis = [
            'table' => $table,
            'primary_key' => $this->analyzePrimaryKey($metadata),
            'foreign_keys' => $this->analyzeForeignKeys($metadata),
            'indexes' => $this->analyzeIndexes($metadata),
            'unique_constraints' => $this->analyzeUniqueConstraints($metadata),
            'check_constraints' => $metadata['check_constraints'],
            'recommendations' => $this->generateRecommendations($metadata),
        ];

        $this->tableCache[$table] = $analysis;

        return $analysis;
    }

    /**
     * Analyze primary key configuration
     */
    protected function analyzePrimaryKey(array $metadata): array
    {
        $compositePk = $metadata['composite_primary_key'];
        $singlePk = $metadata['primary_key'];

        $analysis = [
            'type' => count($compositePk) > 1 ? 'composite' : 'single',
            'columns' => $compositePk,
            'primary_column' => $singlePk,
            'is_auto_increment' => false,
            'is_uuid' => false,
        ];

        // Check if auto-increment
        if (! empty($compositePk)) {
            $columns = $metadata['columns'];
            $pkColumn = collect($columns)->firstWhere('name', $compositePk[0]);

            if ($pkColumn) {
                $analysis['is_auto_increment'] = str_contains(strtolower($pkColumn['extra'] ?? ''), 'auto_increment') ||
                                                 str_contains(strtolower($pkColumn['extra'] ?? ''), 'serial');

                // Check if UUID type
                $type = strtolower((string) $pkColumn['type']);
                $analysis['is_uuid'] = str_contains($type, 'uuid') ||
                                      str_contains($type, 'char(36)') ||
                                      str_contains($type, 'varchar(36)');
            }
        }

        return $analysis;
    }

    /**
     * Analyze foreign keys
     */
    protected function analyzeForeignKeys(array $metadata): array
    {
        $foreignKeys = $metadata['foreign_keys'];

        return array_map(fn (array $fk) => [
            'column' => $fk['column'],
            'references' => [
                'table' => $fk['referenced_table'],
                'column' => $fk['referenced_column'],
            ],
            'constraint_name' => $fk['constraint_name'],
            'is_nullable' => $this->isColumnNullable($metadata['columns'], $fk['column']),
            'has_index' => $this->hasIndexOnColumn($metadata['indexes'], $fk['column']),
            'relationship_type' => $this->determineRelationshipType($fk, $metadata),
        ], $foreignKeys);
    }

    /**
     * Analyze indexes
     */
    protected function analyzeIndexes(array $metadata): array
    {
        $indexes = $metadata['indexes'];

        return array_map(function (array $idx): array {
            $columnCount = count($idx['columns']);

            return [
                'name' => $idx['name'],
                'columns' => array_map(fn (array $col) => $col['name'], $idx['columns']),
                'column_count' => $columnCount,
                'is_composite' => $columnCount > 1,
                'is_unique' => $idx['unique'],
                'is_primary' => $idx['primary'],
                'type' => $idx['type'],
                'column_order' => array_map(fn (array $col): array => [
                    'column' => $col['name'],
                    'order' => $col['order'] ?? 'ASC',
                ], $idx['columns']),
            ];
        }, $indexes);
    }

    /**
     * Analyze unique constraints
     */
    protected function analyzeUniqueConstraints(array $metadata): array
    {
        $uniqueConstraints = $metadata['unique_constraints'];

        return array_map(function (array $constraint) use ($metadata): array {
            $columns = array_map(fn (array $col) => $col['name'], $constraint['columns']);

            return [
                'name' => $constraint['name'],
                'columns' => $columns,
                'is_composite' => count($columns) > 1,
                'is_nullable' => $this->areColumnsNullable($metadata['columns'], $columns),
                'suggestion' => $this->suggestUniqueIndexUsage($columns, $metadata),
            ];
        }, $uniqueConstraints);
    }

    /**
     * Check if column is nullable
     */
    protected function isColumnNullable(array $columns, string $columnName): bool
    {
        $column = collect($columns)->firstWhere('name', $columnName);

        return $column ? ($column['nullable'] ?? false) : false;
    }

    /**
     * Check if any columns are nullable
     */
    protected function areColumnsNullable(array $columns, array $columnNames): bool
    {
        foreach ($columnNames as $name) {
            if ($this->isColumnNullable($columns, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if column has an index
     */
    protected function hasIndexOnColumn(array $indexes, string $columnName): bool
    {
        foreach ($indexes as $index) {
            $indexColumns = array_map(fn (array $col) => $col['name'], $index['columns']);
            if (in_array($columnName, $indexColumns)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine relationship type from foreign key
     */
    protected function determineRelationshipType(array $fk, array $metadata): string
    {
        $column = $fk['column'];

        // Check if foreign key is unique (one-to-one)
        foreach ($metadata['indexes'] as $index) {
            if ($index['unique']) {
                $indexColumns = array_map(fn (array $col) => $col['name'], $index['columns']);
                if (count($indexColumns) === 1 && $indexColumns[0] === $column) {
                    return 'one-to-one';
                }
            }
        }

        return 'one-to-many';
    }

    /**
     * Suggest unique index usage
     */
    protected function suggestUniqueIndexUsage(array $columns, array $metadata): ?string
    {
        if ($this->areColumnsNullable($metadata['columns'], $columns)) {
            return 'Consider making columns NOT NULL for stricter uniqueness enforcement';
        }

        return null;
    }

    /**
     * Generate recommendations for table structure
     */
    protected function generateRecommendations(array $metadata): array
    {
        $recommendations = [];

        // Check for missing primary key
        if (empty($metadata['composite_primary_key'])) {
            $recommendations[] = [
                'type' => 'warning',
                'category' => 'primary_key',
                'message' => 'Table has no primary key defined',
                'suggestion' => 'Add a primary key for better data integrity and performance',
            ];
        }

        // Check for foreign keys without indexes
        foreach ($metadata['foreign_keys'] as $fk) {
            if (! $this->hasIndexOnColumn($metadata['indexes'], $fk['column'])) {
                $recommendations[] = [
                    'type' => 'performance',
                    'category' => 'index',
                    'message' => sprintf("Foreign key column '%s' lacks an index", $fk['column']),
                    'suggestion' => sprintf("Add an index on '%s' for better query performance", $fk['column']),
                ];
            }
        }

        // Check for composite indexes that might be redundant
        $recommendations = array_merge(
            $recommendations,
            $this->checkRedundantIndexes($metadata['indexes'])
        );

        // Check for nullable foreign keys
        foreach ($metadata['foreign_keys'] as $fk) {
            if ($this->isColumnNullable($metadata['columns'], $fk['column'])) {
                $recommendations[] = [
                    'type' => 'info',
                    'category' => 'nullable_fk',
                    'message' => sprintf("Foreign key '%s' is nullable", $fk['column']),
                    'suggestion' => 'Ensure this is intentional for optional relationships',
                ];
            }
        }

        return $recommendations;
    }

    /**
     * Check for redundant indexes
     */
    protected function checkRedundantIndexes(array $indexes): array
    {
        $recommendations = [];
        $indexCount = count($indexes);

        for ($i = 0; $i < $indexCount; $i++) {
            for ($j = $i + 1; $j < $indexCount; $j++) {
                $idx1 = $indexes[$i];
                $idx2 = $indexes[$j];

                $cols1 = array_map(fn (array $col) => $col['name'], $idx1['columns']);
                $cols2 = array_map(fn (array $col) => $col['name'], $idx2['columns']);

                // Check if one index is a prefix of another
                if ($this->isIndexPrefix($cols1, $cols2)) {
                    $recommendations[] = [
                        'type' => 'optimization',
                        'category' => 'redundant_index',
                        'message' => sprintf("Index '%s' may be redundant with '%s'", $idx1['name'], $idx2['name']),
                        'suggestion' => 'Consider removing the shorter index if the longer one serves both purposes',
                    ];
                }
            }
        }

        return $recommendations;
    }

    /**
     * Check if one index is a prefix of another
     */
    protected function isIndexPrefix(array $cols1, array $cols2): bool
    {
        $shorter = count($cols1) < count($cols2) ? $cols1 : $cols2;
        $longer = count($cols1) < count($cols2) ? $cols2 : $cols1;

        if (count($shorter) === count($longer)) {
            return false;
        }
        $counter = count($shorter);

        for ($i = 0; $i < $counter; $i++) {
            if ($shorter[$i] !== $longer[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get constraint summary for all tables
     */
    public function getConstraintSummary(array $tables): array
    {
        $summary = [
            'total_tables' => count($tables),
            'tables_with_pk' => 0,
            'tables_without_pk' => 0,
            'total_foreign_keys' => 0,
            'total_indexes' => 0,
            'total_unique_constraints' => 0,
            'tables_with_issues' => 0,
            'all_recommendations' => [],
        ];

        foreach ($tables as $table) {
            $analysis = $this->analyzeTable($table);

            if (! empty($analysis['primary_key']['columns'])) {
                $summary['tables_with_pk']++;
            } else {
                $summary['tables_without_pk']++;
            }

            $summary['total_foreign_keys'] += count($analysis['foreign_keys']);
            $summary['total_indexes'] += count($analysis['indexes']);
            $summary['total_unique_constraints'] += count($analysis['unique_constraints']);

            if (! empty($analysis['recommendations'])) {
                $summary['tables_with_issues']++;
                $summary['all_recommendations'][$table] = $analysis['recommendations'];
            }
        }

        return $summary;
    }

    /**
     * Validate constraint integrity across tables
     */
    public function validateConstraintIntegrity(array $tables): array
    {
        $issues = [];

        foreach ($tables as $table) {
            $metadata = $this->inspector->getTableMetadata($table);

            foreach ($metadata['foreign_keys'] as $fk) {
                // Check if referenced table exists
                if (! in_array($fk['referenced_table'], $tables)) {
                    $issues[] = [
                        'table' => $table,
                        'type' => 'missing_referenced_table',
                        'foreign_key' => $fk['column'],
                        'referenced_table' => $fk['referenced_table'],
                        'message' => sprintf("Foreign key references non-existent table '%s'", $fk['referenced_table']),
                    ];
                }

                // Check if referenced column exists
                if (in_array($fk['referenced_table'], $tables)) {
                    $referencedColumns = $this->inspector->getColumns($fk['referenced_table']);
                    $referencedColumnNames = array_column($referencedColumns, 'name');

                    if (! in_array($fk['referenced_column'], $referencedColumnNames)) {
                        $issues[] = [
                            'table' => $table,
                            'type' => 'missing_referenced_column',
                            'foreign_key' => $fk['column'],
                            'referenced_table' => $fk['referenced_table'],
                            'referenced_column' => $fk['referenced_column'],
                            'message' => sprintf("Foreign key references non-existent column '%s' in table '%s'", $fk['referenced_column'], $fk['referenced_table']),
                        ];
                    }
                }
            }
        }

        return $issues;
    }
}
