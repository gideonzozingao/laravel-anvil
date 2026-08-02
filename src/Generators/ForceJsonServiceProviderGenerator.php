<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Generators;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;
use Zuqongtech\LaravelAnvil\Support\OpenApiLocator;
use Zuqongtech\LaravelAnvil\Support\ProviderRegistrar;

/**
 * Generates the infrastructure that locks every versioned API request and
 * response — including exceptions — to JSON, and wires it into the app.
 *
 * Files created / updated (all idempotent):
 *
 *  1. app/Http/Middleware/ForceJsonResponse.php
 *     Sets Accept: application/json on the incoming request so Laravel's
 *     exception handler renders JSON instead of HTML.
 *
 *  2. app/Providers/ForceJsonApiServiceProvider.php
 *     Loads every routes/api/v{n}.php file, wraps each version in the API
 *     middleware stack, and registers a JSON exception renderer scoped to the
 *     API prefix.
 *
 *  3. bootstrap/providers.php  ← registration (Laravel 11+)
 *     Appended automatically, falling back to bootstrap/app.php
 *     (->withProviders) or config/app.php on older apps. The middleware is NOT
 *     injected globally: the provider applies it to the versioned route group
 *     itself, so enforcement is self-contained and cannot leak onto web routes.
 *
 * The route prefix is config('anvil.api.prefix') + the version slug, which is the
 * same value OpenApiLocator::apiBasePath() puts in the spec's `servers` block.
 * Deriving both from one config key is what stops the documented paths and the
 * registered routes from disagreeing.
 *
 * Runs once per generation, not once per table — see $completed.
 */
final class ForceJsonServiceProviderGenerator implements Generator
{
    private const PROVIDER_FQN = 'App\\Providers\\ForceJsonApiServiceProvider';

    private const MANAGED_MARKER = '// anvil:managed — do not remove this comment';

    /**
     * This generator produces app-wide infrastructure, but generate() is called
     * once per table. Without a guard, a 32-table run rewrites the same three
     * files 32 times and reports 96 results.
     */
    private bool $completed = false;

    #[\Override]
    public function supports(GenerationOptions $options): bool
    {
        // --no-force-json sets this false; without the check the middleware and
        // provider are generated even when the user opted out.
        return $options->api && (bool) config('anvil.api.force_json', true);
    }

    #[\Override]
    public function getName(): string
    {
        return 'ForceJsonProvider';
    }

    #[\Override]
    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        if ($this->completed) {
            return [];
        }

        $this->completed = true;

