<?php

namespace Zuqongtech\LaravelAnvil\Console;

use Illuminate\Console\Command;
use Zuqongtech\LaravelAnvil\Support\AuthScaffolder;
use Zuqongtech\LaravelAnvil\Support\DatabaseInspector;

/**
 * Scaffolds a complete authentication + authorization layer as Livewire 3
 * components, driven by introspection of the users table and its role /
 * permission relationships.
 *
 * Generates: login, register, logout, forgot/reset password, email
 * verification, two-factor authentication, account lockout + login throttling,
 * RBAC middleware and gates (backed by the schema's own roles/permissions
 * tables), a User authorization trait, a guest layout, and auth routes.
 */
class GenerateAuthCommand extends Command
{
    protected $signature = 'anvil:generate-auth
                            {--connection=      : Database connection to introspect}
                            {--schema=          : Schema the users table lives in (multi-schema DBs)}
                            {--users-table=users : The users/authenticatable table}
                            {--guard=web        : Auth guard the components authenticate against}
                            {--namespace=App\\Livewire\\Auth : Namespace for the generated Livewire auth components}
                            {--layout=          : Guest layout view to extend (default: generates layouts.guest)}
                            {--default-role=    : Role name assigned to newly registered users}
                            {--roles-table=roles : Roles table (RBAC)}
                            {--permissions-table=permissions : Permissions table (RBAC)}
                            {--no-2fa           : Skip two-factor authentication scaffolding}
                            {--no-lockout       : Skip account lockout + throttling}
                            {--no-verification  : Skip email verification flow}
                            {--force            : Overwrite existing files}
                            {--backup           : Back up existing files before overwriting}
                            {--dry-run          : Preview without writing files}';

    protected $description = 'Scaffold Livewire authentication (login, register, 2FA, lockout) and RBAC from the users table';

    public function handle(): int
    {
        $this->info('🔐 Anvil — Authentication Scaffold');

        $connection = $this->option('connection') ?: config('database.default');

        try {
            $inspector = new DatabaseInspector($connection);
        } catch (\Throwable $e) {
            $this->error('Could not connect to the database: '.$e->getMessage());

            return self::FAILURE;
        }

        $usersTable = $this->option('users-table');
        $schema = $this->option('schema') ?: null;

        // Confirm the users table exists in the target schema.
        $tables = array_column($inspector->getAllSchemaTables($schema), 'table');
        if (! in_array($usersTable, $tables, true)) {
            $this->error("Table '{$usersTable}' was not found on connection [{$connection}]".($schema ? " in schema '{$schema}'." : '.'));
            $this->line('   Pass --users-table= (and --schema= for multi-schema databases).');

            return self::FAILURE;
        }

        $scaffolder = new AuthScaffolder($inspector, [
            'connection' => $connection,
            'schema' => $schema,
            'users_table' => $usersTable,
            'guard' => $this->option('guard'),
            'namespace' => trim($this->option('namespace'), '\\'),
            'layout' => $this->option('layout') ?: null,
            'default_role' => $this->option('default-role') ?: null,
            'roles_table' => $this->option('roles-table'),
            'permissions_table' => $this->option('permissions-table'),
            'two_factor' => ! $this->option('no-2fa'),
            'lockout' => ! $this->option('no-lockout'),
            'verification' => ! $this->option('no-verification'),
            'force' => (bool) $this->option('force'),
            'backup' => (bool) $this->option('backup'),
            'dry_run' => (bool) $this->option('dry-run'),
        ]);

        $this->newLine();
        $this->line('  Connection ....... '.$connection.' ('.$inspector->getDriver().')');
        $this->line('  Users table ...... '.($schema ? $schema.'.' : '').$usersTable);
        $this->line('  Guard ............ '.$this->option('guard'));
        $this->line('  Two-factor ....... '.($this->option('no-2fa') ? 'off' : 'on'));
        $this->line('  Lockout .......... '.($this->option('no-lockout') ? 'off' : 'on'));
        $this->line('  RBAC ............. '.($scaffolder->rbacDetected() ? 'roles + permissions detected' : 'role column only'));
        $this->newLine();

        $results = $scaffolder->generate();

        $created = $skipped = $failed = 0;
        foreach ($results as $r) {
            $status = $r['status'] ?? 'success';
            $icon = match ($status) {
                'success', 'dry-run' => '✅',
                'skipped' => '⏭️ ',
                default => '❌',
            };
            $this->line("  {$icon} {$r['type']}: {$r['name']}".(isset($r['reason']) ? " ({$r['reason']})" : ''));
            $status === 'skipped' ? $skipped++ : ($status === 'failed' ? $failed++ : $created++);
        }

        $this->newLine();
        $this->info("📊 {$created} written   ⏭️  {$skipped} skipped   ❌ {$failed} failed");

        foreach ($scaffolder->postInstallNotes() as $note) {
            $this->line('  • '.$note);
        }

        return self::SUCCESS;
    }
}
