<?php

namespace Zuqongtech\LaravelAnvil\Generators;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates a versioned API controller for each model.
 *
 * Unlike the generic ControllerGenerator (which targets App\Http\Controllers\),
 * this generator places controllers under:
 *
 *   App\Http\Controllers\Api\V{n}\{Model}Controller
 *
 * The generated controller:
 *  - Extends the versioned base ApiController (also generated once per version)
 *  - Injects the model's Service class for all business logic
 *  - Uses StoreXxx / UpdateXxx FormRequests for validation
 *  - Returns XxxResource responses — every response is already JSON because
 *    the ForceJsonServiceProvider (generated separately) sets the Accept header
 *  - Handles index (paginated), show, store, update, destroy
 *  - Adds restore / forceDelete when the model uses SoftDeletes
 *  - All responses are wrapped in a consistent envelope via ApiResponse helper
 *
 * This generator is activated only when $options->api === true.
 */
final class ApiControllerGenerator implements Generator
{
    #[\Override]
    public function supports(GenerationOptions $options): bool
    {
        return $options->api;
    }

    #[\Override]
    public function getName(): string
    {
        return 'ApiController';
    }

    #[\Override]
    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $results = [];

        // Ensure the versioned base controller exists (idempotent)
        $results[] = $this->ensureBaseController($options);

        // Generate the model-specific controller
        $results[] = $this->generateModelController($meta, $options);

