<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Console;

use Illuminate\Console\Command;
use Zuqongtech\LaravelAnvil\Support\OpenApiLocator;
use Zuqongtech\LaravelAnvil\Support\SwaggerUiInstaller;

/**
 * Vendors the Swagger UI assets into public/ so the docs page does not depend on
 * a CDN at request time.
 *
 *   php artisan anvil:install:swagger-ui
 *   php artisan anvil:install:swagger-ui --source=http --api-version=1
 *   php artisan anvil:install:swagger-ui --source=npm --timeout=1800
 *   php artisan anvil:install:swagger-ui --check
 *
 * The assets are three static files. Acquisition therefore tries, in order:
 * an existing node_modules copy, a direct download, and only then npm — see
 * SwaggerUiInstaller for why that order matters.
 */

class InstallSwaggerUi extends Command
{

    protected $description = 'Install the Swagger UI assets locally so the docs page does not load them from a CDN';
    protected $signature = 'anvil:install:swagger-ui
                            {--ui-version=       : swagger-ui-dist version (default: anvil.openapi.docs.ui_version)}
                            {--api-version=      : API version whose docs directory receives the assets}
                            {--source=auto       : Where to get the files: auto | local | http | npm}
                            {--timeout=          : Seconds allowed for the npm fallback (default 900)}
                            {--http-timeout=120  : Seconds allowed per file download}
                            {--check             : Report what would happen and exit}
                            {--skip-generate     : Do not regenerate the spec first}
                            {--force             : Re-download even when the correct version is already present}
                            {--dry-run           : Preview without writing files}';
    /** @var list<string> */
    private const SOURCES = ['auto', 'local', 'http', 'npm'];

    public function handle(): int
    {
        $source = strtolower(trim((string) $this->option('source')));

        if (! in_array($source, self::SOURCES, true)) {
            $this->components->error(sprintf(
                'Unknown --source "%s". Expected one of: %s.',
                $source,
                implode(', ', self::SOURCES),
            ));

            return self::FAILURE;
        }

        foreach (['timeout', 'http-timeout'] as $option) {
            $value = (string) ($this->option($option) ?? '');

            if ($value !== '' && (! ctype_digit($value) || (int) $value < 1)) {
                $this->components->error("--{$option} must be a positive integer number of seconds.");

                return self::FAILURE;
            }
        }

        $version = $this->resolveVersion();
        $apiVersion = OpenApiLocator::normaliseVersion(
            $this->option('api-version') ?: OpenApiLocator::configuredVersion(),
        );
        $targetDir = OpenApiLocator::publicDocsDir($apiVersion) . '/assets';

        $this->components->info('Anvil — Swagger UI assets');
        $this->table(['', ''], [
            ['swagger-ui-dist', $version],
            ['API version', $apiVersion],
            ['Target', ltrim(str_replace(base_path(), '', $targetDir), '/') . '/'],
            ['Source', $source === 'auto' ? 'auto (node_modules → download → npm)' : $source],
        ]);
        $this->newLine();

        $installer = new SwaggerUiInstaller(
            version: $version,
            targetDir: $targetDir,
            dryRun: (bool) $this->option('dry-run') || (bool) $this->option('check'),
            httpTimeout: (int) ($this->option('http-timeout') ?: 120),
            npmTimeout: (int) ($this->option('timeout') ?: 900),
        );

        $installer->onOutput(fn(string $line) => $this->line('  <fg=gray>' . $line . '</>'));

        if ($this->option('check')) {
            return $this->reportCheck($installer, $version, $targetDir);
        }

        if (! $this->option('skip-generate') && ! $this->regenerateSpec($apiVersion)) {
            return self::FAILURE;
        }

        $result = $installer->install(
            $this->strategiesFor($source),
            (bool) $this->option('force'),
        );

        $this->reportLog($installer);

        return $result['ok']
            ? $this->reportSuccess($result, $apiVersion, $targetDir)
            : $this->reportFailure($source, $version);
    }

    // -----------------------------------------------------------------------
    // Resolution
    // -----------------------------------------------------------------------

    private function resolveVersion(): string
    {
        $version = trim((string) ($this->option('ui-version') ?: config('anvil.openapi.docs.ui_version', '5.17.14')));

        return ltrim($version, 'v');
    }

    /**
     * @return list<string>
     */
    private function strategiesFor(string $source): array
    {
        return $source === 'auto' ? ['local', 'http', 'npm'] : [$source];
    }

