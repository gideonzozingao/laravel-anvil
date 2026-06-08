<?php

namespace Zuqongtech\LaravelAnvil\Support;

use Illuminate\Support\Str;

class Helpers
{
    /**
     * Normalize namespace by trimming leading/trailing backslashes
     */
    public static function normalizeNamespace(string $namespace): string
    {
        return trim($namespace, '\\');
    }

    /**
     * Convert namespace to file path
     * Example: "App\\Models\\User" -> "app/Models/User"
     */
    public static function namespaceToPath(string $namespace, string $basePath = 'app'): string
    {
        $normalized = self::normalizeNamespace($namespace);
        $path = str_replace('\\', '/', $normalized);

        // Remove common prefixes like "App" if base path is "app"
        if (str_starts_with($path, 'App/') && $basePath === 'app') {
            $path = substr($path, 4);
        }

        return trim($path, '/');
    }

    /**
     * Convert table name to model class name
     * Examples: "users" -> "User", "post_comments" -> "PostComment"
     */
    public static function tableToModelName(string $tableName): string
    {
        return Str::studly(Str::singular($tableName));
    }

    /**
     * Get PHP type from database column type
     */
    public static function mapDatabaseTypeToPhp(string $dbType): string
    {
        $typeMap = [
            'int' => 'int',
            'integer' => 'int',
            'tinyint' => 'int',
            'smallint' => 'int',
            'mediumint' => 'int',
            'bigint' => 'int',
            'decimal' => 'float',
            'numeric' => 'float',
            'float' => 'float',
            'double' => 'float',
            'real' => 'float',
            'bit' => 'int',
            'boolean' => 'bool',
            'bool' => 'bool',
            'serial' => 'int',
            'date' => 'string',
            'datetime' => 'string',
            'timestamp' => 'string',
            'time' => 'string',
            'year' => 'int',
            'char' => 'string',
            'varchar' => 'string',
            'text' => 'string',
            'tinytext' => 'string',
            'mediumtext' => 'string',
            'longtext' => 'string',
            'binary' => 'string',
            'varbinary' => 'string',
            'blob' => 'string',
            'tinyblob' => 'string',
            'mediumblob' => 'string',
            'longblob' => 'string',
            'enum' => 'string',
            'set' => 'string',
            'json' => 'array',
            'jsonb' => 'array',
            'uuid' => 'string',
        ];

        $cleanType = strtolower((string) preg_replace('/\(.*\)/', '', $dbType));

        return $typeMap[$cleanType] ?? 'mixed';
    }

    /**
     * Check if column is nullable
     */
    public static function isNullableType(string $phpType, bool $isNullable): string
    {
        if ($isNullable && $phpType !== 'mixed') {
            return '?'.$phpType;
        }

        return $phpType;
    }

    /**
     * Get relationship method name from foreign key
     * Example: "user_id" -> "user"
     */
    public static function foreignKeyToRelationName(string $foreignKey): string
    {
        $name = str_replace('_id', '', $foreignKey);

        return Str::camel($name);
    }

    /**
     * Get inverse relationship name
     * Example: "User" -> "users"
     */
    public static function getInverseRelationName(string $modelName): string
    {
        return Str::camel(Str::plural($modelName));
    }

    /**
     * Format PHP DocBlock comment
     */
    public static function formatDocBlock(array $lines, int $indent = 1): string
    {
        $indentation = str_repeat('    ', $indent);
        $formatted = $indentation."/**\n";

        foreach ($lines as $line) {
            if (empty($line)) {
                $formatted .= $indentation." *\n";
            } else {
                $formatted .= $indentation.' * '.$line."\n";
            }
        }

        return $formatted.($indentation.' */');
    }

    /**
     * Check if table should be ignored
     */
    public static function shouldIgnoreTable(string $tableName, array $ignoreTables = []): bool
    {
        $defaultIgnore = [
            'migrations',
            'password_resets',
            'failed_jobs',
            'personal_access_tokens',
            'jobs',
            'cache',
            'sessions',
        ];

        $allIgnored = array_merge($defaultIgnore, $ignoreTables);

        return in_array($tableName, $allIgnored, true);
    }

    /**
     * Check if string is a valid PHP class name
     */
    public static function isValidClassName(string $name): bool
    {
        return preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $name) === 1;
    }

    /**
     * Get Laravel cast type from database type
     */
    public static function getCastType(string $dbType): ?string
    {
        $castMap = [
            'boolean' => 'boolean',
            'bool' => 'boolean',
            'tinyint' => 'boolean',
            'int' => 'integer',
            'integer' => 'integer',
            'bigint' => 'integer',
            'decimal' => 'decimal',
            'float' => 'float',
            'double' => 'double',
            'real' => 'float',
            'date' => 'date',
            'datetime' => 'datetime',
            'timestamp' => 'timestamp',
            'json' => 'array',
            'jsonb' => 'array',
        ];

        $cleanType = strtolower((string) preg_replace('/\(.*\)/', '', $dbType));

        return $castMap[$cleanType] ?? null;
    }

    /**
     * Check if column is a timestamp column
     */
    public static function isTimestampColumn(string $columnName): bool
    {
        return in_array($columnName, ['created_at', 'updated_at', 'deleted_at'], true);
    }

    public static function modelToRouteName(string $model): string
    {
        return Str::kebab(
            Str::pluralStudly($model)
        );
    }
}
