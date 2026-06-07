<?php

namespace Zuqongtech\LaravelAnvil\Generators;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates the infrastructure that locks every versioned API request and
 * response to JSON — including exceptions.
 *
 * THREE files are created / updated (all idempotent):
 *
 * 1. app/Http/Middleware/ForceJsonResponse.php
 *    Sets Accept: application/json on every incoming request so Laravel's
 *    exception handler always renders JSON instead of HTML.
 *
 * 2. app/Providers/ForceJsonApiServiceProvider.php
 *    A service provider that:
 *      - Loads every routes/api/v{n}.php file it knows about
 *      - Wraps them in the correct middleware group:
 *          api + ForceJsonResponse + auth:sanctum (configurable)
 *      - Registers a JSON exception renderer that overrides the default
 *        HTML fallback for all API routes (even 404, 405, 422, 500, etc.)
 *
 * 3. bootstrap/app.php  ← registration
 *    Appends the provider and middleware to the application bootstrap file
 *    so Laravel 11+'s minimal bootstrap picks it up automatically.
 *    For Laravel ≤ 10 the generator falls back to config/app.php providers[].
 *
 * The generator only produces output when $options->api === true.
 * Running it multiple times is safe — each file is checked for the presence
 * of its own marker string before any write occurs.
 */
final class ForceJsonServiceProviderGenerator implements Generator
{
    public function supports(GenerationOptions $options): bool
    {
        return $options->api;
    }

    public function getName(): string
    {
        return 'ForceJsonProvider';
    }

    /**
     * ModelMetadata is not used here (infrastructure is per-version, not per-model)
     * but we must honour the interface contract. The generator is intentionally
     * idempotent so calling it once per table is harmless.
     */
    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $results = [];

        $results[] = $this->ensureMiddleware($options);
        $results[] = $this->ensureServiceProvider($options);
        $results[] = $this->ensureBootstrapRegistration($options);

        return $results;
    }

    // -----------------------------------------------------------------------
    // 1. ForceJsonResponse middleware
    // -----------------------------------------------------------------------

    protected function ensureMiddleware(GenerationOptions $options): array
    {
        $path = app_path('Http/Middleware/ForceJsonResponse.php');

        if (file_exists($path)) {
            return [
                'type' => $this->getName().'Middleware',
                'name' => 'ForceJsonResponse',
                'path' => $path,
                'status' => 'skipped',
                'reason' => 'already exists',
            ];
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
 * through this middleware. This causes Laravel's exception handler to render
 * JSON responses for ALL error conditions (401, 403, 404, 405, 422, 500, …)
 * rather than falling back to HTML pages.
 *
 * Applied globally to all versioned API route groups by ForceJsonApiServiceProvider.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        $response = $next($request);

        // Ensure the Content-Type response header is always application/json
        // even for responses that don't set it explicitly (e.g. 204 No Content).
        if (! $response->headers->has('Content-Type') || $response->getStatusCode() !== 204) {
            $response->headers->set('Content-Type', 'application/json');
        }

        return $response;
    }
}

