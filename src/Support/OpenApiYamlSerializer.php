<?php

namespace Zuqongtech\LaravelAnvil\Support;

/**
 * Lightweight YAML serialiser for OpenAPI spec arrays.
 *
 * Avoids a hard dependency on symfony/yaml by implementing the subset
 * of YAML that OpenAPI 3.1 specs actually need:
 *   - Nested mappings with consistent indentation
 *   - Sequences (arrays with integer keys)
 *   - Scalar strings, integers, floats, booleans, nulls
 *   - Multi-line strings via literal block scalar (|)
 *   - $ref values preserved unquoted
 *   - Strings containing special characters properly quoted
 *
 * For JSON output the serialiser simply delegates to json_encode().
 */
final class OpenApiYamlSerializer
{
    private const INDENT = 2;

    // Characters that require quoting in YAML
    private const MUST_QUOTE_PATTERN = '/[:{}\[\],&*#?|\-<>=!%@`\'"]|^\s|\s$|^(true|false|null|yes|no|on|off)$/i';

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    public function toYaml(array $data): string
    {
        return $this->dumpValue($data, 0) . "\n";
    }

    public function toJson(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    public function serialize(array $data, string $format = 'yaml'): string
    {
        return $format === 'json' ? $this->toJson($data) : $this->toYaml($data);
    }

    // -----------------------------------------------------------------------
    // Core dump
    // -----------------------------------------------------------------------

    private function dumpValue(mixed $value, int $depth): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return $this->dumpString($value);
        }

        if (is_array($value)) {
            return $this->dumpArray($value, $depth);
        }

        return "'" . addslashes((string) $value) . "'";
    }

    private function dumpString(string $value): string
    {
        // $ref values — never quote, preserve the $ character
        if (str_starts_with($value, '#/') || str_starts_with($value, '$ref')) {
            return "'" . $value . "'";
        }

        // Empty string
        if ($value === '') {
            return "''";
        }

        // Multi-line — use literal block scalar
        if (str_contains($value, "\n")) {
            $lines = explode("\n", rtrim($value, "\n"));
            $block = "|\n";
            foreach ($lines as $line) {
                $block .= str_repeat(' ', self::INDENT) . $line . "\n";
            }

            return rtrim($block);
        }

        // Strings that need quoting
        if (preg_match(self::MUST_QUOTE_PATTERN, $value) || is_numeric($value)) {
            return '"' . str_replace('"', '\\"', $value) . '"';
        }

        return $value;
    }

    private function dumpArray(array $array, int $depth): string
    {
        if (empty($array)) {
            return '{}';
        }

        // Determine if this is a sequence (list) or mapping
        $isList = array_is_list($array);

        if ($isList) {
            return $this->dumpSequence($array, $depth);
        }

        return $this->dumpMapping($array, $depth);
    }

    private function dumpSequence(array $items, int $depth): string
    {
        $pad = str_repeat(' ', $depth * self::INDENT);
        $innerPad = str_repeat(' ', ($depth + 1) * self::INDENT);

        // Inline short sequences of scalars
        $allScalar = array_reduce($items, fn ($carry, $item) => $carry && ! is_array($item), true);
        if ($allScalar && count($items) <= 5) {
            $rendered = array_map(fn ($v) => $this->dumpValue($v, 0), $items);

            return '[' . implode(', ', $rendered) . ']';
        }

        $lines = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $rendered = $this->dumpMapping($item, $depth + 1);
                // First key on same line as dash
                $rendered = ltrim($rendered);
                $lines[] = "{$pad}- {$rendered}";
            } else {
                $lines[] = "{$pad}- " . $this->dumpValue($item, $depth + 1);
            }
        }

        return "\n" . implode("\n", $lines);
    }

    private function dumpMapping(array $map, int $depth): string
    {
        $pad = str_repeat(' ', $depth * self::INDENT);
        $lines = [];

        foreach ($map as $key => $value) {
            $keyStr = $this->dumpString((string) $key);

            if (is_array($value) && ! empty($value)) {
                $rendered = $this->dumpValue($value, $depth + 1);

                if (str_starts_with(ltrim($rendered), '-') || str_starts_with($rendered, "\n")) {
                    // Sequence — keep dash on next line
                    $lines[] = "{$pad}{$keyStr}:{$rendered}";
                } elseif (str_starts_with($rendered, '{')) {
                    // Inline mapping
                    $lines[] = "{$pad}{$keyStr}: {$rendered}";
                } else {
                    $lines[] = "{$pad}{$keyStr}:\n{$rendered}";
                }
            } else {
                $rendered = $this->dumpValue($value, $depth + 1);
                $lines[] = "{$pad}{$keyStr}: {$rendered}";
            }
        }

        return implode("\n", $lines);
    }

    // -----------------------------------------------------------------------
    // File writing helpers
    // -----------------------------------------------------------------------

    public function writeFile(array $data, string $path, string $format = 'yaml'): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $this->serialize($data, $format));
    }
}