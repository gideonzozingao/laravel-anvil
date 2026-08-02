<?php

namespace Zuqongtech\LaravelAnvil\Generators;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates an Eloquent Observer class for each model.
 *
 * The generated observer:
 *  - Covers the standard Eloquent event hooks:
 *      creating, created, updating, updated,
 *      saving, saved, deleting, deleted
 *  - Adds restoring / restored hooks when the model uses SoftDeletes
 *  - Adds forceDeleting / forceDeleted hooks when the model uses SoftDeletes
 *  - Each method body is left as a documented stub so developers can
 *    drop in their logic without boilerplate hunting
 *  - Includes a registration reminder comment at the top of the file
 *
 * Auto-registration:
 *  When config('anvil.generators.observers.auto_register') is true the
 *  generator appends an observer binding to App\Providers\AppServiceProvider
 *  (or EventServiceProvider if present) inside its boot() method.
 *  The binding is idempotent — re-running the command will not duplicate it.
 *
 * Example generated file:
 *
 *   class PostObserver
 *   {
 *       public function created(Post $post): void { }
 *       public function updated(Post $post): void { }
 *       // …
 *   }
 */
final class ObserverGenerator implements Generator
{
    #[\Override]
    public function supports(GenerationOptions $options): bool
    {
        return $options->observers ?? false;
    }

    #[\Override]
    public function getName(): string
    {
        return 'Observer';
    }

    #[\Override]
    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $observerName = $meta->model.'Observer';
        $path = app_path("Observers/{$observerName}.php");

        if (file_exists($path) && ! $options->force) {
            return [
                'type' => $this->getName(),
                'name' => $observerName,
                'path' => $path,
                'status' => 'skipped',
                'reason' => 'already exists',
            ];
        }

        $namespace = $options->getNamespace();
        $content = $this->buildObserver($meta, $namespace);