        return $results;
    }

    // -----------------------------------------------------------------------
    // Base controller (generated once per version)
    // -----------------------------------------------------------------------

    protected function ensureBaseController(GenerationOptions $options): array
    {
        $versionString = $options->getApiVersionString();
        $dir = app_path("Http/Controllers/Api/{$versionString}");
        $path = "{$dir}/ApiController.php";

        if (file_exists($path)) {
            return [
                'type' => $this->getName().'Base',
                'name' => "Api\\{$versionString}\\ApiController",
                'path' => $path,
                'status' => 'skipped',
                'reason' => 'already exists',
            ];
        }

        $content = $this->buildBaseController($options);

        if (! $options->dryRun) {
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, $content);
        }

        return [
            'type' => $this->getName().'Base',
            'name' => "Api\\{$versionString}\\ApiController",
            'path' => $path,
            'status' => 'success',
            'action' => 'created',
        ];
    }

    protected function buildBaseController(GenerationOptions $options): string
    {
        $versionString = $options->getApiVersionString();
        $versionSlug = $options->getApiVersionSlug();

        return <<<PHP
<?php

namespace App\Http\Controllers\Api\\{$versionString};

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller;

/**
 * Base controller for all {$versionString} API endpoints.
 *
 * Every response flowing through this controller is guaranteed to be JSON
 * because ForceJsonServiceProvider sets Accept: application/json globally.
 *
 * Use the ApiResponse trait's helpers for consistent envelope formatting:
 *   \$this->success(\$data, \$message, \$status)
 *   \$this->error(\$message, \$status, \$errors)
 *   \$this->paginated(\$paginator, ResourceClass::class)
 */
abstract class ApiController extends Controller
{
    use AuthorizesRequests;
    use ValidatesRequests;

    /** API version surfaced in every response envelope. */
    protected string \$apiVersion = '{$versionSlug}';
}

PHP;
    }

    // -----------------------------------------------------------------------
    // Model-specific controller
    // -----------------------------------------------------------------------

    protected function generateModelController(ModelMetadata $meta, GenerationOptions $options): array
    {
        $versionString = $options->getApiVersionString();
        $controllerName = $meta->model.'Controller';
        $dir = app_path("Http/Controllers/Api/{$versionString}");
        $path = "{$dir}/{$controllerName}.php";

        if (file_exists($path) && ! $options->force) {
            return [
                'type' => $this->getName(),
                'name' => $controllerName,
                'path' => $path,
                'status' => 'skipped',
                'reason' => 'already exists',
            ];
        }

        $content = $this->buildModelController($meta, $options);

        if (! $options->dryRun) {
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

    protected function buildModelController(ModelMetadata $meta, GenerationOptions $options): string
    {
        $model = $meta->model;
        $versionString = $options->getApiVersionString();
        $modelNamespace = trim($options->getNamespace(), '\\');
        $fullModel = $modelNamespace.'\\'.$model;
        $variable = lcfirst($model);
        $service = $model.'Service';
        $resource = $model.'Resource';
        $storeReq = 'Store'.$model.'Request';
        $updateReq = 'Update'.$model.'Request';

        $softDeleteMethods = '';
        if ($meta->softDeletes) {
            $softDeleteMethods = <<<PHP


    /**
     * Restore a soft-deleted {$model}.
     *
     * PATCH {$variable}s/{id}/restore
     */
    public function restore(int|string \$id): JsonResponse
    {
        \${$variable} = \$this->service->restore(\$id);

        return response()->json([
            'success' => true,
            'version' => \$this->apiVersion,
            'data'    => new {$resource}(\${$variable}),
        ]);
    }

    /**
     * Permanently delete a {$model}.
     *
     * DELETE {$variable}s/{id}/force
     */
    public function forceDelete(int|string \$id): JsonResponse
    {
        \$this->service->forceDelete(\$id);

        return response()->json([
            'success' => true,
            'version' => \$this->apiVersion,
            'data'    => null,
        ], 204);
    }
PHP;
        }

        return <<<PHP
<?php

namespace App\Http\Controllers\Api\\{$versionString};

use {$fullModel};
use App\Http\Requests\\{$storeReq};
use App\Http\Requests\\{$updateReq};
use App\Http\Resources\\{$resource};
use App\Services\\{$service};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class {$model}Controller extends ApiController
{
    public function __construct(
        protected readonly {$service} \$service,
    ) {}

    /**
     * Return a paginated JSON listing of {$model} records.
     *
     * GET {$variable}s
     */
    public function index(Request \$request): AnonymousResourceCollection
    {
        \$perPage = (int) \$request->query('per_page', 15);
        \$filters = \$request->only([]);  // add filterable fields here

        \$paginator = \$this->service->paginate(\$perPage, \$filters);

        return {$resource}::collection(\$paginator);
    }

    /**
     * Return a single {$model} as JSON.
     *
     * GET {$variable}s/{id}
     */
    public function show(int|string \$id): JsonResponse
    {
        \${$variable} = \$this->service->findOrFail(\$id);

        return response()->json([
            'success' => true,
            'version' => \$this->apiVersion,
            'data'    => new {$resource}(\${$variable}),
        ]);
    }

    /**
     * Store a newly created {$model} and return it as JSON.
     *
     * POST {$variable}s
     */
    public function store({$storeReq} \$request): JsonResponse
    {
        \${$variable} = \$this->service->create(\$request->validated());

        return response()->json([
            'success' => true,
            'version' => \$this->apiVersion,
            'data'    => new {$resource}(\${$variable}),
        ], 201);
    }

    /**
     * Update a {$model} and return the updated resource as JSON.
     *
     * PUT/PATCH {$variable}s/{id}
     */
    public function update({$updateReq} \$request, int|string \$id): JsonResponse
    {
        \${$variable} = \$this->service->update(\$id, \$request->validated());

        return response()->json([
            'success' => true,
            'version' => \$this->apiVersion,
            'data'    => new {$resource}(\${$variable}),
        ]);
    }

    /**
     * Delete a {$model} and return a 204 JSON response.
     *
     * DELETE {$variable}s/{id}
     */
    public function destroy(int|string \$id): JsonResponse
    {
        \$this->service->delete(\$id);

        return response()->json([
            'success' => true,
            'version' => \$this->apiVersion,
            'data'    => null,
        ], 204);
    }
{$softDeleteMethods}
}

PHP;
    }
}
