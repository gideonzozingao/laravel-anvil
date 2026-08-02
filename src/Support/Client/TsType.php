<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Client;

/**
 * A TypeScript type expression.
 *
 * A value object rather than a string so nullability composes correctly:
 * building `string | null` by concatenation works until the base type is
 * itself a union, at which point `'a' | 'b' | null` needs no parentheses but
 * `(() => void) | null` does. Keeping the parts separate means the renderer
 * decides, once, rather than every call site guessing.
 */
final readonly class TsType implements \Stringable
{
    private function __construct(
        public string $base,
        public bool $nullable = false,
    ) {}

    public static function of(string $base, bool $nullable = false): self
    {
        return new self($base, $nullable);
    }

    public static function string(bool $nullable = false): self
    {
        return new self('string', $nullable);
    }

    public static function number(bool $nullable = false): self
    {
        return new self('number', $nullable);
    }

    public static function boolean(bool $nullable = false): self
    {
        return new self('boolean', $nullable);
    }

    public static function unknownRecord(bool $nullable = false): self
    {
        return new self('Record<string, unknown>', $nullable);
    }

    /**
     * A union of string literals, for enum-backed columns.
     *
     * @param  list<string>  $values
     */
    public static function literalUnion(array $values, bool $nullable = false): self
    {
        if ($values === []) {
            return self::string($nullable);
        }

        $quoted = array_map(
            static fn (string $value): string => "'".str_replace("'", "\\'", $value)."'",
            $values,
        );

        return new self(implode(' | ', $quoted), $nullable);
    }

    public static function reference(string $interface, bool $nullable = false): self
    {
        return new self($interface, $nullable);
    }

    public function asArray(): self
    {
        return new self($this->isUnion() ? "({$this->base})[]" : "{$this->base}[]", $this->nullable);
    }

    public function nullable(bool $nullable = true): self
    {
        return new self($this->base, $nullable);
    }

    public function render(): string
    {
        return $this->nullable ? "{$this->base} | null" : $this->base;
    }

    public function __toString(): string
    {
        return $this->render();
    }

    private function isUnion(): bool
    {
        return str_contains($this->base, '|');
    }
}
