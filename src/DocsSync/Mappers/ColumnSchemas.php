<?php

namespace Zuqongtech\LaravelAnvil\DocsSync\Mappers;

/**
 * Column-name -> OpenAPI schema fragment lookup, sourced from the `{Model}`
 * entity schema that the OpenAPI generators already wrote.
 *
 * This class exists so sync NEVER re-derives a column type. If it introspected
 * the database itself it would be a second authority on type mapping, and the
 * two would eventually disagree -- generate would call a column `string/date-time`
 * while sync called it `string`, and every run would report phantom drift. Reading
 * the generator's own output makes that class of bug structurally impossible.
 *
 * It is also why `exists:businesses,id` resolves to the real key type rather than
 * a guessed `string`: the referenced table's entity schema is right there.
 */
final readonly class ColumnSchemas
{
    /** Prose the spec owns. Never copied out of an entity schema into a payload. */
    public const PROSE_KEYS = [
        'description',
        'title',
        'summary',
        'example',
        'examples',
        'deprecated',
        'externalDocs',
        'default',
    ];

    /** @param array<string, array<string, mixed>> $byModel model => properties map */
    public function __construct(private array $byModel = []) {}

    /**
     * Build from the spec's `components.schemas`, treating every object schema as a
     * candidate entity. Cheap, and tolerant of whatever naming the generator used.
     *
     * @param  array<string, mixed>  $componentSchemas
     */
    public static function fromComponents(array $componentSchemas): self
    {
        $byModel = [];

        foreach ($componentSchemas as $name => $schema) {
            if (! is_array($schema) || ! is_array($schema['properties'] ?? null)) {
                continue;
            }

            $byModel[(string) $name] = $schema['properties'];
        }

        return new self($byModel);
    }

    /**
     * Structural schema for a column on a model, prose stripped.
     *
     * @return array<string, mixed>|null
     */
    public function for(string $model, string $column): ?array
    {
        $properties = $this->byModel[$model] ?? null;

        if (! is_array($properties)) {
            return null;
        }

        $schema = $properties[$column] ?? null;

        return is_array($schema) ? self::structuralOnly($schema) : null;
    }

    /**
     * Look a column up on the given model first, then fall back to any model that
     * defines it identically. The fallback matters for `exists:table,column`
     * rules, where the referenced entity is not the model being synced.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(string $model, string $column, ?string $fallbackModel = null): ?array
    {
        return $this->for($model, $column)
            ?? ($fallbackModel !== null ? $this->for($fallbackModel, $column) : null);
    }

    public function has(string $model): bool
    {
        return isset($this->byModel[$model]);
    }

    /**
     * Guess the entity component name for a table name (`businesses` -> `Business`),
     * used only to resolve `exists:` and `unique:` rules. Returns null rather than a
     * bad guess when nothing matches, so the caller degrades to a safe default.
     */
    public function componentForTable(string $table): ?string
    {
        $candidates = [
            str_replace(' ', '', ucwords(str_replace('_', ' ', self::singularise($table)))),
            str_replace(' ', '', ucwords(str_replace('_', ' ', $table))),
        ];

        foreach ($candidates as $candidate) {
            if (isset($this->byModel[$candidate])) {
                return $candidate;
            }
        }

        // Case-insensitive last resort.
        foreach (array_keys($this->byModel) as $known) {
            if (strcasecmp($known, $candidates[0]) === 0) {
                return $known;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function structuralOnly(array $schema): array
    {
        foreach (self::PROSE_KEYS as $key) {
            unset($schema[$key]);
        }

        foreach ($schema as $key => $value) {
            if (str_starts_with((string) $key, 'x-')) {
                unset($schema[$key]);
            }
        }

        return $schema;
    }

    /**
     * Deliberately conservative English singularisation. Anvil's own KeyCase /
     * Str::singular is the real authority in-app; this is only a table-name hint
     * for rule resolution, and a miss costs a fallback, not a wrong type.
     */
    private static function singularise(string $table): string
    {
        $irregular = [
            'people' => 'person',
            'children' => 'child',
            'men' => 'man',
            'women' => 'woman',
            'teeth' => 'tooth',
            'feet' => 'foot',
            'geese' => 'goose',
            'mice' => 'mouse',
            'data' => 'datum',
            'media' => 'medium',
            'criteria' => 'criterion',
        ];

        if (isset($irregular[strtolower($table)])) {
            return $irregular[strtolower($table)];
        }

        foreach ([['/ies$/', 'y'], ['/(ss|sh|ch|x|z|o)es$/', '$1'], ['/ves$/', 'f'], ['/s$/', '']] as [$pattern, $replacement]) {
            if (preg_match($pattern, $table) === 1) {
                return (string) preg_replace($pattern, $replacement, $table);
            }
        }

        return $table;
    }
}
