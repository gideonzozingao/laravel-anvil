<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil;

use App\Console\Commands\InstallSwaggerUi;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Zuqongtech\LaravelAnvil\Console\GenerateAuthCommand;
use Zuqongtech\LaravelAnvil\Console\GenerateModelsFromDatabase;
use Zuqongtech\LaravelAnvil\Console\GenerateOpenApiCommand;
use Zuqongtech\LaravelAnvil\Console\GenerateOpenApiDocsCommand;
use Zuqongtech\LaravelAnvil\Console\GenerateWebCommand;
use Zuqongtech\LaravelAnvil\Generators\ApiControllerGenerator;
use Zuqongtech\LaravelAnvil\Generators\ApiFormRequestGenerator;
use Zuqongtech\LaravelAnvil\Generators\ApiResourceGenerator;
use Zuqongtech\LaravelAnvil\Generators\ApiRouteGenerator;
use Zuqongtech\LaravelAnvil\Generators\ApiServiceGenerator;
use Zuqongtech\LaravelAnvil\Generators\ControllerGenerator;
use Zuqongtech\LaravelAnvil\Generators\EventGenerator;
use Zuqongtech\LaravelAnvil\Generators\FactoryGenerator;
use Zuqongtech\LaravelAnvil\Generators\ForceJsonServiceProviderGenerator;
use Zuqongtech\LaravelAnvil\Generators\FormRequestGenerator;
use Zuqongtech\LaravelAnvil\Generators\GateGenerator;
use Zuqongtech\LaravelAnvil\Generators\ListenerGenerator;
use Zuqongtech\LaravelAnvil\Generators\LivewireComponentGenerator;
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
use Zuqongtech\LaravelAnvil\Generators\ViewGenerator;
use Zuqongtech\LaravelAnvil\Generators\WebControllerGenerator;
use Zuqongtech\LaravelAnvil\Generators\WebRouteGenerator;
use Zuqongtech\LaravelAnvil\Http\DocsController;
use Zuqongtech\LaravelAnvil\Support\GenerationOrchestrator;

class LaravelAnvilServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        /***********************************************************************
         The codebase reads config under two historical keys: older generators
        use config('anvil.*'), while the OpenAPI generators, GenerationOptions,
        and the console command use config('laravel-anvil.*'). Merging the same
        file under BOTH keys makes every lookup resolve correctly without having
         to touch dozens of call sites.

         NOTE: mergeConfigFrom is SHALLOW. A published config/anvil.php that
         contains an 'openapi' or 'api' key REPLACES that whole subtree — new
         keys do not fall back to the package defaults. Re-publish with --force
         after upgrading, or add the missing keys by hand.
         * ***********************************************************************/
        $this->mergeConfigFrom(__DIR__ . '/../config/anvil.php', 'anvil');
        $this->mergeConfigFrom(__DIR__ . '/../config/anvil.php', 'laravel-anvil');

        // ── Generator pipeline (order is authoritative) ──────────────────────
        $generators = [
            // Core per-model artifacts
            ControllerGenerator::class,         // --controllers (non-API)
            ResourceGenerator::class,           // --resources   (unversioned)
            ObserverGenerator::class,           // --observers
            PolicyGenerator::class,             // --policies
            FormRequestGenerator::class,        // --form-requests (unversioned)
            ServiceGenerator::class,            // --services    / --api  (SHARED across versions)
            RepositoryGenerator::class,         // --repositories (auto-registers its provider)
            GateGenerator::class,               // --gates
            FactoryGenerator::class,            // --factories
            SeederGenerator::class,             // --seeders
            MigrationGenerator::class,          // --migrations

            // Domain events — the listener generator MUST follow the event
            // generator: generated listeners type-hint App\Events\* classes, so
            // the events have to exist on disk by the time discovery runs.
            EventGenerator::class,              // --events
            ListenerGenerator::class,           // --listeners

            TestGenerator::class,               // --tests       / --api

            // Routes (legacy + versioned)
            ApiRouteGenerator::class,           // --api-routes  / --api

            // ── Versioned JSON API scaffold (--api) ─────────────────────────
            // Infrastructure MUST precede controllers.
            // ForceJsonServiceProviderGenerator auto-registers the provider in
            // bootstrap/providers.php, so the versioned routes wire themselves up.
            ForceJsonServiceProviderGenerator::class,

            // Requests and resources before the controller that imports them.
            // Nothing reads another's output — a generator only writes files — so
            // this ordering is for a readable run summary, not correctness.
            // All three gate on $options->api only, so the unversioned
            // FormRequestGenerator / ResourceGenerator above never collide with
            // them: a --api run turns those two off.
            ApiFormRequestGenerator::class,     // Requests/Api/V{n}/{Model}/*Request
            ApiResourceGenerator::class,        // Resources/Api/V{n}/{Model}Resource
            ApiServiceGenerator::class,         // Services/Api/V{n} — opt-in subclass
            ApiControllerGenerator::class,      // Controllers/Api/V{n}/{Model}Controller

            // OpenAPI 3.1 — Root orchestrates Schema + Path and writes the root
            // spec in finalize(). Only this entry is registered; it drives the
            // other two internally, so they must NOT be registered separately.
            OpenApiRootGenerator::class,        // --openapi

            // Web scaffold (--web)
            WebControllerGenerator::class,
            WebRouteGenerator::class,
            ViewGenerator::class,
            LivewireComponentGenerator::class,
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

        $this->app->singleton(function ($app) use ($generators): GenerationOrchestrator {
            $orchestrator = new GenerationOrchestrator;

            foreach ($generators as $generatorClass) {
                $orchestrator->addGenerator($app->make($generatorClass));
            }

            // Custom generators registered via config are appended last.
            foreach ((array) config('anvil.custom_generators', []) as $customClass) {
                if (is_string($customClass) && class_exists($customClass)) {
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
                GenerateModelsFromDatabase::class,   // anvil:generate
                GenerateOpenApiCommand::class,       // anvil:generate-api (alias anvil:generate-openapi)
                GenerateOpenApiDocsCommand::class,   // anvil:generate-apidocs
                GenerateWebCommand::class,           // anvil:generate-web
                GenerateAuthCommand::class,          // anvil:auth
                InstallSwaggerUi::class,
            ]);

            // The README documents --tag=anvil-config; 'config' is also kept so
            // existing deploy scripts continue to work.
            foreach (['anvil-config', 'config'] as $tag) {
                $this->publishes([
                    __DIR__ . '/../config/anvil.php' => config_path('anvil.php'),
                ], $tag);
            }

            foreach (['anvil-stubs', 'stubs'] as $tag) {
                $this->publishes([
                    __DIR__ . '/../stubs' => base_path('stubs/anvil'),
                ], $tag);
            }
        }
    }

    /**
     * Docs routes, version-aware.
     *
     * Declaration order matters: the {version} route must come BEFORE the
     * catch-all, or `.*` swallows "v1" as a filename and DocsController::spec()
     * is handed a version segment where it expects a file.
     */
    protected function registerDocsRoutes(): void
    {
        $prefix = trim((string) config('anvil.openapi.docs.route', 'docs'), '/');
        $middleware = config('anvil.openapi.docs.middleware', ['web']);

        Route::middleware($middleware)->group(function () use ($prefix): void {
            Route::get($prefix, [DocsController::class, 'ui'])
                ->name('anvil.docs');

            Route::get($prefix . '/{version}', [DocsController::class, 'ui'])
                ->where('version', 'v?\d+')
                ->name('anvil.docs.version');

            // $file arrives as "v1/openapi.yaml" or "v1/schemas/User.yaml";
            // DocsController splits the leading version segment off.
            Route::get($prefix . '/{file}', [DocsController::class, 'spec'])
                ->where('file', '.*')
                ->name('anvil.docs.spec');
        });
    }
}
