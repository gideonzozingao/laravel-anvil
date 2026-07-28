<?php

namespace Zuqongtech\LaravelAnvil\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Finder\Finder;

/**
 * php artisan anvil:install-swagger-ui
 *
 * One command that:
 *   1. Runs `npm install swagger-ui-dist@{version}` (skipped if already present)
 *   2. Copies the built assets into public/vendor/swagger-ui
 *   3. Regenerates Anvil's API docs (anvil:generate-apidocs --ui)
 *   4. Rewrites every unpkg.com CDN reference in the generated static bundle
 *      (public/api-docs/**\/index.html) to point at the local copy
 *
 * Config (config/anvil.php -> openapi.docs.ui_version) decides which
 * swagger-ui-dist version is installed, so the local copy always matches
 * what Anvil thinks it published.
 */
class InstallSwaggerUi extends Command
{
    protected $signature = 'anvil:install:swagger-ui
                             {--ui-version= : swagger-ui-dist version to install (defaults to config anvil.openapi.docs.ui_version)}
                             {--all-versions : Regenerate docs for every API version present on disk}
                             {--api-version= : Regenerate docs for a single API version}
                             {--skip-npm : Skip the npm install/copy step and only rewrite existing HTML}
                             {--skip-generate : Skip regenerating Anvil docs and only rewrite existing HTML}';

    protected $description = 'Install swagger-ui-dist locally and point Anvil\'s generated docs at it instead of the unpkg CDN';

    private const VENDOR_DIR = 'vendor/swagger-ui';

    private const ASSET_FILES = [
        'swagger-ui-bundle.js',
        'swagger-ui-bundle.js.map',
        'swagger-ui-standalone-preset.js',
        'swagger-ui-standalone-preset.js.map',
        'swagger-ui.css',
        'swagger-ui.css.map',
        'favicon-32x32.png',
        'favicon-16x16.png',
    ];

    public function handle(): int
    {
        $version = $this->option('ui-version')
            ?? config('anvil.openapi.docs.ui_version', '5.17.14');

        $this->info("Target swagger-ui-dist version: {$version}");

        if (! $this->option('skip-npm')) {
            if (! $this->installAndCopyAssets($version)) {
                return self::FAILURE;
            }
        }

        if (! $this->option('skip-generate')) {
            $this->regenerateDocs();
        }

        $rewritten = $this->rewriteCdnReferences($version);

        if ($rewritten === 0) {
            $this->warn('No generated docs found to rewrite. Did you run this before generating any API version? '
                .'Try: php artisan anvil:generate-api --api-version=1 --ui, then re-run this command.');

            return self::FAILURE;
        }

        $vendorDir = self::VENDOR_DIR;

        $this->newLine();
        $this->info("Done. {$rewritten} file(s) now load Swagger UI from /{$vendorDir} instead of unpkg.com.");
        $this->line('Verify with: php artisan route:list --path=docs, then check the Network tab on /docs.');

        return self::SUCCESS;
    }

    private function installAndCopyAssets(string $version): bool
    {
        $packageDir = base_path('node_modules/swagger-ui-dist');
        $installedVersionFile = base_path('node_modules/swagger-ui-dist/package.json');

        $needsInstall = true;

        if (File::exists($installedVersionFile)) {
            $installed = json_decode(File::get($installedVersionFile), true)['version'] ?? null;
            $needsInstall = $installed !== $version;

            if (! $needsInstall) {
                $this->line("swagger-ui-dist@{$version} already installed, skipping npm install.");
            }
        }

        if ($needsInstall) {
            $this->line("Running: npm install swagger-ui-dist@{$version}");

            $result = Process::path(base_path())
                ->timeout(180)
                ->run("npm install swagger-ui-dist@{$version}");

            if (! $result->successful()) {
                $this->error('npm install failed:');
                $this->line($result->errorOutput());

                return false;
            }
        }

        $target = public_path(self::VENDOR_DIR);
        File::ensureDirectoryExists($target);

        $copied = 0;

        foreach (self::ASSET_FILES as $file) {
            $source = "{$packageDir}/{$file}";

            if (! File::exists($source)) {
                continue; // .map files and favicons are not guaranteed in every release
            }

            File::copy($source, "{$target}/{$file}");
            $copied++;
        }

        $this->line("Copied {$copied} asset file(s) to public/".self::VENDOR_DIR);

        return true;
    }

    private function regenerateDocs(): void
    {
        $params = ['--ui' => true, '--force' => true];

        if ($this->option('all-versions')) {
            $params['--all-versions'] = true;
        } elseif ($apiVersion = $this->option('api-version')) {
            $params['--api-version'] = $apiVersion;
        } else {
            $params['--all-versions'] = true;
        }

        $this->line('Regenerating Anvil API docs: php artisan anvil:generate-apidocs '
            .collect($params)->map(fn ($v, $k) => $v === true ? $k : "{$k}={$v}")->implode(' '));

        $this->call('anvil:generate-apidocs', $params);
    }

    private function rewriteCdnReferences(string $version): int
    {
        $docsRoot = public_path('api-docs');

        if (! File::isDirectory($docsRoot)) {
            return 0;
        }

        $finder = (new Finder)->in($docsRoot)->name('*.html')->files();

        $localBase = '/'.self::VENDOR_DIR;

        $replacements = [
            "https://unpkg.com/swagger-ui-dist@{$version}/swagger-ui-bundle.js" => "{$localBase}/swagger-ui-bundle.js",
            "https://unpkg.com/swagger-ui-dist@{$version}/swagger-ui-standalone-preset.js" => "{$localBase}/swagger-ui-standalone-preset.js",
            "https://unpkg.com/swagger-ui-dist@{$version}/swagger-ui.css" => "{$localBase}/swagger-ui.css",
            "https://unpkg.com/swagger-ui-dist@{$version}/favicon-32x32.png" => "{$localBase}/favicon-32x32.png",
        ];

        // Also catch any stale version number left over from a previous ui_version.
        $pattern = '~https://unpkg\.com/swagger-ui-dist@[^/]+/(swagger-ui-bundle\.js|swagger-ui-standalone-preset\.js|swagger-ui\.css|favicon-32x32\.png)~';

        $count = 0;

        foreach ($finder as $file) {
            $contents = File::get($file->getRealPath());
            $original = $contents;

            $contents = strtr($contents, $replacements);
            $contents = preg_replace($pattern, "{$localBase}/$1", $contents);

            if ($contents !== $original) {
                File::put($file->getRealPath(), $contents);
                $count++;
                $this->line('  rewritten: '.str_replace(public_path().'/', '', $file->getRealPath()));
            }
        }

        return $count;
    }
}
