<?php

namespace Zuqongtech\LaravelAnvil\Generators;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;
use Zuqongtech\LaravelAnvil\Support\ProviderRegistrar;

/**
 * Generates the infrastructure that locks every versioned API request and
 * response — including exceptions — to JSON, and wires it into the app.
 *
 * Files created / updated (all idempotent):
 *
 *  1. app/Http/Middleware/ForceJsonResponse.php
 *     Sets Accept: application/json on every incoming request so Laravel's
 *     exception handler always renders JSON instead of HTML.
 *
 *  2. app/Providers/ForceJsonApiServiceProvider.php
 *     Loads every routes/api/v{n}.php file, wraps each version in the correct
 *     middleware group (api + ForceJsonResponse + auth:sanctum), and registers
 *     a JSON exception renderer for all API routes.
 *
 *  3. bootstrap/providers.php  ← registration (Laravel 11+)
 *     The provider is appended here automatically. Falls back to
 *     bootstrap/app.php (->withProviders) or config/app.php on older apps.
 *     The middleware is NOT injected into bootstrap/app.php: the provider
 *     applies ForceJsonResponse on the versioned route group itself, so the
 *     enforcement is self-contained and cannot leak onto non-API routes.
 *
 * Active only when $options->api === true. Safe to run once per table.
 */
final class ForceJsonServiceProviderGenerator implements Generator
{
    private const PROVIDER_FQN = 'App\\Providers\\ForceJsonApiServiceProvider';

    #[\Override]
    public function supports(GenerationOptions $options): bool
    {
        return $options->api;
    }

    #[\Override]
    public function getName(): string
    {
        return 'ForceJsonProvider';
    }

    #[\Override]
    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        return [
            $this->ensureMiddleware($options),
            $this->ensureServiceProvider($options),
            $this->ensureBootstrapRegistration($options),
        ];
    }

    // -----------------------------------------------------------------------
    // 1. ForceJsonResponse middleware
    // -----------------------------------------------------------------------

    protected function ensureMiddleware(GenerationOptions $options): array
    {
        $path = app_path('Http/Middleware/ForceJsonResponse.php');

        if (file_exists($path)) {
            return $this->result('Middleware', 'ForceJsonResponse', $path, 'skipped', 'already exists');
        }

        $content = <<<'PHP'
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ForceJsonResponse middleware.
 *
 * Sets the Accept header to application/json on every request that passes
 * through it, causing Laravel's exception handler to render JSON for ALL
 * error conditions (401, 403, 404, 405, 422, 500, …) rather than HTML.
 *
 * Applied to versioned API route groups by ForceJsonApiServiceProvider.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        $response = $next($request);

        if (! $response->headers->has('Content-Type') || $response->getStatusCode() !== 204) {
            $response->headers->set('Content-Type', 'application/json');
        }

        return $response;
    }
}

