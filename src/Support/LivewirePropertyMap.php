<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

/**
 * Maps a database column to (a) a Livewire property declaration that cannot
 * fatal, and (b) a cast token the generated component hands to
 * NormalizesFormState at save time.
 *
 * Why the declarations are UNTYPED
 * -------------------------------
 * Two separate failures killed the typed version:
 *
 *   public int  $tenant_id = null;   // FatalError at class load: "Default value
 *                                    // for property of type int may not be null"
 *   public ?int $tenant_id = null;   // TypeError at runtime: an empty
 *                                    // <input type="number"> posts "" and
 *                                    // Livewire assigns it straight to the property
 *
 * A form property is a transport for whatever the browser sent — always a string,
 * frequently empty. Declaring a scalar type on it is a bet that the user filled
 * the field in. So: no type, and coercion happens once, in normalizedFormState(),
 * on the way to the model.
 *
 * Do NOT reuse Helpers::mapDatabaseTypeToPhp() here. That exists to write
 * `@property int $tenant_id` docblocks, where int is correct and harmless.
 */
final class LivewirePropertyMap
{
    public const CAST_INT = 'int';

    public const CAST_FLOAT = 'float';

    /** decimal/numeric/money — normalised as a numeric STRING so precision survives. */
    public const CAST_DECIMAL = 'decimal';

    public const CAST_BOOL = 'bool';

    public const CAST_ARRAY = 'array';

    public const CAST_DATE = 'date';

    public const CAST_DATETIME = 'datetime';

    public const CAST_TIME = 'time';

    public const CAST_STRING = 'string';

    /**
     * Columns that are never part of form state.
     *
     * @var list<string>
     */
    private const NEVER_BOUND = [
        'id',
        'created_at',
        'updated_at',
        'deleted_at',
        'remember_token',
        'password',
    ];

    /**
     * The cast token for a column, with a trailing "?" when the column is
     * nullable. The suffix is load-bearing: it is how the runtime trait knows
     * whether an empty text input means null or an empty string.
     *
     * @param  array<string, mixed>  $column
     */
    public static function cast(array $column): string
    {
        $cast = self::baseCast($column);

        return self::isNullable($column) ? $cast.'?' : $cast;
    }

    /**
     * @param  array<string, mixed>  $column
     */
    public static function baseCast(array $column): string
    {
        $type = strtolower(trim((string) ($column['type'] ?? '')));

        // Postgres array columns: integer[], text[], jsonb[]
        if (str_ends_with($type, '[]') || str_starts_with($type, '_')) {
            return self::CAST_ARRAY;
        }

        return match (true) {
            // Checked before the int family: MySQL spells booleans tinyint(1).
            self::matches($type, ['bool', 'boolean', 'bit']) => self::CAST_BOOL,
            str_starts_with($type, 'tinyint(1)') => self::CAST_BOOL,

            self::matches($type, ['json', 'jsonb', 'hstore']) => self::CAST_ARRAY,

            // Before the int family so "timestamp" / "datetime" win.
            self::matches($type, ['timestamptz', 'timestamp', 'datetime']) => self::CAST_DATETIME,
            $type === 'date' => self::CAST_DATE,
            self::matches($type, ['timetz', 'time']) => self::CAST_TIME,

            // Before float: a money column must not become a PHP float.
            self::matches($type, ['decimal', 'numeric', 'money']) => self::CAST_DECIMAL,
            self::matches($type, ['float', 'double', 'real']) => self::CAST_FLOAT,

            self::matches($type, ['int', 'serial']) => self::CAST_INT,

            default => self::CAST_STRING,
        };
    }

    /**
     * The initial value, as PHP source.
     *
     * null for almost everything, because "no input yet" is not 0 or "". The two
     * exceptions both break wire:model when left null:
     *   bool  — a checkbox bound to null renders indeterminate
     *   array — a multi-select or repeater bound to null throws on foreach
     *
     * @param  array<string, mixed>  $column
     */
    public static function defaultLiteral(array $column): string
    {
        return match (self::baseCast($column)) {
            self::CAST_ARRAY => '[]',
            self::CAST_BOOL => self::boolDefaultLiteral($column),
            default => 'null',
        };
    }

    /**
     * One property declaration:  public $tenant_id = null;
     *
     * @param  array<string, mixed>  $column
     */
    public static function declaration(array $column, string $indent = '    '): string
    {
        $name = (string) ($column['name'] ?? '');

        return sprintf('%spublic $%s = %s;', $indent, $name, self::defaultLiteral($column));
    }

