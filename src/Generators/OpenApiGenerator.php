<?php

namespace Zuqongtech\LaravelAnvil\Generators;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\Helpers;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates OpenAPI 3.1 path + schema stubs for each model.
 *
 * Output layout:
 *   docs/openapi/
 *     openapi.yaml          ← root spec (generated once, never overwritten)
 *     paths/
 *       vehicles.yaml       ← CRUD path items per resource
 *     schemas/
 *       Vehicle.yaml        ← JSON Schema component per model
 */
final class OpenApiGenerator implements Generator
{
    public function supports(GenerationOptions $options): bool
    {
        return $options->openApi ?? false;
    }

    public function getName(): string
    {
        return 'OpenAPI';
    }

    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $results = [];

        $results[] = $this->generateSchema($meta, $options);
        $results[] = $this->generatePaths($meta, $options);

        // Generate/update the root spec once per run (first model wins)
        $rootPath = base_path('docs/openapi/openapi.yaml');
        if (! file_exists($rootPath)) {
            $results[] = $this->generateRootSpec($options);
        }

        return $results;
    }

    // -----------------------------------------------------------------------
    // Schema component  (docs/openapi/schemas/Vehicle.yaml)
    // -----------------------------------------------------------------------

    protected function generateSchema(ModelMetadata $meta, GenerationOptions $options): array
    {
        $schemaName = $meta->model;
        $path = base_path("docs/openapi/schemas/{$schemaName}.yaml");

        if (file_exists($path) && ! $options->force) {
            return $this->result('OpenAPI Schema', $schemaName, $path, 'skipped');
        }

        $properties = $this->buildSchemaProperties($meta);
        $required = $this->buildRequiredList($meta);

        $requiredBlock = '';
        if (! empty($required)) {
            $requiredBlock = "required:\n"
                .implode("\n", array_map(fn ($f) => "  - {$f}", $required))
                ."\n";
        }

        $content = <<<YAML
{$schemaName}:
  type: object
  {$requiredBlock}properties:
{$properties}

YAML;

        if (! $options->dryRun) {
            $this->ensureDir(dirname($path));
            file_put_contents($path, $content);
        }

        return $this->result('OpenAPI Schema', $schemaName, $path, 'success');
    }

    protected function buildSchemaProperties(ModelMetadata $meta): string
    {
        $lines = [];

        foreach ($meta->columns as $col) {
            $name = $col['name'];
            $nullable = $col['nullable'] ?? false;
            [$type, $format] = $this->mapColumnType($col['type'] ?? 'varchar');

            $lines[] = "    {$name}:";
            if ($nullable) {
                $lines[] = '      nullable: true';
            }
            $lines[] = "      type: {$type}";
            if ($format) {
                $lines[] = "      format: {$format}";
            }

            // Add example hints for common columns
            $example = $this->exampleForColumn($name, $col['type'] ?? '');
            if ($example !== null) {
                $lines[] = "      example: {$example}";
            }
        }

        return implode("\n", $lines);
    }

    protected function buildRequiredList(ModelMetadata $meta): array
    {
        return array_values(array_map(
            fn ($col) => $col['name'],
            array_filter(
                $meta->columns,
                fn ($col) => ! ($col['nullable'] ?? false)
                    && ! in_array($col['name'], [
                        $meta->primaryKey,
                        'created_at',
                        'updated_at',
                        'deleted_at',
                    ], true)
            )
        ));
    }

    // -----------------------------------------------------------------------
    // Path item  (docs/openapi/paths/vehicles.yaml)
    // -----------------------------------------------------------------------

    protected function generatePaths(ModelMetadata $meta, GenerationOptions $options): array
    {
        $model = $meta->model;
        $resource = Helpers::modelToRouteName($model);   // Vehicle → vehicles
        $tag = $model;
        $pathName = $resource;
        $path = base_path("docs/openapi/paths/{$pathName}.yaml");
        $schemaRef = "#/components/schemas/{$model}";
        $idType = $this->primaryKeyType($meta);

        if (file_exists($path) && ! $options->force) {
            return $this->result('OpenAPI Paths', $model, $path, 'skipped');
        }

        $softDeletePaths = '';
        if ($meta->softDeletes) {
            $softDeletePaths = <<<YAML

  /api/v1/{$resource}/{id}/restore:
    post:
      tags: [{$tag}]
      summary: Restore a soft-deleted {$model}
      operationId: restore{$model}
      parameters:
        - \$ref: '#/components/parameters/Id'
      responses:
        '200':
          description: Restored
          content:
            application/json:
              schema:
                \$ref: '{$schemaRef}'
        '404':
          \$ref: '#/components/responses/NotFound'

  /api/v1/{$resource}/{id}/force-delete:
    delete:
      tags: [{$tag}]
      summary: Permanently delete a {$model}
      operationId: forceDelete{$model}
      parameters:
        - \$ref: '#/components/parameters/Id'
      responses:
        '204':
          description: Permanently deleted
        '404':
          \$ref: '#/components/responses/NotFound'
YAML;
        }

        $content = <<<YAML
/api/v1/{$resource}:
  get:
    tags: [{$tag}]
    summary: List {$model} records
    operationId: list{$model}
    parameters:
      - name: per_page
        in: query
        schema:
          type: integer
          default: 15
    responses:
      '200':
        description: Paginated list
        content:
          application/json:
            schema:
              type: object
              properties:
                data:
                  type: array
                  items:
                    \$ref: '{$schemaRef}'
                meta:
                  \$ref: '#/components/schemas/PaginationMeta'
      '401':
        \$ref: '#/components/responses/Unauthorized'

  post:
    tags: [{$tag}]
    summary: Create a {$model}
    operationId: create{$model}
    requestBody:
      required: true
      content:
        application/json:
          schema:
            \$ref: '{$schemaRef}'
    responses:
      '201':
        description: Created
        content:
          application/json:
            schema:
              \$ref: '{$schemaRef}'
      '422':
        \$ref: '#/components/responses/ValidationError'
      '401':
        \$ref: '#/components/responses/Unauthorized'

/api/v1/{$resource}/{id}:
  parameters:
    - name: id
      in: path
      required: true
      schema:
        type: {$idType}

  get:
    tags: [{$tag}]
    summary: Get a {$model} by ID
    operationId: get{$model}
    responses:
      '200':
        description: Found
        content:
          application/json:
            schema:
              \$ref: '{$schemaRef}'
      '404':
        \$ref: '#/components/responses/NotFound'
      '401':
        \$ref: '#/components/responses/Unauthorized'

  put:
    tags: [{$tag}]
    summary: Update a {$model}
    operationId: update{$model}
    requestBody:
      required: true
      content:
        application/json:
          schema:
            \$ref: '{$schemaRef}'
    responses:
      '200':
        description: Updated
        content:
          application/json:
            schema:
              \$ref: '{$schemaRef}'
      '422':
        \$ref: '#/components/responses/ValidationError'
      '404':
        \$ref: '#/components/responses/NotFound'
      '401':
        \$ref: '#/components/responses/Unauthorized'

  delete:
    tags: [{$tag}]
    summary: Delete a {$model}
    operationId: delete{$model}
    responses:
      '204':
        description: Deleted
      '404':
        \$ref: '#/components/responses/NotFound'
      '401':
        \$ref: '#/components/responses/Unauthorized'
{$softDeletePaths}
YAML;

        if (! $options->dryRun) {
            $this->ensureDir(dirname($path));
            file_put_contents($path, $content);
        }

        return $this->result('OpenAPI Paths', $model, $path, 'success');
    }

    // -----------------------------------------------------------------------
    // Root spec  (docs/openapi/openapi.yaml)
    // -----------------------------------------------------------------------

    protected function generateRootSpec(GenerationOptions $options): array
    {
        $path = base_path('docs/openapi/openapi.yaml');
        $appName = config('app.name', 'Laravel');
        $appUrl = config('app.url', 'http://localhost');

        $content = <<<YAML
openapi: 3.1.0

info:
  title: {$appName} API
  version: 1.0.0
  description: Auto-generated by laravel-anvil

servers:
  - url: {$appUrl}
    description: Local

security:
  - sanctum: []

paths:
  # Populated via \$ref — see paths/ directory
  # Example:
  #   /api/v1/vehicles:
  #     \$ref: './paths/vehicles.yaml#/~1api~1v1~1vehicles'

components:
  securitySchemes:
    sanctum:
      type: http
      scheme: bearer
      bearerFormat: JWT

  parameters:
    Id:
      name: id
      in: path
      required: true
      schema:
        type: integer

  responses:
    NotFound:
      description: Resource not found
      content:
        application/json:
          schema:
            \$ref: '#/components/schemas/ErrorResponse'

    Unauthorized:
      description: Unauthenticated
      content:
        application/json:
          schema:
            \$ref: '#/components/schemas/ErrorResponse'

    ValidationError:
      description: Validation failed
      content:
        application/json:
          schema:
            type: object
            properties:
              message:
                type: string
              errors:
                type: object
                additionalProperties:
                  type: array
                  items:
                    type: string

  schemas:
    ErrorResponse:
      type: object
      properties:
        message:
          type: string
          example: Resource not found

    PaginationMeta:
      type: object
      properties:
        current_page:
          type: integer
        last_page:
          type: integer
        per_page:
          type: integer
        total:
          type: integer

    # Model schemas live in schemas/ directory
    # Import them here as needed:
    # Vehicle:
    #   \$ref: './schemas/Vehicle.yaml#/Vehicle'

YAML;

        if (! $options->dryRun) {
            $this->ensureDir(dirname($path));
            file_put_contents($path, $content);
        }

        return $this->result('OpenAPI Root Spec', 'openapi.yaml', $path, 'success');
    }

    // -----------------------------------------------------------------------
    // Type mapping helpers
    // -----------------------------------------------------------------------

    /**
     * @return array{0: string, 1: string|null} [openapi-type, format|null]
     */
    protected function mapColumnType(string $dbType): array
    {
        $type = strtolower(preg_replace('/\(.*\)/', '', trim($dbType)));

        return match (true) {
            in_array($type, ['int', 'integer', 'smallint', 'mediumint']) => ['integer', 'int32'],
            in_array($type, ['bigint']) => ['integer', 'int64'],
            in_array($type, ['tinyint', 'boolean', 'bool']) => ['boolean', null],
            in_array($type, ['decimal', 'numeric', 'float', 'double', 'real']) => ['number', 'float'],
            in_array($type, ['date']) => ['string', 'date'],
            in_array($type, ['datetime', 'timestamp']) => ['string', 'date-time'],
            in_array($type, ['time']) => ['string', null],
            in_array($type, ['uuid']) => ['string', 'uuid'],
            in_array($type, ['json', 'jsonb']) => ['object', null],
            in_array($type, ['text', 'mediumtext', 'longtext']) => ['string', null],
            default => ['string', null],
        };
    }

    protected function primaryKeyType(ModelMetadata $meta): string
    {
        foreach ($meta->columns as $col) {
            if ($col['name'] === $meta->primaryKey) {
                [$type] = $this->mapColumnType($col['type'] ?? 'integer');

                return $type;
            }
        }

        return 'integer';
    }

    protected function exampleForColumn(string $name, string $dbType): ?string
    {
        $lower = strtolower($name);

        return match (true) {
            str_contains($lower, 'email') => '"user@example.com"',
            str_contains($lower, 'name') => '"Example Name"',
            str_contains($lower, 'phone') => '"+1-555-0100"',
            str_contains($lower, 'url') => '"https://example.com"',
            str_contains($lower, 'uuid') => '"550e8400-e29b-41d4-a716-446655440000"',
            str_contains($lower, 'status') => '"active"',
            str_contains($lower, 'currency') => '"USD"',
            str_contains($lower, 'price'),
            str_contains($lower, 'amount') => '99.99',
            str_contains($lower, 'lat') => '-6.314993',
            str_contains($lower, 'lng'),
            str_contains($lower, 'lon') => '143.955550',
            default => null,
        };
    }

    // -----------------------------------------------------------------------
    // Utilities
    // -----------------------------------------------------------------------

    protected function ensureDir(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    protected function result(string $type, string $name, string $path, string $status): array
    {
        return compact('type', 'name', 'path', 'status');
    }
}
