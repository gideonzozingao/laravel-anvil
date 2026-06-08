<?php

namespace Zuqongtech\LaravelAnvil\Support;

/**
 * Centralises registration of generated service providers into the host
 * application's bootstrap, with first-class support for Laravel 11/12's
 * bootstrap/providers.php convention.
 *
 * Resolution order (first existing target wins):
 *
 *   1. bootstrap/providers.php   — Laravel 11+ (preferred; returns an array)
 *   2. bootstrap/app.php         — Laravel 11+ (->withProviders([...]))
 *   3. config/app.php            — Laravel ≤10 ('providers' => [...])
 *   4. bootstrap/providers.php   — created from scratch if nothing else exists
 *
 * Every operation is idempotent: a provider already present is never added
 * twice, and a second run reports "skipped" rather than corrupting the file.
 */
final class ProviderRegistrar
{
    public function __construct(private readonly bool $dryRun = false) {}

    /**
     * Register a fully-qualified provider class into the application bootstrap.
     *
     * @param  string  $providerFqn  e.g. "App\Providers\ForceJsonApiServiceProvider"
     * @return array{target: string, status: string, path?: string, reason?: string}
     */
    public function registerProvider(string $providerFqn): array
    {
        $providerFqn = ltrim($providerFqn, '\\');

        $providersFile = base_path('bootstrap/providers.php');
        if (file_exists($providersFile)) {
            return $this->registerInProvidersArray($providersFile, $providerFqn);
        }

        $bootstrapApp = base_path('bootstrap/app.php');
        if (file_exists($bootstrapApp)) {
            return $this->registerInBootstrapApp($bootstrapApp, $providerFqn);
        }

        $configApp = config_path('app.php');
        if (file_exists($configApp)) {
            return $this->registerInConfigApp($configApp, $providerFqn);
        }

        // Nothing present — create the Laravel 11+ providers file.
        return $this->createProvidersFile($providersFile, $providerFqn);
    }

    // -----------------------------------------------------------------------
    // 1. bootstrap/providers.php  (Laravel 11+)
    // -----------------------------------------------------------------------

    private function registerInProvidersArray(string $path, string $fqn): array
    {
        $content = file_get_contents($path);

        if (str_contains($content, $fqn.'::class')) {
            return $this->result('bootstrap/providers.php', 'skipped', $path, 'already registered');
        }

        $entry = "    {$fqn}::class,";

        // Insert just before the closing "];" of the returned array.
        $count = 0;
        $updated = preg_replace('/^(\s*)\];/m', "{$entry}\n$1];", $content, 1, $count);

        if ($count === 0 || $updated === null) {
            return $this->result('bootstrap/providers.php', 'failed', $path, 'could not locate array close');
        }

        if (! $this->dryRun) {
            file_put_contents($path, $updated);
        }

        return $this->result('bootstrap/providers.php', 'success', $path);
    }

    private function createProvidersFile(string $path, string $fqn): array
    {
        $content = <<<PHP
<?php

return [
    {$fqn}::class,
];

PHP;

        if (! $this->dryRun) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, $content);
        }

        return $this->result('bootstrap/providers.php', 'success', $path, 'created');
    }

    // -----------------------------------------------------------------------
    // 2. bootstrap/app.php  (Laravel 11+, fallback when providers.php absent)
    // -----------------------------------------------------------------------

    private function registerInBootstrapApp(string $path, string $fqn): array
    {
        $content = file_get_contents($path);

        if (str_contains($content, $fqn.'::class')) {
            return $this->result('bootstrap/app.php', 'skipped', $path, 'already registered');
        }

        $entry = "        {$fqn}::class,";

        // Case A: a ->withProviders([...]) block already exists — append into it.
        if (preg_match('/->withProviders\(\s*\[/', $content)) {
            $count = 0;
            $updated = preg_replace(
                '/(->withProviders\(\s*\[)/',
                "$1\n{$entry}",
                $content,
                1,
                $count,
            );

            if ($count > 0 && $updated !== null) {
                if (! $this->dryRun) {
                    file_put_contents($path, $updated);
                }

                return $this->result('bootstrap/app.php', 'success', $path);
            }
        }

        // Case B: no withProviders — chain one before ->create().
        if (str_contains($content, '->create()')) {
            $inject = "    ->withProviders([\n{$entry}\n    ])\n    ->create()";
            $updated = str_replace('->create()', $inject, $content);

            if (! $this->dryRun) {
                file_put_contents($path, $updated);
            }

            return $this->result('bootstrap/app.php', 'success', $path);
        }

        return $this->result('bootstrap/app.php', 'failed', $path, 'no withProviders() or ->create() anchor found');
    }

    // -----------------------------------------------------------------------
    // 3. config/app.php  (Laravel ≤10)
    // -----------------------------------------------------------------------

    private function registerInConfigApp(string $path, string $fqn): array
    {
        $content = file_get_contents($path);

        if (str_contains($content, $fqn.'::class')) {
            return $this->result('config/app.php', 'skipped', $path, 'already registered');
        }

        $entry = "        {$fqn}::class,";

        // Append after the last known framework provider in the providers array.
        $count = 0;
        $updated = preg_replace(
            '/(App\\\\Providers\\\\RouteServiceProvider::class,)/',
            "$1\n{$entry}",
            $content,
            1,
            $count,
        );

        // Fallback: insert before the first "]," that closes a providers-like array.
        if ($count === 0 || $updated === null) {
            $updated = preg_replace(
                '/(\n\s*\],\s*\n)/',
                "\n{$entry}$1",
                $content,
                1,
                $count,
            );
        }

        if ($count === 0 || $updated === null) {
            return $this->result('config/app.php', 'failed', $path, 'could not locate providers array');
        }

        if (! $this->dryRun) {
            file_put_contents($path, $updated);
        }

        return $this->result('config/app.php', 'success', $path);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @return array{target: string, status: string, path?: string, reason?: string}
     */
    private function result(string $target, string $status, ?string $path = null, ?string $reason = null): array
    {
        $out = ['target' => $target, 'status' => $status];

        if ($path !== null) {
            $out['path'] = $path;
        }
        if ($reason !== null) {
            $out['reason'] = $reason;
        }

        return $out;
    }
}
