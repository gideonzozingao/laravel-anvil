<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Console;

use Illuminate\Console\Command;
use Livewire\Component;
use PragmaRX\Google2FA\Google2FA;
use Zuqongtech\LaravelAnvil\Console\Concerns\RendersScaffoldOutput;
use Zuqongtech\LaravelAnvil\Support\Auth\Ui;
use Zuqongtech\LaravelAnvil\Support\AuthScaffolder;
use Zuqongtech\LaravelAnvil\Support\DatabaseInspector;
use Zuqongtech\LaravelAnvil\Support\ScaffoldReport;

/**
 * Scaffolds a complete authentication + authorization layer as Livewire 3
 * components, driven by introspection of the users table and its role /
 * permission relationships.
 *
 * Generates: login, register, logout, forgot/reset password, email verification,
 * two-factor authentication (challenge + setup), account lockout with login
 * throttling, RBAC middleware and gates backed by the schema's own
 * roles/permissions tables, a User authorization trait, a guest layout styled to
 * match the web scaffold, and the auth routes.
 *
 * Pre-flight checks run before anything is written: the required columns must
 * exist on the users table, the guard must be configured, and the packages the
 * generated code imports must be installed. Generating code that cannot possibly
 * run is worse than refusing.
 *
 * Output follows the same shape as anvil:forge-webapp — pre-flight, configuration
 * table, warnings, connection, generation plan, progress, grouped summary, tail,
 * next steps — via RendersScaffoldOutput. The two commands previously looked like
 * different tools, and the sequence is deliberate: warnings come after the table
 * so the configuration they refer to is still on screen.
 */

class GenerateAuthCommand extends Command
{
    use RendersScaffoldOutput;

    protected $signature = 'anvil:forge-auth
                            {--connection=       : Database connection to introspect}
                            {--schema=           : Schema the users table lives in (multi-schema DBs)}
                            {--users-table=users : The users/authenticatable table}
                            {--guard=web         : Auth guard the components authenticate against}
                            {--namespace=App\\Livewire\\Auth : Namespace for the generated Livewire auth components}
                            {--layout=           : Guest layout view to extend (default: generates layouts.guest)}
                            {--accent=indigo     : Tailwind accent colour for the generated UI: indigo|blue|emerald|violet|rose|slate}
                            {--default-role=     : Role name assigned to newly registered users}
                            {--roles-table=roles : Roles table (RBAC)}
                            {--permissions-table=permissions : Permissions table (RBAC)}
                            {--dark              : Include the dark-mode toggle in the guest layout}
                            {--no-2fa            : Skip two-factor authentication scaffolding}
                            {--no-lockout        : Skip account lockout + throttling}
                            {--no-verification   : Skip email verification flow}
                            {--force             : Overwrite existing files}
                            {--backup            : Back up existing files before overwriting}
                            {--dry-run           : Preview without writing files}';

    protected $description = 'Scaffold Livewire authentication (login, register, 2FA, lockout) and RBAC from the users table';
    /** Columns the generated components reference directly. */
    private const REQUIRED_COLUMNS = ['email', 'password'];

