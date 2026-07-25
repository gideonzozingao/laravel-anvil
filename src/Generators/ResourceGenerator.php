<?php

namespace Zuqongtech\LaravelAnvil\Generators;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\Helpers;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates an API Resource class for each model.
 *
 * The generated resource:
 *  - Extends JsonResource
 *  - Maps every non-hidden column to its array key
 *  - Excludes sensitive fields defined in config('anvil.generators.resources.exclude_fields')
 *  - Conditionally includes loaded relationships as nested resources:
 *      - belongsTo  → WhenLoaded + single resource
 *      - hasMany    → WhenLoaded + resource collection
 *  - Adds a 'created_at' / 'updated_at' ISO-8601 section when timestamps present
 *  - PHPDoc @property hints are derived from the column list
 *
 * Example output for a Post model:
 *
 *   class PostResource extends JsonResource
 *   {
 *       public function toArray(Request $request): array
 *       {
 *           return [
 *               'id'         => $this->id,
 *               'title'      => $this->title,
 *               'user'       => new UserResource($this->whenLoaded('user')),
 *               'created_at' => $this->created_at?->toIso8601String(),
 *               'updated_at' => $this->updated_at?->toIso8601String(),
 *           ];
 *       }
 *   }
 */
final class ResourceGenerator implements Generator
{
    /** Fields always excluded from the resource output. */
    private const DEFAULT_EXCLUDED = [
        'password',
        'remember_token',
        'api_token',
        'secret',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    #[\Override]
    public function supports(GenerationOptions $options): bool
    {
        return $options->resources ?? false;
    }

    #[\Override]
    public function getName(): string
    {
        return 'Resource';
    }

    #[\Override]
    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $resourceName = $meta->model.'Resource';
        $path = app_path("Http/Resources/{$resourceName}.php");

        if (file_exists($path) && ! $options->force) {
            return [
                'type' => $this->getName(),
                'name' => $resourceName,
                'path' => $path,
                'status' => 'skipped',
                'reason' => 'already exists',
            ];
        }

        $namespace = $options->getNamespace();
        $content = $this->buildResource($meta, $namespace);

        if (! $options->dryRun) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, $content);
        }

        return [
            'type' => $this->getName(),
            'name' => $resourceName,
            'path' => $path,
            'status' => 'success',
            'action' => file_exists($path) ? 'overwritten' : 'created',
        ];
    }

    // -----------------------------------------------------------------------
    // Builder
    // -----------------------------------------------------------------------

    protected function buildResource(ModelMetadata $meta, string $namespace): string
    {
        $model = $meta->model;
        $resourceName = $model.'Resource';

        $excludedFields = array_merge(
            self::DEFAULT_EXCLUDED,
            config('anvil.generators.resources.exclude_fields', [])
        );

        // Columns that map to simple scalar fields
        $scalarLines = $this->buildScalarLines($meta, $excludedFields);

        // Relationship lines (belongsTo FK → whenLoaded resource)
        $relationshipLines = $this->buildRelationshipLines($meta, $namespace);

        // Inverse hasMany relationships (if populated by the orchestrator)
        $inversLines = $this->buildInverseRelationshipLines($meta, $namespace);

        // Timestamp lines
        $timestampLines = $this->buildTimestampLines($meta);

        // Merge in correct order: scalars → relationships → inverse → timestamps
        $allLines = array_merge(
            $scalarLines,
            $relationshipLines,
            $inversLines,
            $timestampLines,
        );

        $arrayBody = implode("\n", $allLines);

        // Collect any extra use-imports needed for related resources
        $extraImports = $this->collectRelationshipImports($meta, $namespace);

        return <<<PHP
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
{$extraImports}

class {$resourceName} extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request \$request): array
    {
        return [
{$arrayBody}
        ];
    }
}

