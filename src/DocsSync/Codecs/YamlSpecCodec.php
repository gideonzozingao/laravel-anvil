<?php

namespace Zuqongtech\LaravelAnvil\DocsSync\Codecs;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Zuqongtech\LaravelAnvil\Contracts\SpecCodec;

/**
 * YAML via symfony/yaml, which is already a hard dependency of this package.
 *
 * Deliberately NOT the hand-rolled serialiser: indentation of literal blocks,
 * scalar quoting and empty sequences are all easy to get subtly wrong, and a spec
 * that js-yaml rejects fails in the browser rather than at generation time.
 *
 * INLINE is set high because OpenAPI documents nest deeply and flow style
 * (`{a: 1}`) beyond that depth would make the spec unreadable in review.
 */
final class YamlSpecCodec implements SpecCodec
{
    private const INDENT = 2;

    private const INLINE = 20;

    public function decode(string $contents): array
    {
        throw_unless(class_exists(Yaml::class), RuntimeException::class, 'symfony/yaml is required to read YAML specs.');

        $data = Yaml::parse($contents);

        return is_array($data) ? $data : [];
    }

    public function encode(array $data): string
    {
        throw_unless(class_exists(Yaml::class), RuntimeException::class, 'symfony/yaml is required to write YAML specs.');

        return Yaml::dump($data, self::INLINE, self::INDENT, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }

    public function extension(): string
    {
        return 'yaml';
    }
}
