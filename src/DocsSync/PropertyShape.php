<?php

namespace Zuqongtech\LaravelAnvil\DocsSync;

/**
 * One property discovered in code, plus the provenance needed to explain it in a
 * drift report ("came from a cast", "came from an expression we could not type").
 *
 * `$schema` is an OpenAPI schema fragment, never a full component: readers emit
 * only the STRUCTURAL keys they can justify from source (type, format, enum,
 * items, $ref, nullable, bounds). Prose keys are deliberately absent so the
 * merger can distinguish "code says nothing about this" from "code says empty".
 */
final readonly class PropertyShape
{
    /** Reader saw the property but could not determine its type. */
    public const UNRESOLVED = 'unresolved';

    /**
     * Origin prefix meaning "this key may be absent from the payload entirely".
     * Set by `when()` / `whenLoaded()` wrappers, which return a MissingValue that
     * Laravel strips before serialising. It is the one thing about response
     * requiredness that the database-driven generator cannot possibly know.
     */
    public const CONDITIONAL = 'conditional';

    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        public string $name,
        public array $schema,
        public bool $required = false,
        public string $origin = 'expression',
        public bool $unresolved = false,
        public ?string $expression = null,
    ) {}

    /**
     * A property whose type could not be inferred. It carries no structural keys
     * at all, which is what makes the merger defer to the spec: a type a human
     * wrote by hand survives every subsequent sync.
     */
    public static function unresolved(string $name, string $expression, bool $required = false): self
    {
        return new self(
            name: $name,
            schema: [],
            required: $required,
            origin: self::UNRESOLVED,
            unresolved: true,
            expression: self::truncate($expression),
        );
    }

    /** @param array<string, mixed> $schema */
    public function withSchema(array $schema): self
    {
        return new self($this->name, $schema, $this->required, $this->origin, $this->unresolved, $this->expression);
    }

    public function asRequired(bool $required = true): self
    {
        return new self($this->name, $this->schema, $required, $this->origin, $this->unresolved, $this->expression);
    }

    /** May this key be absent from the payload? */
    public function isConditional(): bool
    {
        return str_starts_with($this->origin, self::CONDITIONAL);
    }

    public function renamed(string $name): self
    {
        return new self($name, $this->schema, $this->required, $this->origin, $this->unresolved, $this->expression);
    }

    /**
     * The fragment as it should appear in the spec, including the marker that
     * lets a later run recognise its own unresolved output rather than mistaking
     * the human's annotation for drift.
     *
     * @return array<string, mixed>
     */
    public function toSpecFragment(): array
    {
        if (! $this->unresolved) {
            return $this->schema;
        }

        return ['x-anvil' => ['unresolved' => $this->expression]];
    }

    private static function truncate(string $expression, int $limit = 120): string
    {
        $expression = trim(preg_replace('/\s+/', ' ', $expression) ?? $expression);

        return strlen($expression) > $limit ? substr($expression, 0, $limit - 1).'...' : $expression;
    }
}
