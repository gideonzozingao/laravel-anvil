<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

/**
 * Decides how one column becomes one Livewire form-state property.
 *
 * The rule this class exists to enforce: **form state is never scalar-typed.**
 *
 *     public int $tenant_id = null;     // fatal at class-compile time
 *     public int $tenant_id;            // "must not be accessed before initialization"
 *     public ?int $tenant_id = null;    // compiles, then TypeError on hydration
 *     public $tenant_id = null;         // correct
 *
 * The first form is the one that produced
 * "Default value for property of type int may not be null" from
 * app/Livewire/Vehicles/Form.php:15. Adding `?` fixes the fatal but not the bug:
 * an empty `<input type="number">` submits the string "", Livewire assigns it
 * straight onto the property during hydration, and `?int` rejects it with a
 * TypeError — a 500 where the operator should have seen "The tenant field is
 * required." Validation is the gate for form input; a PHP type declaration on a
 * property fed directly from an HTTP payload just converts a validation message
 * into a stack trace.
 *
 * So the type lives in a docblock, where an IDE and static analysis can still see
 * it, and coercion happens once at save time rather than on every keystroke.
 *
 * Framework-free so the decisions can be tested without booting Laravel.
 */
final readonly class FormStateProperty
{
    /**
     * Property names that collide with Livewire's own API or with what Blade
     * injects into a view. A column with one of these names cannot become a
     * plain public property.
     *
     * `rules` is the sharp one: Livewire's validation trait still honours a
     * `$rules` property, so a column called "rules" would silently become the
     * component's validation ruleset.
     *
     * @var list<string>
     */
    public const RESERVED = ['rules', 'messages', 'validationAttributes', 'validationCustomValues', 'errors'];

    /**
     * Column names never rendered as form input.
     *
     * @var list<string>
     */
    public const EXCLUDED = ['created_at', 'updated_at', 'deleted_at'];

    /**
     * Postgres/MySQL default expressions that are the database's job, not a form
     * default. Matched case-insensitively as a prefix of the stripped default.
     *
     * @var list<string>
     */
    private const VOLATILE_DEFAULTS = [
        'nextval',
        'now',
        'current_timestamp',
        'current_date',
        'current_time',
        'gen_random_uuid',
        'uuid_generate_v4',
        'uuid()',
        'localtimestamp',
        'clock_timestamp',
    ];

    private function __construct(
        private string $column,
        private string $kind,
        private bool $nullable,
        private mixed $default,
        private ?string $docType,
    ) {}

    /**
     * @param  array<string, mixed>  $column  a row from DatabaseInspector::getColumns()
     */
    public static function fromColumn(array $column): self
    {
        $name = (string) ($column['name'] ?? '');
        $type = strtolower((string) ($column['udt_name'] ?? $column['type'] ?? ''));
        $nullable = (bool) ($column['nullable'] ?? true);
        $kind = self::classify($type);

        return new self(
            $name,
            $kind,
            $nullable,
            self::resolveDefault($kind, $nullable, $column['default'] ?? null),
            self::docType($kind, $nullable),
        );
    }

    /**
     * Whether this column belongs on a form at all.
     *
     * @param  array<string, mixed>  $column
     */
    public static function isFormable(array $column, ?string $primaryKey = null): bool
    {
        $name = strtolower((string) ($column['name'] ?? ''));

        if ($name === '') {
            return false;
        }

        if (in_array($name, self::EXCLUDED, true)) {
            return false;
        }

        // An auto-incrementing or sequence-backed key is assigned by the database.
        if (str_contains(strtolower((string) ($column['extra'] ?? '')), 'auto_increment')) {
            return false;
        }

        if (self::isVolatile((string) ($column['default'] ?? ''))) {
            return false;
        }

        return $primaryKey === null || strcasecmp($name, $primaryKey) !== 0;
    }

    public function name(): string
    {
        return $this->column;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function isReserved(): bool
    {
        foreach (self::RESERVED as $reserved) {
            if (strcasecmp($this->column, $reserved) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The default value as a PHP literal: `null`, `false`, `[]`, `'active'`, `0`.
     */
    public function defaultLiteral(): string
    {
        return match (true) {
            $this->default === null => 'null',
            $this->default === true => 'true',
            $this->default === false => 'false',
            $this->default === [] => '[]',
            is_int($this->default) => (string) $this->default,
            is_float($this->default) => var_export($this->default, true),
            default => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $this->default)."'",
        };
    }

    /**
     * The full declaration, docblock included. Never carries a PHP type.
     */
    public function declaration(string $indent = '    '): string
    {
        $lines = [];

        if ($this->docType !== null) {
            $lines[] = $indent.'/** @var '.$this->docType.' */';
        }

        $lines[] = $indent.'public $'.$this->column.' = '.$this->defaultLiteral().';';

        return implode("\n", $lines);
    }

    /**
     * How this property must be coerced on the way to the model.
     *
     * Livewire hands back strings for every text-ish input, so an empty numeric
     * field arrives as "" and a NOT NULL integer column would receive it verbatim.
     * This is the value NormalizesFormState needs.
     *
     * @return array{cast: string, empty_to_null: bool}
     */
    public function normalisation(): array
    {
        return [
            'cast' => match ($this->kind) {
                'int' => 'int',
                'float' => 'float',
                'bool' => 'bool',
                'array' => 'array',
                'date', 'datetime' => 'datetime',
                default => 'string',
            },
            // "" is a valid value for a nullable text column and a validation
            // failure for everything else, so only non-strings get collapsed.
            'empty_to_null' => $this->kind !== 'string' || $this->nullable,
        ];
    }

    /**
     * Validation rules implied by the column, as a starting point.
     *
     * @return list<string>
     */
    public function rules(): array
    {
        $rules = [$this->nullable ? 'nullable' : 'required'];

        $rules[] = match ($this->kind) {
            'int' => 'integer',
            'float' => 'numeric',
            'bool' => 'boolean',
            'array' => 'array',
            'date' => 'date',
            'datetime' => 'date',
            default => 'string',
        };

        return $rules;
    }

    // -----------------------------------------------------------------------

    /**
     * Map a driver type name onto the handful of shapes a form control has.
     */
    private static function classify(string $type): string
    {
        $type = trim($type);

        return match (true) {
            $type === '' => 'string',
            (bool) preg_match('/^(bool|boolean|bit|tinyint\(1\))/', $type) => 'bool',
            (bool) preg_match('/^(int|integer|smallint|bigint|mediumint|tinyint|serial|bigserial|smallserial|int2|int4|int8)/', $type) => 'int',
            (bool) preg_match('/^(decimal|numeric|float|double|real|money|float4|float8)/', $type) => 'float',
            (bool) preg_match('/^(json|jsonb)/', $type) => 'array',
            (bool) preg_match('/^(_|.*\[\]$)/', $type) => 'array',
            (bool) preg_match('/^(date)$/', $type) => 'date',
            (bool) preg_match('/^(timestamp|datetime|time)/', $type) => 'datetime',
            default => 'string',
        };
    }

    private static function docType(string $kind, bool $nullable): string
    {
        $base = match ($kind) {
            'int' => 'int',
            'float' => 'float',
            'bool' => 'bool',
            'array' => 'array',
            'date', 'datetime' => 'string',
            default => 'string',
        };

        // Always union with null: an untouched form field is null regardless of
        // whether the column accepts it, and a "" from an empty input is a string
        // whatever the column's type. Claiming otherwise misleads static analysis.
        return $base.'|string|null';
    }

    /**
     * The default a *form* should start with, which is not always the column's.
     */
    private static function resolveDefault(string $kind, bool $nullable, mixed $raw): mixed
    {
        // A checkbox with a null default renders indeterminate and submits nothing;
        // an array field bound to null breaks wire:model on a repeater.
        $fallback = match ($kind) {
            'bool' => false,
            'array' => [],
            default => null,
        };

        if (! is_string($raw) || trim($raw) === '') {
            return $fallback;
        }

        if (self::isVolatile($raw)) {
            return $fallback;
        }

        $value = self::stripDefault($raw);

        if ($value === null || strcasecmp($value, 'null') === 0) {
            return $fallback;
        }

        return match ($kind) {
            'bool' => in_array(strtolower($value), ['true', 't', '1', 'yes'], true),
            'int' => preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $fallback,
            'float' => is_numeric($value) ? (float) $value : $fallback,
            // A json default of '{}' or '[]' is still an empty form field.
            'array' => [],
            'date', 'datetime' => $fallback,
            default => $value,
        };
    }

    /**
     * Turn a driver default expression into a bare value:
     * "'active'::character varying" => "active", "0.00" => "0.00", "true" => "true".
     */
    private static function stripDefault(string $raw): ?string
    {
        $value = trim($raw);

        // Postgres appends an explicit cast to almost every default.
        $value = (string) preg_replace('/::[a-z0-9_ \[\]".]+$/i', '', $value);
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Quoted literal, including a doubled-quote escape.
        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            return str_replace("''", "'", substr($value, 1, -1));
        }

        return $value;
    }

    private static function isVolatile(string $raw): bool
    {
        $value = strtolower(trim($raw));

        if ($value === '') {
            return false;
        }

        foreach (self::VOLATILE_DEFAULTS as $needle) {
            if (str_starts_with($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the whole property block for a table, skipping what a form must not
     * touch and reporting any column whose name collides with Livewire's API.
     *
     * @param  array<int, array<string, mixed>>  $columns
     * @return array{properties: list<self>, reserved: list<string>}
     */
    public static function plan(array $columns, ?string $primaryKey = null): array
    {
        $properties = [];
        $reserved = [];

        foreach ($columns as $column) {
            if (! is_array($column) || ! self::isFormable($column, $primaryKey)) {
                continue;
            }

            $property = self::fromColumn($column);

            if ($property->isReserved()) {
                $reserved[] = $property->name();

                continue;
            }

            $properties[] = $property;
        }

        return ['properties' => $properties, 'reserved' => $reserved];
    }
}
