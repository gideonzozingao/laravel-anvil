<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Zuqongtech\LaravelAnvil\Console\DiffCommand;
use Zuqongtech\LaravelAnvil\Console\DocsSyncCommand;
use Zuqongtech\LaravelAnvil\Console\DoctorCommand;
use Zuqongtech\LaravelAnvil\Console\FrontendCommand;
use Zuqongtech\LaravelAnvil\Console\GenerateAuthCommand;
use Zuqongtech\LaravelAnvil\Console\GenerateClientCommand;
use Zuqongtech\LaravelAnvil\Console\GenerateGraphQLCommand;
use Zuqongtech\LaravelAnvil\Console\GenerateModelsFromDatabase;
use Zuqongtech\LaravelAnvil\Console\GenerateOpenApiCommand;
use Zuqongtech\LaravelAnvil\Console\GenerateOpenApiDocsCommand;
use Zuqongtech\LaravelAnvil\Console\GenerateWebCommand;
use Zuqongtech\LaravelAnvil\Console\InstallSwaggerUi;
use Zuqongtech\LaravelAnvil\Console\PolishCommand;
use Zuqongtech\LaravelAnvil\Generators\ApiControllerGenerator;
use Zuqongtech\LaravelAnvil\Generators\ApiFormRequestGenerator;
use Zuqongtech\LaravelAnvil\Generators\ApiResourceGenerator;
use Zuqongtech\LaravelAnvil\Generators\ApiRouteGenerator;
use Zuqongtech\LaravelAnvil\Generators\ApiServiceGenerator;
use Zuqongtech\LaravelAnvil\Generators\ControllerGenerator;
use Zuqongtech\LaravelAnvil\Generators\EnumGenerator;
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
use Zuqongtech\LaravelAnvil\Runtime\Cache\CacheInvalidationListener;
use Zuqongtech\LaravelAnvil\Runtime\Cache\CacheKey;
use Zuqongtech\LaravelAnvil\Runtime\Cache\CachePolicy;
use Zuqongtech\LaravelAnvil\Runtime\Cache\CacheStamps;
use Zuqongtech\LaravelAnvil\Runtime\Cache\QueryCache;
use Zuqongtech\LaravelAnvil\Support\GenerationOrchestrator;

class LaravelAnvilServiceProvider extends ServiceProvider
{
    /**
     * Every Artisan command the package registers.
     *
     * Two families: commands that WRITE code, and commands that only INSPECT.
     * The inspection pair is what makes the writers safe to run repeatedly —
     * anvil:doctor reports schema shapes that break codegen before they do, and
     * anvil:diff reports drift between the database and what was last generated.
     *
     * anvil:docs-sync is a third kind: it reports drift between the database-derived
     * spec and the payload code a developer hand-edited afterwards, and can close it.
     *
     * @var list<class-string<Command>>
     */
    private const COMMANDS = [
        // ── Generation ──────────────────────────────────────────────────────
        GenerateModelsFromDatabase::class,   // anvil:generate
        GenerateOpenApiCommand::class,       // anvil:generate-api  (alias anvil:generate-openapi)
        GenerateOpenApiDocsCommand::class,   // anvil:generate-apidocs
        GenerateWebCommand::class,           // anvil:generate-web
        GenerateAuthCommand::class,          // anvil:generate-auth
        GenerateClientCommand::class,        // anvil:generate-client

        // ── Inspection ──────────────────────────────────────────────────────
        DiffCommand::class,                  // anvil:diff
        DoctorCommand::class,                // anvil:doctor
        DocsSyncCommand::class,              // anvil:docs-sync

        // ── Installation helpers ────────────────────────────────────────────
        InstallSwaggerUi::class,

        PolishCommand::class,
        GenerateGraphQLCommand::class,
        FrontendCommand::class,
    ];

