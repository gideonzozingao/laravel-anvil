<?php

namespace Zuqongtech\LaravelAnvil\Generators;

use Illuminate\Support\Str;
use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates Gate definitions for a model and appends them to
 * app/Providers/AuthServiceProvider.php (or creates a dedicated
 * GateServiceProvider when AuthServiceProvider is absent).
 *
 * Generated gates follow the naming convention:
 *   viewAny-model, view-model, create-model,
 *   update-model, delete-model, restore-model (when softDeletes),
 *   forceDelete-model (when softDeletes)
 *
 * Each gate checks:
 *   - viewAny / view / create: true by default (override as needed)
 *   - update / delete / restore / forceDelete: ownership check when a
 *     user_id column exists, otherwise true
 *
 * Because gates are registered globally this generator appends a single
 * block into the AuthServiceProvider rather than creating per-model files,
 * keeping them centralised and easy to review.
 */
final class GateGenerator implements Generator
{
    public function supports(GenerationOptions $options): bool
    {
        return $options->gates ?? false;
    }

    public function getName(): string
    {
        return 'Gate';
    }

    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        if ($options->dryRun) {
            return [
                'type' => $this->getName(),
                'name' => 'Gate definitions for '.$meta->model,
                'status' => 'dry-run',
            ];
        }

        $this->appendGates($meta, $options);

        return [
            'type' => $this->getName(),
            'name' => 'Gate definitions for '.$meta->model,
            'status' => 'success',
        ];
    }

    protected function appendGates(ModelMetadata $meta, GenerationOptions $options): void
    {
        $providerPath = app_path('Providers/AuthServiceProvider.php');
        $gatePath = app_path('Providers/GateServiceProvider.php');

        $gateBlock = $this->buildGateBlock($meta, $options);

        if (file_exists($providerPath)) {
            $this->injectIntoAuthServiceProvider($providerPath, $meta->model, $gateBlock);
        } else {
            $this->upsertGateServiceProvider($gatePath, $meta->model, $gateBlock);
        }
    }

    protected function buildGateBlock(ModelMetadata $meta, GenerationOptions $options): string
    {
        $model = $meta->model;
        $slug = Str::kebab($model);
        $variable = lcfirst($model);
        $hasOwnership = collect($meta->columns)->contains('name', 'user_id');
        $ownerCheck = $hasOwnership
            ? "\$user->id === \${$variable}->user_id"
            : 'true';

        $namespace = trim($options->getNamespace(), '\\');
        $fullModel = "\\{$namespace}\\{$model}";

        $lines = [];
        $lines[] = "        // --- {$model} gates ---";
        $lines[] = "        Gate::define('viewAny-{$slug}', fn (\\App\\Models\\User \$user) => true);";
        $lines[] = "        Gate::define('view-{$slug}', fn (\\App\\Models\\User \$user, {$fullModel} \${$variable}) => true);";
        $lines[] = "        Gate::define('create-{$slug}', fn (\\App\\Models\\User \$user) => true);";
        $lines[] = "        Gate::define('update-{$slug}', fn (\\App\\Models\\User \$user, {$fullModel} \${$variable}) => {$ownerCheck});";
        $lines[] = "        Gate::define('delete-{$slug}', fn (\\App\\Models\\User \$user, {$fullModel} \${$variable}) => {$ownerCheck});";

        if ($meta->softDeletes) {
            $lines[] = "        Gate::define('restore-{$slug}', fn (\\App\\Models\\User \$user, {$fullModel} \${$variable}) => {$ownerCheck});";
            $lines[] = "        Gate::define('forceDelete-{$slug}', fn (\\App\\Models\\User \$user, {$fullModel} \${$variable}) => {$ownerCheck});";
        }

        return implode("\n", $lines);
    }

    protected function injectIntoAuthServiceProvider(string $path, string $model, string $gateBlock): void
    {
        $content = file_get_contents($path);

        // Idempotency: skip if gates for this model already present
        if (str_contains($content, Str::kebab($model)."' gates")) {
            return;
        }

        // Ensure Gate is imported
        if (! str_contains($content, 'use Illuminate\\Support\\Facades\\Gate;')) {
            $content = str_replace(
                'use Illuminate\\Support\\ServiceProvider;',
                "use Illuminate\\Support\\Facades\\Gate;\nuse Illuminate\\Support\\ServiceProvider;",
                $content,
            );
        }

        // Append inside boot() — look for closing brace of the method
        if (preg_match('/public function boot\(\).*?{/s', $content, $m, PREG_OFFSET_CAPTURE)) {
            // Find the matching closing brace
            $insertPos = $this->findMethodEnd($content, $m[0][1] + strlen($m[0][0]));
            if ($insertPos !== false) {
                $content = substr_replace(
                    $content,
                    "\n{$gateBlock}\n",
                    $insertPos,
                    0,
                );
            }
        }

        file_put_contents($path, $content);
    }

    protected function upsertGateServiceProvider(string $path, string $model, string $gateBlock): void
    {
        if (! file_exists($path)) {
            $content = <<<PHP
<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class GateServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
{$gateBlock}
    }
}

PHP;
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, $content);

            return;
        }

        $content = file_get_contents($path);

        if (str_contains($content, Str::kebab($model)."' gates")) {
            return;
        }

        $updated = str_replace(
            "    }\n}",
            "{$gateBlock}\n    }\n}",
            $content,
        );

        file_put_contents($path, $updated);
    }

    /**
     * Find the position of the closing brace for the method starting at $offset.
     * Returns position just before the closing brace so we can insert before it.
     */
    protected function findMethodEnd(string $source, int $offset): int|false
    {
        $depth = 1;
        $len = strlen($source);

        for ($i = $offset; $i < $len; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return false;
    }
}
