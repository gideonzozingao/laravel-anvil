<?php

namespace Zuqongtech\LaravelAnvil\Support;

/**
 * Maps database column metadata to OpenAPI 3.1 type definitions.
 *
 * Each method returns an array that can be serialised directly into
 * a YAML or JSON schema property block:
 *
 *   ['type' => 'string', 'format' => 'date-time']
 *   ['type' => 'string', 'enum' => ['draft', 'published']]
 *   ['type' => 'integer', 'format' => 'int64']
 *
 * Additional schema keywords (nullable, readOnly, maxLength, minimum,
 * maximum, example) are layered on top by the calling generator.
 */
final class OpenApiTypeMapper
{
    /**
     * Resolve an OpenAPI property definition from a column descriptor.
     *
     * @param  array{name: string, type: string, nullable: bool, default: mixed, max_length?: int, precision?: int, scale?: int, extra?: string}  $column
     * @return array<string, mixed>
     */
    public function column(array $column): array
    {
        $raw  = $column['type'] ?? 'varchar';
        $name = strtolower($column['name']);

        $property = $this->resolveType($raw, $name);

        // max_length
        if (! empty($column['max_length']) && $column['max_length'] > 0) {
            $property['maxLength'] = (int) $column['max_length'];
        }

        // decimal precision
        if (! empty($column['precision']) && $property['type'] === 'number') {
            $property['example'] = round(0, (int) ($column['scale'] ?? 2));
        }

        // nullable
        if ($column['nullable'] ?? false) {
            $property['nullable'] = true;
        }

        // default
        $default = $column['default'] ?? null;
        if ($default !== null && ! str_contains((string) $default, 'nextval')) {
            $property['default'] = $this->castDefault($default, $property['type']);
        }

        // readOnly for auto-increment PKs
        if (str_contains(strtolower($column['extra'] ?? ''), 'auto_increment')) {
            $property['readOnly'] = true;
        }

        return $property;
    }

    /**
     * Resolve OpenAPI type/format/enum from a raw DB type string.
     *
     * @return array<string, mixed>
     */
    public function resolveType(string $rawType, string $columnName = ''): array
    {
        $type = strtolower(preg_replace('/\(.*\)/', '', $rawType));

        // Enum — extract values
        if (str_starts_with(strtolower($rawType), 'enum')) {
            return $this->enumProperty($rawType);
        }

        return match (true) {
            // ── Integer family ───────────────────────────────────────────
            in_array($type, ['bigint', 'int8', 'serial8'])
                => ['type' => 'integer', 'format' => 'int64'],

            in_array($type, ['int', 'integer', 'int4', 'serial', 'serial4'])
                => ['type' => 'integer', 'format' => 'int32'],

            in_array($type, ['mediumint'])
                => ['type' => 'integer', 'format' => 'int32'],

            in_array($type, ['smallint', 'int2', 'serial2'])
                => ['type' => 'integer', 'format' => 'int16'],

            // tinyint(1) treated as boolean
            $type === 'tinyint' && str_contains($rawType, '(1)')
                => ['type' => 'boolean'],

            in_array($type, ['tinyint'])
                => ['type' => 'integer', 'format' => 'int8'],

            // ── Decimal / float family ───────────────────────────────────
            in_array($type, ['decimal', 'numeric'])
                => ['type' => 'number', 'format' => 'decimal'],

            in_array($type, ['float', 'real', 'float4'])
                => ['type' => 'number', 'format' => 'float'],

            in_array($type, ['double', 'double precision', 'float8'])
                => ['type' => 'number', 'format' => 'double'],

            // ── Boolean ──────────────────────────────────────────────────
            in_array($type, ['boolean', 'bool'])
                => ['type' => 'boolean'],

            // ── Date / time family ───────────────────────────────────────
            $type === 'date'
                => ['type' => 'string', 'format' => 'date'],

            in_array($type, ['datetime', 'timestamp', 'timestamptz'])
                => ['type' => 'string', 'format' => 'date-time'],

            in_array($type, ['time', 'timetz'])
                => ['type' => 'string', 'format' => 'time'],

            $type === 'year'
                => ['type' => 'integer', 'format' => 'int32'],

            // ── UUID ─────────────────────────────────────────────────────
            in_array($type, ['uuid', 'guid'])
                => ['type' => 'string', 'format' => 'uuid'],

            // ── JSON ─────────────────────────────────────────────────────
            in_array($type, ['json', 'jsonb'])
                => ['type' => 'object'],

            // ── Binary ───────────────────────────────────────────────────
            in_array($type, ['binary', 'varbinary', 'blob',
                             'tinyblob', 'mediumblob', 'longblob'])
                => ['type' => 'string', 'format' => 'binary'],

            // ── Text family ───────────────────────────────────────────────
            in_array($type, ['text', 'tinytext', 'mediumtext', 'longtext', 'clob'])
                => ['type' => 'string'],

            // ── String family (with name-based format hints) ─────────────
            in_array($type, ['char', 'character',
                             'varchar', 'character varying',
                             'nchar', 'nvarchar', 'string'])
                => $this->stringWithFormatHint($columnName),

            // ── Network types (PostgreSQL) ────────────────────────────────
            in_array($type, ['inet', 'cidr'])
                => ['type' => 'string', 'format' => 'ipv4'],

            $type === 'macaddr'
                => ['type' => 'string', 'format' => 'mac'],

            // ── Fallback ──────────────────────────────────────────────────
            default => ['type' => 'string'],
        };
    }