PHP;
    }

    // -----------------------------------------------------------------------
    // Line builders
    // -----------------------------------------------------------------------

    /**
     * Build the scalar column lines, e.g. "'title' => $this->title,".
     */
    protected function buildScalarLines(ModelMetadata $meta, array $excludedFields): array
    {
        $lines = [];

        // Columns that are FK columns — we handle those in relationship lines

        // Auto-skip timestamp/soft-delete columns (handled separately)
        $autoSkip = ['created_at', 'updated_at', 'deleted_at'];

        foreach ($meta->columns as $col) {
            $name = $col['name'];

            if (in_array($name, $excludedFields, true)) {
                continue;
            }

            if (in_array($name, $autoSkip, true)) {
                continue;
            }

            // Still include FK id columns for API consumers
            $lines[] = "            '{$name}' => \$this->{$name},";
        }

        return $lines;
    }

    /**
     * Build belongsTo relationship lines using whenLoaded().
     *
     * e.g. 'user' => new UserResource($this->whenLoaded('user')),
     */
    protected function buildRelationshipLines(ModelMetadata $meta, string $namespace): array
    {
        $includeRelationships = config('anvil.generators.resources.include_relationships', true);
        if (! $includeRelationships) {
            return [];
        }

        $lines = [];

        foreach ($meta->foreignKeys as $fk) {
            $methodName = Helpers::foreignKeyToRelationName($fk['column']);
            $relatedModel = Helpers::tableToModelName($fk['referenced_table']);
            $resourceClass = $relatedModel.'Resource';

            $lines[] = "            '{$methodName}' => new {$resourceClass}(\$this->whenLoaded('{$methodName}')),";
        }

        return $lines;
    }

    /**
     * Build hasMany inverse relationship lines using whenLoaded() + collection.
     *
     * e.g. 'comments' => CommentResource::collection($this->whenLoaded('comments')),
     */
    protected function buildInverseRelationshipLines(ModelMetadata $meta, string $namespace): array
    {
        $includeRelationships = config('anvil.generators.resources.include_relationships', true);
        if (! $includeRelationships || empty($meta->inverseRelationships)) {
            return [];
        }

        $lines = [];

        foreach ($meta->inverseRelationships as $inverse) {
            $methodName = $inverse['method'];
            $relatedModel = $inverse['model'];
            $resourceClass = $relatedModel.'Resource';

            $lines[] = "            '{$methodName}' => {$resourceClass}::collection(\$this->whenLoaded('{$methodName}')),";
        }

        return $lines;
    }

    /**
     * Build ISO-8601 timestamp lines when the model has timestamps.
     */
    protected function buildTimestampLines(ModelMetadata $meta): array
    {
        $lines = [];
        $columnNames = array_column($meta->columns, 'name');

        if (in_array('created_at', $columnNames, true)) {
            $lines[] = "            'created_at' => \$this->created_at?->toIso8601String(),";
        }

        if (in_array('updated_at', $columnNames, true)) {
            $lines[] = "            'updated_at' => \$this->updated_at?->toIso8601String(),";
        }

        return $lines;
    }

    /**
     * Collect use-import statements for related Resource classes.
     * Skips self-referential resources (same model name).
     */
    protected function collectRelationshipImports(ModelMetadata $meta, string $namespace): string
    {
        $imports = [];

        foreach ($meta->foreignKeys as $fk) {
            $relatedModel = Helpers::tableToModelName($fk['referenced_table']);
            if ($relatedModel !== $meta->model) {
                $imports["App\\Http\\Resources\\{$relatedModel}Resource"] = true;
            }
        }

        foreach ($meta->inverseRelationships as $inverse) {
            $relatedModel = $inverse['model'];
            if ($relatedModel !== $meta->model) {
                $imports["App\\Http\\Resources\\{$relatedModel}Resource"] = true;
            }
        }

        if (empty($imports)) {
            return '';
        }

        return implode("\n", array_map(
            fn ($fqn): string => "use {$fqn};",
            array_keys($imports)
        ));
    }
}
