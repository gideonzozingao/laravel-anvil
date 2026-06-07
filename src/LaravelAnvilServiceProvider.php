<?php

namespace Zuqongtech\LaravelAnvil;

use Illuminate\Support\ServiceProvider;
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
use Zuqongtech\LaravelAnvil\Generators\PolicyGenerator;
use Zuqongtech\LaravelAnvil\Generators\RepositoryGenerator;
use Zuqongtech\LaravelAnvil\Generators\ResourceGenerator;
use Zuqongtech\LaravelAnvil\Generators\SeederGenerator;
use Zuqongtech\LaravelAnvil\Generators\ServiceGenerator;
use Zuqongtech\LaravelAnvil\Generators\TestGenerator;
use Zuqongtech\LaravelAnvil\Support\GenerationOrchestrator;
use Zuqongtech\LaravelAnvil\Generators\OpenApiGenerator;

class LaravelAnvilServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/anvil.php',
            'anvil',
        );

        // ── Standard generators (active for their respective flags) ──────────
        $generators = [
            // Original artifacts
            ControllerGenerator::class,         // --controllers (non-API)
            ResourceGenerator::class,           // --resources
            ObserverGenerator::class,           // --observers
            PolicyGenerator::class,             // --policies
            FormRequestGenerator::class,        // --form-requests / --api
            ServiceGenerator::class,            // --services    / --api
            RepositoryGenerator::class,         // --repositories
            GateGenerator::class,               // --gates
            FactoryGenerator::class,            // --factories
            SeederGenerator::class,             // --seeders
            MigrationGenerator::class,          // --migrations
            EventGenerator::class,              // --events
            TestGenerator::class,               // --tests       / --api

            // Route generator (handles both legacy and versioned modes)
            ApiRouteGenerator::class,           // --api-routes  / --api
            OpenApiGenerator::class,
            // Versioned API scaffold (--api only)
            // Order matters: infrastructure first, then controllers
            ForceJsonServiceProviderGenerator::class,  // --api (runs once per table, idempotent)
            ApiControllerGenerator::class,             // --api
        ];

        foreach ($generators as $generatorClass) {
            $this->app->singleton($generatorClass);
        }

        // Wire all generators into the orchestrator.
        // Developers can replace individual generators by rebinding them
        // in their own service providers before this provider boots.
        $this->app->singleton(GenerationOrchestrator::class, function ($app) use ($generators) {
            $orchestrator = new GenerationOrchestrator;

            foreach ($generators as $generatorClass) {
                $orchestrator->addGenerator($app->make($generatorClass));
            }

            // Merge any custom generators registered via config
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
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateModelsFromDatabase::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/anvil.php' => config_path('anvil.php'),
            ], 'config');

            $this->publishes([
                __DIR__.'/../stubs' => base_path('stubs/anvil'),
            ], 'stubs');
        }
    }
}
