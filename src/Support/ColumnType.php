<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

/**
 * A database column type, normalised away from driver spelling.
 *
 * This exists because the package currently maps raw driver types to a target
 * language in more than one place — `OpenApiTypeMapper` for the spec, and an
 * inline `tsType()` in the client command. Two mappers reading the same column
 * and disagreeing is how a spec ends up documenting `integer` while the
 * TypeScript client declares `string`, and nothing catches it because neither
 * side knows the other exists.
 *
 * One normalisation, many projections. `OpenApiTypeMapper` should be refactored
 * to consume this too, at which point the two can only disagree if this enum is
 * wrong — which is a single test rather than a class of bug.
 */
enum ColumnType
{
    case Integer;
    case Float;
    case Boolean;
    case Json;
    case Date;
    case DateTime;
    case Time;
    case Uuid;
    case Text;
    case String;
    case Binary;
    case Enum;
    case Unknown;

    /**
     * @param  string  $databaseType  raw type as reported by the driver,
     *                                e.g. `varchar(255)`, `tinyint(1)`,
     *                                `timestamp without time zone`
     */
    public static function fromDatabaseType(string $databaseType): self
    {
        $raw = strtolower(trim($databaseType));

        // tinyint(1) is MySQL's boolean. Checked before the integer branch,
        // and against the *unstripped* type, because the width is the signal.
        if (str_starts_with($raw, 'tinyint(1)') || str_starts_with($raw, 'bit(1)')) {
            return self::Boolean;
        }

        $type = trim((string) preg_replace('/\(.*?\)/', '', $raw));

        return match (true) {
            $type === 'uuid', $type === 'uniqueidentifier' => self::Uuid,
            str_contains($type, 'bool') => self::Boolean,
            str_contains($type, 'json'), $type === 'jsonb' => self::Json,

            // Order matters: `timestamp` and `datetime` both contain "time".
            str_contains($type, 'timestamp'), str_contains($type, 'datetime') => self::DateTime,
            $type === 'date' => self::Date,
            str_contains($type, 'time') => self::Time,

            str_contains($type, 'enum'), str_contains($type, 'set') => self::Enum,
            (bool) preg_match('/(int|serial)/', $type) => self::Integer,
            (bool) preg_match('/(decimal|numeric|float|double|real|money)/', $type) => self::Float,
            (bool) preg_match('/(blob|binary|bytea)/', $type) => self::Binary,
            str_contains($type, 'text') => self::Text,
            default => self::String,
        };
    }

    /**
     * True when the value arrives as a JSON number rather than a string.
     *
     * Note that this is about the *wire*, not the database: a `bigint` beyond
     * 2^53 is numeric in the database and unsafe as a JSON number, which is why
     * callers that care about precision should not rely on this alone.
     */
    public function isNumeric(): bool
    {
        return $this === self::Integer || $this === self::Float;
    }

    public function isTemporal(): bool
    {
        return in_array($this, [self::Date, self::DateTime, self::Time], true);
    }
}
