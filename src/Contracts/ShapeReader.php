<?php

namespace Zuqongtech\LaravelAnvil\Contracts;

use Zuqongtech\LaravelAnvil\DocsSync\CodeShape;

/**
 * Reads the payload shape a piece of generated-then-hand-edited code actually
 * produces or accepts, so the OpenAPI spec can be reconciled against it.
 *
 * Implementations are the ONLY authority on turning PHP source into a CodeShape.
 * Nothing else in the sync pipeline may parse PHP -- if a second parser appears,
 * the two will disagree, and the drift report becomes noise.
 */
interface ShapeReader
{
    /** Can this reader handle the given fully-qualified class name? */
    public function supports(string $class): bool;

    /**
     * @param  array<string, mixed>  $context  file, columns, version, model
     * @return CodeShape|null null when unreadable (reported, never fatal)
     */
    public function read(string $class, array $context = []): ?CodeShape;
}
