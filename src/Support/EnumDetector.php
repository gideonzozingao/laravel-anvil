<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

use Illuminate\Support\Facades\DB;

/**
 * Finds the columns whose values come from a fixed set.
 *
 * Databases express that four different ways, and a generator that only handles
 * the first misses most real schemas:
 *
 *   1. MySQL inline enums     status enum('draft','active')
 *   2. Postgres enum types    status booking_status        (a CREATE TYPE)
 *   3. CHECK constraints      CHECK (status IN ('draft','active'))
 *   4. Inspector metadata     when DatabaseInspector already surfaced the values
 *
 * Catalogue lookups are done once per connection and cached, so detecting enums
 * across 300 tables costs two queries rather than 600.
 *
 * Everything is wrapped defensively: a driver that does not support a lookup, or a
 * user without catalogue permissions, degrades to "no enums found" rather than
 * failing the generation run.
 */
final class EnumDetector
{
    /** @var array<string, array<string, list<string>>> connection => type name => labels */
    private static array $typeLabels = [];

    /** @var array<string, array<string, array<string, list<string>>>> connection => table => column => values */
    private static array $checkValues = [];

    /** @var array<string, bool> */
    private static array $loaded = [];

    /**
     * Every enum column on a table, keyed by column name.
     *
     * @return array<string, EnumColumn>
     */
    public static function forTable(ModelMetadata $meta, ?string $connection = null): array
    {
        if (! config('anvil.enums.enabled', true)) {
            return [];
        }

        $connection ??= (string) (config('anvil.connection') ?: config('database.default'));

        self::warm($connection);

        $enums = [];

        foreach ($meta->columns as $column) {
            $enum = self::forColumn($meta, $column, $connection);

            if ($enum !== null) {
                $enums[(string) $column['name']] = $enum;
            }
        }

        return $enums;
    }

    /**
     * @param  array<string, mixed>  $column
     */
    public static function forColumn(ModelMetadata $meta, array $column, ?string $connection = null): ?EnumColumn
    {
        $connection ??= (string) (config('anvil.connection') ?: config('database.default'));
        $name = (string) ($column['name'] ?? '');
        $type = (string) ($column['type'] ?? '');

        if ($name === '') {
            return null;
        }

        // 1. The inspector may already have the values.
        foreach (['enum_values', 'allowed_values', 'options'] as $key) {
            if (is_array($column[$key] ?? null) && $column[$key] !== []) {
                return EnumColumn::make($meta->table, $column, $column[$key], 'metadata', $meta->model);
            }
        }

        // 2. MySQL inline enum / set.
        if (($values = self::parseInline($type)) !== []) {
            return EnumColumn::make($meta->table, $column, $values, 'inline', $meta->model);
        }

        // 3. A Postgres user-defined type.
        self::warm($connection);
        $typeName = strtolower(trim(preg_replace('/\(.*\)/', '', $type) ?? ''));

        if (isset(self::$typeLabels[$connection][$typeName])) {
            return EnumColumn::make($meta->table, $column, self::$typeLabels[$connection][$typeName], 'pg_type', $meta->model);
        }

        // 4. A CHECK constraint restricting this column.
        $values = self::$checkValues[$connection][$meta->table][$name] ?? [];

        if ($values !== []) {
            return EnumColumn::make($meta->table, $column, $values, 'check', $meta->model);
        }

        return null;
    }

    /**
     * MySQL reports the full definition as the column type.
     *
     * @return list<string>
     */
    public static function parseInline(string $type): array
    {
        if (preg_match("/^\s*(?:enum|set)\s*\((.+)\)\s*$/is", $type, $match) !== 1) {
            return [];
        }

        // Values are single-quoted and comma separated, with '' as an escaped quote.
        if (preg_match_all("/'((?:[^']|'')*)'/", $match[1], $values) !== false) {
            return array_map(
                static fn (string $value): string => str_replace("''", "'", $value),
                $values[1],
            );
        }

        return [];
    }

    // -----------------------------------------------------------------------
    // Catalogue lookups, once per connection
    // -----------------------------------------------------------------------

    private static function warm(string $connection): void
    {
        if (self::$loaded[$connection] ?? false) {
            return;
        }

        self::$loaded[$connection] = true;
        self::$typeLabels[$connection] = [];
        self::$checkValues[$connection] = [];

        try {
            $driver = DB::connection($connection)->getDriverName();
        } catch (\Throwable) {
            return;
        }

        match ($driver) {
            'pgsql' => self::warmPostgres($connection),
            'mysql', 'mariadb' => self::warmMysql($connection),
            'sqlite' => self::warmSqlite($connection),
            default => null,
        };
    }

