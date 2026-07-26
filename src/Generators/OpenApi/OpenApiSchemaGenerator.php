<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Generators\OpenApi;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Generators\OpenApi\Concerns\ResolvesSpecOptions;
use Zuqongtech\LaravelAnvil\Support\ApiVersionProfile;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\Helpers;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;
use Zuqongtech\LaravelAnvil\Support\OpenApiLocator;
use Zuqongtech\LaravelAnvil\Support\OpenApiTypeMapper;
use Zuqongtech\LaravelAnvil\Support\OpenApiYamlSerializer;

/**
 * Generates OpenAPI 3.1 component schemas for a model.
 *
 *  {Model}Resource   — the response shape: keys in the version's RESPONSE case,
 *                      hidden columns removed
 *  {Model}Request     — the request body: keys in the version's REQUEST case,
 *                      read-only columns removed
 *  {Model}Collection  — paginated wrapper referencing {Model}Resource
 *  {Model}            — the raw database entity, opt-in (see below)
 *
 * KEY CASING
 *
 * Every property name is resolved through ApiVersionProfile, the same object the
 * form requests and API resources use. Previously this generator keyed properties
 * by raw column name while a camelCase version's resources emitted camelCase, so
 * the spec described an API that did not exist — and nothing caught it, because a
 * spec is only wrong when a human reads it.
 *
 * The `required` list is built from the SAME cased keys. Listing `tenant_id` as
 * required next to a property called `tenantId` is not just misleading, it is an
 * invalid document.
 *
 * THE ENTITY SCHEMA
 *
 * {Model} (all columns, database names, including hidden ones) is referenced by
 * no path — only Resource, Request and Collection are. It is off by default;
 * enable anvil.openapi.include_entity_schema to emit it as internal
 * documentation. Note that turning it off does not delete files a previous run
 * wrote: the root spec globs the schemas directory, so stale files keep being
 * $ref'd until removed.
 */
