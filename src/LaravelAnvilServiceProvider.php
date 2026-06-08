<?php

namespace Zuqongtech\LaravelAnvil;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Zuqongtech\LaravelAnvil\Console\DocsCommand;
use Zuqongtech\LaravelAnvil\Console\GenerateModelsFromDatabase;
use Zuqongtech\LaravelAnvil\Generators\ApiControllerGenerator;
use Zuqongtech\LaravelAnvil\Generators\ApiRouteGenerator;
use Zuqongtech\LaravelAnvil\Generators\ControllerGenerator;
use Zuqongtech\LaravelAnvil\Generators\EventGenerator;
use Zuqongtech\LaravelAnvil\Generators\FactoryGenerator;
use Zuqongtech\LaravelAnvil\Generators\ForceJsonServiceProviderGenerator;
use Zuqongtech\LaravelAnvil\Generators\FormRequestGenerator;
use Zuqongtech\LaravelAnvil\Generators\GateGenerator;
use Zuqongtech\LaravelAnvil\Generators\MigrationGenerator;
use Zuqongtech\LaravelAnvil\Generators\ObserverGenerator;
use Zuqongtech\LaravelAnvil\Generators\OpenApi\OpenApiPathGenerator;
use Zuqongtech\LaravelAnvil\Generators\OpenApi\OpenApiRootGenerator;
use Zuqongtech\LaravelAnvil\Generators\OpenApi\OpenApiSchemaGenerator;
use Zuqongtech\LaravelAnvil\Generators\PolicyGenerator;
use Zuqongtech\LaravelAnvil\Generators\RepositoryGenerator;
use Zuqongtech\LaravelAnvil\Generators\ResourceGenerator;
use Zuqongtech\LaravelAnvil\Generators\SeederGenerator;
use Zuqongtech\LaravelAnvil\Generators\ServiceGenerator;
use Zuqongtech\LaravelAnvil\Generators\TestGenerator;
use Zuqongtech\LaravelAnvil\Http\DocsController;
use Zuqongtech\LaravelAnvil\Support\GenerationOrchestrator;

class LaravelAnvilServiceProvider extends ServiceProvider
{
    public function register(): void
    {

        /***********************************************************************
         The codebase reads config under two historical keys: older generators
        use config('anvil.*'), while the OpenAPI generators, GenerationOptions,
        and the console command use config('laravel-anvil.*'). Merging the same
        file under BOTH keys makes every lookup resolve correctly without having
         to touch dozens of call sites.
         * ***********************************************************************/
        $this->mergeConfigFrom(__DIR__.'/../config/anvil.php', 'anvil');
        $this->mergeConfigFrom(__DIR__.'/../config/anvil.php', 'laravel-anvil');

        // ── Generator pipeline (order is authoritative) ──────────────────────
        $generators = [
            // Core per-model artifacts
            ControllerGenerator::class,         // --controllers (non-API)
            ResourceGenerator::class,           // --resources
            ObserverGenerator::class,           // --observers
            PolicyGenerator::class,             // --policies
            FormRequestGenerator::class,        // --form-requests / --api
            ServiceGenerator::class,            // --services    / --api
            RepositoryGenerator::class,         // --repositories (auto-registers its provider)
            GateGenerator::class,               // --gates
            FactoryGenerator::class,            // --factories
            SeederGenerator::class,             // --seeders
            MigrationGenerator::class,          // --migrations
            EventGenerator::class,              // --events
            TestGenerator::class,               // --tests       / --api

            // Routes (legacy + versioned)
            ApiRouteGenerator::class,           // --api-routes  / --api

            // Versioned JSON API scaffold — infrastructure MUST precede controllers
            // ForceJsonServiceProviderGenerator now auto-registers the provider in
            // bootstrap/providers.php, so the versioned routes wire themselves up.
            ForceJsonServiceProviderGenerator::class,  // --api
            ApiControllerGenerator::class,             // --api
            // OpenAPI 3.1 — Root orchestrates Schema + Path and writes the root
            // spec in finalize(). Only this entry is registered; it drives the
            // other two internally, so they must NOT be registered separately.
            OpenApiRootGenerator::class,        // --openapi
        ];

        // Bind generators. The OpenAPI Root generator depends on the Schema and
        // Path generators (and serializer/type-mapper, which are default
        // constructable), so the container can autowire them — but we bind the
        // collaborators explicitly as singletons for clarity and reuse.
        $this->app->singleton(OpenApiSchemaGenerator::class);
        $this->app->singleton(OpenApiPathGenerator::class);

        foreach ($generators as $generatorClass) {
            $this->app->singleton($generatorClass);
        }

        $this->app->singleton(GenerationOrchestrator::class, function ($app) use ($generators) {
            $orchestrator = new GenerationOrchestrator;

            foreach ($generators as $generatorClass) {
                $orchestrator->addGenerator($app->make($generatorClass));
            }

            // Custom generators registered via config are appended last.
            foreach (config('anvil.custom_generators', []) as $customClass) {
                if (class_exists($customClass)) {
                    $orchestrator->addGenerator($app->make($customClass));
                }
            }

            return $orchestrator;
        });
    }

    public function boot(): void
    {
        if (config('anvil.openapi.docs.enabled', false)) {
            $this->registerDocsRoutes();
        }
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateModelsFromDatabase::class,
                DocsCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/anvil.php' => config_path('anvil.php'),
            ], 'config');

            $this->publishes([
                __DIR__.'/../stubs' => base_path('stubs/anvil'),
            ], 'stubs');
        }
    }

    protected function registerDocsRoutes(): void
    {
        $prefix = trim(config('anvil.openapi.docs.route', 'docs'), '/');
        $middleware = config('anvil.openapi.docs.middleware', ['web']);

        Route::middleware($middleware)->group(function () use ($prefix) {
            Route::get($prefix, [DocsController::class, 'ui'])
                ->name('anvil.docs');

            Route::get($prefix.'/{file}', [DocsController::class, 'spec'])
                ->where('file', '.*')
                ->name('anvil.docs.spec');
        });
    }
}