    // -----------------------------------------------------------------------
    // Enum
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function enumProperty(string $rawType): array
    {
        if (preg_match("/enum\('(.+?)'\)/i", $rawType, $m)) {
            $values = array_map('trim', explode("','", $m[1]));

            return [
                'type' => 'string',
                'enum' => $values,
            ];
        }

        return ['type' => 'string'];
    }

    // -----------------------------------------------------------------------
    // String format hints from column name
    // -----------------------------------------------------------------------

    /**
     * Returns a string property with an optional format hint
     * inferred from the column name.
     *
     * @return array<string, mixed>
     */
    protected function stringWithFormatHint(string $columnName): array
    {
        $name = strtolower($columnName);

        return match (true) {
            str_contains($name, 'email')
                => ['type' => 'string', 'format' => 'email'],

            str_contains($name, 'url') || str_contains($name, 'website') || str_contains($name, 'link')
                => ['type' => 'string', 'format' => 'uri'],

            str_contains($name, 'uuid') || str_contains($name, 'guid')
                => ['type' => 'string', 'format' => 'uuid'],

            str_contains($name, 'password') || str_contains($name, 'secret') || str_contains($name, 'token')
                => ['type' => 'string', 'format' => 'password'],

            str_contains($name, 'ip') || str_contains($name, 'ip_address')
                => ['type' => 'string', 'format' => 'ipv4'],

            str_contains($name, 'color') || str_contains($name, 'colour')
                => ['type' => 'string', 'pattern' => '^#[0-9a-fA-F]{6}$'],

            str_contains($name, 'phone') || str_contains($name, 'mobile')
                => ['type' => 'string', 'pattern' => '^\+?[0-9\s\-\(\)]+$'],

            default => ['type' => 'string'],
        };
    }

    // -----------------------------------------------------------------------
    // Default value casting
    // -----------------------------------------------------------------------

    protected function castDefault(mixed $default, string $openApiType): mixed
    {
        return match ($openApiType) {
            'integer' => (int) $default,
            'number'  => (float) $default,
            'boolean' => (bool) $default,
            default   => (string) $default,
        };
    }

    // -----------------------------------------------------------------------
    // FK ref helper
    // -----------------------------------------------------------------------

    /**
     * Build a $ref property for a foreign key column.
     */
    public function fkRef(string $referencedTable): array
    {
        $model = Helpers::tableToModelName($referencedTable);

        return ['$ref' => "#/components/schemas/{$model}"];
    }

    // -----------------------------------------------------------------------
    // Shared response schema helpers
    // -----------------------------------------------------------------------

    /**
     * Standard paginated collection wrapper schema.
     *
     * @return array<string, mixed>
     */
    public static function paginatedCollection(string $modelName): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'data'  => [
                    'type'  => 'array',
                    'items' => ['$ref' => "#/components/schemas/{$modelName}Resource"],
                ],
                'meta'  => ['$ref' => '#/components/schemas/PaginationMeta'],
                'links' => ['$ref' => '#/components/schemas/PaginationLinks'],
            ],
        ];
    }

    /**
     * Standard Laravel pagination meta schema (reusable).
     *
     * @return array<string, mixed>
     */
    public static function paginationMetaSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'current_page' => ['type' => 'integer'],
                'from'         => ['type' => 'integer', 'nullable' => true],
                'last_page'    => ['type' => 'integer'],
                'per_page'     => ['type' => 'integer'],
                'to'           => ['type' => 'integer', 'nullable' => true],
                'total'        => ['type' => 'integer'],
            ],
        ];
    }

    /**
     * Standard Laravel pagination links schema (reusable).
     *
     * @return array<string, mixed>
     */
    public static function paginationLinksSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'first' => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                'last'  => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                'prev'  => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                'next'  => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
            ],
        ];
    }

    /**
     * Standard 422 Validation error response schema (reusable).
     *
     * @return array<string, mixed>
     */
    public static function validationErrorSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'message' => ['type' => 'string', 'example' => 'The given data was invalid.'],
                'errors'  => [
                    'type'                 => 'object',
                    'additionalProperties' => [
                        'type'  => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Standard 401 Unauthenticated response schema (reusable).
     *
     * @return array<string, mixed>
     */
    public static function unauthenticatedSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'message' => ['type' => 'string', 'example' => 'Unauthenticated.'],
            ],
        ];
    }

    /**
     * Standard 404 Not Found response schema (reusable).
     *
     * @return array<string, mixed>
     */
    public static function notFoundSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'message' => ['type' => 'string', 'example' => 'Record not found.'],
            ],
        ];
    }
}