    /**
     * The generator pipeline. Order is authoritative.
     *
     * @var list<class-string>
     */
    private const GENERATORS = [
        // Core per-model artifacts
        ControllerGenerator::class,         // --controllers (non-API)
        ResourceGenerator::class,           // --resources   (unversioned)
        ObserverGenerator::class,           // --observers
        PolicyGenerator::class,             // --policies
        FormRequestGenerator::class,        // --form-requests (unversioned)
        ServiceGenerator::class,            // --services / --api  (SHARED across versions)
        RepositoryGenerator::class,         // --repositories (auto-registers its provider)
        GateGenerator::class,               // --gates
        FactoryGenerator::class,            // --factories
        SeederGenerator::class,             // --seeders
        MigrationGenerator::class,          // --migrations

        // Domain events — the listener generator MUST follow the event generator:
        // generated listeners type-hint App\Events\* classes, so the events have
        // to exist on disk by the time Laravel's listener discovery runs.
        EventGenerator::class,              // --events
        ListenerGenerator::class,           // --listeners

        TestGenerator::class,               // --tests / --api

        // Routes (unversioned append + versioned file)
        ApiRouteGenerator::class,           // --api-routes / --api

        // ── Versioned JSON API scaffold (--api) ─────────────────────────────
        // Infrastructure MUST precede the controllers: this generator writes the
        // provider that loads the versioned route file and registers itself in
        // bootstrap/providers.php.
        ForceJsonServiceProviderGenerator::class,

        // Requests and resources before the controller that imports them.
        // Nothing reads another generator's output — each only writes files — so
        // the ordering is for a readable run summary rather than correctness.
        // All four gate on $options->api, and anvil:generate-api sets
        // formRequests/resources to false, so the unversioned FormRequest and
        // Resource generators above never produce a competing second set.
        ApiFormRequestGenerator::class,     // Requests/Api/V{n}/{Model}/*Request
        ApiResourceGenerator::class,        // Resources/Api/V{n}/{Model}Resource
        ApiServiceGenerator::class,         // Services/Api/V{n} — opt-in subclass
        ApiControllerGenerator::class,      // Controllers/Api/V{n}/{Model}Controller

        // OpenAPI 3.1 — Root orchestrates Schema + Path and writes the root spec
        // in finalize(). Only this entry is registered; it drives the other two
        // internally, so registering them here would double their work.
        OpenApiRootGenerator::class,        // --openapi

        // Web scaffold (--web)
        WebControllerGenerator::class,
        WebRouteGenerator::class,
        ViewGenerator::class,
        LivewireComponentGenerator::class,
        EnumGenerator::class,
    ];

    #[\Override]
    public function register(): void
    {
        /*
         * The codebase reads config under two historical roots: older generators
         * use config('anvil.*'), while the OpenAPI generators, GenerationOptions
         * and the console commands use config('laravel-anvil.*'). Merging the
         * same file under BOTH keys makes every lookup resolve without touching
         * dozens of call sites.
         *
         * NOTE: mergeConfigFrom is SHALLOW. A published config/anvil.php that
         * contains an 'openapi' or 'api' key REPLACES that whole subtree — new
         * keys do not fall back to package defaults. Re-publish with --force
         * after upgrading, or add the missing keys by hand.
         *
         * This is why every anvil.openapi.sync.* key has a code-side default in
         * SyncConfig: an install whose published config predates docs-sync has no
         * 'sync' key at all, and the shallow merge will not supply one.
         */
        $this->mergeConfigFrom(__DIR__.'/../config/anvil.php', 'anvil');
        $this->mergeConfigFrom(__DIR__.'/../config/anvil.php', 'laravel-anvil');

        $this->registerGenerators();
    }