    /**
     * The whole form-state block, with the DB type kept as a comment so the
     * untyped declaration is not mistaken for laziness.
     *
     * @param  array<int, array<string, mixed>>  $columns
     */
    public static function renderProperties(array $columns, string $indent = '    '): string
    {
        $lines = [];

        foreach (self::boundColumns($columns) as $column) {
            $type = (string) ($column['type'] ?? '');
            $note = $type !== '' ? '  // '.$type.(self::isNullable($column) ? ', nullable' : '') : '';

            $lines[] = self::declaration($column, $indent).$note;
        }

        return implode("\n", $lines);
    }

    /**
     * The cast map the component passes to the runtime trait.
     *
     * @param  array<int, array<string, mixed>>  $columns
     * @return array<string, string>
     */
    public static function castMap(array $columns): array
    {
        $map = [];

        foreach (self::boundColumns($columns) as $column) {
            $map[(string) $column['name']] = self::cast($column);
        }

        return $map;
    }

    /**
     * Renders:
     *
     *     protected array $anvilCasts = [
     *         'tenant_id' => 'int',
     *         'amount' => 'decimal?',
     *     ];
     *
     * @param  array<int, array<string, mixed>>  $columns
     */
    public static function renderCastMap(array $columns, string $indent = '    '): string
    {
        $map = self::castMap($columns);

        if ($map === []) {
            return $indent.'protected array $anvilCasts = [];';
        }

        $out = $indent."/**\n";
        $out .= $indent." * Column => cast for NormalizesFormState. A trailing \"?\" marks a\n";
        $out .= $indent." * nullable column, where an empty input becomes null rather than \"\".\n";
        $out .= $indent." *\n";
        $out .= $indent." * @var array<string, string>\n";
        $out .= $indent." */\n";
        $out .= $indent."protected array \$anvilCasts = [\n";

        foreach ($map as $name => $cast) {
            $out .= $indent."    '{$name}' => '{$cast}',\n";
        }

        return $out.$indent.'];';
    }

    /**
     * The columns a form actually binds: no surrogate key, no timestamps, no
     * auto-increment, no password/token.
     *
     * @param  array<int, array<string, mixed>>  $columns
     * @return list<array<string, mixed>>
     */
    public static function boundColumns(array $columns): array
    {
        $bound = [];

        foreach ($columns as $column) {
            $name = (string) ($column['name'] ?? '');

            if ($name === '' || in_array(strtolower($name), self::NEVER_BOUND, true)) {
                continue;
            }

            if (str_contains(strtolower((string) ($column['extra'] ?? '')), 'auto_increment')) {
                continue;
            }

            if (self::isGenerated($column)) {
                continue;
            }

            $bound[] = $column;
        }

        return $bound;
    }

    /**
     * Validation rules for one column. Deliberately conservative — a generated
     * rule that is wrong is worse than a missing one.
     *
     * @param  array<string, mixed>  $column
     * @return list<string>
     */
    public static function rules(array $column): array
    {
        $nullable = self::isNullable($column);
        $hasDefault = ($column['default'] ?? null) !== null;
        $cast = self::baseCast($column);

        // A checkbox is never "required" — unchecked is a valid answer.
        $rules = [$nullable || $hasDefault || $cast === self::CAST_BOOL ? 'nullable' : 'required'];

        $rules[] = match ($cast) {
            self::CAST_INT => 'integer',
            self::CAST_FLOAT, self::CAST_DECIMAL => 'numeric',
            self::CAST_BOOL => 'boolean',
            self::CAST_ARRAY => 'array',
            self::CAST_DATE, self::CAST_DATETIME => 'date',
            self::CAST_TIME => 'date_format:H:i',
            default => 'string',
        };

        $length = $column['length'] ?? $column['character_maximum_length'] ?? null;

        if ($cast === self::CAST_STRING && is_numeric($length) && (int) $length > 0) {
            $rules[] = 'max:'.(int) $length;
        }

        return $rules;
    }

    // -----------------------------------------------------------------------
    // View side — driven by the SAME cast token as the property
    // -----------------------------------------------------------------------

    /**
     * The HTML control for a column.
     *
     * This must stay derived from baseCast(). The moment the view picks an input
     * type independently, you get a text box posting "" into a column the
     * component normalises as an int — which is the failure mode this whole class
     * exists to remove.
     *
     * @param  array<string, mixed>  $column
     * @param  bool  $isForeignKey  Pass true for a column in the table's FK list.
     */
    public static function inputType(array $column, bool $isForeignKey = false): string
    {
        if ($isForeignKey) {
            return 'select';
        }

        $name = strtolower((string) ($column['name'] ?? ''));
        $type = strtolower((string) ($column['type'] ?? ''));

        if (str_starts_with($type, 'enum') || str_starts_with($type, 'set')) {
            return 'select';
        }

        return match (self::baseCast($column)) {
            self::CAST_BOOL => 'checkbox',
            self::CAST_INT, self::CAST_FLOAT, self::CAST_DECIMAL => 'number',
            self::CAST_DATE => 'date',
            self::CAST_DATETIME => 'datetime-local',
            self::CAST_TIME => 'time',
            self::CAST_ARRAY => 'textarea',
            default => self::stringInputType($name, $type),
        };
    }

