<?php

namespace Zuqongtech\LaravelAnvil\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseInspector
{
    protected string $connectionName;

    protected $connection;

    protected string $driver;

    public function __construct(?string $connectionName = null)
    {
        $this->connectionName = $connectionName ?: config('database.default');
        $this->connection = DB::connection($this->connectionName);
        $this->driver = $this->connection->getDriverName();
    }

    /**
     * Get database driver name
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * Get database name
     */
    public function getDatabaseName(): string
    {
        return $this->connection->getDatabaseName();
    }

    /**
     * Get all tables in the database
     */
    public function getAllTables(): array
    {
        $tables = match ($this->driver) {
            'mysql' => collect($this->connection->select('SHOW TABLES'))
                ->map(fn ($t): mixed => array_values((array) $t)[0])
                ->toArray(),

            'pgsql' => collect($this->connection->select("
                SELECT tablename 
                FROM pg_tables 
                WHERE schemaname = 'public'
                ORDER BY tablename
            "))->pluck('tablename')->toArray(),

            'sqlite' => collect($this->connection->select("
                SELECT name 
                FROM sqlite_master 
                WHERE type='table' 
                AND name NOT LIKE 'sqlite_%'
                ORDER BY name
            "))->pluck('name')->toArray(),

            'sqlsrv' => collect($this->connection->select("
                SELECT TABLE_NAME 
                FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_TYPE = 'BASE TABLE'
                ORDER BY TABLE_NAME
            "))->pluck('TABLE_NAME')->toArray(),

            default => throw new \Exception('Unsupported database driver: '.$this->driver)
        };

        return array_values($tables);
    }

    /**
     * Get columns for a table with metadata
     */
    public function getColumns(string $table): array
    {
        return match ($this->driver) {
            'mysql' => $this->getMysqlColumns($table),
            'pgsql' => $this->getPostgresColumns($table),
            'sqlite' => $this->getSqliteColumns($table),
            'sqlsrv' => $this->getSqlServerColumns($table),
            default => []
        };
    }

    /**
     * Get MySQL columns
     */
    protected function getMysqlColumns(string $table): array
    {
        $columns = $this->connection->select(sprintf('SHOW FULL COLUMNS FROM `%s`', $table));

        return array_map(fn ($col): array => [
            'name' => $col->Field,
            'type' => $col->Type,
            'nullable' => $col->Null === 'YES',
            'default' => $col->Default,
            'extra' => $col->Extra,
            'comment' => $col->Comment,
            'key' => $col->Key,
            'collation' => $col->Collation ?? null,
        ], $columns);
    }

    /**
     * Get PostgreSQL columns
     */
    protected function getPostgresColumns(string $table): array
    {
        $columns = $this->connection->select("
            SELECT 
                column_name,
                data_type,
                udt_name,
                is_nullable,
                column_default,
                character_maximum_length,
                numeric_precision,
                numeric_scale,
                COALESCE(col_description((table_schema||'.'||table_name)::regclass::oid, ordinal_position), '') as column_comment
            FROM information_schema.columns
            WHERE table_name = ?
            ORDER BY ordinal_position
        ", [$table]);

        return array_map(fn ($col): array => [
            'name' => $col->column_name,
            'type' => $col->data_type,
            'udt_name' => $col->udt_name,
            'nullable' => $col->is_nullable === 'YES',
            'default' => $col->column_default,
            'extra' => str_contains($col->column_default ?? '', 'nextval') ? 'auto_increment' : '',
            'comment' => $col->column_comment,
            'key' => '',
            'max_length' => $col->character_maximum_length,
            'precision' => $col->numeric_precision,
            'scale' => $col->numeric_scale,
        ], $columns);
    }

    /**
     * Get SQLite columns
     */
    protected function getSqliteColumns(string $table): array
    {
        $columns = $this->connection->select(sprintf('PRAGMA table_info(`%s`)', $table));

        return array_map(fn ($col): array => [
            'name' => $col->name,
            'type' => $col->type,
            'nullable' => $col->notnull == 0,
            'default' => $col->dflt_value,
            'extra' => $col->pk == 1 ? 'auto_increment' : '',
            'comment' => '',
            'key' => $col->pk == 1 ? 'PRI' : '',
        ], $columns);
    }

    /**
     * Get SQL Server columns
     */
    protected function getSqlServerColumns(string $table): array
    {
        $columns = $this->connection->select("
            SELECT 
                c.COLUMN_NAME as column_name,
                c.DATA_TYPE as data_type,
                c.IS_NULLABLE as is_nullable,
                c.COLUMN_DEFAULT as column_default,
                c.CHARACTER_MAXIMUM_LENGTH as max_length,
                COLUMNPROPERTY(OBJECT_ID(c.TABLE_SCHEMA + '.' + c.TABLE_NAME), c.COLUMN_NAME, 'IsIdentity') as is_identity,
                CAST(ep.value AS NVARCHAR(MAX)) as column_comment
            FROM INFORMATION_SCHEMA.COLUMNS c
            LEFT JOIN sys.extended_properties ep 
                ON ep.major_id = OBJECT_ID(c.TABLE_SCHEMA + '.' + c.TABLE_NAME)
                AND ep.minor_id = COLUMNPROPERTY(OBJECT_ID(c.TABLE_SCHEMA + '.' + c.TABLE_NAME), c.COLUMN_NAME, 'ColumnId')
                AND ep.name = 'MS_Description'
            WHERE c.TABLE_NAME = ?
            ORDER BY c.ORDINAL_POSITION
        ", [$table]);

        return array_map(fn ($col): array => [
            'name' => $col->column_name,
            'type' => $col->data_type,
            'nullable' => $col->is_nullable === 'YES',
            'default' => $col->column_default,
            'extra' => $col->is_identity ? 'auto_increment' : '',
            'comment' => $col->column_comment ?? '',
            'key' => '',
        ], $columns);
    }

    /**
     * Get primary key for a table
     */
    public function getPrimaryKey(string $table): ?string
    {
        try {
            $result = match ($this->driver) {
                'mysql' => collect($this->connection->select("
                    SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'
                "))->pluck('Column_name')->first(),

                'pgsql' => $this->connection->selectOne('
                    SELECT a.attname
                    FROM pg_index i
                    JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
                    WHERE i.indrelid = ?::regclass AND i.indisprimary
                ', [$table])->attname ?? null,

                'sqlite' => collect($this->connection->select(sprintf('PRAGMA table_info(`%s`)', $table)))
                    ->where('pk', 1)
                    ->pluck('name')
                    ->first(),

                'sqlsrv' => $this->connection->selectOne("
                    SELECT COLUMN_NAME
                    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                    WHERE OBJECTPROPERTY(OBJECT_ID(CONSTRAINT_SCHEMA + '.' + CONSTRAINT_NAME), 'IsPrimaryKey') = 1
                    AND TABLE_NAME = ?
                ", [$table])->COLUMN_NAME ?? null,

                default => null,
            };

            return $result ?? 'id';
        } catch (\Exception) {
            return 'id';
        }
    }

    /**
     * Get composite primary key columns
     */
    public function getCompositePrimaryKey(string $table): array
    {
        try {
            return match ($this->driver) {
                'mysql' => collect($this->connection->select("
                    SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'
                    ORDER BY Seq_in_index
                "))->pluck('Column_name')->toArray(),

                'pgsql' => collect($this->connection->select('
                    SELECT a.attname
                    FROM pg_index i
                    JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
                    WHERE i.indrelid = ?::regclass AND i.indisprimary
                    ORDER BY array_position(i.indkey, a.attnum)
                ', [$table]))->pluck('attname')->toArray(),

                'sqlite' => collect($this->connection->select(sprintf('PRAGMA table_info(`%s`)', $table)))
                    ->where('pk', '>', 0)
                    ->sortBy('pk')
                    ->pluck('name')
                    ->toArray(),

                'sqlsrv' => collect($this->connection->select("
                    SELECT COLUMN_NAME
                    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                    WHERE OBJECTPROPERTY(OBJECT_ID(CONSTRAINT_SCHEMA + '.' + CONSTRAINT_NAME), 'IsPrimaryKey') = 1
                    AND TABLE_NAME = ?
                    ORDER BY ORDINAL_POSITION
                ", [$table]))->pluck('COLUMN_NAME')->toArray(),

                default => [],
            };
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Get foreign keys for a table
     */
    public function getForeignKeys(string $table): array
    {
        $foreignKeys = match ($this->driver) {
            'mysql' => $this->connection->select('
                SELECT
                    COLUMN_NAME as column_name,
                    REFERENCED_TABLE_NAME as referenced_table_name,
                    REFERENCED_COLUMN_NAME as referenced_column_name,
                    CONSTRAINT_NAME as constraint_name
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND REFERENCED_TABLE_NAME IS NOT NULL
            ', [$table]),

            'pgsql' => $this->connection->select("
                SELECT
                    kcu.column_name,
                    ccu.table_name AS referenced_table_name,
                    ccu.column_name AS referenced_column_name,
                    tc.constraint_name
                FROM information_schema.table_constraints AS tc
                JOIN information_schema.key_column_usage AS kcu
                    ON tc.constraint_name = kcu.constraint_name 
                    AND tc.table_schema = kcu.table_schema
                JOIN information_schema.constraint_column_usage AS ccu
                    ON ccu.constraint_name = tc.constraint_name 
                    AND ccu.table_schema = tc.table_schema
                WHERE tc.constraint_type = 'FOREIGN KEY' 
                AND tc.table_name = ?
            ", [$table]),

            'sqlite' => collect($this->connection->select(sprintf('PRAGMA foreign_key_list(`%s`)', $table)))
                ->map(fn ($fk) => (object) [
                    'column_name' => $fk->from,
                    'referenced_table_name' => $fk->table,
                    'referenced_column_name' => $fk->to,
                    'constraint_name' => null,
                ])
                ->toArray(),

            'sqlsrv' => $this->connection->select('
                SELECT
                    fkc.COLUMN_NAME AS column_name,
                    pk.TABLE_NAME AS referenced_table_name,
                    pkc.COLUMN_NAME AS referenced_column_name,
                    fk.CONSTRAINT_NAME as constraint_name
                FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS AS rc
                JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS AS fk 
                    ON rc.CONSTRAINT_NAME = fk.CONSTRAINT_NAME
                JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS AS pk 
                    ON rc.UNIQUE_CONSTRAINT_NAME = pk.CONSTRAINT_NAME
                JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE AS fkc 
                    ON rc.CONSTRAINT_NAME = fkc.CONSTRAINT_NAME
                JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE AS pkc 
                    ON pk.CONSTRAINT_NAME = pkc.CONSTRAINT_NAME
                WHERE fk.TABLE_NAME = ?
            ', [$table]),

            default => [],
        };

        return array_map(fn ($fk): array => [
            'column' => $fk->column_name,
            'referenced_table' => $fk->referenced_table_name,
            'referenced_column' => $fk->referenced_column_name,
            'constraint_name' => $fk->constraint_name ?? null,
        ], is_array($foreignKeys) ? $foreignKeys : []);
    }

    /**
     * Get all indexes for a table (including unique constraints)
     */
    public function getIndexes(string $table): array
    {
        return match ($this->driver) {
            'mysql' => $this->getMysqlIndexes($table),
            'pgsql' => $this->getPostgresIndexes($table),
            'sqlite' => $this->getSqliteIndexes($table),
            'sqlsrv' => $this->getSqlServerIndexes($table),
            default => [],
        };
    }

    /**
     * Get MySQL indexes
     */
    protected function getMysqlIndexes(string $table): array
    {
        $rawIndexes = $this->connection->select(sprintf('SHOW INDEX FROM `%s`', $table));

        $grouped = [];
        foreach ($rawIndexes as $idx) {
            $name = $idx->Key_name;

            if (! isset($grouped[$name])) {
                $grouped[$name] = [
                    'name' => $name,
                    'columns' => [],
                    'unique' => $idx->Non_unique == 0,
                    'primary' => $name === 'PRIMARY',
                    'type' => $idx->Index_type,
                ];
            }

            $grouped[$name]['columns'][] = [
                'name' => $idx->Column_name,
                'order' => $idx->Collation === 'D' ? 'DESC' : 'ASC',
                'length' => $idx->Sub_part,
            ];
        }

        return array_values($grouped);
    }

    /**
     * Get PostgreSQL indexes
     */
    protected function getPostgresIndexes(string $table): array
    {
        $rawIndexes = $this->connection->select('
        SELECT
            i.relname as index_name,
            array_agg(a.attname ORDER BY array_position(ix.indkey, a.attnum)) as columns,
            ix.indisunique as is_unique,
            ix.indisprimary as is_primary,
            am.amname as index_type
        FROM pg_class t
        JOIN pg_index ix ON t.oid = ix.indrelid
        JOIN pg_class i ON i.oid = ix.indexrelid
        JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
        JOIN pg_am am ON i.relam = am.oid
        WHERE t.relname = ?
        GROUP BY i.relname, ix.indisunique, ix.indisprimary, am.amname
    ', [$table]);

        return array_map(function ($idx): array {
            // Convert PostgreSQL array string to PHP array
            $columns = $idx->columns;

            // Handle PostgreSQL array format: {col1,col2,col3}
            if (is_string($columns)) {
                $columns = trim($columns, '{}');
                $columns = $columns !== '' && $columns !== '0' ? explode(',', $columns) : [];
            }

            // Ensure it's an array
            if (! is_array($columns)) {
                $columns = [];
            }

            return [
                'name' => $idx->index_name,
                'columns' => array_map(fn ($col): array => ['name' => $col, 'order' => 'ASC'], $columns),
                'unique' => $idx->is_unique,
                'primary' => $idx->is_primary,
                'type' => strtoupper((string) $idx->index_type),
            ];
        }, $rawIndexes);
    }

    /**
     * Get SQLite indexes
     */
    protected function getSqliteIndexes(string $table): array
    {
        $rawIndexes = $this->connection->select(sprintf('PRAGMA index_list(`%s`)', $table));

        $indexes = [];
        foreach ($rawIndexes as $idx) {
            $indexInfo = $this->connection->select(sprintf('PRAGMA index_info(`%s`)', $idx->name));

            $columns = array_map(fn ($col): array => [
                'name' => $col->name,
                'order' => 'ASC',
            ], $indexInfo);

            $indexes[] = [
                'name' => $idx->name,
                'columns' => $columns,
                'unique' => $idx->unique == 1,
                'primary' => $idx->origin === 'pk',
                'type' => 'BTREE',
            ];
        }

        return $indexes;
    }

    /**
     * Get SQL Server indexes
     */
    protected function getSqlServerIndexes(string $table): array
    {
        $rawIndexes = $this->connection->select("
            SELECT 
                i.name as index_name,
                i.is_unique,
                i.is_primary_key,
                i.type_desc,
                STRING_AGG(c.name, ',') WITHIN GROUP (ORDER BY ic.key_ordinal) as columns
            FROM sys.indexes i
            JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id
            JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id
            WHERE i.object_id = OBJECT_ID(?)
            GROUP BY i.name, i.is_unique, i.is_primary_key, i.type_desc
        ", [$table]);

        return array_map(fn ($idx): array => [
            'name' => $idx->index_name,
            'columns' => array_map(fn ($col): array => ['name' => $col, 'order' => 'ASC'], explode(',', (string) $idx->columns)),
            'unique' => $idx->is_unique,
            'primary' => $idx->is_primary_key,
            'type' => $idx->type_desc,
        ], $rawIndexes);
    }

    /**
     * Get unique constraints for a table
     */
    public function getUniqueConstraints(string $table): array
    {
        $indexes = $this->getIndexes($table);

        return array_filter($indexes, fn (array $idx): bool => $idx['unique'] && ! $idx['primary']);
    }

    /**
     * Get check constraints
     */
    public function getCheckConstraints(string $table): array
    {
        return match ($this->driver) {
            'mysql' => $this->getMysqlCheckConstraints($table),
            'pgsql' => $this->getPostgresCheckConstraints($table),
            'sqlite' => [], // SQLite check constraints are harder to introspect
            'sqlsrv' => $this->getSqlServerCheckConstraints($table),
            default => [],
        };
    }

    /**
     * Get MySQL check constraints (8.0.16+)
     */
    protected function getMysqlCheckConstraints(string $table): array
    {
        try {
            $constraints = $this->connection->select('
                SELECT 
                    CONSTRAINT_NAME as name,
                    CHECK_CLAUSE as definition
                FROM information_schema.CHECK_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
            ', [$table]);

            return array_map(fn ($c): array => [
                'name' => $c->name,
                'definition' => $c->definition,
            ], $constraints);
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Get PostgreSQL check constraints
     */
    protected function getPostgresCheckConstraints(string $table): array
    {
        $constraints = $this->connection->select("
            SELECT
                con.conname as name,
                pg_get_constraintdef(con.oid) as definition
            FROM pg_constraint con
            JOIN pg_class rel ON rel.oid = con.conrelid
            WHERE rel.relname = ?
            AND con.contype = 'c'
        ", [$table]);

        return array_map(fn ($c): array => [
            'name' => $c->name,
            'definition' => $c->definition,
        ], $constraints);
    }

    /**
     * Get SQL Server check constraints
     */
    protected function getSqlServerCheckConstraints(string $table): array
    {
        $constraints = $this->connection->select('
            SELECT 
                cc.name,
                cc.definition
            FROM sys.check_constraints cc
            JOIN sys.tables t ON cc.parent_object_id = t.object_id
            WHERE t.name = ?
        ', [$table]);

        return array_map(fn ($c): array => [
            'name' => $c->name,
            'definition' => $c->definition,
        ], $constraints);
    }

    /**
     * Check if table exists
     */
    public function tableExists(string $table): bool
    {
        return Schema::connection($this->connectionName)->hasTable($table);
    }

    /**
     * Get table comment/description
     */
    public function getTableComment(string $table): ?string
    {
        try {
            $result = match ($this->driver) {
                'mysql' => $this->connection->selectOne('
                    SELECT TABLE_COMMENT 
                    FROM INFORMATION_SCHEMA.TABLES 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = ?
                ', [$table]),

                'pgsql' => $this->connection->selectOne('
                    SELECT obj_description(?::regclass) as comment
                ', [$table]),

                default => null,
            };

            return $result->TABLE_COMMENT ?? $result->comment ?? null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Get comprehensive table metadata
     */
    public function getTableMetadata(string $table): array
    {
        return [
            'name' => $table,
            'comment' => $this->getTableComment($table),
            'columns' => $this->getColumns($table),
            'primary_key' => $this->getPrimaryKey($table),
            'composite_primary_key' => $this->getCompositePrimaryKey($table),
            'foreign_keys' => $this->getForeignKeys($table),
            'indexes' => $this->getIndexes($table),
            'unique_constraints' => $this->getUniqueConstraints($table),
            'check_constraints' => $this->getCheckConstraints($table),
        ];
    }
}
