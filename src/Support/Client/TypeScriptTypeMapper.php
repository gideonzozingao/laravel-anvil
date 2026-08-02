<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Client;

use Zuqongtech\LaravelAnvil\Support\ColumnType;
use Zuqongtech\LaravelAnvil\Support\EnumColumn;

/**
 * Projects a normalised {@see ColumnType} onto a TypeScript type.
 *
 * Deliberately the only place in the client generator that knows what a
 * TypeScript type looks like. `OpenApiTypeMapper` should become its sibling —
 * same input, different projection — so a spec and a client generated from one
 * schema cannot describe different shapes.
 */
final readonly class TypeScriptTypeMapper
{
    /**
     * Bigints are emitted as `string` rather than `number` when this is on.
     *
     * JSON numbers are IEEE 754 doubles, so anything past 2^53 loses precision
     * silently in the browser — a snowflake ID arrives subtly wrong and nothing
     * throws. Laravel serialises bigints as numbers by default, so the honest
     * default here is false; turn it on when the API casts them to strings.
     */
    public function __construct(
        private bool $bigIntAsString = false,
    ) {}

    /**
     * @param  array<string, mixed>  $column  a column row from ModelMetadata
     */
    public function forColumn(array $column): TsType
    {
        $raw = (string) ($column['type'] ?? 'varchar');
        $nullable = (bool) ($column['nullable'] ?? false);
        $type = ColumnType::fromDatabaseType($raw);

        // An enum column has a known value set, and a union of literals is far
        // more useful to a caller than `string` — the compiler rejects a typo
        // in a status value at the call site rather than at runtime.
        if ($type === ColumnType::Enum) {
            $values = $this->enumValues($column, $raw);

            return TsType::literalUnion($values, $nullable);
        }

        if ($this->bigIntAsString && $this->isBigInt($raw)) {
            return TsType::string($nullable);
        }

        return match ($type) {
            ColumnType::Integer, ColumnType::Float => TsType::number($nullable),
            ColumnType::Boolean => TsType::boolean($nullable),
            ColumnType::Json => TsType::unknownRecord($nullable),

            // ISO 8601 strings. JSON has no date type, and parsing is the
            // caller's decision — returning `Date` would force one.
            ColumnType::Date, ColumnType::DateTime, ColumnType::Time => TsType::string($nullable),

            default => TsType::string($nullable),
        };
    }

    /**
     * The type of a primary or foreign key, for signatures like `get(id: X)`.
     */
    public function forKey(?array $column): TsType
    {
        if ($column === null) {
            return TsType::of('number | string');
        }

        // Keys are never null in a path parameter even when the column allows
        // it, so the nullability of the column is not carried through.
        return $this->forColumn(['type' => $column['type'] ?? '', 'nullable' => false]);
    }

    /**
     * @param  array<string, mixed>  $column
     * @return list<string>
     */
    private function enumValues(array $column, string $raw): array
    {
        // Prefer values the detector already resolved; they account for
        // check-constraint emulation on Postgres, which is not recoverable
        // from the type string alone.
        $detected = $column['enum_values'] ?? $column['values'] ?? null;

        if (is_array($detected) && $detected !== []) {
            return array_values(array_map(strval(...), $detected));
        }

        if (class_exists(EnumColumn::class) && method_exists(EnumColumn::class, 'valuesFromType')) {
            /** @var list<string> $values */
            $values = EnumColumn::valuesFromType($raw);

            if ($values !== []) {
                return $values;
            }
        }

        // MySQL: enum('draft','published')
        if (preg_match("/enum\((.*)\)/i", $raw, $matches) === 1) {
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $matches[1], $found);

            return array_map(
                stripslashes(...),
                $found[1] ?? [],
            );
        }

        return [];
    }

    private function isBigInt(string $raw): bool
    {
        $type = strtolower($raw);

        return str_contains($type, 'bigint') || str_contains($type, 'bigserial');
    }
}
