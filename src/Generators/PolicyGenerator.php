<?php

namespace Zuqongtech\LaravelAnvil\Generators;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates a Laravel Policy class for each model.
 *
 * The generated policy:
 *  - Extends nothing (plain class) — compatible with Laravel's automatic
 *    policy discovery when following the Model → ModelPolicy naming convention
 *  - Covers all standard gate abilities:
 *      viewAny, view, create, update, delete
 *  - Adds restore / forceDelete abilities when the model uses SoftDeletes
 *  - Detects an ownership column (default: user_id, configurable via
 *    config('anvil.generators.policies.ownership_column')) and emits
 *    ownership checks on write abilities; falls back to `true` when absent
 *  - Generates a before() hook stub for super-admin bypass patterns
 *
 * Auto-registration:
 *  When config('anvil.generators.policies.auto_register') is true the
 *  generator appends a `$policies` entry to AuthServiceProvider.
 *  The binding is idempotent — re-running the command will not duplicate it.
 *
 * Example generated file (Post model, user_id ownership):
 *
 *   class PostPolicy
 *   {
 *       public function before(User $user, string $ability): bool|null { … }
 *       public function viewAny(User $user): bool { return true; }
 *       public function view(User $user, Post $post): bool { return true; }
 *       public function create(User $user): bool { return true; }
 *       public function update(User $user, Post $post): bool { return $user->id === $post->user_id; }
 *       public function delete(User $user, Post $post): bool { return $user->id === $post->user_id; }
 *       public function restore(User $user, Post $post): bool { … }
 *       public function forceDelete(User $user, Post $post): bool { … }
 *   }
 */
final class PolicyGenerator implements Generator
{
    public function supports(GenerationOptions $options): bool
    {
        return $options->policies ?? false;
    }

    public function getName(): string
    {
        return 'Policy';
    }

    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $policyName = $meta->model.'Policy';
        $path = app_path("Policies/{$policyName}.php");

        if (file_exists($path) && ! $options->force) {
            return [
                'type' => $this->getName(),
                'name' => $policyName,
                'path' => $path,
                'status' => 'skipped',
                'reason' => 'already exists',
            ];
        }

        $namespace = $options->getNamespace();
        $content = $this->buildPolicy($meta, $namespace);

