<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

/**
 * Parses a --schema selection, including the case where the shell split it.
 *
 * `--schema=core,employers_db, admin_db,forms_db` contains a space, so the shell
 * hands Symfony `--schema=core,employers_db,` plus a positional argument
 * `admin_db,forms_db`. Symfony then rejects the whole invocation with
 * "No arguments expected", which tells the operator nothing about the real cause.
 *
 * Rather than fail, the command declares an optional catch-all argument and feeds
 * it through here: fragments that look like schema names are folded back into the
 * selection and reported, and anything else is rejected by name so a genuine typo
 * still surfaces.
 *
 * Framework-free so it can be unit tested without booting Laravel.
 */
final readonly class SchemaSelection
{
    /** A single schema identifier: letters, digits, underscore, dollar, dot, hyphen. */
    private const IDENTIFIER = '[A-Za-z_][A-Za-z0-9_$.-]*';

    /**
     * @param  list<string>  $schemas  the resolved selection, in order, deduplicated
     * @param  list<string>  $recovered  fragments recovered from stray arguments
     * @param  list<string>  $rejected  stray arguments that are not schema names
     */
    private function __construct(
        private array $schemas,
        private array $recovered,
        private array $rejected,
    ) {}

    /**
     * @param  string|array<int, string>|null  $option  the --schema value
     * @param  array<int, string>  $strayArguments  positional arguments Symfony collected
     */
    public static function fromInput(string|array|null $option, array $strayArguments = []): self
    {
        $schemas = [];
        $recovered = [];
        $rejected = [];

        foreach (self::split($option) as $name) {
            $schemas[] = $name;
        }

        foreach ($strayArguments as $argument) {
            $argument = trim((string) $argument);

            if ($argument === '') {
                continue;
            }

            if (preg_match('/^'.self::IDENTIFIER.'(\s*,\s*'.self::IDENTIFIER.')*,?$/', $argument) !== 1) {
                $rejected[] = $argument;

                continue;
            }

            foreach (self::split($argument) as $name) {
                $schemas[] = $name;
                $recovered[] = $name;
            }
        }

        return new self(
            self::dedupe($schemas),
            self::dedupe($recovered),
            array_values($rejected),
        );
    }

    /**
     * @return list<string>
     */
    public function schemas(): array
    {
        return $this->schemas;
    }

    /**
     * The selection as DatabaseInspector::resolveSchemas() wants it, or null when
     * nothing was requested (meaning: the connection's default schema).
     */
    public function value(): ?string
    {
        return $this->schemas === [] ? null : implode(',', $this->schemas);
    }

    /**
     * @return list<string>
     */
    public function recovered(): array
    {
        return $this->recovered;
    }

    /**
     * @return list<string>
     */
    public function rejected(): array
    {
        return $this->rejected;
    }

    public function hasRecovered(): bool
    {
        return $this->recovered !== [];
    }

    public function hasRejected(): bool
    {
        return $this->rejected !== [];
    }

    public function isEmpty(): bool
    {
        return $this->schemas === [];
    }

    /**
     * Whether the selection asks for every schema.
     */
    public function isAll(): bool
    {
        foreach ($this->schemas as $schema) {
            if ($schema === 'all' || $schema === '*') {
                return true;
            }
        }

        return false;
    }

    /**
     * The correctly quoted flag, for telling the operator what to type instead.
     */
    public function suggestedFlag(): string
    {
        return $this->isEmpty() ? '' : '--schema="'.implode(',', $this->schemas).'"';
    }

    /**
     * @param  string|array<int, string>|null  $value
     * @return list<string>
     */
    private static function split(string|array|null $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            $parts = [];

            foreach ($value as $item) {
                foreach (self::split((string) $item) as $name) {
                    $parts[] = $name;
                }
            }

            return $parts;
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', $value)),
            static fn (string $name): bool => $name !== '',
        ));
    }

    /**
     * Deduplicate case-insensitively, keeping the first spelling seen. Schema names
     * are case-sensitive when quoted in Postgres, so the original casing is
     * preserved rather than lowercased.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private static function dedupe(array $names): array
    {
        $seen = [];
        $out = [];

        foreach ($names as $name) {
            $key = strtolower($name);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $name;
        }

        return $out;
    }
}
