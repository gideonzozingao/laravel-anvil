<?php

namespace Zuqongtech\LaravelAnvil\Generators;

use Illuminate\Support\Str;
use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\Helpers;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates a full resource controller for the web scaffold (--web).
 *
 * Placed under App\Http\Controllers\Web\{Model}Controller so it never collides
 * with the JSON ControllerGenerator (App\Http\Controllers) or the versioned API
 * controllers (App\Http\Controllers\Api\V{n}).
 *
 * Unlike the API controller it implements the FULL resource set including the
 * form pages — index, create, store, show, edit, update, destroy — returns Blade
 * views, and redirects with flash messages on writes. Business logic is delegated
 * to the model's Service (shared with the API scaffold); validation uses the same
 * StoreXxx / UpdateXxx FormRequests.
 *
 * restore / forceDelete actions are added for SoftDeletes models.
 */
final class WebControllerGenerator implements Generator
{
    public function supports(GenerationOptions $options): bool
    {
        return $options->web ?? false;
    }

    public function getName(): string
    {
        return 'WebController';
    }

    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $controllerName = $meta->model.'Controller';
        $dir = app_path('Http/Controllers/Web');
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

        $content = $this->build($meta, $options);

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
        ];
    }

    protected function build(ModelMetadata $meta, GenerationOptions $options): string
    {
        $model = $meta->model;
        $service = $model.'Service';
        $storeReq = 'Store'.$model.'Request';
        $updateReq = 'Update'.$model.'Request';
        $var = lcfirst($model);
        $pluralVar = lcfirst(Str::pluralStudly($model));
        $slug = Helpers::modelToRouteName($model);
        $title = Str::headline($model);
        $namespace = trim($options->getNamespace(), '\\');
        $fullModel = $namespace.'\\'.$model;

        $softDeleteMethods = '';
        if ($meta->softDeletes) {
            $softDeleteMethods = <<<PHP


    /**
     * Restore a soft-deleted {$model}.
     */
    public function restore(int|string \$id): RedirectResponse
    {
        \$this->service->restore(\$id);

        return redirect()
            ->route('{$slug}.index')
            ->with('success', '{$title} restored.');
    }

    /**
     * Permanently delete a {$model}.
     */
    public function forceDelete(int|string \$id): RedirectResponse
    {
        \$this->service->forceDelete(\$id);

        return redirect()
            ->route('{$slug}.index')
            ->with('success', '{$title} permanently deleted.');
    }
PHP;
        }

        return <<<PHP
<?php

namespace App\Http\Controllers\Web;

use {$fullModel};
use App\Http\Controllers\Controller;
use App\Http\Requests\\{$storeReq};
use App\Http\Requests\\{$updateReq};
use App\Services\\{$service};
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class {$model}Controller extends Controller
{
    public function __construct(
        protected readonly {$service} \$service,
    ) {}

    /**
     * Display a paginated listing of {$model} records.
     */
    public function index(Request \$request): View
    {
        \${$pluralVar} = \$this->service->paginate(
            (int) \$request->query('per_page', 15),
        );

        return view('{$slug}.index', compact('{$pluralVar}'));
    }

    /**
     * Show the form for creating a new {$model}.
     */
    public function create(): View
    {
        return view('{$slug}.create');
    }

    /**
     * Store a newly created {$model}.
     */
    public function store({$storeReq} \$request): RedirectResponse
    {
        \$this->service->create(\$request->validated());

        return redirect()
            ->route('{$slug}.index')
            ->with('success', '{$title} created.');
    }

    /**
     * Display the specified {$model}.
     */
    public function show(int|string \$id): View
    {
        \${$var} = \$this->service->findOrFail(\$id);

        return view('{$slug}.show', compact('{$var}'));
    }

    /**
     * Show the form for editing the specified {$model}.
     */
    public function edit(int|string \$id): View
    {
        \${$var} = \$this->service->findOrFail(\$id);

        return view('{$slug}.edit', compact('{$var}'));
    }

    /**
     * Update the specified {$model}.
     */
    public function update({$updateReq} \$request, int|string \$id): RedirectResponse
    {
        \$this->service->update(\$id, \$request->validated());

        return redirect()
            ->route('{$slug}.index')
            ->with('success', '{$title} updated.');
    }

    /**
     * Remove the specified {$model}.
     */
    public function destroy(int|string \$id): RedirectResponse
    {
        \$this->service->delete(\$id);

        return redirect()
            ->route('{$slug}.index')
            ->with('success', '{$title} deleted.');
    }{$softDeleteMethods}
}

PHP;
    }
}
