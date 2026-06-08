<?php

namespace Zuqongtech\LaravelAnvil\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * Serialises OpenAPI spec arrays to YAML or JSON.
 *
 * YAML output is delegated to symfony/yaml (a hard dependency of this package),
 * which correctly handles indentation, literal block scalars for multi-line
 * strings, scalar quoting, and empty sequences — all of which the previous
 * hand-rolled implementation got wrong, producing specs that Swagger UI's
 * js-yaml parser rejected (e.g. "a multiline key may not be an implicit key"
 * on multi-line `description` fields).
 *
 * The public API is unchanged, so callers (OpenApiRootGenerator,
 * OpenApiSchemaGenerator, OpenApiPathGenerator) need no modification.
 */
final class OpenApiYamlSerializer
{
    /** Indentation width for nested mappings/sequences. */
    private const INDENT = 2;

    /**
     * Depth at which YAML collapses to inline flow style ({...}, [...]).
     * OpenAPI specs are deeply nested, so we keep them expanded (block style)
     * well beyond any realistic nesting level.
     */
    private const INLINE_DEPTH = 20;

    public function toYaml(array $data): string
    {
        return Yaml::dump($data, self::INLINE_DEPTH, self::INDENT, $this->dumpFlags());
    }

    public function toJson(array $data): string
    {
        return json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";
    }

    public function serialize(array $data, string $format = 'yaml'): string
    {
        return $format === 'json' ? $this->toJson($data) : $this->toYaml($data);
    }

    public function writeFile(array $data, string $path, string $format = 'yaml'): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $this->serialize($data, $format));
    }

    /**
     * Build the Symfony YAML dump flags.
     *
     *  - DUMP_MULTI_LINE_LITERAL_BLOCK: render multi-line strings as correctly
     *    indented literal blocks (|) instead of breaking the mapping.
     *  - DUMP_EMPTY_ARRAY_AS_SEQUENCE: render empty arrays as `[]` (a sequence)
     *    rather than `{}`, which matters for OpenAPI fields like `security: []`
     *    and empty `scopes`. Guarded with defined() for older symfony/yaml.
     */
    private function dumpFlags(): int
    {
        $flags = Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK;

        if (defined(Yaml::class.'::DUMP_EMPTY_ARRAY_AS_SEQUENCE')) {
            $flags |= Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE;
        }

        return $flags;
    }
}