    private static function warmPostgres(string $connection): void
    {
        // CREATE TYPE … AS ENUM (…)
        try {
            $rows = DB::connection($connection)->select(<<<'SQL'
                SELECT t.typname AS type_name, e.enumlabel AS label
                FROM pg_type t
                JOIN pg_enum e ON e.enumtypid = t.oid
                ORDER BY t.typname, e.enumsortorder
            SQL);

            foreach ($rows as $row) {
                self::$typeLabels[$connection][strtolower((string) $row->type_name)][] = (string) $row->label;
            }
        } catch (\Throwable) {
            // No catalogue access; fall through to CHECK constraints.
        }

        // CHECK (status = ANY (ARRAY['draft'::text, …])) — how Postgres stores an
        // IN list once it has been through the planner.
        try {
            $rows = DB::connection($connection)->select(<<<'SQL'
                SELECT c.relname AS table_name, pg_get_constraintdef(con.oid) AS definition
                FROM pg_constraint con
                JOIN pg_class c ON c.oid = con.conrelid
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE con.contype = 'c'
                  AND n.nspname NOT IN ('pg_catalog', 'information_schema')
            SQL);

            foreach ($rows as $row) {
                self::recordCheck($connection, (string) $row->table_name, (string) $row->definition);
            }
        } catch (\Throwable) {
            //
        }
    }

    private static function warmMysql(string $connection): void
    {
        // Inline enums cover most of MySQL; CHECK constraints arrived in 8.0.16.
        try {
            $database = DB::connection($connection)->getDatabaseName();

            $rows = DB::connection($connection)->select(
                'SELECT tc.TABLE_NAME AS table_name, cc.CHECK_CLAUSE AS definition
                 FROM information_schema.TABLE_CONSTRAINTS tc
                 JOIN information_schema.CHECK_CONSTRAINTS cc
                   ON cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
                  AND cc.CONSTRAINT_SCHEMA = tc.TABLE_SCHEMA
                 WHERE tc.CONSTRAINT_TYPE = \'CHECK\' AND tc.TABLE_SCHEMA = ?',
                [$database],
            );

            foreach ($rows as $row) {
                self::recordCheck($connection, (string) $row->table_name, (string) $row->definition);
            }
        } catch (\Throwable) {
            //
        }
    }

    private static function warmSqlite(string $connection): void
    {
        try {
            $rows = DB::connection($connection)->select(
                "SELECT name, sql FROM sqlite_master WHERE type = 'table' AND sql IS NOT NULL",
            );

            foreach ($rows as $row) {
                self::recordCheck($connection, (string) $row->name, (string) $row->sql);
            }
        } catch (\Throwable) {
            //
        }
    }

    /**
     * Pull `column IN ('a','b')` out of a constraint definition, in the several
     * shapes the drivers produce:
     *
     *   status IN ('draft', 'active')                            MySQL, SQLite
     *   ((status)::text = ANY ((ARRAY['draft'::character …])))    Postgres
     *   status = ANY (ARRAY['draft'::text, 'active'::text])       Postgres
     */
    private static function recordCheck(string $connection, string $table, string $definition): void
    {
        $patterns = [
            // column IN (…)
            '/[\("`\[]?(\w+)[\)"`\]]?(?:::\w+(?:\s\w+)*)?\s+IN\s*\(([^)]+)\)/i',
            // column = ANY (ARRAY[…])
            '/[\("`\[]?(\w+)[\)"`\]]?(?:::\w+(?:\s\w+)*)?\s*=\s*ANY\s*\(+\s*ARRAY\s*\[([^\]]+)\]/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $definition, $matches, PREG_SET_ORDER) === false) {
                continue;
            }

            foreach ($matches as $match) {
                $column = strtolower($match[1]);
                $values = self::parseValueList($match[2]);

                if (count($values) >= 2) {
                    self::$checkValues[$connection][$table][$column] = $values;
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function parseValueList(string $list): array
    {
        if (preg_match_all("/'((?:[^']|'')*)'/", $list, $matches) !== false && $matches[1] !== []) {
            return array_map(
                static fn (string $value): string => str_replace("''", "'", $value),
                $matches[1],
            );
        }

        // A numeric list: IN (1, 2, 3)
        $numbers = array_values(array_filter(
            array_map(trim(...), explode(',', $list)),
            static fn (string $value): bool => preg_match('/^-?\d+$/', $value) === 1,
        ));

        return $numbers;
    }

    /**
     * Test seam, and useful between runs in a long-lived process.
     */
    public static function flush(): void
    {
        self::$typeLabels = [];
        self::$checkValues = [];
        self::$loaded = [];
    }
}
