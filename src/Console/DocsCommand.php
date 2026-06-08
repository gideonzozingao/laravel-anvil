<?php

namespace Zuqongtech\LaravelAnvil\Console;

use Illuminate\Console\Command;

/**
 * Prints the location of the generated API documentation (Swagger UI) and the
 * underlying OpenAPI spec, and reports whether the spec has been generated yet.
 *
 *   php artisan anvil:docs
 *   php artisan anvil:docs --json
final  *   php artisan anvil:docs --open    (best-effort: opens the URL in a browser)
 */
class DocsCommand extends Command
{
    protected $signature = 'anvil:docs
                            {--json  : Output machine-readable JSON instead of a table}
                            {--open  : Attempt to open the docs URL in the default browser}';

    protected $description = 'Show the Swagger UI documentation URL for the generated OpenAPI spec';

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