PHP;

        if (! $options->dryRun) {
            $this->putFile($path, $content);
        }

        return $this->result('Middleware', 'ForceJsonResponse', $path, 'success', null, 'created');
    }

    // -----------------------------------------------------------------------
    // 2. ForceJsonApiServiceProvider
    // -----------------------------------------------------------------------

    protected function ensureServiceProvider(GenerationOptions $options): array
    {
        $path = app_path('Providers/ForceJsonApiServiceProvider.php');
        $versionSlug = $options->getApiVersionSlug();

        if (file_exists($path)) {
            $this->appendVersionToProvider($path, $versionSlug, $options);

            return $this->result('Provider', 'ForceJsonApiServiceProvider', $path, 'updated', "appended {$versionSlug} routes");
        }

        $middleware = config('anvil.api_middleware', ['auth:sanctum']);
        $mwList = "'".implode("',\n        '", $middleware)."'";

        $content = $this->buildServiceProvider($versionSlug, $mwList);

        if (! $options->dryRun) {
            $this->putFile($path, $content);
        }

        return $this->result('Provider', 'ForceJsonApiServiceProvider', $path, 'success', null, 'created');
    }

    protected function buildServiceProvider(string $versionSlug, string $mwList): string
    {
        return <<<PHP
<?php

namespace App\Providers;

use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * ForceJsonApiServiceProvider
 *
 *  1. Loads every versioned API route file from routes/api/v{n}.php.
 *  2. Wraps each version in a middleware stack that GUARANTEES JSON for every
 *     request, response, and exception (404, 405, 422, 500, …).
 *  3. Registers a global JSON exception renderer for the API prefix.
 *
 * To add a new version: re-run  php artisan anvil:generate --api --api-version=<n>
 * which appends the entry below automatically.
 */
class ForceJsonApiServiceProvider extends ServiceProvider
{
    /**
     * Registered API versions: slug => route file path.
     *
     * @var array<string, string>
     */
    protected array \$versions = [
        // anvil:managed — do not remove this comment
        '{$versionSlug}' => 'routes/api/{$versionSlug}.php',
    ];

    /**
     * Middleware applied to every API route regardless of version.
     *
     * @var array<int, string|class-string>
     */
    protected array \$middleware = [
        'api',
        ForceJsonResponse::class,
        {$mwList},
    ];

    public function boot(): void
    {
        \$this->registerApiRoutes();
        \$this->registerJsonExceptionHandler();
    }

    protected function registerApiRoutes(): void
    {
        foreach (\$this->versions as \$version => \$routeFile) {
            if (! file_exists(base_path(\$routeFile))) {
                continue;
            }

            Route::middleware(\$this->middleware)
                ->prefix(\$version)
                ->name("api.{\$version}.")
                ->group(base_path(\$routeFile));
        }
    }

    /**
     * Render all unhandled exceptions on API paths as a consistent JSON envelope.
     */
    protected function registerJsonExceptionHandler(): void
    {
        \$this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)
            ->renderable(function (Throwable \$e, \$request) {
                if (! \$request->is('v*/*') && ! \$request->is('*/api/*') && ! \$request->wantsJson()) {
                    return null;
                }

                return \$this->renderApiException(\$e, \$request);
            });
    }

    protected function renderApiException(Throwable \$e, \$request): \Illuminate\Http\JsonResponse
    {
        [\$status, \$message, \$errors] = \$this->classifyException(\$e);

        \$payload = [
            'success' => false,
            'message' => \$message,
        ];

        if (! empty(\$errors)) {
            \$payload['errors'] = \$errors;
        }

        if (config('app.debug')) {
            \$payload['debug'] = [
                'exception' => get_class(\$e),
                'file'      => \$e->getFile(),
                'line'      => \$e->getLine(),
                'trace'     => collect(\$e->getTrace())->take(10)->toArray(),
            ];
        }

        return response()->json(\$payload, \$status);
    }

    /**
     * @return array{int, string, array<mixed>}
     */
    protected function classifyException(Throwable \$e): array
    {
        return match (true) {
            \$e instanceof \Illuminate\Auth\AuthenticationException
                => [401, 'Unauthenticated.', []],

            \$e instanceof \Illuminate\Auth\Access\AuthorizationException
                => [403, 'This action is unauthorized.', []],

            \$e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                => [404, 'Resource not found.', []],

            \$e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
                => [404, 'The requested URL was not found.', []],

            \$e instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException
                => [405, 'Method not allowed.', []],

            \$e instanceof \Illuminate\Validation\ValidationException
                => [422, 'The given data was invalid.', \$e->errors()],

            \$e instanceof \Symfony\Component\HttpKernel\Exception\HttpException
                => [\$e->getStatusCode(), \$e->getMessage() ?: 'HTTP error.', []],

            \$e instanceof \Illuminate\Database\QueryException
                => [500, 'A database error occurred.', []],

            default => [500, 'An unexpected error occurred.', []],
        };
    }
}

PHP;
    }

    /**
     * Append a new version entry to $versions in an existing provider file.
     */
    protected function appendVersionToProvider(string $path, string $versionSlug, GenerationOptions $options): void
    {
        $content = file_get_contents($path);

        if (str_contains($content, "'{$versionSlug}' =>")) {
            return;
        }

        $newEntry = "        '{$versionSlug}' => 'routes/api/{$versionSlug}.php',";

        $updated = str_replace(
            '// anvil:managed — do not remove this comment',
            "// anvil:managed — do not remove this comment\n{$newEntry}",
            $content,
        );

        if (! $options->dryRun) {
            file_put_contents($path, $updated);
        }
    }

    // -----------------------------------------------------------------------
    // 3. Bootstrap registration (delegated to ProviderRegistrar)
    // -----------------------------------------------------------------------

    protected function ensureBootstrapRegistration(GenerationOptions $options): array
    {
        $registrar = new ProviderRegistrar($options->dryRun);
        $outcome = $registrar->registerProvider(self::PROVIDER_FQN);

        return [
            'type' => $this->getName().'Bootstrap',
            'name' => $outcome['target'],
            'path' => $outcome['path'] ?? null,
            'status' => $outcome['status'],
            'reason' => $outcome['reason'] ?? null,
        ];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    protected function putFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);
    }

    /**
     * @return array<string, mixed>
     */
    protected function result(
        string $suffix,
        string $name,
        string $path,
        string $status,
        ?string $reason = null,
        ?string $action = null,
    ): array {
        $out = [
            'type' => $this->getName().$suffix,
            'name' => $name,
            'path' => $path,
            'status' => $status,
        ];

        if ($reason !== null) {
            $out['reason'] = $reason;
        }
        if ($action !== null) {
            $out['action'] = $action;
        }

        return $out;
    }
}