        return [
            $this->ensureMiddleware($options),
            $this->ensureServiceProvider($options),
            $this->ensureBootstrapRegistration($options),
        ];
    }

    // -----------------------------------------------------------------------
    // 1. ForceJsonResponse middleware
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function ensureMiddleware(GenerationOptions $options): array
    {
        $path = app_path('Http/Middleware/ForceJsonResponse.php');
        $exists = file_exists($path);

        if ($exists && ! $options->force) {
            return $this->result('Middleware', 'ForceJsonResponse', $path, 'skipped', 'already exists');
        }

        if ($options->dryRun) {
            return $this->result('Middleware', 'ForceJsonResponse', $path, 'dry-run', null, $exists ? 'would overwrite' : 'would create');
        }

        $this->putFile($path, $this->buildMiddleware());

        return $this->result('Middleware', 'ForceJsonResponse', $path, 'success', null, $exists ? 'overwritten' : 'created');
    }

    protected function buildMiddleware(): string
    {
        return <<<'PHP'
            <?php

            namespace App\Http\Middleware;

            use Closure;
            use Illuminate\Http\Request;
            use Symfony\Component\HttpFoundation\Response;

            /**
             * ForceJsonResponse middleware.
             *
             * Sets Accept: application/json on the incoming request, which is what makes
             * Laravel's exception handler render JSON for every error condition (401, 403,
             * 404, 405, 422, 500 …) rather than an HTML error page.
             *
             * Applied to the versioned API route group by ForceJsonApiServiceProvider.
             */
            class ForceJsonResponse
            {
                public function handle(Request $request, Closure $next): Response
                {
                    $request->headers->set('Accept', 'application/json');

                    $response = $next($request);

                    // Only fill in a MISSING Content-Type, and never on a 204 (which has no
                    // body by definition). Setting it unconditionally would relabel file
                    // downloads, CSV exports and streamed responses as JSON.
                    if (! $response->headers->has('Content-Type') && $response->getStatusCode() !== 204) {
                        $response->headers->set('Content-Type', 'application/json');
                    }

                    return $response;
                }
            }

            PHP;
    }

    // -----------------------------------------------------------------------
    // 2. ForceJsonApiServiceProvider
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function ensureServiceProvider(GenerationOptions $options): array
    {
        $path = app_path('Providers/ForceJsonApiServiceProvider.php');
        $version = $options->getApiVersionSlug();

        if (file_exists($path) && ! $options->force) {
            return $this->appendVersion($path, $version, $options);
        }

        if ($options->dryRun) {
            return $this->result('Provider', 'ForceJsonApiServiceProvider', $path, 'dry-run');
        }

        $this->putFile($path, $this->buildServiceProvider($version));

        return $this->result('Provider', 'ForceJsonApiServiceProvider', $path, 'success', null, 'created');
    }

    protected function buildServiceProvider(string $version): string
    {
        $prefix = trim((string) config('anvil.api.prefix', 'api'), '/');
        $middleware = $this->middlewareList();
        $marker = self::MANAGED_MARKER;

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
                 *  2. Wraps each version in a middleware stack that guarantees JSON for every
                 *     request, response and exception.
                 *  3. Registers a JSON exception renderer scoped to the API prefix.
                 *
                 * Routes are mounted at /{$prefix}/v{n}/… — the same base path the OpenAPI spec
                 * documents in its `servers` block. Change API_PREFIX and regenerate both, or
                 * they will disagree and every "Try it out" in the docs will 404.
                 *
                 * Add a version with:
                 *   php artisan anvil:generate-api --api-version=<n>
                 * which appends an entry below automatically.
                 */
                class ForceJsonApiServiceProvider extends ServiceProvider
                {
                    /**
                     * URL prefix shared by every version, giving /{$prefix}/v1, /{$prefix}/v2, …
                     */
                    protected string \$prefix = '{$prefix}';

                    /**
                     * Registered API versions: slug => route file path.
                     *
                     * @var array<string, string>
                     */
                    protected array \$versions = [
                        {$marker}
                        '{$version}' => 'routes/api/{$version}.php',
                    ];

                    /**
                     * Middleware applied to every API route regardless of version.
                     *
                     * @var array<int, string|class-string>
                     */
                    protected array \$middleware = [
                            {$middleware}
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
                                ->prefix(\$this->prefix.'/'.\$version)
                                ->name("api.{\$version}.")
                                ->group(base_path(\$routeFile));
                        }
                    }

                    /**
                     * Render unhandled exceptions on API paths as a consistent JSON envelope.
                     */
                    protected function registerJsonExceptionHandler(): void
                    {
                        \$this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)
                            ->renderable(fn (Throwable \$e, \$request) => \$this->isApiRequest(\$request)
                                ? \$this->renderApiException(\$e, \$request)
                                : null);
                    }

                    /**
                     * Only requests inside the API prefix.
                     *
                     * Deliberately NOT \$request->wantsJson(): that is true for Livewire, for
                     * Inertia, and for any fetch() from your own front end, so it would hand
                     * every web-side error to this renderer and break those integrations in ways
                     * that are hard to trace.
                     */
                    protected function isApiRequest(\$request): bool
                    {
                        return \$request->is(\$this->prefix.'/*')
                            || \$request->is(\$this->prefix);
                    }

                    protected function renderApiException(Throwable \$e, \$request): \Illuminate\Http\JsonResponse
                    {
                        [\$status, \$message, \$errors] = \$this->classifyException(\$e);

                        \$payload = [
                            'success' => false,
                            'message' => \$message,
                        ];

                        if (\$errors !== []) {
                            \$payload['errors'] = \$errors;
                        }

                        // Never in production: a stack trace names your file paths, your
                        // dependencies and often your query structure.
                        if (config('app.debug')) {
                            \$payload['debug'] = [
                                'exception' => get_class(\$e),
                                'file' => \$e->getFile(),
                                'line' => \$e->getLine(),
                                'trace' => collect(\$e->getTrace())->take(10)->map(fn (array \$frame): array => [
                                    'file' => \$frame['file'] ?? null,
                                    'line' => \$frame['line'] ?? null,
                                    'function' => \$frame['function'] ?? null,
                                ])->all(),
                            ];
                        }

                        return response()->json(\$payload, \$status);
                    }

                    /**
                     * @return array{0: int, 1: string, 2: array<mixed>}
                     */
                    protected function classifyException(Throwable \$e): array
                    {
                        return match (true) {
                            \$e instanceof \Illuminate\Validation\ValidationException
                                => [\$e->status, 'The given data was invalid.', \$e->errors()],

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

                            \$e instanceof \Illuminate\Routing\Exceptions\InvalidSignatureException
                                => [403, 'Invalid or expired signature.', []],

                            \$e instanceof \Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException
                                => [429, 'Too many requests.', []],

                            // Must come after the specific HttpExceptions above, since they all
                            // extend it.
                            \$e instanceof \Symfony\Component\HttpKernel\Exception\HttpException
                                => [\$e->getStatusCode(), \$e->getMessage() ?: 'HTTP error.', []],

                            // The message can contain SQL and column names, so it is replaced.
                            \$e instanceof \Illuminate\Database\QueryException
                                => [500, 'A database error occurred.', []],

                            default => [500, 'An unexpected error occurred.', []],
                        };
                    }
                }

                PHP;
    }

    /**
     * The middleware stack, rendered as PHP array entries.
     *
     * Read from anvil.api.middleware, which anvil:generate-api populates from
     * --auth, --guard, --throttle and --middleware. The legacy anvil.api_middleware
     * key is honoured as a fallback for configs that predate that block.
     */
    protected function middlewareList(): string
    {
        $configured = (array) config('anvil.api.middleware', config('anvil.api_middleware', ['auth:sanctum']));

        $entries = ["        'api',", '        ForceJsonResponse::class,'];

        foreach ($configured as $middleware) {
            $middleware = trim((string) $middleware);

            // 'api' is already first, and ForceJsonResponse is added by class name.
            if ($middleware === '' || $middleware === 'api') {
                continue;
            }

            $entries[] = "        '".addslashes($middleware)."',";
        }

        return implode("\n", array_values(array_unique($entries)));
    }

    /**
     * Add a version to $versions in an existing provider.
     *
     * @return array<string, mixed>
     */
    protected function appendVersion(string $path, string $version, GenerationOptions $options): array
    {
        $contents = (string) file_get_contents($path);

        if (str_contains($contents, "'{$version}' =>")) {
            return $this->result('Provider', 'ForceJsonApiServiceProvider', $path, 'skipped', "{$version} already registered");
        }

        if (! str_contains($contents, self::MANAGED_MARKER)) {
            return $this->result(
                'Provider',
                'ForceJsonApiServiceProvider',
                $path,
                'skipped',
                'managed marker missing — add "'.$version.'" => "routes/api/'.$version.'.php" to $versions by hand',
            );
        }

        if ($options->dryRun) {
            return $this->result('Provider', 'ForceJsonApiServiceProvider', $path, 'dry-run', "would append {$version}");
        }

        $updated = str_replace(
            self::MANAGED_MARKER,
            self::MANAGED_MARKER."\n        '{$version}' => 'routes/api/{$version}.php',",
            $contents,
        );

        file_put_contents($path, $updated);

        return $this->result('Provider', 'ForceJsonApiServiceProvider', $path, 'success', null, "appended {$version}");
    }

    // -----------------------------------------------------------------------
    // 3. Bootstrap registration
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
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

    /**
     * The base path this generator mounts routes at — the same value the spec
     * documents. Exposed so a command can report it, and so the two cannot be
     * computed differently.
     */
    public static function apiBasePath(GenerationOptions $options): string
    {
        return OpenApiLocator::apiBasePath($options->getApiVersionSlug());
    }

    protected function putFile(string $path, string $content): void
    {
        $dir = dirname($path);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return;
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
