<?php

namespace Zuqongtech\LaravelAnvil\Generators\OpenApi;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\Helpers;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;
use Zuqongtech\LaravelAnvil\Support\OpenApiTypeMapper;
use Zuqongtech\LaravelAnvil\Support\OpenApiYamlSerializer;

/**
 * Generates OpenAPI 3.1 component schemas for a model:
 *
 *  {Model}           — full database entity (all columns)
 *  {Model}Resource   — API response shape (excludes sensitive fields)
 *  {Model}Request    — store/update request body
 *  {Model}Collection — paginated wrapper referencing {Model}Resource
 */
final class OpenApiSchemaGenerator implements Generator
{
    private const SENSITIVE_FIELDS = [
        'password', 'remember_token', 'two_factor_secret',
        'two_factor_recovery_codes', 'api_key', 'api_secret', 'secret',
    ];

    private const READ_ONLY_FIELDS = [
        'id', 'created_at', 'updated_at', 'deleted_at',
    ];

    public function __construct(
        private readonly OpenApiTypeMapper $mapper = new OpenApiTypeMapper,
        private readonly OpenApiYamlSerializer $serializer = new OpenApiYamlSerializer,
    ) {}

    public function supports(GenerationOptions $options): bool
    {
        return $options->openApi ?? false;
    }

    public function getName(): string
    {
        return 'OpenApiSchema';
    }

    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $format     = config('laravel-anvil.openapi.format', 'yaml');
        $splitFiles = config('laravel-anvil.openapi.split_files', true);
        $outputPath = base_path(config('laravel-anvil.openapi.output_path', 'openapi'));

        $schemas = $this->buildSchemas($meta);
        $results = [];

        foreach ($schemas as $schemaName => $schema) {
            if ($splitFiles) {
                $ext  = $format === 'json' ? 'json' : 'yaml';
                $path = "{$outputPath}/schemas/{$schemaName}.{$ext}";

                if (file_exists($path) && ! $options->force) {
                    $results[] = [
                        'type'   => $this->getName(),
                        'name'   => $schemaName,
                        'path'   => $path,
                        'status' => 'skipped',
                        'reason' => 'already exists',
                    ];
                    continue;
                }

                if (! $options->dryRun) {
                    $this->serializer->writeFile([$schemaName => $schema], $path, $format);
                }

                $results[] = [
                    'type'   => $this->getName(),
                    'name'   => $schemaName,
                    'path'   => $path,
                    'status' => 'success',
                ];
            } else {
                $results[] = [
                    'type'       => $this->getName(),
                    'name'       => $schemaName,
                    'status'     => 'merged',
                    'schema_key' => $schemaName,
                    'schema_def' => $schema,
                ];
            }
        }

