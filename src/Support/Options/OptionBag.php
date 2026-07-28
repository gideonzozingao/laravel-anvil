<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Options;

/**
 * Base for every option group.
 *
 * THE PROBLEM THIS SOLVES
 *
 * GenerationOptions::fromArray() accepts a string-keyed array and quietly ignores
 * anything it does not recognise. That single behaviour produced two separate
 * workaround layers:
 *
 *   GenerateOpenApiCommand::alignKeys()            — reflects over toArray() to
 *                                                    guess the accepted spelling
 *   GenerateOpenApiCommand::assertOptionsUnderstood() — checks afterwards whether
 *                                                    the flags survived
 *
 * Both exist because passing 'open_api' where 'openApi' was expected left every
 * multi-word flag at its default, the pipeline ran to completion, and the command
 * reported success having written nothing. A misspelling that produces a silent
 * no-op is the worst possible failure mode for a generator.
 *
 * So hydration here is STRICT. An unrecognised key throws, and the message names
 * the closest valid key. Spelling stops being something callers have to guess,
 * which is what lets alignKeys() and assertOptionsUnderstood() be deleted.
 *
 * Keys are matched case-insensitively with separators ignored, so 'per_page',
 * 'perPage' and 'PerPage' all reach $perPage — the flexibility alignKeys() was
 * reaching for, without the guessing.
 */
abstract readonly class OptionBag
{
    /**
     * @param  array<string, mixed>  $values
     *
     * @throws \InvalidArgumentException on an unrecognised key
     */
    public static function fromArray(array $values): static
    {
        $parameters = self::parameters();
        $index = [];

        foreach ($parameters as $parameter) {
            $index[self::normalise($parameter->getName())] = $parameter->getName();
        }

        $arguments = [];
        $unknown = [];

        foreach ($values as $key => $value) {
            $normalised = self::normalise((string) $key);

            if (! isset($index[$normalised])) {
                $unknown[] = (string) $key;

                continue;
            }

            $arguments[$index[$normalised]] = $value;
        }

        if ($unknown !== []) {
            throw new \InvalidArgumentException(self::unknownKeyMessage($unknown, array_values($index)));
        }

        return new static(...self::coerce($arguments, $parameters));
    }

    /**
     * Lenient variant for migrating existing call sites.
     *
     * Returns the unrecognised keys instead of throwing, so a command can report
     * them and continue. Prefer fromArray() in new code — a warning nobody reads
     * is only marginally better than the silence this replaces.
     *
     * $unknown is untyped by reference for the same reason preg_match's $matches
     * is: a typed by-ref parameter TypeErrors on an as-yet-undefined variable.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $unknown  Populated with keys that were ignored
     */
    public static function fromArrayLenient(array $values, &$unknown = []): static
    {
        $known = [];
        $unknown = [];
        $index = [];

        foreach (self::parameters() as $parameter) {
            $index[self::normalise($parameter->getName())] = $parameter->getName();
        }

        foreach ($values as $key => $value) {
            $normalised = self::normalise((string) $key);

            isset($index[$normalised])
                ? $known[$index[$normalised]] = $value
                : $unknown[] = (string) $key;
        }

        return static::fromArray($known);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];

        foreach (self::parameters() as $parameter) {
            $name = $parameter->getName();
            $value = $this->{$name};

            $out[$name] = $value instanceof self ? $value->toArray() : $value;
        }

        return $out;
    }

    /**
     * An immutable copy with some values replaced.
     *
     * Readonly classes cannot be mutated, and the alternative — re-listing every
     * constructor argument at each call site — is where a forgotten field
     * silently reverts to its default.
     *
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): static
    {
        return static::fromArray([...$this->toArray(), ...$changes]);
    }

    /** @return list<string> Valid key names, for error messages and docs. */
    public static function keys(): array
    {
        return array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            self::parameters(),
        );
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /** @return list<\ReflectionParameter> */
    private static function parameters(): array
    {
        $constructor = (new \ReflectionClass(static::class))->getConstructor();

        return $constructor === null ? [] : $constructor->getParameters();
    }

    private static function normalise(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
    }

    /**
     * Cast scalars to their declared types.
     *
     * Console options arrive as strings — '15' for an int, '1' for a bool — and a
     * typed promoted property would otherwise TypeError on perfectly valid input.
     *
     * @param  array<string, mixed>  $arguments
     * @param  list<\ReflectionParameter>  $parameters
     * @return array<string, mixed>
     */
    private static function coerce(array $arguments, array $parameters): array
    {
        foreach ($parameters as $parameter) {
            $name = $parameter->getName();

            if (! array_key_exists($name, $arguments)) {
                continue;
            }

            $type = $parameter->getType();

            if (! $type instanceof \ReflectionNamedType) {
                continue;
            }

            $value = $arguments[$name];

            if ($value === null) {
                // Leave nulls alone: either the type allows it, or dropping the
                // key so the default applies is the kinder reading.
                if (! $type->allowsNull()) {
                    unset($arguments[$name]);
                }

                continue;
            }

            $arguments[$name] = match ($type->getName()) {
                'bool' => is_string($value)
                    ? filter_var($value, FILTER_VALIDATE_BOOLEAN)
                    : (bool) $value,
                'int' => is_numeric($value) ? (int) $value : $value,
                'float' => is_numeric($value) ? (float) $value : $value,
                'string' => is_scalar($value) ? (string) $value : $value,
                'array' => is_array($value) ? $value : [$value],
                default => $value,
            };
        }

        return $arguments;
    }

    /**
     * @param  list<string>  $unknown
     * @param  list<string>  $valid
     */
    private static function unknownKeyMessage(array $unknown, array $valid): string
    {
        $parts = [];

        foreach ($unknown as $key) {
            $suggestion = self::closest($key, $valid);

            $parts[] = $suggestion === null
                ? sprintf('"%s"', $key)
                : sprintf('"%s" (did you mean "%s"?)', $key, $suggestion);
        }

        return sprintf(
            "%s does not accept %s.\nValid keys: %s",
            static::class,
            implode(', ', $parts),
            implode(', ', $valid),
        );
    }

    /**
     * @param  list<string>  $candidates
     */
    private static function closest(string $key, array $candidates): ?string
    {
        $normalised = self::normalise($key);
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($candidates as $candidate) {
            $distance = levenshtein($normalised, self::normalise($candidate));

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }

        // Beyond a third of the key's length the "suggestion" is noise.
        return $bestDistance <= max(2, intdiv(strlen($normalised), 3)) ? $best : null;
    }
}
