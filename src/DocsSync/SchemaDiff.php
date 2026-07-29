<?php

namespace Zuqongtech\LaravelAnvil\DocsSync;

/**
 * Compares a CodeShape against the component currently in the spec.
 *
 * Severity is DIRECTION-DEPENDENT, and that is the whole point of this class. The
 * same textual change means opposite things depending on who is promising what:
 *
 *   - A response is the server's promise. Taking something away from it, or
 *     widening what it might contain, breaks clients.
 *   - A request is the server's constraint. Demanding more, or accepting less,
 *     breaks clients.
 *
 * So `optional -> required` is additive in a response (a stronger guarantee) and
 * breaking in a request (a new demand). A tool that reported one severity for both
 * would be wrong half the time, which is worse than not reporting severity at all.
 */
final class SchemaDiff
{
    /** Keys that are structural; everything else in a schema is prose. */
    private const STRUCTURAL = [
        'type',
        'format',
        'enum',
        'items',
        '$ref',
        'anyOf',
        'oneOf',
        'allOf',
        'pattern',
        'minLength',
        'maxLength',
        'minimum',
        'maximum',
        'exclusiveMinimum',
        'exclusiveMaximum',
        'minItems',
        'maxItems',
        'properties',
        'required',
        'additionalProperties',
    ];

    /** Bounds where a LARGER value is a looser constraint. */
    private const UPPER_BOUNDS = ['maxLength', 'maximum', 'maxItems', 'exclusiveMaximum'];

    /** Bounds where a SMALLER value is a looser constraint. */
    private const LOWER_BOUNDS = ['minLength', 'minimum', 'minItems', 'exclusiveMinimum'];

