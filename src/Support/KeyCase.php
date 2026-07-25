<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

use Illuminate\Support\Str;

/**
 * Converts payload key casing, and — more importantly — builds the EXPLICIT maps
 * the generated code uses instead of converting at runtime.
 *
 * Why maps rather than runtime conversion:
 *
 *   address_line_1  →camel→  addressLine1  →snake→  address_line1   ✗
 *
 * The reverse trip is not lossless, because camelCase discards the boundary
 * before a digit. Any generated class that calls Str::snake() on inbound keys
 * will silently drop or misroute such a field: validation passes (the rule keyed
 * address_line_1 simply sees nothing), the column is never written, and nothing
 * anywhere reports an error.
 *
 * Building the map from the real column list at generation time removes the
 * ambiguity entirely — the generated class states "addressLine1 is
 * address_line_1" as a literal fact about the schema it was generated from.
 */
final class KeyCase
{
    public const SNAKE = 'snake';

    public const CAMEL = 'camel';

    public const STUDLY = 'studly';

    public const KEBAB = 'kebab';

    /** No transformation — keys pass through as the column names they are. */
    public const NONE = 'none';

    /** @var list<string> */
    public const ALL = [self::SNAKE, self::CAMEL, self::STUDLY, self::KEBAB, self::NONE];

    public static function normalise(mixed $case): string
    {
        $case = strtolower((string) $case);

        return in_array($case, self::ALL, true) ? $case : self::SNAKE;
    }

    public static function convert(string $key, string $case): string
    {
        return match (self::normalise($case)) {
            self::CAMEL => Str::camel($key),
            self::STUDLY => Str::studly($key),
            self::KEBAB => Str::kebab(Str::camel($key)),
            self::SNAKE => Str::snake($key),
            default => $key,
        };
    }

    /**
     * apiKey => column, built from the actual column list.
     *
     * Collisions are possible in theory (columns "foo_bar" and "fooBar" both
     * camelising to "fooBar"); the first wins and the second keeps its original
     * name, so no column becomes unreachable.
     *
     * @param  list<string>  $columns
     * @return array<string, string>
     */
    public static function map(array $columns, string $case): array
    {
        $case = self::normalise($case);
        $map = [];

        foreach ($columns as $column) {
            $column = (string) $column;

            if ($column === '') {
                continue;
            }

            $key = self::convert($column, $case);

            if (isset($map[$key]) && $map[$key] !== $column) {
                $key = $column;   // collision — fall back to the column name
            }

            $map[$key] = $column;
        }

        return $map;
    }

    /**
     * Keys that would round-trip incorrectly, i.e. where converting back does
     * not return the original column. These are the reason maps exist; surfacing
     * them in the run summary is a useful warning about schema naming.
     *
     * @param  list<string>  $columns
     * @return array<string, string> column => what a naive reverse trip yields
     */
    public static function lossyRoundTrips(array $columns, string $case): array
    {
        $case = self::normalise($case);

        if ($case === self::SNAKE || $case === self::NONE) {
            return [];
        }

        $lossy = [];

        foreach ($columns as $column) {
            $column = (string) $column;
            $back = Str::snake(self::convert($column, $case));

            if ($back !== $column) {
                $lossy[$column] = $back;
            }
        }

        return $lossy;
    }

    /**
     * Recursively re-key an array. Used by tests and tooling; the generated
     * runtime classes carry their own inlined version so they have no dependency
     * on this package.
     *
     * @param  array<mixed>  $data
     * @param  list<string>  $preserve  keys passed through untouched
     * @return array<mixed>
     */
    public static function keys(array $data, string $case, array $preserve = []): array
    {
        if (self::normalise($case) === self::NONE) {
            return $data;
        }

        $out = [];

        foreach ($data as $key => $value) {
            $newKey = is_string($key) && ! in_array($key, $preserve, true)
                ? self::convert($key, $case)
                : $key;

            $out[$newKey] = is_array($value) ? self::keys($value, $case, $preserve) : $value;
        }

        return $out;
    }
}
