<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

use Composer\InstalledVersions;

/**
 * Read-only probe of the host application's frontend tooling.
 *
 * Deliberately side-effect free: it never installs, never writes, never shells
 * out. That keeps `anvil:generate-web` safe to run against an unknown project,
 * and makes the detection independently testable.
 */
final class FrontendDetector
{
    private ?FrontendState $cached = null;

    /** @param  string|null  $basePath  Overridable for tests; defaults to base_path(). */
    public function __construct(private readonly ?string $basePath = null) {}

    public function detect(bool $fresh = false): FrontendState
    {
        if ($this->cached !== null && ! $fresh) {
            return $this->cached;
        }

        $packageJson = $this->readJson('package.json');
        $tailwindConstraint = $this->dependencyConstraint($packageJson, 'tailwindcss');
        $viteConfig = $this->readViteConfig();
        $cssEntrypoint = $this->findCssEntrypoint();
        $cssContents = $cssEntrypoint !== null ? $this->read($cssEntrypoint) : null;

        return $this->cached = new FrontendState(
            livewireInstalled: $this->composerPackageInstalled('livewire/livewire'),
            livewireVersion: $this->composerPackageVersion('livewire/livewire'),
            tailwindInstalled: $tailwindConstraint !== null,
            tailwindMajor: $this->resolveTailwindMajor($tailwindConstraint),
            packageJsonPresent: $packageJson !== null,
            nodeModulesPresent: is_dir($this->path('node_modules')),
            viteConfigPresent: $viteConfig !== null,
            tailwindWiredToVite: $viteConfig !== null && str_contains($viteConfig, '@tailwindcss/vite'),
            postcssConfigured: $this->postcssConfigured(),
            cssEntrypoint: $cssEntrypoint,
            cssImportsTailwind: $cssContents !== null && $this->importsTailwind($cssContents),
        );
    }

    // -----------------------------------------------------------------------
    // Composer
    // -----------------------------------------------------------------------

    /**
     * Prefers Composer's runtime API over parsing composer.json, because the
     * declared constraint and the installed reality can differ — a package can
     * be required but not yet installed, which is exactly the state that
     * produces "class not found" after a half-finished install.
     */
    private function composerPackageInstalled(string $package): bool
    {
        if (class_exists(InstalledVersions::class)) {
            try {
                if (InstalledVersions::isInstalled($package)) {
                    return true;
                }
            } catch (\Throwable) {
                // Fall through to the filesystem check below.
            }
        }

        return is_dir($this->path('vendor/' . $package));
    }

    private function composerPackageVersion(string $package): ?string
    {
        if (! class_exists(InstalledVersions::class)) {
            return null;
        }

        try {
            return InstalledVersions::isInstalled($package)
                ? InstalledVersions::getPrettyVersion($package)
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // -----------------------------------------------------------------------
    // Node / Tailwind
    // -----------------------------------------------------------------------

    /** @param  array<string, mixed>|null  $packageJson */
    private function dependencyConstraint(?array $packageJson, string $name): ?string
    {
        if ($packageJson === null) {
            return null;
        }

        foreach (['devDependencies', 'dependencies'] as $section) {
            $deps = $packageJson[$section] ?? null;

            if (is_array($deps) && isset($deps[$name]) && is_string($deps[$name])) {
                return $deps[$name];
            }
        }

        return null;
    }

    /**
     * Resolves the installed major version, preferring node_modules over the
     * declared constraint — "^3.4.0" in package.json means nothing if npm
     * resolved something else, and a constraint like "latest" or a git URL
     * carries no version at all.
     */
    private function resolveTailwindMajor(?string $constraint): int
    {
        if ($constraint === null) {
            return 0;
        }

        $installed = $this->readJson('node_modules/tailwindcss/package.json');
        $version = is_array($installed) && isset($installed['version']) && is_string($installed['version'])
            ? $installed['version']
            : $constraint;

        return preg_match('/(\d+)/', $version, $m) === 1 ? (int) $m[1] : 0;
    }

    private function postcssConfigured(): bool
    {
        foreach (['postcss.config.js', 'postcss.config.cjs', 'postcss.config.mjs', 'postcss.config.ts'] as $file) {
            $contents = $this->read($file);

            if ($contents !== null && str_contains($contents, 'tailwind')) {
                return true;
            }
        }

        $packageJson = $this->readJson('package.json');

        if (is_array($packageJson) && isset($packageJson['postcss'])) {
            return str_contains(json_encode($packageJson['postcss']) ?: '', 'tailwind');
        }

        return false;
    }

    private function readViteConfig(): ?string
    {
        foreach (['vite.config.js', 'vite.config.mjs', 'vite.config.ts'] as $file) {
            $contents = $this->read($file);

            if ($contents !== null) {
                return $contents;
            }
        }

        return null;
    }

    public function viteConfigPath(): ?string
    {
        foreach (['vite.config.js', 'vite.config.mjs', 'vite.config.ts'] as $file) {
            if (is_file($this->path($file))) {
                return $file;
            }
        }

        return null;
    }

    /** Relative path to the app's CSS entrypoint, following config first. */
    public function findCssEntrypoint(): ?string
    {
        $configured = (string) config('anvil.web.frontend.css_entrypoint', '');

        $candidates = array_values(array_filter([
            $configured !== '' ? $configured : null,
            'resources/css/app.css',
            'resources/css/app.scss',
            'resources/sass/app.scss',
        ]));

        foreach ($candidates as $candidate) {
            if (is_file($this->path($candidate))) {
                return $candidate;
            }
        }

        return null;
    }

    private function importsTailwind(string $css): bool
    {
        // Tailwind 4: `@import "tailwindcss";`  Tailwind 3: `@tailwind base;`
        return str_contains($css, '@tailwind ')
            || preg_match('/@import\s+["\']tailwindcss/', $css) === 1;
    }

    // -----------------------------------------------------------------------
    // Filesystem primitives
    // -----------------------------------------------------------------------

    public function path(string $relative = ''): string
    {
        $base = $this->basePath ?? (function_exists('base_path') ? base_path() : getcwd() ?: '.');

        return rtrim($base, '/') . ($relative !== '' ? '/' . ltrim($relative, '/') : '');
    }

    private function read(string $relative): ?string
    {
        $path = $this->path($relative);

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /** @return array<string, mixed>|null */
    private function readJson(string $relative): ?array
    {
        $contents = $this->read($relative);

        if ($contents === null) {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }
}
