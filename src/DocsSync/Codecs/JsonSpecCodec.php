<?php

namespace Zuqongtech\LaravelAnvil\DocsSync\Codecs;

use JsonException;
use RuntimeException;
use Zuqongtech\LaravelAnvil\Contracts\SpecCodec;

final class JsonSpecCodec implements SpecCodec
{
    public function decode(string $contents): array
    {
        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Spec is not valid JSON: '.$e->getMessage(), previous: $e);
        }

        return is_array($data) ? $data : [];
    }

    public function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    public function extension(): string
    {
        return 'json';
    }
}
