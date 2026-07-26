<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

use Illuminate\Support\Str;

/**
 * Compares a generated Eloquent model against the table it was generated from.
 *
 * This is the check Pint, Rector and PHPStan cannot do: they see the PHP, not the
 * database. A model whose $fillable still lists a dropped column, or whose
 * timestamp column has no datetime cast, is perfectly valid PHP that fails at
 * runtime — usually far from the cause.
 *
 * Deliberately regex-based rather than AST: the package has no parser dependency,
 * the shapes it reads are ones Anvil itself emits, and a false negative on an
 * exotic hand-edit is a much better failure than a hard dependency.
 */
final class ModelAuditor
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    public const NOTE = 'note';

    /** Columns Eloquent manages itself; they belong in neither $fillable nor $casts. */
    private const MANAGED = ['created_at', 'updated_at', 'deleted_at'];

    private const SENSITIVE = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'api_key',
        'api_secret',
        'secret',
    ];

    /**
     * @return list<array{severity: string, check: string, message: string, fix: string}>
     */
    public function audit(ModelMetadata $meta, string $path, ?string $connection = null): array
    {
        if (! is_file($path)) {
            return [[
                'severity' => self::WARNING,
                'check' => 'missing',
                'message' => 'No model file at '.$this->relative($path).'.',
                'fix' => "php artisan anvil:generate --tables={$meta->table}",
            ]];
        }

        $source = (string) file_get_contents($path);
        $columns = array_map(strval(...), array_column($meta->columns, 'name'));

        return array_merge(
            $this->auditTable($meta, $source),
            $this->auditBaseClass($meta, $source, $columns),
            $this->auditFillable($meta, $source, $columns),
            $this->auditCasts($meta, $source, $columns, $connection),
            $this->auditHidden($source, $columns),
            $this->auditRelations($meta, $source),
        );
    }

    // -----------------------------------------------------------------------
    // Checks
    // -----------------------------------------------------------------------

    /**
     * @return list<array<string, string>>
     */
    private function auditTable(ModelMetadata $meta, string $source): array
    {
        if (preg_match('/protected\s+\$table\s*=\s*[\'"]([^\'"]+)[\'"]/', $source, $match) !== 1) {
            // Only a problem when the convention would resolve elsewhere.
            $conventional = Str::snake(Str::pluralStudly($meta->model));

            return $conventional === $meta->table ? [] : [$this->finding(
                self::ERROR,
                'table',
                "No \$table property, and the convention resolves to \"{$conventional}\" rather than \"{$meta->table}\".",
                "Add: protected \$table = '{$meta->table}';",
            )];
        }

        $declared = $match[1];
        $expected = [$meta->table, ($meta->schema !== null ? $meta->schema.'.'.$meta->table : $meta->table)];

        if (in_array($declared, $expected, true)) {
            return [];
        }

        return [$this->finding(
            self::ERROR,
            'table',
            "\$table is \"{$declared}\" but the model was generated from \"{$meta->table}\".",
            'The table was renamed, or the model belongs to a different one. Regenerate.',
        )];
    }

    /**
     * @param  list<string>  $columns
     * @return list<array<string, string>>
     */
    private function auditBaseClass(ModelMetadata $meta, string $source, array $columns): array
    {
        if (! in_array('password', $columns, true)) {
            return [];
        }

        if (preg_match('/class\s+\w+\s+extends\s+(\w+)/', $source, $match) !== 1) {
            return [];
        }

        if (in_array($match[1], ['Authenticatable', 'User'], true)) {
            return [];
        }

        return [$this->finding(
            self::ERROR,
            'authenticatable',
            "Has a password column but extends {$match[1]}.",
            'SessionGuard throws a TypeError on validateCredentials(). Extend Illuminate\\Foundation\\Auth\\User '
                .'(aliased as Authenticatable), and add the model to anvil.protected_models.',
        )];
    }

    /**
     * @param  list<string>  $columns
     * @return list<array<string, string>>
     */
    private function auditFillable(ModelMetadata $meta, string $source, array $columns): array
    {
        $fillable = $this->arrayProperty($source, 'fillable');

        if ($fillable === null) {
            return [];
        }

        $findings = [];

        // Drift: the model lists a column the table no longer has. A mass assign
        // including it throws only when that code path runs.
        foreach (array_diff($fillable, $columns) as $stale) {
            $findings[] = $this->finding(
                self::ERROR,
                'fillable',
                "\$fillable lists \"{$stale}\", which is not a column on {$meta->table}.",
                'The column was dropped or renamed. Regenerate the model.',
            );
        }

        // Drift the other way: a new column nobody can write to.
        $writable = array_values(array_filter($columns, fn (string $column): bool => $column !== $meta->primaryKey
            && ! in_array($column, self::MANAGED, true)
            && ! in_array($column, $meta->compositePrimaryKey, true)));

        $missing = array_diff($writable, $fillable);

        if (count($missing) > 0) {
            $findings[] = $this->finding(
                self::WARNING,
                'fillable',
                'Columns absent from $fillable: '.implode(', ', array_slice($missing, 0, 6))
                    .(count($missing) > 6 ? ' (+'.(count($missing) - 6).' more)' : ''),
                'Mass assignment silently drops them. Regenerate, or add them by hand if the omission is deliberate.',
            );
        }

        if (in_array((string) $meta->primaryKey, $fillable, true)) {
            $findings[] = $this->finding(
                self::WARNING,
                'fillable',
                "The primary key \"{$meta->primaryKey}\" is mass assignable.",
                'A client can then choose its own id. Remove it from $fillable.',
            );
        }

        foreach (array_intersect($fillable, self::SENSITIVE) as $sensitive) {
            if ($sensitive === 'password') {
                continue;   // legitimately fillable on registration
            }

            $findings[] = $this->finding(
                self::WARNING,
                'fillable',
                "\"{$sensitive}\" is mass assignable.",
                'A crafted request could set it directly. Remove it from $fillable.',
            );
        }

        return $findings;
    }

    /**
     * @param  list<string>  $columns
     * @return list<array<string, string>>
     */
    private function auditCasts(ModelMetadata $meta, string $source, array $columns, ?string $connection): array
    {
        $casts = $this->castKeys($source);
        $findings = [];

        foreach ($meta->columns as $column) {
            $name = (string) $column['name'];
            $type = strtolower((string) preg_replace('/\(.*\)/', '', (string) ($column['type'] ?? '')));

            if (in_array($name, self::MANAGED, true) || in_array($name, $casts, true)) {
                continue;
            }

            // The one that bites hardest: a datetime column with no cast is a
            // string, so ->isFuture() / ->format() are fatal.
            if (preg_match('/(timestamp|datetime)/', $type) === 1 || $type === 'date') {
                $findings[] = $this->finding(
                    self::ERROR,
                    'casts',
                    "\"{$name}\" is a {$type} with no cast, so it is a string at runtime.",
                    "Add '{$name}' => 'datetime' — otherwise ->isFuture(), ->format() and comparisons all fail.",
                );

                continue;
            }

            if (preg_match('/(json|jsonb)/', $type) === 1) {
                $findings[] = $this->finding(
                    self::WARNING,
                    'casts',
                    "\"{$name}\" is {$type} with no cast, so it is a raw JSON string.",
                    "Add '{$name}' => 'array'.",
                );

                continue;
            }

            if (preg_match('/(decimal|numeric|money)/', $type) === 1) {
                $findings[] = $this->finding(
                    self::NOTE,
                    'casts',
                    "\"{$name}\" is {$type} with no cast; PHP will treat it as a float.",
                    "Add '{$name}' => 'decimal:2' to keep the scale.",
                );

                continue;
            }

            if ($type === 'boolean' || $type === 'bool' || (string) ($column['type'] ?? '') === 'tinyint(1)') {
                $findings[] = $this->finding(
                    self::WARNING,
                    'casts',
                    "\"{$name}\" is boolean with no cast, so it is 0/1 rather than true/false.",
                    "Add '{$name}' => 'boolean'.",
                );
            }
        }

        // Enum columns should cast to the generated enum.
        foreach (EnumDetector::forTable($meta, $connection) as $column => $enum) {
            if (in_array($column, $casts, true) && str_contains($source, $enum->enumName.'::class')) {
                continue;
            }

            $findings[] = $this->finding(
                self::WARNING,
                'casts',
                "\"{$column}\" has a fixed value set but is not cast to {$enum->enumName}.",
                "Add '{$column}' => {$enum->enumName}::class so match() over it is exhaustive.",
            );
        }

        // A cast for a column that no longer exists.
        foreach (array_diff($casts, $columns) as $stale) {
            $findings[] = $this->finding(
                self::WARNING,
                'casts',
                "\$casts references \"{$stale}\", which is not a column on {$meta->table}.",
                'Harmless but misleading. Regenerate.',
            );
        }

        return $findings;
    }

    /**
     * @param  list<string>  $columns
     * @return list<array<string, string>>
     */
    private function auditHidden(string $source, array $columns): array
    {
        $hidden = $this->arrayProperty($source, 'hidden') ?? [];
        $findings = [];

        foreach (array_intersect($columns, self::SENSITIVE) as $sensitive) {
            if (in_array($sensitive, $hidden, true)) {
                continue;
            }

            $findings[] = $this->finding(
                self::ERROR,
                'hidden',
                "\"{$sensitive}\" is not in \$hidden, so toArray()/toJson() expose it.",
                "Add '{$sensitive}' to \$hidden. Any resource built from this model leaks it otherwise.",
            );
        }

        return $findings;
    }

    /**
     * @return list<array<string, string>>
     */
    private function auditRelations(ModelMetadata $meta, string $source): array
    {
        $findings = [];

        // hasMany without an explicit FK guesses "<parent>_id", which is wrong for
        // every qualified relation (customer_id, assigned_agent_id).
        if (preg_match_all('/->(hasMany|hasOne)\(\s*([^,)]+)\s*\)/', $source, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $findings[] = $this->finding(
                    self::WARNING,
                    'relations',
                    trim($match[0]).' has no foreign key argument.',
                    'Eloquent guesses <parent>_id. Pass the column explicitly.',
                );
            }
        }

        // Duplicate method names: the fatal this whole thread started with.
        if (preg_match_all('/public\s+function\s+(\w+)\s*\(/', $source, $matches) !== false) {
            $counts = array_count_values($matches[1] ?? []);

            foreach ($counts as $method => $count) {
                if ($count > 1) {
                    $findings[] = $this->finding(
                        self::ERROR,
                        'relations',
                        "{$method}() is declared {$count} times — PHP cannot load this class.",
                        'Two foreign keys to the same parent. Regenerate: names are now qualified from the column.',
                    );
                }
            }
        }

        return $findings;
    }

    // -----------------------------------------------------------------------
    // Parsing helpers
    // -----------------------------------------------------------------------

    /**
     * `protected $fillable = ['a', 'b'];` → ['a', 'b']
     *
     * @return list<string>|null null when the property is absent
     */
    private function arrayProperty(string $source, string $property): ?array
    {
        if (preg_match('/protected\s+\$'.$property.'\s*=\s*\[(.*?)\];/s', $source, $match) !== 1) {
            return null;
        }

        preg_match_all("/'([^']+)'/", $match[1], $values);

        return $values[1] ?? [];
    }

    /**
     * Cast keys from either the `$casts` property or the `casts()` method.
     *
     * @return list<string>
     */
    private function castKeys(string $source): array
    {
        $blocks = [];

        if (preg_match('/protected\s+\$casts\s*=\s*\[(.*?)\];/s', $source, $match) === 1) {
            $blocks[] = $match[1];
        }

        if (preg_match('/function\s+casts\s*\(\s*\)\s*:\s*array\s*\{.*?return\s*\[(.*?)\];/s', $source, $match) === 1) {
            $blocks[] = $match[1];
        }

        $keys = [];

        foreach ($blocks as $block) {
            preg_match_all("/'([^']+)'\s*=>/", $block, $matches);
            $keys = array_merge($keys, $matches[1] ?? []);
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<string, string>
     */
    private function finding(string $severity, string $check, string $message, string $fix): array
    {
        return compact('severity', 'check', 'message', 'fix');
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
