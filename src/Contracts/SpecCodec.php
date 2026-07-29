<?php

namespace Zuqongtech\LaravelAnvil\Contracts;

/**
 * Serialises spec documents. Abstracted so the sync core has no hard dependency on
 * a YAML library, which also makes it testable without one.
 */
interface SpecCodec
{
    /** @return array<string, mixed> */
    public function decode(string $contents): array;

    /** @param array<string, mixed> $data */
    public function encode(array $data): string;

    /** File extension, without the dot. */
    public function extension(): string;
}
