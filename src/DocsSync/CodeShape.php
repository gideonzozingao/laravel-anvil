<?php

namespace Zuqongtech\LaravelAnvil\DocsSync;

/**
 * The payload shape a class actually produces (response) or accepts (request),
 * as read from its source. This is the unit the differ and merger operate on.
 *
 * The `$partial` flag is the most important field here. A reader sets it whenever
 * it could not see the complete property set -- a `mergeWhen()`, a spread, a
 * `parent::toArray()`, a dynamically computed key. When true, the merger is
 * forbidden from pruning: absence of evidence is not evidence of absence, and
 * silently deleting a documented field on the strength of a partial read is the
 * one failure mode that would make this command untrustworthy.
 */
final readonly class CodeShape
{
    public const RESPONSE = 'response';

    public const REQUEST = 'request';

    /**
     * @param  array<string, PropertyShape>  $properties
     * @param  list<string>  $notes
     */
    public function __construct(
        public string $component,
        public string $kind,
        public array $properties,
        public string $source,
        public bool $partial = false,
        public array $notes = [],
        public ?string $fingerprint = null,
    ) {}

    public function isRequest(): bool
    {
        return $this->kind === self::REQUEST;
    }

    /**
     * Whether sync is the authority on this component's `required` list.
     *
     * Only for requests. `rules()` is the single source of truth for what a client
     * must send, so sync owns it outright.
     *
     * For responses it is deliberately false, to avoid an unwinnable fight with
     * `anvil:generate --openapi`. The generator derives an entity's `required` from
     * database nullability; a resource reader would derive it from payload presence.
     * Both are defensible, they disagree, and if sync claimed ownership the two
     * commands would overwrite each other on every run -- the spec would flip back
     * and forth forever and every `--check` would fail after a plain generate.
     *
     * So for responses sync only ever REMOVES a name, and only on the one signal
     * the generator cannot see: the property became conditional.
     */
    public function ownsRequired(): bool
    {
        return $this->kind === self::REQUEST;
    }

    /** @return list<string> */
    public function conditionalNames(): array
    {
        $names = [];

        foreach ($this->properties as $name => $property) {
            if ($property->isConditional()) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /** @return list<string> */
    public function requiredNames(): array
    {
        $required = [];

        foreach ($this->properties as $name => $property) {
            if ($property->required) {
                $required[] = $name;
            }
        }

        sort($required);

        return $required;
    }

    /** @return list<string> */
    public function propertyNames(): array
    {
        return array_keys($this->properties);
    }

    /** @return list<string> */
    public function unresolvedNames(): array
    {
        $names = [];

        foreach ($this->properties as $name => $property) {
            if ($property->unresolved) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The structural half of an OpenAPI object schema. Prose is not this
     * object's business; SpecMerger layers the spec's own prose back on top.
     *
     * @return array<string, mixed>
     */
    public function toObjectSchema(): array
    {
        $properties = [];

        foreach ($this->properties as $name => $property) {
            $properties[$name] = $property->toSpecFragment();
        }

        $schema = ['type' => 'object', 'properties' => $properties];

        if ($required = $this->requiredNames()) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    public function withFingerprint(string $fingerprint): self
    {
        return new self($this->component, $this->kind, $this->properties, $this->source, $this->partial, $this->notes, $fingerprint);
    }

    /** @param list<string> $notes */
    public function withNotes(array $notes, ?bool $partial = null): self
    {
        return new self(
            $this->component,
            $this->kind,
            $this->properties,
            $this->source,
            $partial ?? $this->partial,
            array_values(array_unique([...$this->notes, ...$notes])),
            $this->fingerprint,
        );
    }
}
