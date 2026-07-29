<?php

namespace Zuqongtech\LaravelAnvil\DocsSync;

/**
 * One difference between what the code says and what the spec says.
 */
final readonly class SchemaChange implements \Stringable
{
    public const ADDITIVE = 'additive';

    public const BREAKING = 'breaking';

    public const COSMETIC = 'cosmetic';

    public function __construct(
        public string $kind,
        public string $path,
        public string $severity,
        public string $detail,
    ) {}

    public function isBreaking(): bool
    {
        return $this->severity === self::BREAKING;
    }

    public function __toString(): string
    {
        $marker = match ($this->severity) {
            self::BREAKING => '!',
            self::ADDITIVE => '+',
            default => '~',
        };

        return "  {$marker} {$this->path}: {$this->detail}";
    }
}
