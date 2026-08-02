<?php

namespace Zuqongtech\LaravelAnvil\Generators;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates a resourceful API controller for each model.
 *
 * The generated controller:
 *  - Extends App\Http\Controllers\Controller
 *  - Injects the model's Service class for all business logic
 *  - Uses StoreXxx / UpdateXxx FormRequests for validation
 *  - Returns Xxx Resource responses for consistent API output
 *  - Handles index (paginated), show, store, update, destroy
 *  - Adds restore / forceDelete when the model uses SoftDeletes
 */
final class ControllerGenerator implements Generator
{
    #[\Override]
    public function supports(GenerationOptions $options): bool
    {
        return $options->controllers ?? false;
    }

    #[\Override]
    public function getName(): string
    {
        return 'Controller';
    }

    #[\Override]
    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $controllerName = $meta->model.'Controller';
        $path = app_path("Http/Controllers/{$controllerName}.php");

        if (file_exists($path) && ! $options->force) {
            return [
                'type' => $this->getName(),
                'name' => $controllerName,
                'path' => $path,
                'status' => 'skipped',
                'reason' => 'already exists',
            ];
        }

        $content = $this->buildController($meta, $options);

        if (! $options->dryRun) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, $content);
        }

        return [
            'type' => $this->getName(),
            'name' => $controllerName,
            'path' => $path,
            'status' => 'success',
            'action' => file_exists($path) ? 'overwritten' : 'created',
        ];
    }

    protected function buildController(ModelMetadata $meta, GenerationOptions $options): string
    {
        $model = $meta->model;
        $controller = $model.'Controller';
        $service = $model.'Service';
        $resource = $model.'Resource';
        $storeReq = 'Store'.$model.'Request';
        $updateReq = 'Update'.$model.'Request';
        $variable = lcfirst($model);
        $namespace = trim($options->getNamespace(), '\\');
        $fullModel = $namespace.'\\'.$model;

        $softDeleteMethods = '';
        if ($meta->softDeletes) {

            $softDeleteMethods = <<<PHP


        /**
         * Restore a soft-deleted {$model}.
         */
        public function restore(int|string \$id): JsonResponse
        {
            \${$variable} = \$this->service->restore(\$id);

            return response()->json(new {$resource}(\${$variable}));
        }

        /**
         * Permanently delete a {$model}.
         */
        public function forceDelete(int|string \$id): JsonResponse
        {
            \$this->service->forceDelete(\$id);

            return response()->json(null, 204);
        }
        PHP;
        }

        return <<<PHP
            <?php

            namespace App\Http\Controllers;

            use {$fullModel};
            use App\Http\Requests\\{$storeReq};
            use App\Http\Requests\\{$updateReq};
            use App\Http\Resources\\{$resource};
            use App\Services\\{$service};
            use Illuminate\Http\JsonResponse;
            use Illuminate\Http\Request;
            use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

            class {$controller} extends Controller
            {
                public function __construct(
                    protected readonly {$service} \$service,
                ) {}

                /**
                 * Display a paginated listing of {$model} records.
                 */
                public function index(Request \$request): AnonymousResourceCollection
                {
                    \$perPage = (int) \$request->query('per_page', 15);
                    \$filters = \$request->only([]);  // add filterable fields here

                    \$paginator = \$this->service->paginate(\$perPage, \$filters);

                    return {$resource}::collection(\$paginator);
                }

                /**
                 * Display the specified {$model}.
                 */
                public function show(int|string \$id): JsonResponse
                {
                    \${$variable} = \$this->service->findOrFail(\$id);

                    return response()->json(new {$resource}(\${$variable}));
                }

                /**
                 * Store a newly created {$model}.
                 */
                public function store({$storeReq} \$request): JsonResponse
                {
                    \${$variable} = \$this->service->create(\$request->validated());

                    return response()->json(new {$resource}(\${$variable}), 201);
                }

                /**
                 * Update the specified {$model}.
                 */
                public function update({$updateReq} \$request, int|string \$id): JsonResponse
                {
                    \${$variable} = \$this->service->update(\$id, \$request->validated());

                    return response()->json(new {$resource}(\${$variable}));
                }

                /**
                 * Remove the specified {$model}.
                 */
                public function destroy(int|string \$id): JsonResponse
                {
                    \$this->service->delete(\$id);

                    return response()->json(null, 204);
                }{$softDeleteMethods}
            }
    PHP;
    }
}