    public function boot(): void
    {
        // Registered unconditionally (not gated behind runningInConsole()):
        // 'anvil::docs.show' must resolve on a normal HTTP request, since that's
        // when DocsController::render() actually calls view(). Config merging
        // above already ran in register(), so anvil.openapi.docs.enabled is
        // readable here regardless of which root it came from.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'anvil');

        if (config('anvil.openapi.docs.enabled', false)) {
            $this->registerDocsRoutes();
        }

        // Cache invalidation is a RUNTIME concern, so it must be subscribed before
        // the console guard below. Model writes overwhelmingly happen during HTTP
        // requests; subscribing only in console meant a web request could write a
        // row and never invalidate the entry caching it, and QueryCache would keep
        // serving the stale value until its TTL expired. Console commands are the
        // one context where invalidation matters least.
        if (config('anvil.cache.enabled', true)) {
            $this->app->make(CacheInvalidationListener::class)->subscribe($this->app['events']);
        }

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands(self::COMMANDS);

        // The README documents --tag=anvil-config; the bare 'config' tag is kept
        // so existing deploy scripts keep working.
        foreach (['anvil-config', 'config'] as $tag) {
            $this->publishes([
                __DIR__.'/../config/anvil.php' => config_path('anvil.php'),
            ], $tag);
        }

        foreach (['anvil-stubs', 'stubs'] as $tag) {
            $this->publishes([
                __DIR__.'/../stubs' => base_path('stubs/anvil'),
            ], $tag);
        }

        // Lets a consumer run `php artisan vendor:publish --tag=anvil-views` to
        // get an editable copy under resources/views/vendor/anvil/docs/…
        // Laravel's view finder prefers the published copy over the package's
        // own automatically, so no config change is needed after publishing —
        // config('anvil.openapi.docs.view') stays 'anvil::docs.show' either way.
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/anvil'),
        ], 'anvil-views');
    }

    protected function registerGenerators(): void
    {
        // The OpenAPI root generator autowires its collaborators, but they are
        // bound explicitly as singletons for clarity and reuse.
        $this->app->singleton(OpenApiSchemaGenerator::class);
        $this->app->singleton(OpenApiPathGenerator::class);
        $this->app->singleton(CachePolicy::class);
        $this->app->singleton(CacheStamps::class);
        $this->app->singleton(CacheKey::class);
        $this->app->singleton(QueryCache::class);

        foreach (self::GENERATORS as $generator) {
            $this->app->singleton($generator);
        }

        // The abstract is REQUIRED. singleton() is ($abstract, $concrete); passing
        // the closure as the first argument reaches unset($this->instances[$abstract])
        // with a Closure as an array key, which throws "Illegal offset type" —
        // and leaves nothing able to resolve the orchestrator.
        $this->app->singleton(function ($app): GenerationOrchestrator {
            $orchestrator = new GenerationOrchestrator;

            foreach (self::GENERATORS as $generator) {
                $orchestrator->addGenerator($app->make($generator));
            }

            // Custom generators from config are appended last.
            foreach ((array) config('anvil.custom_generators', []) as $custom) {
                if (is_string($custom) && class_exists($custom)) {
                    $orchestrator->addGenerator($app->make($custom));
                }
            }

            return $orchestrator;
        });

        /*
         * DocsSynchronizer is deliberately NOT bound.
         *
         * It is built per invocation by SyncConfig::synchronizer($version), because
         * the spec directory it reads is fixed at construction. A singleton would be
         * pinned to whichever version was current when the container first resolved
         * it, and `anvil:docs-sync --api-version=v2` would then silently reconcile
         * v1's spec — wrong output, exit code 0, no warning.
         *
         * Custom readers therefore go in config, alongside custom_generators above:
         *
         *   'openapi' => ['sync' => ['readers' => [App\Docs\DtoShapeReader::class]]]
         *
         * SyncConfig resolves them through the container, so they may take
         * constructor dependencies, and it keeps pace with the DocsSynchronizer
         * constructor so no call site has to repeat its arguments.
         */
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

            Route::get($prefix.'/{version}', [DocsController::class, 'ui'])
                ->where('version', 'v?\d+')
                ->name('anvil.docs.version');

            // $file arrives as "v1/openapi.yaml" or "v1/schemas/User.yaml";
            // DocsController splits the leading version segment off.
            Route::get($prefix.'/{file}', [DocsController::class, 'spec'])
                ->where('file', '.*')
                ->name('anvil.docs.spec');
        });
    }
}
