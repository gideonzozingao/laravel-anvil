<?php

namespace Zuqongtech\LaravelAnvil\DocsSync;

/**
 * Merges a CodeShape into an existing component schema.
 *
 * The contract, which is the reason this command is safe to run on a spec your
 * team has been hand-maintaining for months:
 *
 *   STRUCTURE comes from code    type, format, enum, $ref, items, required,
 *                               property key set, bounds, pattern
 *
 *   PROSE comes from the spec    description, title, summary, example(s),
 *                               deprecated, externalDocs, default, every x-* key
 *
 * Four safety rules follow from that split:
 *
 *   1. Hand-authored components are never touched. Only schemas carrying
 *      `x-anvil.managed` are rewritten (see SpecFiles::isManaged), so a component
 *      you wrote from scratch is invisible to sync until you adopt it.
 *   2. Partial reads never prune. If the reader could not see the whole property
 *      set, nothing is removed -- absence of evidence is not evidence of absence.
 *   3. Unresolved properties defer to the spec. A property the reader could not
 *      type keeps whatever the spec says, so a hand-written type survives forever.
 *   4. A property marked `x-anvil: {managed: false}` is never modified or pruned,
 *      which is the escape hatch for documenting something code cannot express.
 *
 * Output is deterministic -- no timestamps, stable key order. A sync that produced
 * a different byte sequence each run would make every commit a merge conflict.
 */
