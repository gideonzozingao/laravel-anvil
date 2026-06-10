<?php

namespace Zuqongtech\LaravelAnvil\Console;

use Illuminate\Console\Command;

/**
 * Prints the location of the generated API documentation (Swagger UI) and the
 * underlying OpenAPI spec, and reports whether the spec has been generated yet.
 *
 *   php artisan anvil:docs
 *   php artisan anvil:docs --json
 *   php artisan anvil:docs --open
 */
class DocsCommand extends Command
{
    protected $signature = 'anvil:docs
                            {--json  : Output machine-readable JSON instead of a table}
                            {--open  : Attempt to open the docs URL in the default browser}';

    protected $description = 'Show the Swagger UI documentation URL for the generated OpenAPI spec';

    public function handle(): int
    {
        $enabled = (bool) config('anvil.openapi.docs.enabled', false);
        $route = trim((string) config('anvil.openapi.docs.route', 'docs'), '/');
        $format = config('anvil.openapi.format', 'yaml') === 'json' ? 'json' : 'yaml';

        $appUrl = rtrim((string) config('app.url', 'http://localhost'), '/');
        $docsUrl = "{$appUrl}/{$route}";
        $specUrl = "{$docsUrl}/openapi.{$format}";

        $specPath = base_path((string) config('anvil.openapi.output_path', 'openapi'));
        $rootFile = "{$specPath}/openapi.{$format}";
        $specExists = is_file($rootFile);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'enabled' => $enabled,
                'docs_url' => $docsUrl,
                'spec_url' => $specUrl,
                'spec_path' => $rootFile,
                'spec_exists' => $specExists,
                'format' => $format,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>⚒  Anvil — API Documentation</>');
        $this->newLine();

        $this->twoColumn('Docs UI', $enabled ? $docsUrl : '<fg=yellow>disabled</> (set anvil.openapi.docs.enabled = true)');
        $this->twoColumn('OpenAPI spec', $specUrl);
        $this->twoColumn('Spec file', $rootFile);
        $this->twoColumn('Spec status', $specExists
            ? '<fg=green>✓ generated</>'
            : '<fg=red>✗ not found</> — run: php artisan anvil:generate --openapi');
        $this->newLine();

        if (! $specExists) {
            $this->warn('  The spec has not been generated yet, so the docs page will 404 until you run the generator.');
            $this->newLine();
        }

        if ($this->option('open')) {
            if (! $enabled) {
                $this->warn('  Docs are disabled in config; not opening the browser.');
            } elseif (! $specExists) {
                $this->warn('  Spec not generated yet; not opening the browser.');
            } else {
                $this->openInBrowser($docsUrl);
                $this->newLine();
            }
        }

        return self::SUCCESS;
    }

    protected function twoColumn(string $label, string $value): void
    {
        $this->line(sprintf('  <options=bold>%-13s</> %s', $label, $value));
    }

    /**
     * Best-effort cross-platform browser open. Silently no-ops if unsupported.
     */
    protected function openInBrowser(string $url): void
    {
        $binary = match (true) {
            stripos(PHP_OS_FAMILY, 'Darwin') !== false => 'open',
            stripos(PHP_OS_FAMILY, 'Windows') !== false => 'start',
            default => 'xdg-open',
        };

        if (function_exists('exec')) {
            @exec(escapeshellcmd($binary).' '.escapeshellarg($url).' > /dev/null 2>&1 &');
            $this->info("🌐 Opening {$url} …");
        }
    }
}
