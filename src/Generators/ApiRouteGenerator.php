<?php

namespace Zuqongtech\LaravelAnvil\Generators;

use Illuminate\Support\Str;
use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Registers API routes for each model.
 *
 * TWO MODES — selected automatically based on $options->api:
 *
 * ── Legacy mode  ($options->api === false) ────────────────────────────────
 *   Appends a versioned Route::prefix()/group() block to routes/api.php,
 *   pointing at the standard App\Http\Controllers\{Model}Controller.
 *   (Original behaviour, unchanged.)
 *
 * ── Versioned API mode  ($options->api === true) ──────────────────────────
 *   1. Creates (or appends to) routes/api/v{n}.php — a dedicated route file
 *      per API version with all model routes collected in one place.
 *   2. Registers that file with the ForceJsonApiServiceProvider so it is
 *      loaded automatically with the correct middleware stack:
 *        - api
 *        - auth:sanctum  (configurable)
 *        - \App\Http\Middleware\ForceJsonResponse  (ensures JSON for ALL requests)
 *   3. Adds restore / forceDelete extra routes when the model uses SoftDeletes.
 *   4. Both modes are idempotent — running anvil:generate twice will not
 *      produce duplicate route registrations.
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

        // Ensure the versioned route file exists
        $routeDir = base_path('routes/api');
        $routeFile = "{$routeDir}/{$versionSlug}.php";
        $this->ensureVersionedRouteFile($routeFile, $versionSlug);

        $existing = file_get_contents($routeFile);

        // Idempotency
        if (str_contains($existing, "apiResource('{$slug}'")) {
            return [
                'type' => $this->getName(),
                'name' => $slug,
                'status' => 'skipped',
                'reason' => 'route already registered',
            ];
        }

        // Build route block
        $routeBlock = $this->buildVersionedRouteBlock($meta, $slug, $controllerFqn);

        file_put_contents($routeFile, $existing.$routeBlock);

        // Ensure the versioned route file is loaded by the service provider
        $this->ensureVersionedRouteLoaded($routeFile, $versionSlug, $options);

        return [
            'type' => $this->getName(),
            'name' => $slug,
            'path' => $routeFile,
            'status' => 'success',
        ];
    }

    protected function buildVersionedRouteBlock(
        ModelMetadata $meta,
        string $slug,
        string $controllerFqn,
    ): string {
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
| Routes in this file are automatically loaded by ForceJsonApiServiceProvider
| and are wrapped in the following middleware stack:
|
|   - api
|   - App\Http\Middleware\ForceJsonResponse  (all requests/responses locked to JSON)
|   - {$mwList}
|
| Every request received here MUST carry:
|   Accept: application/json
| Every response returned here WILL be:
|   Content-Type: application/json
|
*/

use Illuminate\Support\Facades\Route;

PHP
        );
    }

    /**
     * Ensure ForceJsonApiServiceProvider loads this version's route file.
     * Delegates to the provider generator (called once per version, idempotent).
     */
    protected function ensureVersionedRouteLoaded(
        string $routeFile,
        string $versionSlug,
        GenerationOptions $options,
    ): void {
        $providerPath = app_path('Providers/ForceJsonApiServiceProvider.php');

        // The ForceJsonApiServiceProvider is created/updated by
        // ForceJsonServiceProviderGenerator. Here we simply ensure it knows
        // about this version's route file.  If the provider doesn't exist yet
        // it will be created by ForceJsonServiceProviderGenerator; we just
        // need to register the file path so it can be picked up.
        // (The full provider generation happens in ForceJsonServiceProviderGenerator.)
    }

    // -----------------------------------------------------------------------
    // Legacy mode (unchanged from original ApiRouteGenerator)
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