    public function handle(): int
    {
        $this->info('🔧 Running pre-flight checks...');

        if (! class_exists(Component::class)) {
            $this->error('This command generates Livewire 3 components. Install it first:');
            $this->line('   composer require livewire/livewire');

            return self::FAILURE;
        }

        $accent = strtolower(trim((string) $this->option('accent')));

        if (! Ui::supportsAccent($accent)) {
            $this->error(sprintf(
                'Unknown --accent "%s". Expected one of: %s.',
                $accent,
                implode(', ', Ui::accents()),
            ));

            return self::FAILURE;
        }

        $guard = (string) $this->option('guard');

        if (($problem = $this->checkGuard($guard)) !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $connection = (string) ($this->option('connection') ?: config('database.default'));

        try {
            $inspector = new DatabaseInspector($connection);
        } catch (\Throwable $e) {
            $this->error('Could not connect to the database: ' . $e->getMessage());

            return self::FAILURE;
        }

        $usersTable = (string) $this->option('users-table');
        $schema = $this->option('schema') ?: null;

        if (($problem = $this->checkUsersTable($inspector, $connection, $usersTable, $schema)) !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $columns = array_map(strval(...), array_column($inspector->getColumns($usersTable, $schema), 'name'));

        if (($problem = $this->checkRequiredColumns($usersTable, $columns)) !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $scaffolder = new AuthScaffolder($inspector, $this->scaffolderConfig($connection, $schema, $usersTable, $guard, $accent));

        // The context knows things the checks above cannot: whether the RBAC
        // tables have the shape the middleware assumes, whether --default-role
        // exists, whether the schema/guard pair is coherent. Asking it was
        // always the intent; nothing was calling it.
        if (($problem = $scaffolder->validate()) !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $this->info("✅ Pre-flight checks passed.\n");

        // ── Heading, configuration, warnings ─────────────────────────────────
        $this->renderHeading('🔐', 'Authentication Scaffold');
        $this->renderConfigTable($this->configRows($inspector, $scaffolder, $connection, $usersTable, $schema));
        $this->renderWarnings($this->optionalGaps($columns));

        // ── Connection, mode, plan ───────────────────────────────────────────
        $this->renderConnectionLine($connection, $inspector->getDriver(), $inspector->getDatabaseName());
        $this->renderModeLine(
            '🔐',
            'Auth scaffold',
            $guard,
            'Livewire components, guest layout, RBAC middleware and auth routes',
        );

        $this->renderGenerationPlan(
            $scaffolder->plannedParts(),
            $this->planDetails($scaffolder, $guard, $schema, $usersTable),
        );

        // ── Generate, with a progress bar over the parts ──────────────────────
        $parts = $scaffolder->plannedParts();
        $this->startProgress(count($parts), 'Starting...');

        try {
            $results = $scaffolder->generate(function (string $label): void {
                $this->advanceProgress($label);
            });
        } catch (\RuntimeException $e) {
            $this->finishProgress();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->finishProgress();

        return $this->report($results, $scaffolder, $guard);
    }

    // -----------------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function scaffolderConfig(
        string $connection,
        ?string $schema,
        string $usersTable,
        string $guard,
        string $accent,
    ): array {
        return [
            'connection' => $connection,
            'schema' => $schema,
            'users_table' => $usersTable,
            'guard' => $guard,
            'namespace' => trim((string) $this->option('namespace'), '\\'),
            'layout' => $this->option('layout') ?: null,
            'accent' => $accent,
            'default_role' => $this->option('default-role') ?: null,
            'roles_table' => (string) $this->option('roles-table'),
            'permissions_table' => (string) $this->option('permissions-table'),
            'two_factor' => ! $this->option('no-2fa'),
            'lockout' => ! $this->option('no-lockout'),
            'verification' => ! $this->option('no-verification'),
            'dark_mode' => (bool) $this->option('dark'),
            'force' => (bool) $this->option('force'),
            'backup' => (bool) $this->option('backup'),
            'dry_run' => (bool) $this->option('dry-run'),
        ];
    }

    // -----------------------------------------------------------------------
    // Pre-flight
    // -----------------------------------------------------------------------

    /**
     * A guard that is not configured produces components whose attempt() always
     * throws "Auth guard [x] is not defined" — at request time, on a login page,
     * long after this command reported success.
     */
    private function checkGuard(string $guard): ?string
    {
        $guards = (array) config('auth.guards', []);

        if (! array_key_exists($guard, $guards)) {
            return sprintf(
                "Auth guard '%s' is not defined in config/auth.php.%s",
                $guard,
                $guards === [] ? '' : "\n   Configured guards: " . implode(', ', array_keys($guards)),
            );
        }

        $providerName = $guards[$guard]['provider'] ?? null;

        if ($providerName === null) {
            return sprintf("Auth guard '%s' has no provider configured in config/auth.php.", $guard);
        }

        $provider = config("auth.providers.{$providerName}");

        if ($provider === null) {
            return sprintf(
                "Auth guard '%s' references provider '%s', which is not defined in config/auth.php.",
                $guard,
                $providerName,
            );
        }

        $model = $provider['model'] ?? null;

        if (is_string($model) && ! class_exists($model)) {
            return sprintf(
                "Auth provider '%s' points at model %s, which does not exist.\n"
                    . '   Generate it first (anvil:forge --models --tables=%s) or fix config/auth.php.',
                $providerName,
                $model,
                (string) $this->option('users-table'),
            );
        }

        return null;
    }

    private function checkUsersTable(
        DatabaseInspector $inspector,
        string $connection,
        string $usersTable,
        ?string $schema,
    ): ?string {
        $tables = array_map(strval(...), array_column($inspector->getAllSchemaTables($schema), 'table'));

        if ($this->containsInsensitive($tables, $usersTable)) {
            return null;
        }

        // A near-miss is the common case (users vs user vs app_users), so offer
        // the candidates rather than just refusing.
        $candidates = array_values(array_filter(
            $tables,
            static fn(string $table): bool => str_contains(strtolower($table), 'user')
                || str_contains(strtolower($table), 'account'),
        ));

        return sprintf(
            "Table '%s' was not found on connection [%s]%s.%s\n   Pass --users-table= (and --schema= for multi-schema databases).",
            $usersTable,
            $connection,
            $schema !== null ? " in schema '{$schema}'" : '',
            $candidates === [] ? '' : "\n   Candidates: " . implode(', ', array_slice($candidates, 0, 8)),
        );
    }

    /**
     * @param  list<string>  $columns
     */
    private function checkRequiredColumns(string $usersTable, array $columns): ?string
    {
        $missing = [];

        // Case-insensitive: SQL Server and quoted Postgres identifiers do not
        // fold the way the rest of this comparison assumes.
        foreach (self::REQUIRED_COLUMNS as $required) {
            if (! $this->containsInsensitive($columns, $required)) {
                $missing[] = $required;
            }
        }

        if ($missing === []) {
            return null;
        }

        return sprintf(
            "Table '%s' is missing the column(s) the generated components authenticate against: %s.\n"
                . '   Anvil would emit code referencing attributes that do not exist. Add the columns, or point '
                . '--users-table at the right table.',
            $usersTable,
            implode(', ', $missing),
        );
    }

    /**
     * Non-fatal gaps: the scaffold still works, but a feature will be inert until
     * the operator acts. Better said now than discovered in production.
     *
     * Returned rather than printed, so the caller controls where they appear —
     * these used to print above the configuration table, pushing the very settings
     * they refer to off the top of a short terminal.
     *
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function optionalGaps(array $columns): array
    {
        $warnings = [];

        if (! $this->containsInsensitive($columns, 'name')) {
            $warnings[] = 'No "name" column — the register form will omit that field.';
        }

        if (! $this->containsInsensitive($columns, 'email_verified_at') && ! $this->option('no-verification')) {
            $warnings[] = 'No "email_verified_at" column — email verification cannot work. '
                . 'Add the column or pass --no-verification.';
        }

        if (! $this->option('no-lockout')) {
            $lockoutColumns = [];

            foreach (['failed_login_attempts', 'locked_until'] as $column) {
                if (! $this->containsInsensitive($columns, $column)) {
                    $lockoutColumns[] = $column;
                }
            }

            if ($lockoutColumns !== []) {
                $warnings[] = 'Missing lockout column(s) ' . implode(', ', $lockoutColumns)
                    . ' — a migration is generated for them; run it before signing in, or pass --no-lockout.';
            }
        }

        if (! $this->containsInsensitive($columns, 'last_login_at')) {
            $warnings[] = 'No "last_login_at" column — login stamps it; the generated migration adds it.';
        }

        if (! $this->option('no-2fa') && ! class_exists(Google2FA::class)) {
            $warnings[] = 'pragmarx/google2fa is not installed; the generated TwoFactorAuthenticationService '
                . 'will not resolve until you run: composer require pragmarx/google2fa';
        }

        // Silent double-hashing is the single most common auth bug in generated
        // code: a `hashed` cast is idempotent, a hand-rolled mutator is not.
        $model = config('auth.providers.' . (config("auth.guards.{$this->option('guard')}.provider", 'users')) . '.model');

        if (is_string($model) && class_exists($model) && method_exists($model, 'setPasswordAttribute')) {
            $warnings[] = sprintf(
                '%s defines setPasswordAttribute() — the generated register form calls Hash::make(), so the '
                    . 'mutator would hash the hash. Remove the mutator and use the "hashed" cast instead.',
                class_basename($model),
            );
        }

        return $warnings;
    }

    /**
     * @param  list<string>  $haystack
     */
    private function containsInsensitive(array $haystack, string $needle): bool
    {
        foreach ($haystack as $candidate) {
            if (strcasecmp($candidate, $needle) === 0) {
                return true;
            }
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // Output
    // -----------------------------------------------------------------------

    /**
     * @return array<int, array{0: string, 1: string|null}>
     */
    private function configRows(
        DatabaseInspector $inspector,
        AuthScaffolder $scaffolder,
        string $connection,
        string $usersTable,
        ?string $schema,
    ): array {
        $skipped = $scaffolder->skippedParts();

        $rows = [
            ['Connection', $connection . ' (' . $inspector->getDriver() . ')'],
            ['Users table', ($schema !== null ? $schema . '.' : '') . $usersTable],
            ['Guard', (string) $this->option('guard')],
            ['Components', trim((string) $this->option('namespace'), '\\')],
            ['Guest layout', $this->option('layout') ?: 'layouts.guest (generated)'],
            ['Accent', (string) $this->option('accent')],
            ['Dark mode', $this->option('dark') ? 'toggle included' : 'follows system only'],
            ['Two-factor', $this->option('no-2fa') ? 'off' : 'challenge + setup'],
            ['Lockout', $this->option('no-lockout') ? 'off' : 'on (5 attempts, 15 min)'],
            ['Verification', $this->option('no-verification') ? 'off' : 'on'],
            ['RBAC', $scaffolder->rbacDetected()
                ? 'roles + permissions tables detected'
                : 'not detected — helpers assume Role/Permission models'],
            ['Skipped', $skipped === [] ? null : implode(', ', $skipped)],
        ];

        if ($this->option('dry-run')) {
            $rows[] = ['Mode', 'dry run — no files will be written'];
        }

        return $rows;
    }

    /**
     * @return array<string, string|null>
     */
    private function planDetails(
        AuthScaffolder $scaffolder,
        string $guard,
        ?string $schema,
        string $usersTable,
    ): array {
        $namespace = trim((string) $this->option('namespace'), '\\');

        return [
            'Guard' => $guard,
            'Users table' => ($schema !== null ? $schema . '.' : '') . $usersTable,
            'Components' => $namespace . '\\',
            'Views' => 'resources/views/auth/ (login, register, forgot-password, reset-password, verify-email)',
            'Guest layout' => $this->option('layout') ?: 'resources/views/layouts/guest.blade.php',
            'Route file' => 'routes/auth.php',
            'Middleware' => 'EnsureUserHasRole, EnsureUserHasPermission',
            'Provider' => 'App\\Providers\\AnvilAuthServiceProvider',
            'Migrations' => $this->option('no-lockout') && $this->option('no-2fa')
                ? null
                : 'database/migrations/ (login security and 2FA columns)',
            'Skipped' => $scaffolder->skippedParts() === []
                ? null
                : implode(', ', $scaffolder->skippedParts()),
        ];
    }

    /**
     * @param  list<array{type: string, name: string, status: string, reason?: string}>  $results
     */
    private function report(array $results, AuthScaffolder $scaffolder, string $guard): int
    {
        $report = ScaffoldReport::fromResults($results);

        $this->renderItemisedResults($results);
        $this->renderSummary($report);

        $this->renderCompletion('🔐', sprintf('Auth scaffold complete [%s].', $guard), [
            'Components' => trim((string) $this->option('namespace'), '\\') . '\\',
            'Views' => 'resources/views/auth/',
            'Routes' => 'routes/auth.php (require it from routes/web.php)',
            'Middleware' => 'App\\Http\\Middleware\\ (role, permission)',
        ]);

        $this->renderNextSteps($scaffolder->postInstallNotesByPart());
        $this->renderDone((bool) $this->option('dry-run'));

        // A partial scaffold is not a success: exit non-zero so CI notices.
        return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
    }
}
