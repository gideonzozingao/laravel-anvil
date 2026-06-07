<?php

namespace Zuqongtech\LaravelAnvil\Generators;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates three files for every model:
 *
 *  1. app/Repositories/Contracts/{Model}RepositoryInterface.php
 *  2. app/Repositories/{Model}Repository.php   (Eloquent implementation)
 *  3. app/Providers/RepositoryServiceProvider.php  (created once, appended per model)
 *
 * The interface defines the standard data-access contract so the
 * service layer depends only on the abstraction. Swapping out
 * persistence engines (Eloquent → Doctrine, Redis cache, etc.) only
 * requires a new implementation and a binding change.
 */
final class RepositoryGenerator implements Generator
{
    public function supports(GenerationOptions $options): bool
    {
        return $options->repositories ?? false;
    }

    public function getName(): string
    {
        return 'Repository';
    }

    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $results = [];

        $results[] = $this->generateInterface($meta, $options);
        $results[] = $this->generateImplementation($meta, $options);
        $this->ensureServiceProvider($meta, $options);

        return $results;
    }

    // -----------------------------------------------------------------------
    // Interface
    // -----------------------------------------------------------------------

    protected function generateInterface(ModelMetadata $meta, GenerationOptions $options): array
    {
        $name = $meta->model.'RepositoryInterface';
        $dir = app_path('Repositories/Contracts');
        $path = "{$dir}/{$name}.php";

        if (file_exists($path) && ! $options->force) {
            return ['type' => $this->getName().'Interface', 'name' => $name, 'path' => $path, 'status' => 'skipped', 'reason' => 'already exists'];
        }

        $namespace = $options->getNamespace();
        $fullModel = trim($namespace, '\\').'\\'.$meta->model;
        $model = $meta->model;
        $pk = $meta->primaryKey ?? 'id';
        $pkType = $this->pkPhpType($meta);

        $softDeleteMethods = $meta->softDeletes ? <<<PHP

    /**
     * Retrieve a soft-deleted model by primary key.
     */
    public function findTrashed({$pkType} \${$pk}): {$model};

    /**
     * Return only trashed records, paginated.
     */
    public function paginateTrashed(int \$perPage = 15): \Illuminate\Pagination\LengthAwarePaginator;
PHP
            : '';

        $content = <<<PHP
<?php

namespace App\Repositories\Contracts;

use {$fullModel};
use Illuminate\Pagination\LengthAwarePaginator;

interface {$name}
{
    /**
     * Return paginated records, optionally filtered.
     *
     * @param  array<string, mixed>  \$filters
     */
    public function paginate(int \$perPage = 15, array \$filters = []): LengthAwarePaginator;

    /**
     * Find by primary key or throw ModelNotFoundException.
     */
    public function findOrFail({$pkType} \${$pk}): {$model};

    /**
     * Create a new record.
     *
     * @param  array<string, mixed>  \$data
     */
    public function create(array \$data): {$model};

    /**
     * Update an existing record.
     *
     * @param  array<string, mixed>  \$data
     */
    public function update({$model} \$model, array \$data): {$model};

    /**
     * Delete a record.
     */
    public function delete({$model} \$model): void;
{$softDeleteMethods}
}

PHP;

        if (! $options->dryRun) {
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, $content);
        }

        return ['type' => $this->getName().'Interface', 'name' => $name, 'path' => $path, 'status' => 'success'];
    }

    // -----------------------------------------------------------------------
    // Eloquent implementation
    // -----------------------------------------------------------------------

    protected function generateImplementation(ModelMetadata $meta, GenerationOptions $options): array
    {
        $name = $meta->model.'Repository';
        $path = app_path("Repositories/{$name}.php");

        if (file_exists($path) && ! $options->force) {
            return ['type' => $this->getName(), 'name' => $name, 'path' => $path, 'status' => 'skipped', 'reason' => 'already exists'];
        }

        $namespace = $options->getNamespace();
        $fullModel = trim($namespace, '\\').'\\'.$meta->model;
        $model = $meta->model;
        $iface = $model.'RepositoryInterface';
        $variable = lcfirst($model);
        $pk = $meta->primaryKey ?? 'id';
        $pkType = $this->pkPhpType($meta);

        $softDeleteMethods = $meta->softDeletes ? <<<PHP


    public function findTrashed({$pkType} \${$pk}): {$model}
    {
        return {$model}::onlyTrashed()->findOrFail(\${$pk});
    }

    public function paginateTrashed(int \$perPage = 15): LengthAwarePaginator
    {
        return {$model}::onlyTrashed()->latest()->paginate(\$perPage);
    }
PHP
            : '';

        $content = <<<PHP
<?php

namespace App\Repositories;

use {$fullModel};
use App\Repositories\Contracts\\{$iface};
use Illuminate\Pagination\LengthAwarePaginator;

class {$name} implements {$iface}
{
    public function __construct(
        protected readonly {$model} \$model,
    ) {}

    public function paginate(int \$perPage = 15, array \$filters = []): LengthAwarePaginator
    {
        \$query = \$this->model->newQuery();

        // Apply filters — extend this with your filter logic
        foreach (\$filters as \$column => \$value) {
            if (\$value !== null && \$value !== '') {
                \$query->where(\$column, \$value);
            }
        }

        return \$query->latest()->paginate(\$perPage);
    }

    public function findOrFail({$pkType} \${$pk}): {$model}
    {
        return \$this->model->findOrFail(\${$pk});
    }

    public function create(array \$data): {$model}
    {
        return \$this->model->create(\$data);
    }

    public function update({$model} \${$variable}, array \$data): {$model}
    {
        \${$variable}->update(\$data);

        return \${$variable};
    }

    public function delete({$model} \${$variable}): void
    {
        \${$variable}->delete();
    }
{$softDeleteMethods}
}

PHP;

        if (! $options->dryRun) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, $content);
        }

        return ['type' => $this->getName(), 'name' => $name, 'path' => $path, 'status' => 'success'];
    }

    // -----------------------------------------------------------------------
    // RepositoryServiceProvider — created once, bindings appended
    // -----------------------------------------------------------------------

    protected function ensureServiceProvider(ModelMetadata $meta, GenerationOptions $options): void
    {
        if ($options->dryRun) {
            return;
        }

        $path = app_path('Providers/RepositoryServiceProvider.php');

        $model = $meta->model;
        $repoClass = "\\App\\Repositories\\{$model}Repository";
        $ifaceClass = "\\App\\Repositories\\Contracts\\{$model}RepositoryInterface";
        $modelClass = trim($options->getNamespace(), '\\')."\\{$model}";

        $bindingLine = "        \$this->app->bind({$ifaceClass}::class, fn () => new {$repoClass}(new \\{$modelClass}()));";

        if (! file_exists($path)) {
            // Create the provider for the first time
            $content = <<<PHP
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
{$bindingLine}
    }
}

PHP;
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, $content);

            return;
        }

        // Append new binding if not already present
        $existing = file_get_contents($path);
        if (str_contains($existing, "{$model}RepositoryInterface")) {
            return;
        }

        // Insert before the closing brace of register()
        $updated = str_replace(
            "    }\n}",
            "{$bindingLine}\n    }\n}",
            $existing,
        );

        file_put_contents($path, $updated);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    protected function pkPhpType(ModelMetadata $meta): string
    {
        if (empty($meta->primaryKey)) {
            return 'int|string';
        }

        $pk = $meta->primaryKey;
        $col = collect($meta->columns)->firstWhere('name', $pk);

        if (! $col) {
            return 'int|string';
        }

        $type = strtolower(preg_replace('/\(.*\)/', '', $col['type'] ?? ''));

        if (in_array($type, ['uuid', 'char', 'varchar', 'string'])) {
            return 'string';
        }

        return 'int';
    }
}