    /**
     * @param  array<string, mixed>  $specSchema
     * @return list<SchemaChange>
     */
    public function compare(CodeShape $code, array $specSchema): array
    {
        $changes = [];
        $isRequest = $code->isRequest();
        $specProperties = is_array($specSchema['properties'] ?? null) ? $specSchema['properties'] : [];
        $specRequired = is_array($specSchema['required'] ?? null) ? $specSchema['required'] : [];

        foreach ($code->properties as $name => $property) {
            $existing = is_array($specProperties[$name] ?? null) ? $specProperties[$name] : null;

            // A property the spec opted out of management is not comparable: the
            // merger will not change it, so reporting a difference would be drift
            // that no sync can ever resolve.
            if ($existing !== null && SpecMerger::isPropertyProtected($existing)) {
                continue;
            }

            if ($existing === null) {
                $requiredNow = $property->required;

                $changes[] = new SchemaChange(
                    'property_added',
                    $name,
                    // A new REQUIRED request field breaks every existing client.
                    $isRequest && $requiredNow ? SchemaChange::BREAKING : SchemaChange::ADDITIVE,
                    $requiredNow ? 'new required property in code' : 'new property in code',
                );

                continue;
            }

            if ($property->unresolved) {
                // We could not type it, so we have nothing to compare. Not drift.
                continue;
            }

            $changes = [...$changes, ...$this->compareFragment($name, $property->schema, $existing, $isRequest)];

            $wasRequired = in_array($name, $specRequired, true);

            if (! $code->ownsRequired()) {
                // Responses: the only requiredness signal worth reporting is a
                // property becoming conditional. Reporting presence-derived
                // requiredness here would flag every field on every run, because the
                // generator derives the same list from database nullability instead.
                if ($property->isConditional() && $wasRequired) {
                    $changes[] = new SchemaChange(
                        'required_removed',
                        $name,
                        SchemaChange::BREAKING,
                        'wrapped in when()/whenLoaded(), so it may now be absent',
                    );
                }

                continue;
            }

            if ($property->required && ! $wasRequired) {
                $changes[] = new SchemaChange(
                    'required_added',
                    $name,
                    SchemaChange::BREAKING,
                    'code now requires this field',
                );
            } elseif (! $property->required && $wasRequired) {
                $changes[] = new SchemaChange(
                    'required_removed',
                    $name,
                    SchemaChange::ADDITIVE,
                    'field is no longer mandatory',
                );
            }
        }

        // Documented but absent from code. On a partial read this is not evidence of
        // removal, so it is reported as cosmetic and never pruned.
        foreach ($specProperties as $name => $fragment) {
            if (isset($code->properties[$name])) {
                continue;
            }

            // Opted out of management, so its absence from code is intentional.
            // Reporting it would make `--check` fail on every run forever, which is
            // the worst possible failure mode for a CI gate: permanently red, and
            // no amount of syncing fixes it.
            if (is_array($fragment) && SpecMerger::isPropertyProtected($fragment)) {
                continue;
            }

            $changes[] = new SchemaChange(
                'property_removed',
                (string) $name,
                $code->partial
                    ? SchemaChange::COSMETIC
                    : ($isRequest ? SchemaChange::ADDITIVE : SchemaChange::BREAKING),
                $code->partial
                    ? 'documented but not visible in this partial read (kept)'
                    : 'documented but no longer produced by the code',
            );
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $code
     * @param  array<string, mixed>  $spec
     * @return list<SchemaChange>
     */
    private function compareFragment(string $path, array $code, array $spec, bool $isRequest): array
    {
        $changes = [];

        $codeRef = $code['$ref'] ?? null;
        $specRef = $spec['$ref'] ?? null;

        if ($codeRef !== $specRef && ($codeRef !== null || $specRef !== null)) {
            $changes[] = new SchemaChange(
                'ref_changed',
                $path,
                SchemaChange::BREAKING,
                sprintf('$ref %s -> %s', self::render($specRef), self::render($codeRef)),
            );

            return $changes;
        }

        $codeTypes = self::types($code);
        $specTypes = self::types($spec);
        $codeBase = array_values(array_diff($codeTypes, ['null']));
        $specBase = array_values(array_diff($specTypes, ['null']));

        if ($codeBase !== [] && $specBase !== [] && $codeBase !== $specBase) {
            $changes[] = new SchemaChange(
                'type_changed',
                $path,
                SchemaChange::BREAKING,
                sprintf('type %s -> %s', implode('|', $specBase), implode('|', $codeBase)),
            );
        }

        $codeNullable = in_array('null', $codeTypes, true) || self::anyOfHasNull($code);
        $specNullable = in_array('null', $specTypes, true) || self::anyOfHasNull($spec);

        if ($codeNullable !== $specNullable) {
            $changes[] = new SchemaChange(
                'nullability_changed',
                $path,
                // Response may now be null -> clients break. Request may now be null
                // -> clients gain an option.
                $codeNullable
                    ? ($isRequest ? SchemaChange::ADDITIVE : SchemaChange::BREAKING)
                    : ($isRequest ? SchemaChange::BREAKING : SchemaChange::ADDITIVE),
                $codeNullable ? 'now nullable' : 'no longer nullable',
            );
        }

        if (($code['format'] ?? null) !== ($spec['format'] ?? null) && isset($code['format'])) {
            $changes[] = new SchemaChange(
                'format_changed',
                $path,
                SchemaChange::COSMETIC,
                sprintf('format %s -> %s', self::render($spec['format'] ?? null), self::render($code['format'])),
            );
        }

        $changes = [...$changes, ...$this->compareEnum($path, $code, $spec, $isRequest)];
        $changes = [...$changes, ...$this->compareBounds($path, $code, $spec, $isRequest)];

        // Recurse into nested objects and array items.
        if (is_array($code['properties'] ?? null) && is_array($spec['properties'] ?? null)) {
            foreach ($code['properties'] as $child => $childSchema) {
                if (is_array($childSchema) && is_array($spec['properties'][$child] ?? null)) {
                    $changes = [...$changes, ...$this->compareFragment("{$path}.{$child}", $childSchema, $spec['properties'][$child], $isRequest)];
                } elseif (! isset($spec['properties'][$child])) {
                    $changes[] = new SchemaChange('property_added', "{$path}.{$child}", SchemaChange::ADDITIVE, 'new nested property in code');
                }
            }
        }

        if (is_array($code['items'] ?? null) && is_array($spec['items'] ?? null) && $code['items'] !== [] && $spec['items'] !== []) {
            $changes = [...$changes, ...$this->compareFragment("{$path}[]", $code['items'], $spec['items'], $isRequest)];
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $code
     * @param  array<string, mixed>  $spec
     * @return list<SchemaChange>
     */
    private function compareEnum(string $path, array $code, array $spec, bool $isRequest): array
    {
        $codeEnum = is_array($code['enum'] ?? null) ? $code['enum'] : null;
        $specEnum = is_array($spec['enum'] ?? null) ? $spec['enum'] : null;

        if ($codeEnum === null || $specEnum === null || $codeEnum === $specEnum) {
            return [];
        }

        $added = array_values(array_diff($codeEnum, $specEnum));
        $removed = array_values(array_diff($specEnum, $codeEnum));
        $changes = [];

        if ($added !== []) {
            $changes[] = new SchemaChange(
                'enum_widened',
                $path,
                // A response may now contain a value the client has no branch for.
                // A request accepting more values costs the client nothing.
                $isRequest ? SchemaChange::ADDITIVE : SchemaChange::BREAKING,
                'enum gained '.implode(', ', array_map(self::render(...), $added)),
            );
        }

        if ($removed !== []) {
            $changes[] = new SchemaChange(
                'enum_narrowed',
                $path,
                $isRequest ? SchemaChange::BREAKING : SchemaChange::ADDITIVE,
                'enum lost '.implode(', ', array_map(self::render(...), $removed)),
            );
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $code
     * @param  array<string, mixed>  $spec
     * @return list<SchemaChange>
     */
    private function compareBounds(string $path, array $code, array $spec, bool $isRequest): array
    {
        $changes = [];

        foreach ([...self::UPPER_BOUNDS, ...self::LOWER_BOUNDS] as $key) {
            $codeValue = $code[$key] ?? null;
            $specValue = $spec[$key] ?? null;

            if ($codeValue === $specValue || $codeValue === null) {
                continue;
            }

            if ($specValue === null) {
                $changes[] = new SchemaChange(
                    'constraint_added',
                    $path,
                    $isRequest ? SchemaChange::BREAKING : SchemaChange::COSMETIC,
                    "{$key} constraint added ({$codeValue})",
                );

                continue;
            }

            $isUpper = in_array($key, self::UPPER_BOUNDS, true);
            $tighter = $isUpper ? $codeValue < $specValue : $codeValue > $specValue;

            $changes[] = new SchemaChange(
                'constraint_changed',
                $path,
                // A tighter request constraint rejects payloads that used to pass.
                $tighter
                    ? ($isRequest ? SchemaChange::BREAKING : SchemaChange::COSMETIC)
                    : SchemaChange::ADDITIVE,
                sprintf('%s %s -> %s', $key, self::render($specValue), self::render($codeValue)),
            );
        }

        return $changes;
    }

    /** @return list<string> */
    private static function types(array $schema): array
    {
        $type = $schema['type'] ?? null;

        if ($type === null) {
            return [];
        }

        return array_values(array_map(strval(...), is_array($type) ? $type : [$type]));
    }

    private static function anyOfHasNull(array $schema): bool
    {
        foreach (($schema['anyOf'] ?? []) as $branch) {
            if (is_array($branch) && ($branch['type'] ?? null) === 'null') {
                return true;
            }
        }

        return false;
    }

    private static function render(mixed $value): string
    {
        if ($value === null) {
            return '(none)';
        }

        return is_scalar($value) ? (string) $value : (string) json_encode($value);
    }

    /** @return list<string> */
    public static function structuralKeys(): array
    {
        return self::STRUCTURAL;
    }
}
