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
     * Convert table name to model class name (plural form)
     */
    public static function tableToModelNamePlural(string $tableName): string
    {
        return Str::studly($tableName);
    }

    /**
     * Get full model class name with namespace
     */
    public static function getFullModelClass(string $tableName, string $namespace): string
    {
        $modelName = self::tableToModelName($tableName);
        $normalizedNamespace = self::normalizeNamespace($namespace);

        return $normalizedNamespace.'\\'.$modelName;
    }

    /**
     * Convert column name to property name (camelCase)
     */
    public static function columnToProperty(string $columnName): string
    {
        return Str::camel($columnName);
    }

    /**
     * Convert column name to method name (camelCase)
     */
    public static function columnToMethodName(string $columnName): string
    {
        return Str::camel($columnName);
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
     * Detect relationship type from foreign key
     */
    public static function detectRelationType(string $foreignKeyColumn): string
    {
        // Basic detection logic
        if (str_ends_with($foreignKeyColumn, '_id')) {
            return 'belongsTo';
        }

        return 'hasMany';
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
     * Generate PHPDoc for property
     */
    public static function generatePropertyDoc(string $type, string $columnName, ?string $comment = null): string
    {
        $lines = [];

        if ($comment) {
            $lines[] = $comment;
            $lines[] = '';
        }

        $lines[] = '@var '.$type;

        return self::formatDocBlock($lines);
    }

    /**
     * Generate PHPDoc for relationship method
     */
    public static function generateRelationshipDoc(string $relationType, string $relatedModel): string
    {
        $lines = [
            '@return \Illuminate\Database\Eloquent\Relations\\'.$relationType,
        ];

        return self::formatDocBlock($lines);
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
     * Ensure directory exists
     */
    public static function ensureDirectoryExists(string $path): bool
    {
        if (! is_dir($path)) {
            return mkdir($path, 0755, true);
        }

        return true;
    }

    /**
     * Get file path for model
     */
    public static function getModelFilePath(string $tableName, string $namespace, string $basePath): string
    {
        $modelName = self::tableToModelName($tableName);
        $namespacePath = self::namespaceToPath($namespace, $basePath);

        $fullPath = base_path($basePath.'/'.$namespacePath);

        return $fullPath.'/'.$modelName.'.php';
    }

    /**
     * Convert snake_case to StudlyCase
     */
    public static function studly(string $value): string
    {
        return Str::studly($value);
    }

    /**
     * Convert string to singular form
     */
    public static function singular(string $value): string
    {
        return Str::singular($value);
    }

    /**
     * Convert string to plural form
     */
    public static function plural(string $value): string
    {
        return Str::plural($value);
    }

    /**
     * Generate timestamp for file header
     */
    public static function getGenerationTimestamp(): string
    {
        return now()->toDateTimeString();
    }

    /**
     * Sanitize string for use in code
     */
    public static function sanitizeString(string $value): string
    {
        return addslashes($value);
    }

    /**
     * Check if string is a valid PHP class name
     */
    public static function isValidClassName(string $name): bool
    {
        return preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $name) === 1;
    }

    /**
     * Format array for code generation
     */
    public static function formatArray(array $items, int $indent = 2): string
    {
        if ($items === []) {
            return '[]';
        }

        $indentation = str_repeat('    ', $indent);
        $innerIndent = str_repeat('    ', $indent + 1);

        $formatted = "[\n";
        foreach ($items as $key => $value) {
            if (is_string($key)) {
                $formatted .= $innerIndent.sprintf("'%s' => ", $key);
            } else {
                $formatted .= $innerIndent;
            }

            if (is_string($value)) {
                $formatted .= "'{$value}',\n";
            } elseif (is_array($value)) {
                $formatted .= self::formatArray($value, $indent + 1).",\n";
            } else {
                $formatted .= $value.',
';
            }
        }

        return $formatted.($indentation.']');
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

    /**
     * Get pivot table name from two model names
     */
    public static function getPivotTableName(string $model1, string $model2): string
    {
        $tables = [
            Str::snake(Str::plural($model1)),
            Str::snake(Str::plural($model2)),
        ];

        sort($tables);

        return implode('_', $tables);
    }

    public static function modelToRouteName(string $model): string
    {
        return Str::kebab(
            Str::pluralStudly($model)
        );
    }
}
