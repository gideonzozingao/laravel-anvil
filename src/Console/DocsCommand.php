<?php

namespace Zuqongtech\LaravelAnvil\Console;

use Illuminate\Console\Command;

/**
 * Prints the location of the generated API documentation (Swagger UI) and the
 * underlying OpenAPI spec, and reports whether the spec has been generated yet.
 *
 *   php artisan anvil:docs
 *   php artisan anvil:docs --json
 *   php artisan anvil:docs --open    (best-effort: opens the URL in a browser)
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
        $prefix = trim((string) config('anvil.openapi.docs.route', 'docs'), '/');
        $ext = config('anvil.openapi.format', 'yaml') === 'json' ? 'json' : 'yaml';

        $specDir = base_path(config('anvil.openapi.output_path', 'openapi'));
        $specFile = "{$specDir}/openapi.{$ext}";
        $exists = file_exists($specFile);

        $appUrl = rtrim((string) config('app.url', 'http://localhost'), '/');
        $docsUrl = "{$appUrl}/{$prefix}";
        $specUrl = "{$docsUrl}/openapi.{$ext}";

        if ($this->option('json')) {
            $this->line(json_encode([
                'enabled' => $enabled,
                'docs_url' => $docsUrl,
                'spec_url' => $specUrl,
                'spec_file' => $specFile,
                'spec_exists' => $exists,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('📚 API Documentation');
        $this->table(
            ['', ''],
            [
                ['Swagger UI', $docsUrl],
                ['Spec URL', $specUrl],
                ['Spec file', $specFile.($exists ? '' : '  (not generated yet)')],
                ['Routes', $enabled ? 'enabled' : 'disabled'],
            ],
        );

        if (! $enabled) {
            $this->warn('⚠️  Docs routes are disabled. Enable them in config/anvil.php:');
            $this->line("      'openapi' => ['docs' => ['enabled' => true]]");
        }

        if (! $exists) {
            $this->warn('⚠️  No spec found yet. Generate it with:');
            $this->line('      php artisan anvil:generate --openapi --openapi-single-file');
        }

        if ($this->option('open') && $exists && $enabled) {
            $this->openInBrowser($docsUrl);
        }

        $this->newLine();

        return self::SUCCESS;
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
