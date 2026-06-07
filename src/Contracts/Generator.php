<?php

namespace Zuqongtech\LaravelAnvil\Contracts;

use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

interface Generator
{
    public function generate(ModelMetadata $meta, GenerationOptions $options): array;

    public function supports(GenerationOptions $options): bool;

    public function getName(): string;
}