    /**
     * Regenerate the spec before publishing the UI, unless told not to.
     *
     * Wrapped: a spec-generation failure should not surface as an unrelated stack
     * trace from inside the asset installer.
     *
     * Delegates to 'anvil:forge-api' — GenerateOpenApiCommand's registered
     * signature name. This previously called 'anvil:generate-api', a name left
     * over from before that command was renamed; since it was never
     * registered under that name, has() always returned false, this method
     * always took the "not registered" branch, and --skip-generate was
     * effectively the only path that worked. Keep this in sync with
     * GenerateOpenApiCommand::$signature if that command is ever renamed
     * again.
     */
    private function regenerateSpec(string $apiVersion): bool
    {
        if (! $this->getApplication()?->has('anvil:forge-api')) {
            $this->components->warn(
                'anvil:forge-api is not registered, so the spec was not regenerated. Pass --skip-generate to '
                    . 'silence this.',
            );

            return true;
        }

        try {
            $status = $this->call('anvil:forge-api', [
                '--spec-only' => true,
                '--api-version' => ltrim($apiVersion, 'v'),
            ]);
        } catch (\Throwable $e) {
            $this->components->error('Spec generation failed: ' . $e->getMessage());
            $this->line('  Pass <fg=yellow>--skip-generate</> to install the assets without regenerating.');

            return false;
        }

        if ($status !== self::SUCCESS) {
            $this->components->error('Spec generation returned a non-zero status; not installing assets.');
            $this->line('  Pass <fg=yellow>--skip-generate</> to install the assets anyway.');

            return false;
        }

        return true;
    }

    // -----------------------------------------------------------------------
    // Reporting
    // -----------------------------------------------------------------------

    private function reportCheck(SwaggerUiInstaller $installer, string $version, string $targetDir): int
    {
        if ($installer->alreadyInstalled()) {
            $this->components->info("swagger-ui-dist {$version} is already installed. Nothing to do.");

            return self::SUCCESS;
        }

        $present = [];
        $missing = [];

        foreach (SwaggerUiInstaller::REQUIRED_FILES as $file) {
            is_file($targetDir . '/' . $file) ? $present[] = $file : $missing[] = $file;
        }

        if ($present !== []) {
            $this->line('  <fg=gray>present:</> ' . implode(', ', $present));
        }

        $this->components->warn(sprintf(
            '%d required file(s) missing: %s',
            count($missing),
            implode(', ', $missing),
        ));

        $this->line('  Run without <fg=yellow>--check</> to install.');

        return self::FAILURE;
    }

    /**
     * @param  array{ok: bool, strategy: ?string, files: list<string>, bytes: int}  $result
     */
    private function reportSuccess(array $result, string $apiVersion, string $targetDir): int
    {
        $this->newLine();

        if ($result['strategy'] === 'cache') {
            $this->components->info('Already installed — nothing to do. Pass --force to re-download.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->components->info('Dry run complete — nothing was written.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            '%d files installed via %s.',
            count($result['files']),
            $result['strategy'],
        ));

        $relative = ltrim(str_replace(public_path(), '', $targetDir), '/');

        $this->line('  Point the docs page at these assets by setting:');
        $this->line("    <fg=yellow>anvil.openapi.docs.asset_base</fg=yellow> = '/{$relative}'");
        $this->newLine();
        $this->line('  Docs: <options=bold>' . OpenApiLocator::docsUrl($apiVersion) . '</>');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Every strategy failed. Say what was tried and what to do about it — the
     * previous behaviour was an uncaught ProcessTimedOutException, which named a
     * vendor file and a line number and nothing the operator could act on.
     */
    private function reportFailure(string $source, string $version): int
    {
        $this->newLine();
        $this->components->error("Could not install swagger-ui-dist {$version}.");

        $suggestions = [];

        if ($source === 'auto' || $source === 'npm') {
            $suggestions[] = 'Skip npm entirely — the files are static: <fg=yellow>--source=http</>';
            $suggestions[] = 'Allow npm longer on a slow link: <fg=yellow>--timeout=1800</>';
        }

        if ($source === 'auto' || $source === 'http') {
            $suggestions[] = 'Check outbound access to cdn.jsdelivr.net and unpkg.com, including any proxy';
            $suggestions[] = 'Behind a proxy, export <fg=yellow>HTTPS_PROXY</> before running';
        }

        $suggestions[] = sprintf(
            'Install it yourself and re-run with --source=local:  npm install --no-save swagger-ui-dist@%s',
            $version,
        );
        $suggestions[] = 'Or leave the docs page on the CDN: it works without local assets, it just needs the network '
            . 'at request time';

        $this->line('  <options=bold>Options</>');

        foreach ($suggestions as $suggestion) {
            $this->line('   • ' . $suggestion);
        }

        $this->newLine();

        return self::FAILURE;
    }

    private function reportLog(SwaggerUiInstaller $installer): void
    {
        foreach ($installer->log() as $entry) {
            $colour = match ($entry['status']) {
                'success' => 'green',
                'failed' => 'red',
                'dry-run' => 'cyan',
                default => 'gray',
            };

            $this->line(sprintf(
                '  <fg=%s>%-8s</> <fg=gray>%-11s</> %s',
                $colour,
                $entry['status'],
                $entry['strategy'],
                $entry['detail'],
            ));
        }
    }
}
