<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Generators;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Generators\Concerns\WritesVersionedFiles;
use Zuqongtech\LaravelAnvil\Support\ApiVersionProfile;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates a versioned API controller per model:
 *
 *   App\Http\Controllers\Api\V1\ApiController      (base, once per version)
 *   App\Http\Controllers\Api\V1\UserController
 *
 * Wired to the version-scoped classes rather than global ones:
 *
 *   App\Http\Requests\Api\V1\User\{Index,Store,Update}Request
 *   App\Http\Resources\Api\V1\{UserResource, UserCollection}
 *   App\Services\User Service                     (shared across versions)
 *   App\Services\Api\V1\UserService               (when versioned_services=true)
 *
 * Envelope helpers live on the base controller, so the shape is defined once
 * instead of being pasted into every action.
 */
final class ApiControllerGenerator implements Generator
{
    use WritesVersionedFiles;

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
        $profile = $this->profile($options);

        return [
            $this->writeClass(
                $this->getName().'Base',
                $profile->controllerNamespace(),
                'ApiController',
                $options,
                fn (): string => $this->buildBaseController($profile),
                overwritable: false,
            ),
            $this->writeClass(
                $this->getName(),
                $profile->controllerNamespace(),
                $meta->model.'Controller',
                $options,
                fn (): string => $this->buildModelController($meta, $profile, $options),
            ),
        ];
    }

    // -----------------------------------------------------------------------
    // Base controller
    // -----------------------------------------------------------------------

    protected function buildBaseController(ApiVersionProfile $profile): string
    {
        $namespace = $profile->controllerNamespace();
        $version = $profile->version;

        return <<<PHP
<?php

namespace {$namespace};

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Base controller for {$version} API endpoints.
 *
 * Every response is JSON because ForceJsonApiServiceProvider sets
 * Accept: application/json for this route group.
 *
 * The envelope helpers below are real methods, not documentation: the previous
 * generation described \$this->success() in a docblock while every action built
 * the array inline, so changing the envelope meant editing every controller.
 */
abstract class ApiController extends Controller
{
    use AuthorizesRequests;
    use ValidatesRequests;

    /** Surfaced in every response envelope. */
    protected string \$apiVersion = '{$version}';

    /**
     * @param  mixed  \$data
     */
    protected function success(\$data = null, ?string \$message = null, int \$status = 200): JsonResponse
    {
        return response()->json(array_filter([
            'success' => true,
            'version' => \$this->apiVersion,
            'message' => \$message,
            'data' => \$data,
        ], static fn (\$value, \$key): bool => \$key === 'data' || \$value !== null, ARRAY_FILTER_USE_BOTH), \$status);
    }

    /**
     * @param  array<string, mixed>  \$errors
     */
    protected function error(string \$message, int \$status = 400, array \$errors = []): JsonResponse
    {
        return response()->json(array_filter([
            'success' => false,
            'version' => \$this->apiVersion,
            'message' => \$message,
            'errors' => \$errors ?: null,
        ], static fn (\$value): bool => \$value !== null), \$status);
    }

    /**
     * 204 responses carry NO body. Sending one is a protocol violation that
     * some HTTP clients reject outright.
     */
    protected function deleted(): JsonResponse
    {
        return response()->json(null, 204);
    }
}

PHP;
    }

    // -----------------------------------------------------------------------
    // Model controller
    // -----------------------------------------------------------------------

    protected function buildModelController(ModelMetadata $meta, ApiVersionProfile $profile, GenerationOptions $options): string
    {
        $model = $meta->model;
        $variable = lcfirst($model);
        $namespace = $profile->controllerNamespace();

        $requestNamespace = $profile->requestNamespace($model);
        $indexRequest = $profile->requestClass($model, 'index');
        $storeRequest = $profile->requestClass($model, 'store');
        $updateRequest = $profile->requestClass($model, 'update');

        $resourceNamespace = $profile->resourceNamespace();
        $resource = $profile->resourceClass($model);
        $collection = $profile->collectionClass($model);

        [$serviceFqn, $service] = $this->serviceFor($model, $profile);

        $imports = array_unique([
            "use {$requestNamespace}\\{$indexRequest};",
            "use {$requestNamespace}\\{$storeRequest};",
            "use {$requestNamespace}\\{$updateRequest};",
            "use {$resourceNamespace}\\{$resource};",
            "use {$resourceNamespace}\\{$collection};",
            "use {$serviceFqn};",
            'use Illuminate\Http\JsonResponse;',
        ]);

        sort($imports);
        $importBlock = implode("\n", $imports);

        $softDeletes = $meta->softDeletes
            ? $this->buildSoftDeleteActions($model, $variable, $resource)
            : '';

        return <<<PHP
<?php

namespace {$namespace};

{$importBlock}

class {$model}Controller extends ApiController
{
    public function __construct(
        protected readonly {$service} \$service,
    ) {}

    /**
     * GET — paginated {$model} listing.
     *
     * Page size comes from the request object, which clamps it to this version's
     * maximum; an unclamped ?per_page= is a denial-of-service invitation.
     */
    public function index({$indexRequest} \$request): {$collection}
    {
        return new {$collection}(
            \$this->service->paginate(\$request->perPage())
        );
    }

    /**
     * GET — a single {$model}.
     */
    public function show(int|string \$id): JsonResponse
    {
        return \$this->success(
            new {$resource}(\$this->service->findOrFail(\$id))
        );
    }

    /**
     * POST — create a {$model}.
     */
    public function store({$storeRequest} \$request): JsonResponse
    {
        \${$variable} = \$this->service->create(\$request->validated());

        return \$this->success(new {$resource}(\${$variable}), '{$model} created.', 201);
    }

    /**
     * PUT/PATCH — update a {$model}.
     */
    public function update({$updateRequest} \$request, int|string \$id): JsonResponse
    {
        \${$variable} = \$this->service->update(\$id, \$request->validated());

        return \$this->success(new {$resource}(\${$variable}), '{$model} updated.');
    }

    /**
     * DELETE — remove a {$model}.
     */
    public function destroy(int|string \$id): JsonResponse
    {
        \$this->service->delete(\$id);

        return \$this->deleted();
    }{$softDeletes}
}

PHP;
    }

    protected function buildSoftDeleteActions(string $model, string $variable, string $resource): string
    {
        return <<<PHP


    /**
     * PATCH — restore a soft-deleted {$model}.
     */
    public function restore(int|string \$id): JsonResponse
    {
        \${$variable} = \$this->service->restore(\$id);

        return \$this->success(new {$resource}(\${$variable}), '{$model} restored.');
    }

    /**
     * DELETE — permanently remove a {$model}.
     */
    public function forceDelete(int|string \$id): JsonResponse
    {
        \$this->service->forceDelete(\$id);

        return \$this->deleted();
    }
PHP;
    }

    /**
     * Business logic is shared across versions by default: a per-version copy of
     * a service duplicates the logic the service layer exists to centralise.
     * When anvil.api.versions.{v}.versioned_services is true, a thin SUBCLASS is
     * generated instead, so a version can override behaviour without forking it.
     *
     * @return array{0: string, 1: string} [fqcn, short name]
     */
    protected function serviceFor(string $model, ApiVersionProfile $profile): array
    {
        $service = $model.'Service';

        if (! (bool) $profile->get('versioned_services', false)) {
            return ['App\\Services\\'.$service, $service];
        }

        $namespace = trim((string) $profile->get('namespaces.services', 'App\\Services\\Api'), '\\')
            .'\\'.$profile->segment();

        return [$namespace.'\\'.$service, $service];
    }
}
