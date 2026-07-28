<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Auth;

use Zuqongtech\LaravelAnvil\Support\DatabaseInspector;

/**
 * Everything the auth scaffold needs to know, resolved once and then read-only.
 *
 * This replaces the untyped $config array plus the has()/enabled() helpers that
 * every method reached into. Two things fall out of making it a value object:
 * introspection happens exactly once rather than per template, and the questions
 * the templates actually ask — "does this table have a name column", "should a
 * default role be assigned" — become named methods instead of conditions
 * duplicated across a dozen call sites.
 */
final readonly class AuthContext
{
    /**
     * @param  list<string>  $userColumns
     */
    private function __construct(
        public string $connection,
        public ?string $schema,
        public string $usersTable,
        public string $guard,
        public string $namespace,
        public ?string $layout,
        public ?string $defaultRole,
        public string $rolesTable,
        public string $permissionsTable,
        public bool $twoFactor,
        public bool $lockout,
        public bool $verification,
        public bool $darkMode,
        public bool $force,
        public bool $backup,
        public bool $dryRun,
        public bool $rbac,
        public array $userColumns,
        public int $lockThreshold,
        public int $lockMinutes,
        public int $throttleMax,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function make(DatabaseInspector $inspector, array $config): self
    {
        $schema = isset($config['schema']) && $config['schema'] !== '' ? (string) $config['schema'] : null;
        $usersTable = (string) ($config['users_table'] ?? 'users');

        $columns = array_map(strval(...), array_column(
            $inspector->getColumns($usersTable, $schema),
            'name',
        ));

        $tables = array_map(strval(...), array_column(
            $inspector->getAllSchemaTables($schema),
            'table',
        ));

        $rolesTable = (string) ($config['roles_table'] ?? 'roles');
        $permissionsTable = (string) ($config['permissions_table'] ?? 'permissions');

        return new self(
            connection: (string) ($config['connection'] ?? ''),
            schema: $schema,
            usersTable: $usersTable,
            guard: (string) ($config['guard'] ?? 'web'),
            namespace: trim((string) ($config['namespace'] ?? 'App\\Livewire\\Auth'), '\\'),
            layout: isset($config['layout']) && $config['layout'] !== '' ? (string) $config['layout'] : null,
            defaultRole: isset($config['default_role']) && $config['default_role'] !== ''
                ? (string) $config['default_role']
                : null,
            rolesTable: $rolesTable,
            permissionsTable: $permissionsTable,
            twoFactor: (bool) ($config['two_factor'] ?? false),
            lockout: (bool) ($config['lockout'] ?? false),
            verification: (bool) ($config['verification'] ?? false),
            darkMode: (bool) ($config['dark_mode'] ?? false),
            force: (bool) ($config['force'] ?? false),
            backup: (bool) ($config['backup'] ?? false),
            dryRun: (bool) ($config['dry_run'] ?? false),
            rbac: in_array($rolesTable, $tables, true) && in_array($permissionsTable, $tables, true),
            userColumns: $columns,
            lockThreshold: max(1, (int) ($config['lock_threshold'] ?? 5)),
            lockMinutes: max(1, (int) ($config['lock_minutes'] ?? 15)),
            throttleMax: max(1, (int) ($config['throttle_max'] ?? 5)),
        );
    }

    // -----------------------------------------------------------------------
    // Schema questions
    // -----------------------------------------------------------------------

    public function has(string $column): bool
    {
        return in_array($column, $this->userColumns, true);
    }

    public function hasAll(string ...$columns): bool
    {
        foreach ($columns as $column) {
            if (! $this->has($column)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Columns from the given list that the table does NOT have.
     *
     * @return list<string>
     */
    public function missing(string ...$columns): array
    {
        return array_values(array_diff($columns, $this->userColumns));
    }

    // -----------------------------------------------------------------------
    // Derived decisions
    // -----------------------------------------------------------------------

    public function layoutView(): string
    {
        return $this->layout ?? 'layouts.guest';
    }

    public function generatesGuestLayout(): bool
    {
        return $this->layout === null;
    }

    /**
     * Whether registration should assign a role.
     *
     * The previous condition omitted the default-role check, so the common case
     * generated `Role::where('name', '')->value('id')` — a query that always
     * returns null, assigns nothing, and reports nothing.
     */
    public function assignsDefaultRole(): bool
    {
        return $this->rbac && $this->has('role_id') && $this->defaultRole !== null;
    }

    /**
     * Whether login may write last_login_at / last_login_ip.
     *
     * Kept separate from the lockout flag. Previously login wrote these
     * unconditionally while the migration that adds them was gated on lockout,
     * so --no-lockout on a stock table produced a QueryException on every
     * successful sign-in.
     */
    public function stampsLastLogin(): bool
    {
        return $this->lockout || $this->hasAll('last_login_at', 'last_login_ip');
    }

    public function needsLoginSecurityMigration(): bool
    {
        return $this->stampsLastLogin()
            && $this->missing('failed_login_attempts', 'locked_until', 'last_login_at', 'last_login_ip') !== [];
    }

    public function needsTwoFactorMigration(): bool
    {
        return $this->twoFactor && ! $this->has('two_factor_secret');
    }

    // -----------------------------------------------------------------------
    // Paths
    // -----------------------------------------------------------------------

    /**
     * Filesystem path for a generated Livewire component.
     *
     * The previous implementation did substr($ns, strlen('App\\')) with no
     * prefix check, so --namespace=Domain\Auth silently produced "in/Auth".
     * validate() rejects that up front; this method can then assume the prefix.
     */
    public function componentPath(string $class): string
    {
        $relative = substr($this->namespace, strlen($this->appNamespace()));

        return app_path(trim(str_replace('\\', '/', $relative), '/').'/'.$class.'.php');
    }

    public function appNamespace(): string
    {
        // Container-resolved so a renamed application namespace still works.
        try {
            return trim(app()->getNamespace(), '\\').'\\';
        } catch (\Throwable) {
            return 'App\\';
        }
    }

    /**
     * A reason the scaffold cannot run, or null.
     *
     * Called by the command before anything is written — a generator that emits
     * files to a path it guessed wrong is worse than one that refuses.
     */
    public function validate(): ?string
    {
        $app = $this->appNamespace();

        if (! str_starts_with($this->namespace.'\\', $app)) {
            return sprintf(
                '--namespace must sit under the application namespace (%s). "%s" cannot be mapped to a path.',
                rtrim($app, '\\'),
                $this->namespace,
            );
        }

        if ($this->defaultRole !== null && ! $this->rbac) {
            return sprintf(
                '--default-role="%s" was given but the roles/permissions tables (%s, %s) were not both found.',
                $this->defaultRole,
                $this->rolesTable,
                $this->permissionsTable,
            );
        }

        if ($this->defaultRole !== null && ! $this->has('role_id')) {
            return sprintf(
                '--default-role="%s" needs a role_id column on %s. For a pivot-based RBAC, assign the role in a '
                    .'Registered event listener instead.',
                $this->defaultRole,
                $this->usersTable,
            );
        }

        return null;
    }
}