    /**
     * The step attribute for a numeric input. Without it the browser rejects
     * decimals on a `number` field.
     *
     * @param  array<string, mixed>  $column
     */
    public static function inputStep(array $column): ?string
    {
        $cast = self::baseCast($column);

        if ($cast === self::CAST_INT) {
            return '1';
        }

        if ($cast !== self::CAST_DECIMAL && $cast !== self::CAST_FLOAT) {
            return null;
        }

        $scale = $column['scale'] ?? $column['numeric_scale'] ?? null;

        if (is_numeric($scale)) {
            $scale = (int) $scale;

            return $scale <= 0 ? '1' : '0.'.str_repeat('0', $scale - 1).'1';
        }

        // Parse it out of "numeric(12,2)" when the inspector did not split it.
        if (preg_match('/\(\s*\d+\s*,\s*(\d+)\s*\)/', (string) ($column['type'] ?? ''), $m) === 1) {
            $scale = (int) $m[1];

            return $scale <= 0 ? '1' : '0.'.str_repeat('0', $scale - 1).'1';
        }

        return 'any';
    }

    /**
     * The wire:model variant. Controls with discrete values update live; free
     * text waits for blur so every keystroke is not a round trip.
     *
     * @param  array<string, mixed>  $column
     */
    public static function wireModel(array $column, bool $isForeignKey = false): string
    {
        $name = (string) ($column['name'] ?? '');

        return match (self::inputType($column, $isForeignKey)) {
            'checkbox', 'select', 'date', 'datetime-local', 'time' => 'wire:model.live="'.$name.'"',
            default => 'wire:model.blur="'.$name.'"',
        };
    }

    /**
     * enum('draft','sent') / set(...) → ['draft', 'sent']. Empty for other types.
     *
     * @param  array<string, mixed>  $column
     * @return list<string>
     */
    public static function enumValues(array $column): array
    {
        $type = (string) ($column['type'] ?? '');

        if (preg_match('/^(?:enum|set)\s*\((.*)\)$/is', trim($type), $m) !== 1) {
            return [];
        }

        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $m[1], $values);

        return array_map(
            static fn (string $value): string => str_replace(["\\'", '\\\\'], ["'", '\\'], $value),
            $values[1] ?? [],
        );
    }

    private static function stringInputType(string $name, string $type): string
    {
        if (self::matches($type, ['text', 'clob']) && ! str_contains($type, 'varchar') && ! str_contains($type, 'tinytext')) {
            return 'textarea';
        }

        return match (true) {
            str_contains($name, 'email') => 'email',
            str_contains($name, 'url') || str_contains($name, 'website') => 'url',
            str_contains($name, 'phone') || str_contains($name, 'mobile') || str_contains($name, 'telephone') => 'tel',
            str_contains($name, 'colour') || str_contains($name, 'color') => 'color',
            default => 'text',
        };
    }

    // -----------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $column
     */
    private static function isNullable(array $column): bool
    {
        $nullable = $column['nullable'] ?? $column['is_nullable'] ?? true;

        if (is_string($nullable)) {
            return ! in_array(strtoupper($nullable), ['NO', 'FALSE', '0'], true);
        }

        return (bool) $nullable;
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private static function isGenerated(array $column): bool
    {
        $extra = strtolower((string) ($column['extra'] ?? ''));

        if (str_contains($extra, 'generated') || str_contains($extra, 'virtual') || str_contains($extra, 'stored')) {
            return true;
        }

        return (bool) ($column['generated'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private static function boolDefaultLiteral(array $column): string
    {
        $default = $column['default'] ?? null;

        if ($default === null) {
            // A nullable boolean still starts unchecked; null would render the
            // checkbox indeterminate and post nothing.
            return 'false';
        }

        $normalized = strtolower(trim((string) $default));

        return in_array($normalized, ['1', 'true', 't', 'yes', 'y', 'on'], true) ? 'true' : 'false';
    }

    /**
     * @param  list<string>  $needles
     */
    private static function matches(string $type, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($type, $needle)) {
                return true;
            }
        }

        return false;
    }
}
