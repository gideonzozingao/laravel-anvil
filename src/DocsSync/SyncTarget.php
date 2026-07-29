<?php

namespace Zuqongtech\LaravelAnvil\DocsSync;

/**
 * One class that documents one component.
 */
final readonly class SyncTarget
{
    public function __construct(
        public string $class,
        public string $file,
        public string $kind,
        public string $model,
        public ?string $version = null,
    ) {}

    public function label(): string
    {
        return ComponentNaming::shortName($this->class);
    }
}
