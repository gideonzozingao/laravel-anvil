<?php

namespace Zuqongtech\LaravelAnvil\Support;

use Illuminate\Support\Facades\DB;

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

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function getDatabaseName(): string
    {
        return $this->connection->getDatabaseName();
    }

    // =======================================================================
    // Schema discovery
    // =======================================================================

    /**
     * The default schema for the current driver.
     *
     *  - PostgreSQL → public
     *  - SQL Server → dbo
     *  - MySQL      → the connected database (MySQL has no schema separate from the database)
     *  - SQLite     → main (SQLite has no real schemas)
     */
    public function defaultSchema(): ?string
    {
        return match ($this->driver) {
            'pgsql' => 'public',
            'sqlsrv' => 'dbo',
            'mysql' => $this->getDatabaseName(),
            'sqlite' => 'main',
            default => null,
        };
    }

    /**
     * True when the driver has a real notion of multiple schemas within one
     * connection. (MySQL technically does via multiple databases; SQLite does not.)
     */
    public function supportsSchemas(): bool
    {
        return in_array($this->driver, ['pgsql', 'sqlsrv', 'mysql'], true);
    }

    /**
     * List all user (non-system) schemas for the current connection.
     *
     * @return list<string>
     */
    public function getSchemas(): array
    {
        return match ($this->driver) {
            'pgsql' => collect($this->connection->select("
                SELECT schema_name
                FROM information_schema.schemata
                WHERE schema_name NOT IN ('pg_catalog', 'information_schema', 'pg_toast')
                  AND schema_name NOT LIKE 'pg_temp_%'
                  AND schema_name NOT LIKE 'pg_toast_temp_%'
                ORDER BY schema_name
            "))->pluck('schema_name')->map(fn ($s) => (string) $s)->all(),

            // In MySQL a "schema" is a database.
            'mysql' => collect($this->connection->select("
                SELECT schema_name
                FROM information_schema.schemata
                WHERE schema_name NOT IN ('mysql', 'information_schema', 'performance_schema', 'sys')
                ORDER BY schema_name
            "))->pluck('schema_name')->map(fn ($s) => (string) $s)->all(),

            'sqlsrv' => collect($this->connection->select("
                SELECT s.name AS schema_name
                FROM sys.schemas s
                WHERE s.name NOT IN ('sys', 'INFORMATION_SCHEMA', 'guest',
                                     'db_owner', 'db_accessadmin', 'db_securityadmin',
                                     'db_ddladmin', 'db_backupoperator', 'db_datareader',
                                     'db_datawriter', 'db_denydatareader', 'db_denydatawriter')
                  AND s.principal_id <> 4
                ORDER BY s.name
            "))->pluck('schema_name')->map(fn ($s) => (string) $s)->all(),

            // SQLite: single schema.
            'sqlite' => ['main'],

            default => [],
        };
    }

    /**
     * Resolve a caller-supplied schema selection into a concrete list.
     *
     * Accepts: null (→ default schema), 'all'/'*', a comma string, or an array.
     * Unknown schema names are kept (the user may know better); empty → default.
     *
     * @param  string|array<int, string>|null  $selection
     * @return list<string>
     */
    public function resolveSchemas(string|array|null $selection): array
    {
        if ($selection === null || $selection === '' || $selection === []) {
            $default = $this->defaultSchema();

            return $default !== null ? [$default] : [];
        }

        if (is_string($selection)) {
            $selection = array_map('trim', explode(',', $selection));
        }

        $selection = array_values(array_filter(array_map('strval', $selection), fn ($s) => $s !== ''));

        if (in_array('all', $selection, true) || in_array('*', $selection, true)) {
            return $this->getSchemas();
        }

        return $selection;
    }

    // =======================================================================
    // Table enumeration
    // =======================================================================

    /**
     * Get all tables in the default schema (backward-compatible).
     *
     * @return list<string>
     */
    public function getAllTables(): array
    {
        return $this->getTablesInSchema(null);
    }

    /**
     * Get all base-table names within a single schema.
     *
     * @return list<string>
     */
    public function getTablesInSchema(?string $schema): array
    {
        $tables = match ($this->driver) {
            'mysql' => collect($this->connection->select('
                SELECT TABLE_NAME AS name
                FROM information_schema.tables
                WHERE TABLE_SCHEMA = ?
                  AND TABLE_TYPE = ?
                ORDER BY TABLE_NAME
            ', [$schema ?? $this->getDatabaseName(), 'BASE TABLE']))->pluck('name')->all(),

            'pgsql' => collect($this->connection->select('
                SELECT tablename
                FROM pg_tables
                WHERE schemaname = ?
                ORDER BY tablename
            ', [$schema ?? 'public']))->pluck('tablename')->all(),

            'sqlite' => collect($this->connection->select("
                SELECT name
                FROM sqlite_master
                WHERE type = 'table'
                  AND name NOT LIKE 'sqlite_%'
                ORDER BY name
            "))->pluck('name')->all(),

            'sqlsrv' => collect($this->connection->select('
                SELECT TABLE_NAME
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_TYPE = ?
                  AND TABLE_SCHEMA = ?
                ORDER BY TABLE_NAME
            ', ['BASE TABLE', $schema ?? 'dbo']))->pluck('TABLE_NAME')->all(),

            default => throw new \Exception('Unsupported database driver: '.$this->driver),
        };

        return array_values(array_map('strval', $tables));
    }

    /**
     * Get every table across the selected schemas as {schema, table} pairs.
     *
     * @param  string|array<int, string>|null  $schemaSelection  null|'all'|list
     * @return list<array{schema: ?string, table: string}>
     */
    public function getAllSchemaTables(string|array|null $schemaSelection = null): array
    {
        $schemas = $this->resolveSchemas($schemaSelection);

        // SQLite has no real schemas — return its tables with a null schema.
        if (! $this->supportsSchemas()) {
            return array_map(
                fn (string $t): array => ['schema' => null, 'table' => $t],
                $this->getTablesInSchema(null),
            );
        }

        $pairs = [];
        foreach ($schemas as $schema) {
            foreach ($this->getTablesInSchema($schema) as $table) {
                $pairs[] = ['schema' => $schema, 'table' => $table];
            }
        }

        return $pairs;
    }

    // =======================================================================
    // Identifier qualification helpers
    // =======================================================================

    /** Qualify a table for a Postgres `::regclass` cast (quoted, schema-aware). */
    protected function pgRegclass(string $table, ?string $schema): string
    {
        return $schema !== null
            ? '"'.$schema.'"."'.$table.'"'
            : '"'.$table.'"';
    }

    /** Qualify a table for a SQL Server OBJECT_ID() call. */
    protected function sqlsrvObject(string $table, ?string $schema): string
    {
        return $schema !== null ? "[{$schema}].[{$table}]" : "[{$table}]";
    }

    /** Backtick-qualify a MySQL table (optionally with its database/schema). */
    protected function mysqlQualified(string $table, ?string $schema): string
    {
        return $schema !== null ? "`{$schema}`.`{$table}`" : "`{$table}`";
    }

    // =======================================================================
    // Columns
    // =======================================================================

    public function getColumns(string $table, ?string $schema = null): array
    {
        return match ($this->driver) {
            'mysql' => $this->getMysqlColumns($table, $schema),
            'pgsql' => $this->getPostgresColumns($table, $schema),
            'sqlite' => $this->getSqliteColumns($table),
            'sqlsrv' => $this->getSqlServerColumns($table, $schema),
            default => [],
        };
    }

    protected function getMysqlColumns(string $table, ?string $schema = null): array
    {
        $columns = $this->connection->select(
            sprintf('SHOW FULL COLUMNS FROM %s', $this->mysqlQualified($table, $schema)),
        );

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

    protected function getPostgresColumns(string $table, ?string $schema = null): array
    {
        $sql = "
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
        ".($schema !== null ? ' AND table_schema = ?' : '').'
            ORDER BY ordinal_position
        ';

        $bindings = $schema !== null ? [$table, $schema] : [$table];
        $columns = $this->connection->select($sql, $bindings);

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

    protected function getSqlServerColumns(string $table, ?string $schema = null): array
    {
        $sql = "
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
        ".($schema !== null ? ' AND c.TABLE_SCHEMA = ?' : '').'
            ORDER BY c.ORDINAL_POSITION
        ';

        $bindings = $schema !== null ? [$table, $schema] : [$table];
        $columns = $this->connection->select($sql, $bindings);

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

    // =======================================================================
    // Primary keys
    // =======================================================================

    public function getPrimaryKey(string $table, ?string $schema = null): ?string
    {
        try {
            $result = match ($this->driver) {
                'mysql' => collect($this->connection->select(
                    sprintf('SHOW KEYS FROM %s WHERE Key_name = ?', $this->mysqlQualified($table, $schema)),
                    ['PRIMARY'],
                ))->pluck('Column_name')->first(),

                'pgsql' => $this->connection->selectOne('
                    SELECT a.attname
                    FROM pg_index i
                    JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
                    WHERE i.indrelid = '.'?'.'::regclass AND i.indisprimary
                ', [$this->pgRegclass($table, $schema)])->attname ?? null,

                'sqlite' => collect($this->connection->select(sprintf('PRAGMA table_info(`%s`)', $table)))
                    ->where('pk', 1)
                    ->pluck('name')
                    ->first(),

                'sqlsrv' => $this->connection->selectOne('
                    SELECT COLUMN_NAME
                    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                    WHERE OBJECTPROPERTY(OBJECT_ID(CONSTRAINT_SCHEMA + \'.\' + CONSTRAINT_NAME), \'IsPrimaryKey\') = 1
                    AND TABLE_NAME = ?
                    '.($schema !== null ? 'AND TABLE_SCHEMA = ?' : '').'
                ', $schema !== null ? [$table, $schema] : [$table])->COLUMN_NAME ?? null,

                default => null,
            };

            return $result ?? 'id';
        } catch (\Exception) {
            return 'id';
        }
    }

    public function getCompositePrimaryKey(string $table, ?string $schema = null): array
    {
        try {
            return match ($this->driver) {
                'mysql' => collect($this->connection->select(
                    sprintf('SHOW KEYS FROM %s WHERE Key_name = ? ORDER BY Seq_in_index', $this->mysqlQualified($table, $schema)),
                    ['PRIMARY'],
                ))->pluck('Column_name')->toArray(),

                'pgsql' => collect($this->connection->select('
                    SELECT a.attname
                    FROM pg_index i
                    JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
                    WHERE i.indrelid = '.'?'.'::regclass AND i.indisprimary
                    ORDER BY array_position(i.indkey, a.attnum)
                ', [$this->pgRegclass($table, $schema)]))->pluck('attname')->toArray(),

                'sqlite' => collect($this->connection->select(sprintf('PRAGMA table_info(`%s`)', $table)))
                    ->where('pk', '>', 0)
                    ->sortBy('pk')
                    ->pluck('name')
                    ->toArray(),

                'sqlsrv' => collect($this->connection->select('
                    SELECT COLUMN_NAME
                    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                    WHERE OBJECTPROPERTY(OBJECT_ID(CONSTRAINT_SCHEMA + \'.\' + CONSTRAINT_NAME), \'IsPrimaryKey\') = 1
                    AND TABLE_NAME = ?
                    '.($schema !== null ? 'AND TABLE_SCHEMA = ?' : '').'
                    ORDER BY ORDINAL_POSITION
                ', $schema !== null ? [$table, $schema] : [$table]))->pluck('COLUMN_NAME')->toArray(),

                default => [],
            };
        } catch (\Exception) {
            return [];
        }
    }

    // =======================================================================
    // Foreign keys (schema-aware, including the referenced schema)
    // =======================================================================

    public function getForeignKeys(string $table, ?string $schema = null): array
    {
        $foreignKeys = match ($this->driver) {
            'mysql' => $this->connection->select('
                SELECT
                    COLUMN_NAME as column_name,
                    REFERENCED_TABLE_NAME as referenced_table_name,
                    REFERENCED_TABLE_SCHEMA as referenced_table_schema,
                    REFERENCED_COLUMN_NAME as referenced_column_name,
                    CONSTRAINT_NAME as constraint_name
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = ?
                AND TABLE_NAME = ?
                AND REFERENCED_TABLE_NAME IS NOT NULL
            ', [$schema ?? $this->getDatabaseName(), $table]),

            'pgsql' => $this->connection->select('
                SELECT
                    kcu.column_name,
                    ccu.table_name AS referenced_table_name,
                    ccu.table_schema AS referenced_table_schema,
                    ccu.column_name AS referenced_column_name,
                    tc.constraint_name
                FROM information_schema.table_constraints AS tc
                JOIN information_schema.key_column_usage AS kcu
                    ON tc.constraint_name = kcu.constraint_name
                    AND tc.table_schema = kcu.table_schema
                JOIN information_schema.constraint_column_usage AS ccu
                    ON ccu.constraint_name = tc.constraint_name
                    AND ccu.table_schema = tc.table_schema
                WHERE tc.constraint_type = \'FOREIGN KEY\'
                AND tc.table_name = ?
                '.($schema !== null ? 'AND tc.table_schema = ?' : '').'
            ', $schema !== null ? [$table, $schema] : [$table]),

            'sqlite' => collect($this->connection->select(sprintf('PRAGMA foreign_key_list(`%s`)', $table)))
                ->map(fn ($fk) => (object) [
                    'column_name' => $fk->from,
                    'referenced_table_name' => $fk->table,
                    'referenced_table_schema' => null,
                    'referenced_column_name' => $fk->to,
                    'constraint_name' => null,
                ])
                ->toArray(),

            'sqlsrv' => $this->connection->select('
                SELECT
                    fkc.COLUMN_NAME AS column_name,
                    pk.TABLE_NAME AS referenced_table_name,
                    pk.TABLE_SCHEMA AS referenced_table_schema,
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
                '.($schema !== null ? 'AND fk.TABLE_SCHEMA = ?' : '').'
            ', $schema !== null ? [$table, $schema] : [$table]),

            default => [],
        };

        return array_map(fn ($fk): array => [
            'column' => $fk->column_name,
            'referenced_table' => $fk->referenced_table_name,
            'referenced_schema' => $fk->referenced_table_schema ?? null,
            'referenced_column' => $fk->referenced_column_name,
            'constraint_name' => $fk->constraint_name ?? null,
        ], is_array($foreignKeys) ? $foreignKeys : []);
    }

    // =======================================================================
    // Indexes
    // =======================================================================

    public function getIndexes(string $table, ?string $schema = null): array
    {
        return match ($this->driver) {
            'mysql' => $this->getMysqlIndexes($table, $schema),
            'pgsql' => $this->getPostgresIndexes($table, $schema),
            'sqlite' => $this->getSqliteIndexes($table),
            'sqlsrv' => $this->getSqlServerIndexes($table, $schema),
            default => [],
        };
    }

    protected function getMysqlIndexes(string $table, ?string $schema = null): array
    {
        $rawIndexes = $this->connection->select(
            sprintf('SHOW INDEX FROM %s', $this->mysqlQualified($table, $schema)),
        );

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

    protected function getPostgresIndexes(string $table, ?string $schema = null): array
    {
        $sql = '
            SELECT
                i.relname as index_name,
                array_agg(a.attname ORDER BY array_position(ix.indkey, a.attnum)) as columns,
                ix.indisunique as is_unique,
                ix.indisprimary as is_primary,
                am.amname as index_type
            FROM pg_class t
            JOIN pg_namespace n ON n.oid = t.relnamespace
            JOIN pg_index ix ON t.oid = ix.indrelid
            JOIN pg_class i ON i.oid = ix.indexrelid
            JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
            JOIN pg_am am ON i.relam = am.oid
            WHERE t.relname = ?
        '.($schema !== null ? ' AND n.nspname = ?' : '').'
            GROUP BY i.relname, ix.indisunique, ix.indisprimary, am.amname
        ';

        $bindings = $schema !== null ? [$table, $schema] : [$table];
        $rawIndexes = $this->connection->select($sql, $bindings);

        return array_map(function ($idx): array {
            $columns = $idx->columns;

            if (is_string($columns)) {
                $columns = trim($columns, '{}');
                $columns = $columns !== '' && $columns !== '0' ? explode(',', $columns) : [];
            }

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

    protected function getSqlServerIndexes(string $table, ?string $schema = null): array
    {
        $rawIndexes = $this->connection->select('
            SELECT
                i.name as index_name,
                i.is_unique,
                i.is_primary_key,
                i.type_desc,
                STRING_AGG(c.name, \',\') WITHIN GROUP (ORDER BY ic.key_ordinal) as columns
            FROM sys.indexes i
            JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id
            JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id
            WHERE i.object_id = OBJECT_ID(?)
            GROUP BY i.name, i.is_unique, i.is_primary_key, i.type_desc
        ', [$this->sqlsrvObject($table, $schema)]);

        return array_map(fn ($idx): array => [
            'name' => $idx->index_name,
            'columns' => array_map(fn ($col): array => ['name' => $col, 'order' => 'ASC'], explode(',', (string) $idx->columns)),
            'unique' => $idx->is_unique,
            'primary' => $idx->is_primary_key,
            'type' => $idx->type_desc,
        ], $rawIndexes);
    }

    public function getUniqueConstraints(string $table, ?string $schema = null): array
    {
        $indexes = $this->getIndexes($table, $schema);

        return array_filter($indexes, fn (array $idx): bool => $idx['unique'] && ! $idx['primary']);
    }

    // =======================================================================
    // Check constraints
    // =======================================================================

    public function getCheckConstraints(string $table, ?string $schema = null): array
    {
        return match ($this->driver) {
            'mysql' => $this->getMysqlCheckConstraints($table, $schema),
            'pgsql' => $this->getPostgresCheckConstraints($table, $schema),
            'sqlite' => [],
            'sqlsrv' => $this->getSqlServerCheckConstraints($table, $schema),
            default => [],
        };
    }

    protected function getMysqlCheckConstraints(string $table, ?string $schema = null): array
    {
        try {
            $constraints = $this->connection->select('
                SELECT
                    CONSTRAINT_NAME as name,
                    CHECK_CLAUSE as definition
                FROM information_schema.CHECK_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = ?
                AND TABLE_NAME = ?
            ', [$schema ?? $this->getDatabaseName(), $table]);

            return array_map(fn ($c): array => [
                'name' => $c->name,
                'definition' => $c->definition,
            ], $constraints);
        } catch (\Exception) {
            return [];
        }
    }

    protected function getPostgresCheckConstraints(string $table, ?string $schema = null): array
    {
        $sql = '
            SELECT
                con.conname as name,
                pg_get_constraintdef(con.oid) as definition
            FROM pg_constraint con
            JOIN pg_class rel ON rel.oid = con.conrelid
            JOIN pg_namespace n ON n.oid = rel.relnamespace
            WHERE rel.relname = ?
            AND con.contype = \'c\'
        '.($schema !== null ? ' AND n.nspname = ?' : '');

        $bindings = $schema !== null ? [$table, $schema] : [$table];
        $constraints = $this->connection->select($sql, $bindings);

        return array_map(fn ($c): array => [
            'name' => $c->name,
            'definition' => $c->definition,
        ], $constraints);
    }

    protected function getSqlServerCheckConstraints(string $table, ?string $schema = null): array
    {
        $sql = '
            SELECT
                cc.name,
                cc.definition
            FROM sys.check_constraints cc
            JOIN sys.tables t ON cc.parent_object_id = t.object_id
            JOIN sys.schemas s ON t.schema_id = s.schema_id
            WHERE t.name = ?
        '.($schema !== null ? ' AND s.name = ?' : '');

        $bindings = $schema !== null ? [$table, $schema] : [$table];
        $constraints = $this->connection->select($sql, $bindings);

        return array_map(fn ($c): array => [
            'name' => $c->name,
            'definition' => $c->definition,
        ], $constraints);
    }

    // =======================================================================
    // Table comment
    // =======================================================================

    public function getTableComment(string $table, ?string $schema = null): ?string
    {
        try {
            $result = match ($this->driver) {
                'mysql' => $this->connection->selectOne('
                    SELECT TABLE_COMMENT
                    FROM INFORMATION_SCHEMA.TABLES
                    WHERE TABLE_SCHEMA = ?
                    AND TABLE_NAME = ?
                ', [$schema ?? $this->getDatabaseName(), $table]),

                'pgsql' => $this->connection->selectOne('
                    SELECT obj_description('.'?'.'::regclass) as comment
                ', [$this->pgRegclass($table, $schema)]),

                default => null,
            };

            return $result->TABLE_COMMENT ?? $result->comment ?? null;
        } catch (\Exception) {
            return null;
        }
    }

    // =======================================================================
    // Aggregate
    // =======================================================================

    /**
     * Get comprehensive table metadata. Pass $schema to introspect a table in a
     * specific schema; omit it to use the connection's default/search-path.
     */
    public function getTableMetadata(string $table, ?string $schema = null): array
    {
        return [
            'name' => $table,
            'schema' => $schema,
            'comment' => $this->getTableComment($table, $schema),
            'columns' => $this->getColumns($table, $schema),
            'primary_key' => $this->getPrimaryKey($table, $schema),
            'composite_primary_key' => $this->getCompositePrimaryKey($table, $schema),
            'foreign_keys' => $this->getForeignKeys($table, $schema),
            'indexes' => $this->getIndexes($table, $schema),
            'unique_constraints' => $this->getUniqueConstraints($table, $schema),
            'check_constraints' => $this->getCheckConstraints($table, $schema),
        ];
    }
}