        return $results;
    }

    // -----------------------------------------------------------------------
    // Schema builders
    // -----------------------------------------------------------------------

    /**
     * @return array<string, array<string, mixed>>
     */
    public function buildSchemas(ModelMetadata $meta): array
    {
        return [
            $meta->model              => $this->buildEntitySchema($meta),
            $meta->model.'Resource'   => $this->buildResourceSchema($meta),
            $meta->model.'Request'    => $this->buildRequestSchema($meta),
            $meta->model.'Collection' => $this->buildCollectionSchema($meta),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildEntitySchema(ModelMetadata $meta): array
    {
        $properties = [];
        $required   = [];

        $fkMap = array_column($meta->foreignKeys, 'referenced_table', 'column');

        foreach ($meta->columns as $col) {
            $name = $col['name'];

            $property = $this->mapper->column($col);

            if (in_array($name, self::READ_ONLY_FIELDS, true)
                || in_array($name, $meta->compositePrimaryKey, true)
                || $name === $meta->primaryKey
            ) {
                $property['readOnly'] = true;
            }

            if (isset($fkMap[$name])) {
                $refModel = Helpers::tableToModelName($fkMap[$name]);
                $property['description'] = "Foreign key referencing {$refModel}";
            }

            $properties[$name] = $property;

            if (
                ! ($col['nullable'] ?? false)
                && ($col['default'] ?? null) === null
                && ! in_array($name, self::READ_ONLY_FIELDS, true)
                && $name !== $meta->primaryKey
                && ! in_array($name, $meta->compositePrimaryKey, true)
            ) {
                $required[] = $name;
            }
        }

        $schema = [
            'type'       => 'object',
            'properties' => $properties,
        ];

        if (! empty($required)) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildResourceSchema(ModelMetadata $meta): array
    {
        $properties = [];
        $required   = [];

        foreach ($meta->columns as $col) {
            $name = $col['name'];

            if (in_array($name, self::SENSITIVE_FIELDS, true)) {
                continue;
            }

            $property = $this->mapper->column($col);

            if (in_array($name, self::READ_ONLY_FIELDS, true)
                || $name === $meta->primaryKey
                || in_array($name, $meta->compositePrimaryKey, true)
            ) {
                $property['readOnly'] = true;
            }

            $properties[$name] = $property;

            if (
                ! ($col['nullable'] ?? false)
                && ($col['default'] ?? null) === null
                && ! in_array($name, self::READ_ONLY_FIELDS, true)
            ) {
                $required[] = $name;
            }
        }

        // Relationship links — emit a BARE $ref (valid as a property value in
        // OpenAPI 3.1 / JSON Schema 2020-12). The previous allOf-wrapper pattern
        // — allOf: [{$ref: ...}] + nullable + readOnly — was a 3.0-era workaround
        // for attaching siblings to a $ref, but it broke Swagger UI's resolver on
        // self-referential and cyclic relationships (e.g. a Location whose
        // `parent` is another Location, or Vehicle → Tenant → Location → parent).
        // On hitting the cycle the resolver substitutes a non-object placeholder
        // inside the allOf array, yielding "Elements in allOf must be objects".
        // A bare circular $ref is resolved correctly and rendered as a
        // collapsible recursive model.
        foreach ($meta->foreignKeys as $fk) {
            $relName  = Helpers::foreignKeyToRelationName($fk['column']);
            $relModel = Helpers::tableToModelName($fk['referenced_table']);

            $properties[$relName] = [
                '$ref' => "#/components/schemas/{$relModel}Resource",
            ];
        }

        $schema = [
            'type'       => 'object',
            'properties' => $properties,
        ];

        if (! empty($required)) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildRequestSchema(ModelMetadata $meta): array
    {
        $properties = [];
        $required   = [];

        $skipCols = array_merge(
            [$meta->primaryKey, 'created_at', 'updated_at', 'deleted_at', 'remember_token'],
            $meta->compositePrimaryKey,
        );

        $fkMap = array_column($meta->foreignKeys, 'referenced_table', 'column');

        foreach ($meta->columns as $col) {
            $name = $col['name'];

            if (in_array($name, $skipCols, true)) {
                continue;
            }
            if (in_array($name, self::SENSITIVE_FIELDS, true) && $name !== 'password') {
                continue;
            }

            $property = $this->mapper->column($col);

            $hints = [];
            if (isset($fkMap[$name])) {
                $refModel = Helpers::tableToModelName($fkMap[$name]);
                $hints[]  = "Must exist in {$fkMap[$name]}";
                $property['description'] = "Must exist in {$fkMap[$name]} ({$refModel})";
            }

            foreach ($meta->uniqueConstraints as $constraint) {
                $constraintCols = array_column($constraint['columns'], 'name');
                if (in_array($name, $constraintCols, true)) {
                    $hints[] = 'Must be unique';
                    break;
                }
            }

            if (! empty($hints) && ! isset($property['description'])) {
                $property['description'] = implode('. ', $hints);
            }

            $properties[$name] = $property;

            if (! ($col['nullable'] ?? false) && ($col['default'] ?? null) === null) {
                $required[] = $name;
            }
        }

        $schema = [
            'type'       => 'object',
            'properties' => $properties,
        ];

        if (! empty($required)) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildCollectionSchema(ModelMetadata $meta): array
    {
        return OpenApiTypeMapper::paginatedCollection($meta->model);
    }
}