<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

use Illuminate\Support\Str;

/**
 * One column whose values come from a fixed set, and the PHP enum that will
 * represent it.
 *
 * Case naming is the fiddly part. Database values are arbitrary strings; PHP enum
 * cases are class constants, so they must be valid identifiers, must not collide
 * after transformation, and must avoid the handful of names PHP refuses outright.
 * All of that is resolved once, here, so the model cast, the validation rule, the
 * OpenAPI schema and the TypeScript union all agree.
 */
final readonly class EnumColumn
{
    /**
     * `class` is not usable as a class constant, so it is not usable as an enum
     * case either. The rest are reserved words that PHP does allow as case names
     * but which read badly and break some tooling.
     *
     * @var list<string>
     */
    private const AWKWARD_CASE_NAMES = ['class', 'default', 'function', 'match', 'fn', 'list', 'print', 'echo'];

    /**
     * @param  array<string, string>  $cases  value => case name
     */
    private function __construct(
        public string $table,
        public string $column,
        public string $enumName,
        public string $backing,
        public array $cases,
        public bool $nullable,
        public ?string $default,
        public string $source,
    ) {}

    /**
     * @param  list<string|int>  $values
     * @param  array<string, mixed>  $column
     */
    public static function make(
        string $table,
        array $column,
        array $values,
        string $source,
        ?string $modelName = null,
    ): ?self {
        $values = array_values(array_unique(array_map(strval(...), $values)));

        if (count($values) < 2) {
            // A one-value "set" is a constant, not an enum; and an empty one is a
            // parse failure we should not turn into an empty enum class.
            return null;
        }

        $name = (string) $column['name'];
        $backing = self::backingFor($values);

        return new self(
            table: $table,
            column: $name,
            enumName: self::className($table, $name, $modelName),
            backing: $backing,
            cases: self::caseNames($values, $backing),
            nullable: (bool) ($column['nullable'] ?? false),
            default: self::normaliseDefault($column['default'] ?? null),
            source: $source,
        );
    }

    /**
     * Integer-backed only when every value is an integer. A mixed set is a string
     * enum: "1" and "active" cannot share an int backing.
     *
     * @param  list<string>  $values
     */
    private static function backingFor(array $values): string
    {
        foreach ($values as $value) {
            if (! preg_match('/^-?\d+$/', $value)) {
                return 'string';
            }
        }

        return 'int';
    }

    /**
     * The generated class name. `vehicle_bookings.status` → `VehicleBookingStatus`
     * by default; `anvil.enums.naming = 'column'` gives plain `Status`, which
     * collides across tables and is only sensible for shared vocabularies.
     */
    private static function className(string $table, string $column, ?string $modelName): string
    {
        $model = $modelName ?? Helpers::tableToModelName($table);
        $suffix = Str::studly($column);

        return match ((string) config('anvil.enums.naming', 'model_column')) {
            'column' => $suffix,
            // Avoid StatusStatus when the column is named after the model.
            default => str_starts_with($suffix, $model) ? $suffix : $model.$suffix,
        };
    }

    /**
     * @param  list<string>  $values
     * @return array<string, string> value => case name
     */
    private static function caseNames(array $values, string $backing): array
    {
        $cases = [];
        $taken = [];

        foreach ($values as $value) {
            $name = self::caseName($value, $backing);

            // Two values can studly onto the same identifier ("in-progress" and
            // "in_progress"); suffix rather than silently dropping one.
            if (isset($taken[$name])) {
                $suffix = 2;

                while (isset($taken[$name.$suffix])) {
                    $suffix++;
                }

                $name .= $suffix;
            }

            $taken[$name] = true;
            $cases[$value] = $name;
        }

        return $cases;
    }

    private static function caseName(string $value, string $backing): string
    {
        if ($backing === 'int') {
            // Numeric values carry no name of their own.
            return 'Value'.str_replace('-', 'Minus', $value);
        }

        $name = Str::studly(preg_replace('/[^A-Za-z0-9]+/', '_', $value) ?? $value);

        if ($name === '') {
            $name = 'Value'.substr(md5($value), 0, 6);
        }

        // An identifier cannot begin with a digit.
        if (preg_match('/^\d/', $name) === 1) {
            $name = 'Value'.$name;
        }

        if (in_array(strtolower($name), self::AWKWARD_CASE_NAMES, true)) {
            $name .= 'Case';
        }

        return $name;
    }

    private static function normaliseDefault(mixed $default): ?string
    {
        if ($default === null || ! is_scalar($default)) {
            return null;
        }

        $value = trim((string) $default);

        // Postgres reports defaults with their cast: 'scheduled'::character varying
        if (($cast = strpos($value, '::')) !== false) {
            $value = trim(substr($value, 0, $cast));
        }

        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            $value = str_replace("''", "'", substr($value, 1, -1));
        }

        return $value === '' ? null : $value;
    }

    // -----------------------------------------------------------------------
    // Accessors
    // -----------------------------------------------------------------------

    /** @return list<string> */
    public function values(): array
    {
        return array_keys($this->cases);
    }

    public function namespace(): string
    {
        return trim((string) config('anvil.enums.namespace', 'App\\Enums'), '\\');
    }

    public function fqcn(): string
    {
        return $this->namespace().'\\'.$this->enumName;
    }

    public function path(): string
    {
        $relative = $this->namespace();

        if (str_starts_with($relative, 'App\\')) {
            $relative = substr($relative, 4);
        }

        return app_path(str_replace('\\', '/', $relative).'/'.$this->enumName.'.php');
    }

    /** The case name for the column default, when the default is one of the values. */
    public function defaultCase(): ?string
    {
        return $this->default !== null ? ($this->cases[$this->default] ?? null) : null;
    }

    /** Human label for a raw value: "in_progress" → "In Progress". */
    public function label(string $value): string
    {
        return Str::headline(str_replace('-', ' ', $value));
    }

    /** `'draft','active'` — for an `in:` rule or a spec enum list. */
    public function valueList(string $separator = ','): string
    {
        return implode($separator, $this->values());
    }
}