        if (! $options->dryRun) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, $content);

            if (config('anvil.generators.observers.auto_register', false)) {
                $this->registerObserver($meta, $namespace);
            }
        }

        return [
            'type' => $this->getName(),
            'name' => $observerName,
            'path' => $path,
            'status' => 'success',
            'action' => file_exists($path) ? 'overwritten' : 'created',
        ];
    }

    // -----------------------------------------------------------------------
    // Builder
    // -----------------------------------------------------------------------

    protected function buildObserver(ModelMetadata $meta, string $namespace): string
    {
        $model = $meta->model;
        $observerName = $model.'Observer';
        $fullModel = trim($namespace, '\\').'\\'.$model;
        $variable = lcfirst($model);

        $softDeleteMethods = '';
        if ($meta->softDeletes) {
            $softDeleteMethods = $this->buildSoftDeleteMethods($model, $variable);
        }

        config(
            'anvil.generators.observers.include_soft_delete_events',
            true
        );

        return <<<PHP
                <?php

                namespace App\Observers;

                use {$fullModel};

                /**
                 * Observer for the {$model} model.
                 *
                 * Register this observer in a service provider:
                 *
                 *   {$model}::observe({$observerName}::class);
                 *
                 * Or set config('anvil.generators.observers.auto_register') = true to let
                 * Laravel Anvil append the registration to AppServiceProvider automatically.
                 */
                class {$observerName}
                {
                    /**
                     * Handle the {$model} "creating" event.
                     *
                     * Fired before the model is first saved. Return false to abort.
                     */
                    public function creating({$model} \${$variable}): void
                    {
                        //
                    }

                    /**
                     * Handle the {$model} "created" event.
                     *
                     * Fired after the model is first saved.
                     */
                    public function created({$model} \${$variable}): void
                    {
                        //
                    }

                    /**
                     * Handle the {$model} "updating" event.
                     *
                     * Fired before an existing model is saved. Return false to abort.
                     */
                    public function updating({$model} \${$variable}): void
                    {
                        //
                    }

                    /**
                     * Handle the {$model} "updated" event.
                     *
                     * Fired after an existing model is saved.
                     */
                    public function updated({$model} \${$variable}): void
                    {
                        //
                    }

                    /**
                     * Handle the {$model} "saving" event.
                     *
                     * Fired before any save (create or update). Return false to abort.
                     */
                    public function saving({$model} \${$variable}): void
                    {
                        //
                    }

                    /**
                     * Handle the {$model} "saved" event.
                     *
                     * Fired after any save (create or update).
                     */
                    public function saved({$model} \${$variable}): void
                    {
                        //
                    }

                    /**
                     * Handle the {$model} "deleting" event.
                     *
                     * Fired before the model is deleted. Return false to abort.
                     */
                    public function deleting({$model} \${$variable}): void
                    {
                        //
                    }

                    /**
                     * Handle the {$model} "deleted" event.
                     *
                     * Fired after the model is deleted.
                     */
                    public function deleted({$model} \${$variable}): void
                    {
                        //
                    }
                        {$softDeleteMethods}
                }

        PHP;
    }

    /**
     * Build the extra soft-delete lifecycle methods.
     */
    protected function buildSoftDeleteMethods(string $model, string $variable): string
    {
        return <<<PHP
        
                    /**
                     * Handle the {$model} "restoring" event.
                     *
                     * Fired before a soft-deleted model is restored. Return false to abort.
                     */
                    public function restoring({$model} \${$variable}): void
                    {
                        //
                    }

                    /**
                     * Handle the {$model} "restored" event.
                     *
                     * Fired after a soft-deleted model is restored.
                     */
                    public function restored({$model} \${$variable}): void
                    {
                        //
                    }

                    /**
                     * Handle the {$model} "forceDeleting" event.
                     *
                     * Fired before a model is permanently deleted. Return false to abort.
                     */
                    public function forceDeleting({$model} \${$variable}): void
                    {
                        //
                    }

                    /**
                     * Handle the {$model} "forceDeleted" event.
                     *
                     * Fired after a model is permanently deleted.
                     */
                    public function forceDeleted({$model} \${$variable}): void
                    {
                        //
                    }
                PHP;
    }

    // -----------------------------------------------------------------------
    // Auto-registration
    // -----------------------------------------------------------------------

    /**
     * Append `Model::observe(ModelObserver::class)` into AppServiceProvider::boot().
     * Falls back to EventServiceProvider when AppServiceProvider is absent.
     * The insertion is idempotent.
     */
    protected function registerObserver(ModelMetadata $meta, string $namespace): void
    {
        $providers = [
            app_path('Providers/AppServiceProvider.php'),
            app_path('Providers/EventServiceProvider.php'),
        ];

        $providerPath = null;
        foreach ($providers as $candidate) {
            if (file_exists($candidate)) {
                $providerPath = $candidate;
                break;
            }
        }

        if ($providerPath === null) {
            return;
        }

        $model = $meta->model;
        $observerFqn = "\\App\\Observers\\{$model}Observer";
        $fullModel = '\\'.trim($namespace, '\\').'\\'.$model;
        $bindLine = "        {$fullModel}::observe({$observerFqn}::class);";

        $content = file_get_contents($providerPath);

        // Idempotency — skip if already registered
        if (str_contains($content, "{$model}::observe")) {
            return;
        }

        // Ensure the model's namespace is imported
        $modelFqn = trim($namespace, '\\').'\\'.$model;
        if (! str_contains($content, "use {$modelFqn};")) {
            $content = preg_replace(
                '/^(namespace\s+[^;]+;)/m',
                "$1\n\nuse {$modelFqn};\nuse App\\Observers\\{$model}Observer;",
                $content,
                1,
            );
        }

        // Inject inside boot() before its closing brace
        if (preg_match('/public function boot\(\)[^{]*{/s', $content, $match, PREG_OFFSET_CAPTURE)) {
            $insertPos = $this->findMethodEnd($content, $match[0][1] + strlen($match[0][0]));
            if ($insertPos !== false) {
                $content = substr_replace($content, "\n{$bindLine}\n", $insertPos, 0);
            }
        }

        file_put_contents($providerPath, $content);
    }

    /**
     * Walk the source string from $offset to find the matching closing brace,
     * and return the position just before it for insertion.
     */
    protected function findMethodEnd(string $source, int $offset): int|false
    {
        $depth = 1;
        $len = strlen($source);

        for ($i = $offset; $i < $len; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return false;
    }
}