        if (! $options->dryRun) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, $content);

            if (config('anvil.generators.policies.auto_register', false)) {
                $this->registerPolicy($meta, $namespace);
            }
        }

        return [
            'type' => $this->getName(),
            'name' => $policyName,
            'path' => $path,
            'status' => 'success',
            'action' => file_exists($path) ? 'overwritten' : 'created',
        ];
    }

    // -----------------------------------------------------------------------
    // Builder
    // -----------------------------------------------------------------------

    protected function buildPolicy(ModelMetadata $meta, string $namespace): string
    {
        $model = $meta->model;
        $policyName = $model.'Policy';
        $fullModel = trim($namespace, '\\').'\\'.$model;
        $variable = lcfirst($model);

        $ownershipColumn = config(
            'anvil.generators.policies.ownership_column',
            config('anvil.generators.gates.ownership_column', 'user_id')
        );

        $hasOwnership = collect($meta->columns)
            ->contains('name', $ownershipColumn);

        $ownerCheck = $hasOwnership
            ? "\$user->id === \${$variable}->{$ownershipColumn}"
            : 'true';

        $softDeleteMethods = $meta->softDeletes
            ? $this->buildSoftDeleteMethods($model, $variable, $ownerCheck)
            : '';

        return <<<PHP
<?php

namespace App\Policies;

use {$fullModel};
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class {$policyName}
{
    use HandlesAuthorization;

    /**
     * Perform pre-authorization checks.
     *
     * Return true to grant all abilities regardless of specific checks.
     * Return false to deny all. Return null to fall through to the ability method.
     *
     * Example super-admin bypass:
     *   if (\$user->isAdmin()) { return true; }
     */
    public function before(User \$user, string \$ability): bool|null
    {
        return null;
    }

    /**
     * Determine whether the user can list {$model} records.
     */
    public function viewAny(User \$user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view a specific {$model}.
     */
    public function view(User \$user, {$model} \${$variable}): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create {$model} records.
     */
    public function create(User \$user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update a {$model}.
     */
    public function update(User \$user, {$model} \${$variable}): bool
    {
        return {$ownerCheck};
    }

    /**
     * Determine whether the user can delete a {$model}.
     */
    public function delete(User \$user, {$model} \${$variable}): bool
    {
        return {$ownerCheck};
    }
{$softDeleteMethods}
}

PHP;
    }

    /**
     * Build restore / forceDelete policy methods for SoftDeletes models.
     */
    protected function buildSoftDeleteMethods(string $model, string $variable, string $ownerCheck): string
    {
        return <<<PHP


    /**
     * Determine whether the user can restore a soft-deleted {$model}.
     */
    public function restore(User \$user, {$model} \${$variable}): bool
    {
        return {$ownerCheck};
    }

    /**
     * Determine whether the user can permanently delete a {$model}.
     */
    public function forceDelete(User \$user, {$model} \${$variable}): bool
    {
        return {$ownerCheck};
    }
PHP;
    }

    // -----------------------------------------------------------------------
    // Auto-registration in AuthServiceProvider
    // -----------------------------------------------------------------------

    /**
     * Append the Model → Policy mapping to the $policies array inside
     * AuthServiceProvider. Creates a minimal AuthServiceProvider when absent.
     * Idempotent — skips if the model is already mapped.
     */
    protected function registerPolicy(ModelMetadata $meta, string $namespace): void
    {
        $authProviderPath = app_path('Providers/AuthServiceProvider.php');

        $model = $meta->model;
        $modelFqn = trim($namespace, '\\').'\\'.$model;
        $policyFqn = "App\\Policies\\{$model}Policy";

        if (! file_exists($authProviderPath)) {
            $this->createAuthServiceProvider($authProviderPath, $modelFqn, $policyFqn);

            return;
        }

        $content = file_get_contents($authProviderPath);

        // Idempotency
        if (str_contains($content, "{$model}Policy::class")) {
            return;
        }

        // Ensure both classes are imported
        foreach ([$modelFqn, $policyFqn] as $fqn) {
            if (! str_contains($content, "use {$fqn};")) {
                $content = preg_replace(
                    '/^(namespace\s+[^;]+;)/m',
                    "$1\n\nuse {$fqn};",
                    $content,
                    1,
                );
            }
        }

        $bindingLine = "        {$model}::class => {$model}Policy::class,";

        // Try to append inside an existing $policies array
        if (preg_match('/protected\s+\$policies\s*=\s*\[/s', $content, $match, PREG_OFFSET_CAPTURE)) {
            $insertPos = $this->findArrayEnd($content, $match[0][1] + strlen($match[0][0]));
            if ($insertPos !== false) {
                $content = substr_replace($content, "\n{$bindingLine}", $insertPos, 0);
                file_put_contents($authProviderPath, $content);

                return;
            }
        }

        // No $policies array found — inject one before boot()
        $policiesBlock = <<<PHP

    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected \$policies = [
{$bindingLine}
    ];

PHP;

        $content = preg_replace(
            '/(public function boot\(\))/s',
            $policiesBlock.'$1',
            $content,
            1,
        );

        file_put_contents($authProviderPath, $content);
    }

    /**
     * Create a minimal AuthServiceProvider with the first policy already wired.
     */
    protected function createAuthServiceProvider(
        string $path,
        string $modelFqn,
        string $policyFqn,
    ): void {
        $model = class_basename($modelFqn);
        $policyName = class_basename($policyFqn);

        $content = <<<PHP
<?php

namespace App\Providers;

use {$modelFqn};
use {$policyFqn};
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected \$policies = [
        {$model}::class => {$policyName}::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        \$this->registerPolicies();
    }
}

PHP;

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Walk the source string from $offset to find the closing bracket of a
     * PHP array (`]`) and return the position just before it for insertion.
     */
    protected function findArrayEnd(string $source, int $offset): int|false
    {
        $depth = 1;
        $len = strlen($source);

        for ($i = $offset; $i < $len; $i++) {
            if ($source[$i] === '[') {
                $depth++;
            } elseif ($source[$i] === ']') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return false;
    }
}
