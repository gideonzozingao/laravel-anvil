<?php

namespace Zuqongtech\LaravelAnvil\Generators;

use Illuminate\Support\Str;
use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;
use Zuqongtech\LaravelAnvil\Support\ProviderRegistrar;

/**
 * Registers API routes for each model.
 *
 * ── Legacy mode  ($options->api === false) ────────────────────────────────
 *   Appends a versioned Route::prefix()/group() block to routes/api.php.
 *
 * ── Versioned API mode  ($options->api === true) ──────────────────────────
 *   1. Creates / appends to routes/api/v{n}.php.
 *   2. Ensures that file is wired into ForceJsonApiServiceProvider's $versions
 *      map (idempotent) AND that the provider is registered in
 *      bootstrap/providers.php — so the routes are actually live without any
 *      manual step. ForceJsonServiceProviderGenerator creates the provider
 *      itself; this generator only guarantees the connection, making the two
 *      generators safe to run in any order.
 *   3. Adds restore / forceDelete routes when the model uses SoftDeletes.
 *
 * Both modes are idempotent.
 */
final class ApiRouteGenerator implements Generator
{
    public function supports(GenerationOptions $options): bool
    {
        return $options->apiRoutes || $options->api;
    }

    public function getName(): string
    {
        return 'ApiRoute';
    }

    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        return $options->api
            ? $this->generateVersioned($meta, $options)
            : $this->generateLegacy($meta, $options);
    }

    // -----------------------------------------------------------------------
    // Versioned mode
    // -----------------------------------------------------------------------

    protected function generateVersioned(ModelMetadata $meta, GenerationOptions $options): array
    {
        $slug = Str::plural(Str::kebab($meta->model));
        $versionString = $options->getApiVersionString();
        $versionSlug = $options->getApiVersionSlug();
        $controllerFqn = "\\App\\Http\\Controllers\\Api\\{$versionString}\\{$meta->model}Controller";

        if ($options->dryRun) {
            return [
                'type' => $this->getName(),
                'name' => "Route::apiResource('{$slug}', ...) [{$versionSlug}]",
                'status' => 'dry-run',
            ];
        }

        $routeDir = base_path('routes/api');
        $routeFile = "{$routeDir}/{$versionSlug}.php";
        $this->ensureVersionedRouteFile($routeFile, $versionSlug);

        $existing = file_get_contents($routeFile);

        if (str_contains($existing, "apiResource('{$slug}'")) {
            // Even when the resource block is already present, make sure the
            // route file is connected to the app (cheap, idempotent).
            $this->ensureVersionedRouteLoaded($versionSlug, $options);

            return [
                'type' => $this->getName(),
                'name' => $slug,
                'status' => 'skipped',
                'reason' => 'route already registered',
            ];
        }

        $routeBlock = $this->buildVersionedRouteBlock($meta, $slug, $controllerFqn);
        file_put_contents($routeFile, $existing.$routeBlock);

        $connection = $this->ensureVersionedRouteLoaded($versionSlug, $options);

        return [
            'type' => $this->getName(),
            'name' => $slug,
            'path' => $routeFile,
            'status' => 'success',
            'connection' => $connection,
        ];
    }

    protected function buildVersionedRouteBlock(ModelMetadata $meta, string $slug, string $controllerFqn): string
    {
        $softDeleteRoutes = '';
        if ($meta->softDeletes) {
            $softDeleteRoutes = <<<PHP

    // Soft-delete lifecycle routes for {$meta->model}
    Route::patch('{$slug}/{id}/restore', [{$controllerFqn}::class, 'restore'])
        ->name('{$slug}.restore');
    Route::delete('{$slug}/{id}/force', [{$controllerFqn}::class, 'forceDelete'])
        ->name('{$slug}.forceDelete');
PHP;
        }

        return <<<PHP


// {$meta->model} resource routes
Route::apiResource('{$slug}', {$controllerFqn}::class);
{$softDeleteRoutes}
PHP;
    }

    /**
     * Create routes/api/v{n}.php with the standard header if it doesn't exist.
     */
    protected function ensureVersionedRouteFile(string $path, string $versionSlug): void
    {
        if (file_exists($path)) {
            return;
        }

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $middleware = config('anvil.api_middleware', ['auth:sanctum']);
        $mwList = implode("', '", $middleware);

        file_put_contents($path, <<<PHP
<?php

/*
|--------------------------------------------------------------------------
| API Routes — {$versionSlug}
|--------------------------------------------------------------------------
|
| Loaded automatically by ForceJsonApiServiceProvider and wrapped in:
|   - api
|   - App\Http\Middleware\ForceJsonResponse  (all I/O locked to JSON)
|   - {$mwList}
|
*/

use Illuminate\Support\Facades\Route;

PHP
        );
    }

    /**
     * Guarantee the versioned route file is connected to the application:
     *   (a) registered in ForceJsonApiServiceProvider::$versions, and
     *   (b) the provider itself registered in bootstrap/providers.php.
     *
     * Both steps are idempotent and complement ForceJsonServiceProviderGenerator.
     *
     * @return array<string, mixed>
     */
    protected function ensureVersionedRouteLoaded(string $versionSlug, GenerationOptions $options): array
    {
        $result = ['version_map' => 'pending', 'bootstrap' => 'pending'];

        $providerPath = app_path('Providers/ForceJsonApiServiceProvider.php');

        // (a) Ensure the $versions map contains this slug — only possible once the
        // provider exists. When it doesn't yet, ForceJsonServiceProviderGenerator
        // will create it (with this version already present) later in the run.
        if (file_exists($providerPath) && ! $options->dryRun) {
            $content = file_get_contents($providerPath);

            if (! str_contains($content, "'{$versionSlug}' =>")) {
                $entry = "        '{$versionSlug}' => 'routes/api/{$versionSlug}.php',";
                $content = str_replace(
                    '// anvil:managed — do not remove this comment',
                    "// anvil:managed — do not remove this comment\n{$entry}",
                    $content,
                );
                file_put_contents($providerPath, $content);
            }

            $result['version_map'] = 'linked';
        } else {
            $result['version_map'] = 'deferred-to-provider-generator';
        }

        // (b) Ensure the provider is registered in the app bootstrap.
        $registrar = new ProviderRegistrar($options->dryRun);
        $outcome = $registrar->registerProvider('App\\Providers\\ForceJsonApiServiceProvider');
        $result['bootstrap'] = $outcome['status'];

        return $result;
    }

    // -----------------------------------------------------------------------
    // Legacy mode
    // -----------------------------------------------------------------------

    protected function generateLegacy(ModelMetadata $meta, GenerationOptions $options): array
    {
        $slug = Str::plural(Str::kebab($meta->model));
        $controllerFqn = "\\App\\Http\\Controllers\\{$meta->model}Controller";

        if ($options->dryRun) {
            return [
                'type' => $this->getName(),
                'name' => "Route::apiResource('{$slug}', ...) [legacy]",
                'status' => 'dry-run',
            ];
        }

        $routeFile = base_path('routes/api.php');
        $this->ensureLegacyRouteFile($routeFile);

        $existing = file_get_contents($routeFile);

        if (str_contains($existing, "apiResource('{$slug}'")) {
            return [
                'type' => $this->getName(),
                'name' => $slug,
                'status' => 'skipped',
                'reason' => 'route already registered',
            ];
        }

        $version = config('anvil.api_version', 'v1');
        $middleware = config('anvil.api_middleware', ['auth:sanctum']);
        $mwStr = "['".implode("', '", $middleware)."']";

        $block = <<<PHP


Route::prefix('{$version}')->middleware({$mwStr})->group(function () {
    Route::apiResource('{$slug}', {$controllerFqn}::class)
        ->names('api.{$version}.{$slug}');
});
PHP;

        file_put_contents($routeFile, $existing.$block);

        return [
            'type' => $this->getName(),
            'name' => $slug,
            'path' => $routeFile,
            'status' => 'success',
        ];
    }

    protected function ensureLegacyRouteFile(string $path): void
    {
        if (file_exists($path)) {
            return;
        }

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, <<<'PHP'
<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

PHP
        );
    }
}
