<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Console;

use Illuminate\Console\Command;
use Livewire\Component;
use PragmaRX\Google2FA\Google2FA;
use Zuqongtech\LaravelAnvil\Support\AuthScaffolder;
use Zuqongtech\LaravelAnvil\Support\DatabaseInspector;

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
 * exist on the users table, and the packages the generated code imports must be
 * installed. Generating code that cannot possibly run is worse than refusing.
 */
class GenerateAuthCommand extends Command
{
    /** Columns the generated components reference directly. */
    private const REQUIRED_COLUMNS = ['email', 'password'];

    protected $signature = 'anvil:forge-auth
                            {--connection=       : Database connection to introspect}
                            {--schema=           : Schema the users table lives in (multi-schema DBs)}
                            {--users-table=users : The users/authenticatable table}
                            {--guard=web         : Auth guard the components authenticate against}
                            {--namespace=App\\Livewire\\Auth : Namespace for the generated Livewire auth components}
                            {--layout=           : Guest layout view to extend (default: generates layouts.guest)}
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

    public function handle(): int
    {
        $this->info('🔐 Anvil — Authentication Scaffold');
        $this->newLine();

        if (! class_exists(Component::class)) {
            $this->error('This command generates Livewire 3 components. Install it first:');
            $this->line('   composer require livewire/livewire');

            return self::FAILURE;
        }

        $connection = (string) ($this->option('connection') ?: config('database.default'));

        try {
            $inspector = new DatabaseInspector($connection);
        } catch (\Throwable $e) {
            $this->error('Could not connect to the database: '.$e->getMessage());

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

        $this->warnAboutOptionalGaps($columns);

        $scaffolder = new AuthScaffolder($inspector, [
            'connection' => $connection,
            'schema' => $schema,
            'users_table' => $usersTable,
            'guard' => (string) $this->option('guard'),
            'namespace' => trim((string) $this->option('namespace'), '\\'),
            'layout' => $this->option('layout') ?: null,
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
        ]);

        $this->summarise($inspector, $scaffolder, $connection, $usersTable, $schema);

        return $this->report($scaffolder->generate(), $scaffolder);
    }

    // -----------------------------------------------------------------------
    // Pre-flight
    // -----------------------------------------------------------------------

    private function checkUsersTable(
        DatabaseInspector $inspector,
        string $connection,
        string $usersTable,
        ?string $schema,
    ): ?string {
        $tables = array_map(strval(...), array_column($inspector->getAllSchemaTables($schema), 'table'));

        if (in_array($usersTable, $tables, true)) {
            return null;
        }

        // A near-miss is the common case (users vs user vs app_users), so offer
        // the candidates rather than just refusing.
        $candidates = array_values(array_filter(
            $tables,
            static fn (string $table): bool => str_contains(strtolower($table), 'user')
                || str_contains(strtolower($table), 'account'),
        ));

        return sprintf(
            "Table '%s' was not found on connection [%s]%s.%s\n   Pass --users-table= (and --schema= for multi-schema databases).",
            $usersTable,
            $connection,
            $schema !== null ? " in schema '{$schema}'" : '',
            $candidates === [] ? '' : "\n   Candidates: ".implode(', ', array_slice($candidates, 0, 8)),
        );
    }

    /**
     * @param  list<string>  $columns
     */
    private function checkRequiredColumns(string $usersTable, array $columns): ?string
    {
        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, $columns));

        if ($missing === []) {
            return null;
        }