PHP;

        if (! $options->dryRun) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, $content);
        }

        return [
            'type' => $this->getName().'Middleware',
            'name' => 'ForceJsonResponse',
            'path' => $path,
            'status' => 'success',
            'action' => 'created',
        ];
    }

    // -----------------------------------------------------------------------
    // 2. ForceJsonApiServiceProvider
    // -----------------------------------------------------------------------

    protected function ensureServiceProvider(GenerationOptions $options): array
    {
        $path = app_path('Providers/ForceJsonApiServiceProvider.php');
        $versionSlug = $options->getApiVersionSlug();
        $versionString = $options->getApiVersionString();

        if (file_exists($path)) {
            // Provider already exists — append new version entry if missing
            $this->appendVersionToProvider($path, $versionSlug, $options);

            return [
                'type' => $this->getName().'Provider',
                'name' => 'ForceJsonApiServiceProvider',
                'path' => $path,
                'status' => 'updated',
                'reason' => "appended {$versionSlug} routes",
            ];
        }

        $middleware = config('anvil.api_middleware', ['auth:sanctum']);
        $mwList = "'".implode("',\n                    '", $middleware)."'";

        $content = $this->buildServiceProvider($versionSlug, $mwList);

        if (! $options->dryRun) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, $content);
        }

        return [
            'type' => $this->getName().'Provider',
            'name' => 'ForceJsonApiServiceProvider',
            'path' => $path,
            'status' => 'success',
            'action' => 'created',
        ];
    }

    protected function buildServiceProvider(string $versionSlug, string $mwList): string
    {
        return <<<PHP
<?php

namespace App\Providers;

use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * ForceJsonApiServiceProvider
 *
 * Responsibilities:
 *  1. Load every versioned API route file from routes/api/v{n}.php
 *  2. Wrap each version's routes in the correct middleware stack that
 *     GUARANTEES JSON for every request and every response including
 *     exceptions (404, 405, 422, 500, etc.)
 *  3. Register a global JSON exception renderer so unhandled exceptions
 *     inside the API prefix always return a structured JSON envelope.
 *
 * To add a new API version simply add an entry to \$versions below,
 * or re-run  php artisan anvil:generate --api --api-version=<n>
 * which will append the entry automatically.
 */
class ForceJsonApiServiceProvider extends ServiceProvider
{
    /**
     * Registered API versions.
     * Each entry maps a version slug to its route file path.
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

    // -----------------------------------------------------------------------

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
     * Override Laravel's default exception rendering for all API paths so
     * that every unhandled exception — including framework-level ones —
     * returns a consistent JSON envelope instead of an HTML error page.
     *
     * This covers: ModelNotFoundException (404), AuthenticationException (401),
     * AuthorizationException (403), ValidationException (422), and any
     * unhandled Throwable (500).
     */
    protected function registerJsonExceptionHandler(): void
    {
        \$this->app->make(\Illuminate\Contracts\Http\Kernel::class)
            ->pushMiddleware(ForceJsonResponse::class);

        // Register a renderable exception handler that catches ALL throwables
        // on API paths and formats them as JSON envelopes.
        \$this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)
            ->renderable(function (Throwable \$e, \$request) {
                if (! \$request->is('v*/*') && ! \$request->is('*/api/*') && ! \$request->wantsJson()) {
                    return null; // Let non-API requests fall through
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
     * Map a Throwable to an HTTP status code, human message, and errors bag.
     *
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
     * Append a new version entry to \$versions in an existing provider file.
     */
    protected function appendVersionToProvider(
        string $path,
        string $versionSlug,
        GenerationOptions $options,
    ): void {
        $content = file_get_contents($path);

        // Idempotency
        if (str_contains($content, "'{$versionSlug}'")) {
            return;
        }

        $newEntry = "        '{$versionSlug}' => 'routes/api/{$versionSlug}.php',";

        // Insert after the anvil:managed marker
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
    // 3. Bootstrap registration
    // -----------------------------------------------------------------------

    protected function ensureBootstrapRegistration(GenerationOptions $options): array
    {
        // Laravel 11+ uses bootstrap/app.php
        $bootstrapPath = base_path('bootstrap/app.php');

        if (file_exists($bootstrapPath)) {
            return $this->registerInBootstrapApp($bootstrapPath, $options);
        }

        // Laravel ≤ 10 uses config/app.php providers array
        $configAppPath = config_path('app.php');
        if (file_exists($configAppPath)) {
            return $this->registerInConfigApp($configAppPath, $options);
        }

        return [
            'type' => $this->getName().'Bootstrap',
            'name' => 'Bootstrap registration',
            'status' => 'skipped',
            'reason' => 'Could not locate bootstrap/app.php or config/app.php',
        ];
    }

    /**
     * Register ForceJsonApiServiceProvider in Laravel 11+ bootstrap/app.php.
     *
     * Appends ->withProviders([...]) or adds to an existing withProviders call,
     * and registers the ForceJsonResponse middleware alias.
     */
    protected function registerInBootstrapApp(string $path, GenerationOptions $options): array
    {
        $content = file_get_contents($path);
        $marker = 'ForceJsonApiServiceProvider';

        if (str_contains($content, $marker)) {
            return [
                'type' => $this->getName().'Bootstrap',
                'name' => 'bootstrap/app.php',
                'path' => $path,
                'status' => 'skipped',
                'reason' => 'already registered',
            ];
        }

        // Strategy: inject ->withProviders() before the final ->create() call
        $providerLine = '    \\App\\Providers\\ForceJsonApiServiceProvider::class,';
        $middlewareLine = '        \\App\\Http\\Middleware\\ForceJsonResponse::class,';

        // Add withProviders block if there's a ->create() at the end
        if (str_contains($content, '->create()')) {
            $inject = <<<PHP

    ->withProviders([
{$providerLine}
    ])
    ->withMiddleware(function (\Illuminate\Foundation\Configuration\Middleware \$middleware) {
        \$middleware->appendToGroup('api', [
{$middlewareLine}
        ]);
    })
PHP;
            $content = str_replace('->create()', $inject."\n    ->create()", $content);
        }

        if (! $options->dryRun) {
            file_put_contents($path, $content);
        }

        return [
            'type' => $this->getName().'Bootstrap',
            'name' => 'bootstrap/app.php',
            'path' => $path,
            'status' => 'success',
            'action' => 'updated',
        ];
    }

    /**
     * Register ForceJsonApiServiceProvider in Laravel ≤10 config/app.php.
     */
    protected function registerInConfigApp(string $path, GenerationOptions $options): array
    {
        $content = file_get_contents($path);
        $marker = 'ForceJsonApiServiceProvider';

        if (str_contains($content, $marker)) {
            return [
                'type' => $this->getName().'Bootstrap',
                'name' => 'config/app.php',
                'path' => $path,
                'status' => 'skipped',
                'reason' => 'already registered',
            ];
        }

        // Append just before the closing bracket of 'providers'
        $providerLine = '        \\App\\Providers\\ForceJsonApiServiceProvider::class,';

        $updated = preg_replace(
            "/(App\\\\Providers\\\\[A-Za-z]+ServiceProvider::class,)\n(\s*\]\s*,?\s*\/\/ providers)/",
            "$1\n{$providerLine}\n$2",
            $content,
        );

        // Fallback: naive append before last ]; in the providers section
        if ($updated === null || $updated === $content) {
            $updated = str_replace(
                "App\\Providers\\RouteServiceProvider::class,\n    ],",
                "App\\Providers\\RouteServiceProvider::class,\n{$providerLine}\n    ],",
                $content,
            );
        }

        if (! $options->dryRun) {
            file_put_contents($path, $updated);
        }

        return [
            'type' => $this->getName().'Bootstrap',
            'name' => 'config/app.php',
            'path' => $path,
            'status' => 'success',
            'action' => 'updated',
        ];
    }
}
