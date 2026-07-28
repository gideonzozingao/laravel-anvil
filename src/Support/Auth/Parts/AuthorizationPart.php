<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Auth\Parts;

use Zuqongtech\LaravelAnvil\Support\Auth\AuthContext;
use Zuqongtech\LaravelAnvil\Support\Auth\Contracts\ScaffoldPart;
use Zuqongtech\LaravelAnvil\Support\Auth\ScaffoldWriter;

/**
 * Authorization: the User trait, the role/permission middleware, and the gate
 * provider.
 *
 * Two corrections to the originals:
 *
 *   1. The super-user check compared with ===, so a MySQL tinyint(1) — which
 *      yields int 1, not true — never matched. It failed closed, which is the
 *      safe direction and exactly why nobody noticed.
 *   2. The provider ran `Permission::pluck('name')` plus N Gate::define() calls
 *      on every boot, including for guests and console commands. The permission
 *      list is now cached, keyed so a permission write invalidates it.
 */
final readonly class AuthorizationPart implements ScaffoldPart
{
    public function supports(AuthContext $context): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'Authorization';
    }

    public function emit(AuthContext $context, ScaffoldWriter $writer): void
    {
        $tokens = $writer->tokens();

        $writer->appFile(
            'Models/Concerns/InteractsWithAuthorization.php',
            $tokens->render($this->trait()),
            'Trait',
            'InteractsWithAuthorization',
        );

        $writer->appFile(
            'Http/Middleware/EnsureUserHasRole.php',
            $tokens->render($this->middleware('EnsureUserHasRole', 'roles', 'hasRole', 'role')),
            'Middleware',
            'EnsureUserHasRole',
        );

        $writer->appFile(
            'Http/Middleware/EnsureUserHasPermission.php',
            $tokens->render($this->middleware('EnsureUserHasPermission', 'permissions', 'hasAnyPermission', 'permission')),
            'Middleware',
            'EnsureUserHasPermission',
        );

        $writer->appFile(
            'Providers/AnvilAuthServiceProvider.php',
            $tokens->render($this->provider()),
            'Provider',
            'AnvilAuthServiceProvider',
        );
    }

    /**
     * @return list<string>
     */
    public function notes(AuthContext $context): array
    {
        $notes = [
            'Add  use App\\Models\\Concerns\\InteractsWithAuthorization;  to your User model, and use the trait in '
                .'the class body.',
            'Register the gate provider in bootstrap/providers.php:  App\\Providers\\AnvilAuthServiceProvider::class',
            "Alias the middleware in bootstrap/app.php withMiddleware():  \$middleware->alias(['role' => "
                .'EnsureUserHasRole::class, \'permission\' => EnsureUserHasPermission::class]);',
        ];

        if ($context->has('is_super_user')) {
            $notes[] = "Cast the super-user flag:  'is_super_user' => 'boolean'  in the User model's \$casts.";
        }

        if (! $context->rbac) {
            $notes[] = sprintf(
                'Tables %s and %s were not both found — the RBAC helpers assume App\\Models\\Role and '
                    .'App\\Models\\Permission exist. Generate them with anvil:generate first.',
                $context->rolesTable,
                $context->permissionsTable,
            );
        }

        return $notes;
    }

    // -----------------------------------------------------------------------
    // Templates
    // -----------------------------------------------------------------------

    private function trait(): string
    {
        return <<<'PHP'
<?php

namespace App\Models\Concerns;

use App\Models\Role;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Role/permission helpers backed by the schema's own roles + permissions tables
 * (single role via role_id, permissions through the role).
 *
 * Add `use InteractsWithAuthorization;` to your User model. Assumes a Role model
 * with a permissions() relationship and a name column.
 */
trait InteractsWithAuthorization
{
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function hasRole(string|array $roles): bool
    {
        if (! $this->role) {
            return false;
        }

        return in_array($this->role->name, (array) $roles, true);
    }

    public function hasPermissionTo(string $permission): bool
    {
        if ($this->isSuperUser()) {
            return true;
        }

        return (bool) $this->role?->permissions?->contains('name', $permission);
    }

    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermissionTo($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Truthiness, not identity.
     *
     * A MySQL tinyint(1) read without a cast is int 1, and 1 === true is false —
     * so a strict comparison silently denies every super-user. Casting the
     * attribute to boolean in the model is still worth doing; this is the belt.
     */
    public function isSuperUser(): bool
    {
        return filter_var($this->is_super_user ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
PHP;
    }

    private function middleware(string $class, string $parameter, string $check, string $label): string
    {
        return strtr(<<<'PHP'
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class %CLASS%
{
    public function handle(Request $request, Closure $next, string ...$%PARAM%): Response
    {
        $user = $request->user();

        if (! $user || ! method_exists($user, '%CHECK%') || ! $user->%CHECK%($%PARAM%)) {
            abort(403, 'You do not have the required %LABEL%.');
        }

        return $next($request);
    }
}
PHP, [
            '%CLASS%' => $class,
            '%PARAM%' => $parameter,
            '%CHECK%' => $check,
            '%LABEL%' => $label,
        ]);
    }

    private function provider(): string
    {
        return <<<'PHP'
<?php

namespace App\Providers;

use App\Models\Permission;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Registers a Gate for every row in the permissions table and grants super-users
 * a blanket pass.
 *
 * The permission list is cached. Registering gates ran a query plus one closure
 * per permission on EVERY boot — including for unauthenticated requests, queue
 * workers and artisan commands, none of which will consult a gate. Clear the
 * cache when permissions change:
 *
 *     Cache::forget(AnvilAuthServiceProvider::CACHE_KEY);
 *
 * or call AnvilAuthServiceProvider::flush() from a Permission observer.
 */
class AnvilAuthServiceProvider extends ServiceProvider
{
    public const CACHE_KEY = 'anvil.auth.permissions';

    public const CACHE_SECONDS = 3600;

    public function boot(): void
    {
        Gate::before(fn ($user) => $this->isSuperUser($user) ? true : null);

        foreach ($this->permissions() as $permission) {
            Gate::define($permission, fn ($user) => method_exists($user, 'hasPermissionTo')
                ? $user->hasPermissionTo($permission)
                : false);
        }
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return list<string>
     */
    protected function permissions(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): array {
                if (! Schema::hasTable('%PERMISSIONS_TABLE%')) {
                    return [];
                }

                return Permission::query()->pluck('name')->map(strval(...))->all();
            });
        } catch (\Throwable $e) {
            // Missing table or unreachable database during boot — for instance
            // the first migrate on an empty schema. Reported in debug so a real
            // misconfiguration is not silently indistinguishable from that.
            if (config('app.debug')) {
                report($e);
            }

            return [];
        }
    }

    protected function isSuperUser(mixed $user): bool
    {
        if (is_object($user) && method_exists($user, 'isSuperUser')) {
            return (bool) $user->isSuperUser();
        }

        return filter_var($user->is_super_user ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
PHP;
    }
}
