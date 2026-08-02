<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Client;

/**
 * One property on a generated TypeScript interface.
 */
final readonly class ClientProperty
{
    public function __construct(
        public string $name,
        public TsType $type,
        public bool $optional = false,
        public ?string $comment = null,
    ) {}

    public function render(string $indent = '  '): string
    {
        $line = $indent.$this->name.($this->optional ? '?' : '').': '.$this->type->render().';';

        return $this->comment === null
            ? $line
            : $indent.'/** '.$this->comment.' */'."\n".$line;
    }
}
