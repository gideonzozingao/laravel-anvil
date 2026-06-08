<?php

namespace Zuqongtech\LaravelAnvil\Generators;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\Helpers;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Registers web routes for the web scaffold (--web).
 *
 * Appends a `Route::resource('{slug}', \App\Http\Controllers\Web\{Model}Controller::class)`
 * entry to routes/web.php (configurable), wrapped — on first write — in a
 * middleware group (default: ['web', 'auth']) with an anvil-managed marker block.
 * Subsequent models append their resource line inside that same group.
 *
 * SoftDeletes models also get named restore / force-delete routes.
 *
 * Idempotent: a resource already registered is skipped rather than duplicated.
 */
final class WebRouteGenerator implements Generator
{
    private const MARKER = '// anvil:web-routes — do not remove this comment';

    #[\Override]
    public function supports(GenerationOptions $options): bool
    {
        return $options->web ?? false;
    }

    #[\Override]
    public function getName(): string
    {
        return 'WebRoute';
    }

    #[\Override]
    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $slug = Helpers::modelToRouteName($meta->model);
        $controllerFqn = "\\App\\Http\\Controllers\\Web\\{$meta->model}Controller";

        if ($options->dryRun) {
            return [
                'type' => $this->getName(),
                'name' => "Route::resource('{$slug}', ...)",
                'status' => 'dry-run',
            ];
        }

        $routeFile = base_path(config('anvil.web.route_file', 'routes/web.php'));
        $this->ensureRouteFile($routeFile);

        $existing = file_get_contents($routeFile);

        if (str_contains($existing, "resource('{$slug}'")) {
            return [
                'type' => $this->getName(),
                'name' => $slug,
                'status' => 'skipped',
                'reason' => 'route already registered',
            ];
        }

        $block = $this->buildRouteBlock($meta, $slug, $controllerFqn);

        // Insert the resource line just after the marker so all anvil web routes
        // stay grouped together within the configured middleware group.
        if (str_contains($existing, self::MARKER)) {
            $updated = str_replace(
                self::MARKER,
                self::MARKER."\n".$block,
                $existing,
            );
        } else {
            // No managed group yet — create one and append everything.
            $updated = $existing.$this->buildManagedGroup($block);
        }

        file_put_contents($routeFile, $updated);

        return [
            'type' => $this->getName(),
            'name' => $slug,
            'path' => $routeFile,
            'status' => 'success',
        ];
    }

    protected function buildRouteBlock(ModelMetadata $meta, string $slug, string $controllerFqn): string
    {
        $lines = [];
        $lines[] = "    Route::resource('{$slug}', {$controllerFqn}::class);";

        if ($meta->softDeletes) {
            $lines[] = "    Route::patch('{$slug}/{id}/restore', [{$controllerFqn}::class, 'restore'])->name('{$slug}.restore');";
            $lines[] = "    Route::delete('{$slug}/{id}/force', [{$controllerFqn}::class, 'forceDelete'])->name('{$slug}.forceDelete');";
        }

        return implode("\n", $lines);
    }

    protected function buildManagedGroup(string $firstBlock): string
    {
        $middleware = config('anvil.web.middleware', ['web', 'auth']);
        $mwStr = "['".implode("', '", $middleware)."']";
        $marker = self::MARKER;

        return <<<PHP


/*
|--------------------------------------------------------------------------
| Anvil-generated web routes
|--------------------------------------------------------------------------
*/
Route::middleware({$mwStr})->group(function () {
    {$marker}
{$firstBlock}
});

PHP;
    }

    protected function ensureRouteFile(string $path): void
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

use Illuminate\Support\Facades\Route;

PHP
        );
    }
}
