<?php

namespace Zuqongtech\LaravelAnvil\Generators;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates a service class for each model.
 *
 * The service is the application-layer home for business logic:
 *  - Receives validated DTO / array data (not raw Request objects)
 *  - Delegates persistence to the injected repository
 *  - Fires model events (Created / Updated / Deleted)
 *  - Provides hooks (beforeCreate, afterCreate, etc.) for subclasses
 *  - Handles soft-delete toggling when the model uses SoftDeletes
 *
 * The generated class is intentionally thin — the scaffolded methods
 * are stubs with inline comments guiding developers where to add logic.
 */
final class ServiceGenerator implements Generator
{
    #[\Override]
    public function supports(GenerationOptions $options): bool
    {
        return $options->services ?? false;
    }

    #[\Override]
    public function getName(): string
    {
        return 'Service';
    }

    #[\Override]
    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $serviceName = $meta->model.'Service';
        $path = app_path("Services/{$serviceName}.php");

        if (file_exists($path) && ! $options->force) {
            return [
                'type' => $this->getName(),
                'name' => $serviceName,
                'path' => $path,
                'status' => 'skipped',
                'reason' => 'already exists',
            ];
        }

        $namespace = $options->getNamespace();
        $content = $this->buildService($meta, $namespace);

        if (! $options->dryRun) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, $content);
        }

        return [
            'type' => $this->getName(),
            'name' => $serviceName,
            'path' => $path,
            'status' => 'success',
            'action' => file_exists($path) ? 'overwritten' : 'created',
        ];
    }

    protected function buildService(ModelMetadata $meta, string $namespace): string
    {
        $model = $meta->model;
        $service = $model.'Service';
        $repoInterface = $model.'RepositoryInterface';
        $variable = lcfirst($model);
        $fullModel = trim($namespace, '\\').'\\'.$model;

        $eventImports = implode("\n", [
            "use App\\Events\\{$model}Created;",
            "use App\\Events\\{$model}Updated;",
            "use App\\Events\\{$model}Deleted;",
        ]);

        $softDeleteMethods = '';
        if ($meta->softDeletes) {
            $softDeleteMethods = <<<PHP


    /**
     * Restore a soft-deleted {$model}.
     */
    public function restore(int|string \$id): {$model}
    {
        \${$variable} = \$this->repository->findTrashed(\$id);

        \$this->beforeRestore(\${$variable});

        \${$variable}->restore();

        \$this->afterRestore(\${$variable});

        return \${$variable};
    }

    /**
     * Permanently delete a {$model}.
     */
    public function forceDelete(int|string \$id): void
    {
        \${$variable} = \$this->repository->findTrashed(\$id);

        \$this->beforeForceDelete(\${$variable});

        \${$variable}->forceDelete();
    }

    // Hook stubs for soft-delete lifecycle
    protected function beforeRestore({$model} \${$variable}): void {}
    protected function afterRestore({$model} \${$variable}): void {}
    protected function beforeForceDelete({$model} \${$variable}): void {}
PHP;
        }

        return <<<PHP
<?php

namespace App\Services;

use {$fullModel};
use App\Repositories\Contracts\\{$repoInterface};
{$eventImports}
use Illuminate\Pagination\LengthAwarePaginator;

class {$service}
{
    public function __construct(
        protected readonly {$repoInterface} \$repository,
    ) {}

    /**
     * Return a paginated list of {$model} records.
     */
    public function paginate(int \$perPage = 15, array \$filters = []): LengthAwarePaginator
    {
        return \$this->repository->paginate(\$perPage, \$filters);
    }

    /**
     * Find a single {$model} by primary key (throws ModelNotFoundException).
     */
    public function findOrFail(int|string \$id): {$model}
    {
        return \$this->repository->findOrFail(\$id);
    }

    /**
     * Create a new {$model} from validated data.
     *
     * @param  array<string, mixed>  \$data
     */
    public function create(array \$data): {$model}
    {
        \$this->beforeCreate(\$data);

        \${$variable} = \$this->repository->create(\$data);

        \$this->afterCreate(\${$variable});

        event(new {$model}Created(\${$variable}));

        return \${$variable};
    }

    /**
     * Update an existing {$model}.
     *
     * @param  array<string, mixed>  \$data
     */
    public function update(int|string \$id, array \$data): {$model}
    {
        \${$variable} = \$this->repository->findOrFail(\$id);

        \$this->beforeUpdate(\${$variable}, \$data);

        \$this->repository->update(\${$variable}, \$data);

        \$this->afterUpdate(\${$variable});

        event(new {$model}Updated(\${$variable}));

        return \${$variable}->fresh();
    }

    /**
     * Delete a {$model} by primary key.
     */
    public function delete(int|string \$id): void
    {
        \${$variable} = \$this->repository->findOrFail(\$id);

        \$this->beforeDelete(\${$variable});

        \$this->repository->delete(\${$variable});

        event(new {$model}Deleted(\${$variable}));
    }

    // -----------------------------------------------------------------------
    // Lifecycle hooks — override in subclasses to add business logic
    // -----------------------------------------------------------------------

    /** @param  array<string, mixed>  \$data */
    protected function beforeCreate(array &\$data): void {}

    protected function afterCreate({$model} \${$variable}): void {}

    /** @param  array<string, mixed>  \$data */
    protected function beforeUpdate({$model} \${$variable}, array &\$data): void {}

    protected function afterUpdate({$model} \${$variable}): void {}

    protected function beforeDelete({$model} \${$variable}): void {}
{$softDeleteMethods}
}

PHP;
    }
}