final readonly class OpenApiSchemaGenerator implements Generator
{
    use ResolvesSpecOptions;

    private const READ_ONLY_FIELDS = [
        'id',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * Dependencies are default-constructible so this generator works whether it
     * is resolved through the container (autowired) or built with a bare `new`.
     */
    public function __construct(
        private OpenApiTypeMapper $mapper = new OpenApiTypeMapper,
        private OpenApiYamlSerializer $serializer = new OpenApiYamlSerializer,
    ) {}

    #[\Override]
    public function supports(GenerationOptions $options): bool
    {
        return $this->specEnabled($options);
    }

    #[\Override]
    public function getName(): string
    {
        return 'OpenApiSchema';
    }

    #[\Override]
    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $format = OpenApiLocator::format();
        $splitFiles = $this->splitFiles();
        $schemasDir = OpenApiLocator::schemasDir();

        $schemas = $this->buildSchemas($meta, $this->profile($options));
        $results = [];

        foreach ($schemas as $schemaName => $schema) {
            if (! $splitFiles) {
                $results[] = [
                    'type' => $this->getName(),
                    'name' => $schemaName,
                    'status' => 'merged',
                    'schema_key' => $schemaName,
                    'schema_def' => $schema,
                ];

                continue;
            }

            $path = "{$schemasDir}/{$schemaName}.{$format}";

            if (file_exists($path) && ! $this->overwrites($options)) {
                $results[] = [
                    'type' => $this->getName(),
                    'name' => $schemaName,
                    'path' => $path,
                    'status' => 'skipped',
                    'reason' => 'already exists',
                ];

                continue;
            }

            if (! $this->isDryRun($options)) {
                $this->serializer->writeFile([$schemaName => $schema], $path, $format);
            }

            $results[] = [
                'type' => $this->getName(),
                'name' => $schemaName,
                'path' => $path,
                'status' => $this->isDryRun($options) ? 'dry-run' : 'success',
            ];
        }

        return $results;
    }

    protected function profile(GenerationOptions $options): ApiVersionProfile
    {
        return ApiVersionProfile::for($options->apiVersion);
    }

    // -----------------------------------------------------------------------
    // Schema builders
    // -----------------------------------------------------------------------

    /**
     * @return array<string, array<string, mixed>>
     */
    public function buildSchemas(ModelMetadata $meta, ?ApiVersionProfile $profile = null): array
    {
        $profile ??= ApiVersionProfile::for();

        $schemas = [
            $meta->model.'Resource' => $this->buildResourceSchema($meta, $profile),
            $meta->model.'Request' => $this->buildRequestSchema($meta, $profile),
            $meta->model.'Collection' => $this->buildCollectionSchema($meta),
        ];

        if ((bool) config('anvil.openapi.include_entity_schema', false)) {
            $schemas[$meta->model] = $this->buildEntitySchema($meta);
        }

        return $schemas;
    }

    /**
     * The raw table, in database names. Documentation only — no path references
     * it. Hidden columns ARE included, which is precisely why it must not be
     * confused with the response shape.
     *
     * @return array<string, mixed>
     */
    protected function buildEntitySchema(ModelMetadata $meta): array
    {
        $properties = [];
        $required = [];

        $fkMap = array_column($meta->foreignKeys, 'referenced_table', 'column');

        foreach ($meta->columns as $col) {
            $name = (string) $col['name'];
            $property = $this->property($col);

            if ($this->isReadOnly($meta, $name)) {
                $property['readOnly'] = true;
            }

            if (isset($fkMap[$name])) {
                $property['description'] = 'Foreign key referencing '.Helpers::tableToModelName($fkMap[$name]);
            }

            $properties[$name] = $property;

            if ($this->isRequired($meta, $col)) {
                $required[] = $name;
            }
        }

        return $this->schema('Raw '.$meta->table.' row, in database column names.', $properties, $required);
    }

    /**
     * The response shape: keys in the version's response casing, hidden columns
     * omitted entirely.
     *
     * @return array<string, mixed>
     */
    protected function buildResourceSchema(ModelMetadata $meta, ApiVersionProfile $profile): array
    {
        $properties = [];
        $required = [];

        foreach ($meta->columns as $col) {
            $name = (string) $col['name'];

            if ($profile->isHidden($name)) {
                continue;
            }

            $key = $this->outboundKey($profile, $name);
            $property = $this->property($col);

            if ($this->isReadOnly($meta, $name)) {
                $property['readOnly'] = true;
            }

            $properties[$key] = $property;

            // required is keyed by the SAME name as the property. Mixing the two
            // produces a document that references a property which does not
            // exist.
            if ($this->isRequired($meta, $col) && ! $this->isReadOnly($meta, $name)) {
                $required[] = $key;
            }
        }

        // Relationship links — a bare $ref (valid as a property value in
        // OpenAPI 3.1). The allOf-wrapper pattern broke Swagger UI's resolver on
        // self-referential relationships ("Elements in allOf must be objects");
        // a bare circular $ref resolves and renders as a recursive model.
        //
        // Relations are NOT required: they appear only when eager-loaded.
        foreach ($meta->foreignKeys as $fk) {
            $column = (string) ($fk['column'] ?? '');
            $method = $meta->belongsToName($column) ?? ($column !== '' ? Helpers::foreignKeyToRelationName($column) : null);

            if ($method === null) {
                continue;
            }

            $related = Helpers::tableToModelName((string) $fk['referenced_table']);

            $properties[$this->outboundKey($profile, $method)] = [
                '$ref' => "#/components/schemas/{$related}Resource",
            ];
        }

        foreach ($meta->inverseRelationships as $row) {
            $table = (string) ($row['table'] ?? '');
            $column = (string) ($row['column'] ?? $row['foreign_key'] ?? '');
            $method = $table !== '' ? $meta->inverseName($table, $column) : null;

            if ($method === null) {
                continue;
            }

            $related = Helpers::tableToModelName($table);

            $properties[$this->outboundKey($profile, $method)] = [
                'type' => 'array',
                'items' => ['$ref' => "#/components/schemas/{$related}Resource"],
            ];
        }

        return $this->schema(
            sprintf('%s as returned by the %s API.', $meta->model, $profile->version),
            $properties,
            $required,
        );
    }

    /**
     * The request body: keys in the version's request casing, read-only and
     * non-writable columns omitted.
     *
     * @return array<string, mixed>
     */
    protected function buildRequestSchema(ModelMetadata $meta, ApiVersionProfile $profile): array
    {
        $properties = [];
        $required = [];

        $skip = array_merge(
            [$meta->primaryKey, 'created_at', 'updated_at', 'deleted_at', 'remember_token'],
            $meta->compositePrimaryKey,
        );

        $fkMap = array_column($meta->foreignKeys, 'referenced_table', 'column');
        $inbound = array_flip($profile->inboundMap(array_column($meta->columns, 'name')));

        foreach ($meta->columns as $col) {
            $name = (string) $col['name'];

            if (in_array($name, $skip, true) || $profile->isReadOnly($name)) {
                continue;
            }

            // A hidden field may still be writable: password is never returned
            // but must be accepted on create.
            if ($profile->isHidden($name) && $name !== 'password') {
                continue;
            }

            $key = $inbound[$name] ?? $name;
            $property = $this->property($col);

            // readOnly makes no sense on a request body.
            unset($property['readOnly']);

            $hints = [];

            if (isset($fkMap[$name])) {
                $hints[] = sprintf('Must exist in %s (%s)', $fkMap[$name], Helpers::tableToModelName($fkMap[$name]));
            }

            if ($this->isUnique($meta, $name)) {
                $hints[] = 'Must be unique';
            }

            if ($hints !== []) {
                $property['description'] = implode('. ', $hints);
            }

            $properties[$key] = $property;

            if ($this->isRequired($meta, $col)) {
                $required[] = $key;
            }
        }

        return $this->schema(
            sprintf('Payload accepted when creating or replacing a %s (%s).', $meta->model, $profile->version),
            $properties,
            $required,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildCollectionSchema(ModelMetadata $meta): array
    {
        return OpenApiTypeMapper::paginatedCollection($meta->model);
    }

    // -----------------------------------------------------------------------
    // Property helpers
    // -----------------------------------------------------------------------

    /**
     * Map a column, then clean up what the type mapper leaves behind.
     *
     * @param  array<string, mixed>  $col
     * @return array<string, mixed>
     */
    protected function property(array $col): array
    {
        $property = $this->mapper->column($col);

        // Database default expressions are not JSON Schema values. Postgres
        // reports "'monthly'::character varying" and "nextval('seq'::regclass)";
        // MySQL reports "CURRENT_TIMESTAMP". Emitting those verbatim tells a
        // client the default value is a SQL fragment.
        if (array_key_exists('default', $property)) {
            $default = $this->normaliseDefault($property['default'], (string) ($property['type'] ?? 'string'));

            if ($default === null) {
                unset($property['default']);
            } else {
                $property['default'] = $default;
            }
        }

        return $property;
    }

    /**
     * Strip casts and quoting from a database default, or drop it when it is an
     * expression rather than a literal.
     */
    protected function normaliseDefault(mixed $default, string $type): mixed
    {
        if ($default === null || is_bool($default) || is_int($default) || is_float($default)) {
            return $default;
        }

        if (! is_string($default)) {
            return null;
        }

        $value = trim($default);

        // "'monthly'::character varying" → "'monthly'"
        if (($castAt = strpos($value, '::')) !== false) {
            $value = trim(substr($value, 0, $castAt));
        }

        // Function calls and keywords are expressions, not values.
        if (
            preg_match('/^(nextval|now|current_timestamp|current_date|uuid_generate|gen_random_uuid|null)\b/i', $value) === 1
            || str_contains($value, '(')
        ) {
            return null;
        }

        // "'monthly'" → monthly
        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            $value = str_replace("''", "'", substr($value, 1, -1));
        }

        return match ($type) {
            'integer' => is_numeric($value) ? (int) $value : null,
            'number' => is_numeric($value) ? (float) $value : null,
            'boolean' => match (strtolower($value)) {
                'true', 't', '1' => true,
                'false', 'f', '0' => false,
                default => null,
            },
            'object', 'array' => null,
            default => $value === '' ? null : $value,
        };
    }

    protected function outboundKey(ApiVersionProfile $profile, string $column): string
    {
        return $profile->outboundMap([$column])[$column] ?? $column;
    }

    protected function isReadOnly(ModelMetadata $meta, string $name): bool
    {
        return in_array($name, self::READ_ONLY_FIELDS, true)
            || $name === $meta->primaryKey
            || in_array($name, $meta->compositePrimaryKey, true);
    }

    /**
     * @param  array<string, mixed>  $col
     */
    protected function isRequired(ModelMetadata $meta, array $col): bool
    {
        $name = (string) $col['name'];

        return ! ($col['nullable'] ?? false)
            && ($col['default'] ?? null) === null
            && ! in_array($name, self::READ_ONLY_FIELDS, true)
            && $name !== $meta->primaryKey
            && ! in_array($name, $meta->compositePrimaryKey, true);
    }

    protected function isUnique(ModelMetadata $meta, string $column): bool
    {
        foreach ($meta->uniqueConstraints as $constraint) {
            $columns = array_column($constraint['columns'] ?? [], 'name');

            if (count($columns) === 1 && $columns[0] === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    protected function schema(string $description, array $properties, array $required): array
    {
        $schema = [
            'type' => 'object',
            'description' => $description,
            'properties' => $properties,
        ];

        if ($required !== []) {
            $schema['required'] = array_values(array_unique($required));
        }

        return $schema;
    }
}