        return sprintf(
            "Table '%s' is missing the column(s) the generated components authenticate against: %s.\n"
                .'   Anvil would emit code referencing attributes that do not exist. Add the columns, or point '
                .'--users-table at the right table.',
            $usersTable,
            implode(', ', $missing),
        );
    }

    /**
     * Non-fatal gaps: the scaffold still works, but a feature will be inert until
     * the operator acts. Better said now than discovered in production.
     *
     * @param  list<string>  $columns
     */
    private function warnAboutOptionalGaps(array $columns): void
    {
        $warnings = [];

        if (! in_array('name', $columns, true)) {
            $warnings[] = 'No "name" column — the register form will omit that field.';
        }

        if (! in_array('email_verified_at', $columns, true) && ! $this->option('no-verification')) {
            $warnings[] = 'No "email_verified_at" column — email verification cannot work. '
                .'Add the column or pass --no-verification.';
        }

        if (! $this->option('no-lockout')) {
            $lockoutColumns = array_values(array_diff(['failed_login_attempts', 'locked_until'], $columns));

            if ($lockoutColumns !== []) {
                $warnings[] = 'Missing lockout column(s) '.implode(', ', $lockoutColumns)
                    .' — a migration is generated for them; run it before signing in, or pass --no-lockout.';
            }
        }

        if (! in_array('last_login_at', $columns, true)) {
            $warnings[] = 'No "last_login_at" column — login stamps it; the generated migration adds it.';
        }

        if (! $this->option('no-2fa') && ! class_exists(Google2FA::class)) {
            $warnings[] = 'pragmarx/google2fa is not installed; the generated TwoFactorAuthenticationService '
                .'will not resolve until you run: composer require pragmarx/google2fa';
        }

        foreach ($warnings as $warning) {
            $this->components->warn($warning);
        }

        if ($warnings !== []) {
            $this->newLine();
        }
    }

    // -----------------------------------------------------------------------
    // Output
    // -----------------------------------------------------------------------

    private function summarise(
        DatabaseInspector $inspector,
        AuthScaffolder $scaffolder,
        string $connection,
        string $usersTable,
        ?string $schema,
    ): void {
        $layout = $this->option('layout') ?: 'layouts.guest (generated)';

        $rows = [
            ['Connection', $connection.' ('.$inspector->getDriver().')'],
            ['Users table', ($schema !== null ? $schema.'.' : '').$usersTable],
            ['Guard', (string) $this->option('guard')],
            ['Components', trim((string) $this->option('namespace'), '\\')],
            ['Guest layout', (string) $layout],
            ['Dark mode', $this->option('dark') ? 'toggle included' : 'follows system only'],
            ['Two-factor', $this->option('no-2fa') ? 'off' : 'challenge + setup'],
            ['Lockout', $this->option('no-lockout') ? 'off' : 'on (5 attempts, 15 min)'],
            ['Verification', $this->option('no-verification') ? 'off' : 'on'],
            ['RBAC', $scaffolder->rbacDetected()
                ? 'roles + permissions tables detected'
                : 'not detected — helpers assume Role/Permission models'],
        ];

        if ($this->option('dry-run')) {
            $rows[] = ['Mode', 'dry run — no files will be written'];
        }

        $this->table(['', ''], $rows);
        $this->newLine();
    }

    /**
     * @param  list<array{type: string, name: string, status: string, reason?: string}>  $results
     */
    private function report(array $results, AuthScaffolder $scaffolder): int
    {
        $counts = ['success' => 0, 'skipped' => 0, 'dry-run' => 0, 'failed' => 0];

        foreach ($results as $result) {
            $status = $result['status'] ?? 'success';
            $counts[$status] = ($counts[$status] ?? 0) + 1;

            $icon = match ($status) {
                'success' => '<fg=green>✔</>',
                'dry-run' => '<fg=cyan>◌</>',
                'skipped' => '<fg=gray>–</>',
                default => '<fg=red>✘</>',
            };

            $this->line(sprintf(
                '  %s %-11s %s%s',
                $icon,
                $result['type'],
                $result['name'],
                isset($result['reason']) ? " <fg=gray>({$result['reason']})</>" : '',
            ));
        }

        $this->newLine();
        $this->line(sprintf(
            '  <options=bold>%d written</>   %d skipped   %d previewed   %s',
            $counts['success'],
            $counts['skipped'],
            $counts['dry-run'],
            $counts['failed'] > 0 ? "<fg=red>{$counts['failed']} failed</>" : '0 failed',
        ));

        $notes = $scaffolder->postInstallNotes();

        if ($notes !== []) {
            $this->newLine();
            $this->line('  <options=bold>Next steps</>');

            foreach ($notes as $note) {
                $this->line('   • '.$note);
            }
        }

        $this->newLine();

        // A partial scaffold is not a success: exit non-zero so CI notices.
        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