final readonly class SpecMerger
{
    /** Keys the spec owns. Code never overwrites these. */
    public const PROSE_KEYS = [
        'description',
        'title',
        'summary',
        'example',
        'examples',
        'deprecated',
        'externalDocs',
        'default',
        'readOnly',
        'writeOnly',
    ];

    public function __construct(
        private bool $preserveAnnotations = true,
        private bool $prune = true,
    ) {}

    /**
     * @param  array<string, mixed>  $existing
     * @return array{schema: array<string, mixed>, pruned: list<string>, protected: list<string>}
     */
    public function merge(CodeShape $code, array $existing, string $component): array
    {
        $existingProperties = is_array($existing['properties'] ?? null) ? $existing['properties'] : [];
        $existingRequired = is_array($existing['required'] ?? null) ? array_values($existing['required']) : [];

        $properties = [];
        $protected = [];

        foreach ($code->properties as $name => $property) {
            $name = (string) $name;
            $current = is_array($existingProperties[$name] ?? null) ? $existingProperties[$name] : [];

            if (self::isPropertyProtected($current)) {
                $properties[$name] = $current;
                $protected[] = $name;

                continue;
            }

            $properties[$name] = $this->mergeProperty($property, $current);
        }

        // Properties in the spec but not in this read.
        $pruned = [];

        foreach ($existingProperties as $name => $fragment) {
            $name = (string) $name;

            if (isset($properties[$name])) {
                continue;
            }

            $keep = ! $this->prune
                || $code->partial
                || self::isPropertyProtected(is_array($fragment) ? $fragment : []);

            if ($keep) {
                $properties[$name] = $fragment;

                if (self::isPropertyProtected(is_array($fragment) ? $fragment : [])) {
                    $protected[] = $name;
                }
            } else {
                $pruned[] = $name;
            }
        }

        $schema = $existing;
        $schema['type'] = 'object';
        $schema['properties'] = $properties;

        $required = $this->mergeRequired($code, $existingRequired, array_keys($properties), $protected);

        if ($required === []) {
            unset($schema['required']);
        } else {
            $schema['required'] = $required;
        }

        $schema['x-anvil'] = $this->marker($code, $component, is_array($schema['x-anvil'] ?? null) ? $schema['x-anvil'] : []);

        return [
            'schema' => self::orderKeys($schema),
            'pruned' => $pruned,
            'protected' => array_values(array_unique($protected)),
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function mergeProperty(PropertyShape $property, array $current): array
    {
        // Rule 3: an unresolved property makes no structural claim, so the spec's
        // own definition stands. Only when the spec has nothing do we leave a
        // marker explaining what we could not read.
        if ($property->unresolved) {
            if ($current !== [] && self::hasStructure($current)) {
                $merged = $current;
                $merged['x-anvil'] = array_merge(
                    is_array($current['x-anvil'] ?? null) ? $current['x-anvil'] : [],
                    ['unresolved' => $property->expression],
                );

                return self::orderKeys($merged);
            }

            return self::orderKeys(array_merge($current, $property->toSpecFragment()));
        }

        $merged = $property->schema;

        // Carry the spec's prose across.
        if ($this->preserveAnnotations) {
            foreach (self::PROSE_KEYS as $key) {
                if (array_key_exists($key, $current)) {
                    $merged[$key] = $current[$key];
                }
            }

            foreach ($current as $key => $value) {
                if (str_starts_with((string) $key, 'x-') && $key !== 'x-anvil') {
                    $merged[$key] = $value;
                }
            }
        }

        // The property is typed now, so a stale `unresolved` note must go, but any
        // other x-anvil metadata a human added is kept.
        $anvil = is_array($current['x-anvil'] ?? null) ? $current['x-anvil'] : [];
        unset($anvil['unresolved']);

        if ($anvil !== []) {
            $merged['x-anvil'] = $anvil;
        }

        // Recurse so nested objects keep their per-child descriptions.
        if (is_array($merged['properties'] ?? null) && is_array($current['properties'] ?? null)) {
            foreach ($merged['properties'] as $child => $childSchema) {
                if (is_array($childSchema) && is_array($current['properties'][$child] ?? null)) {
                    $merged['properties'][$child] = $this->mergeNestedFragment($childSchema, $current['properties'][$child]);
                }
            }
        }

        if (is_array($merged['items'] ?? null) && is_array($current['items'] ?? null) && $merged['items'] !== []) {
            $merged['items'] = $this->mergeNestedFragment($merged['items'], $current['items']);
        }

        return self::orderKeys($merged);
    }

    /**
     * @param  array<string, mixed>  $code
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function mergeNestedFragment(array $code, array $current): array
    {
        if (! $this->preserveAnnotations) {
            return self::orderKeys($code);
        }

        foreach (self::PROSE_KEYS as $key) {
            if (array_key_exists($key, $current)) {
                $code[$key] = $current[$key];
            }
        }

        foreach ($current as $key => $value) {
            if (str_starts_with((string) $key, 'x-') && $key !== 'x-anvil') {
                $code[$key] = $value;
            }
        }

        if (is_array($code['properties'] ?? null) && is_array($current['properties'] ?? null)) {
            foreach ($code['properties'] as $child => $childSchema) {
                if (is_array($childSchema) && is_array($current['properties'][$child] ?? null)) {
                    $code['properties'][$child] = $this->mergeNestedFragment($childSchema, $current['properties'][$child]);
                }
            }
        }

        if (is_array($code['items'] ?? null) && is_array($current['items'] ?? null) && $code['items'] !== []) {
            $code['items'] = $this->mergeNestedFragment($code['items'], $current['items']);
        }

        return self::orderKeys($code);
    }

    /**
     * Merge the `required` list.
     *
     * Requests: `rules()` is the only authority on what a client must send, so code
     * decides outright -- except on a partial read, where its view is incomplete and
     * dropping a name would be a guess.
     *
     * Responses: sync does NOT own this list (see CodeShape::ownsRequired for why
     * claiming it would put this command in a permanent fight with `--openapi`). It
     * starts from whatever the spec says and only ever removes a name, and only when
     * the code shows the property became conditional -- the single signal a
     * database-driven generator cannot derive.
     *
     * @param  list<string>  $existingRequired
     * @param  list<string>  $finalProperties
     * @param  list<string>  $protected
     * @return list<string>
     */
    private function mergeRequired(CodeShape $code, array $existingRequired, array $finalProperties, array $protected): array
    {
        if ($code->ownsRequired()) {
            $required = $code->requiredNames();

            if ($code->partial || ! $this->prune) {
                $required = array_merge($required, $existingRequired);
            }
        } else {
            $conditional = $code->conditionalNames();

            $required = array_values(array_filter(
                $existingRequired,
                static fn (string $name): bool => ! in_array($name, $conditional, true),
            ));
        }

        // A protected property keeps whatever requiredness the spec gave it.
        foreach ($protected as $name) {
            if (in_array($name, $existingRequired, true)) {
                $required[] = $name;
            }
        }

        $required = array_values(array_unique(array_filter(
            array_map(strval(...), $required),
            static fn (string $name): bool => in_array($name, array_map(strval(...), $finalProperties), true),
        )));

        sort($required);

        return $required;
    }

    /**
     * The managed marker. Carries no timestamp on purpose: a timestamp would change
     * the file on every run and turn the spec into a permanent source of git noise
     * and merge conflicts.
     *
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function marker(CodeShape $code, string $component, array $existing): array
    {
        $marker = array_merge($existing, [
            'managed' => true,
            'source' => $code->source,
            'kind' => $code->kind,
        ]);

        if ($code->partial) {
            $marker['partialRead'] = true;
        } else {
            unset($marker['partialRead']);
        }

        unset($marker['unresolved']);
        ksort($marker);

        return $marker;
    }

    /**
     * A property explicitly opted out of management. The escape hatch for
     * documenting something the code cannot express.
     *
     * @param  array<string, mixed>  $fragment
     */
    public static function isPropertyProtected(array $fragment): bool
    {
        $anvil = $fragment['x-anvil'] ?? null;

        return is_array($anvil) && ($anvil['managed'] ?? null) === false;
    }

    /** @param array<string, mixed> $schema */
    private static function hasStructure(array $schema): bool
    {
        foreach (SchemaDiff::structuralKeys() as $key) {
            if (array_key_exists($key, $schema)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stable, readable key order. Deterministic output keeps diffs reviewable.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function orderKeys(array $schema): array
    {
        $preferred = [
            '$ref',
            'type',
            'format',
            'enum',
            'pattern',
            'items',
            'properties',
            'required',
            'additionalProperties',
            'anyOf',
            'oneOf',
            'allOf',
            'minLength',
            'maxLength',
            'minimum',
            'maximum',
            'exclusiveMinimum',
            'exclusiveMaximum',
            'minItems',
            'maxItems',
            'title',
            'description',
            'default',
            'example',
            'examples',
            'readOnly',
            'writeOnly',
            'deprecated',
            'externalDocs',
        ];

        $ordered = [];

        foreach ($preferred as $key) {
            if (array_key_exists($key, $schema)) {
                $ordered[$key] = $schema[$key];
            }
        }

        foreach ($schema as $key => $value) {
            if (! array_key_exists($key, $ordered)) {
                $ordered[$key] = $value;
            }
        }

        return $ordered;
    }
}
