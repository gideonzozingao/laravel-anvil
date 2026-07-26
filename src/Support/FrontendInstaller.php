<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

use Illuminate\Support\Facades\Process;

/**
 * Installs the frontend dependencies the web scaffold expects.
 *
 * Design constraints that shaped this class:
 *
 * 1. NOTHING RUNS IMPLICITLY. Every install is opt-in via flag or confirmation.
 *    A code generator that silently mutates composer.json and runs npm is a
 *    generator nobody trusts on an existing codebase.
 *
 * 2. COMPOSER INSTALLS DO NOT TAKE EFFECT THIS PROCESS. The autoloader is
 *    already built and the service providers already registered, so a freshly
 *    required Livewire is not usable until the next boot. Callers must treat
 *    installLivewire() as a preflight step and tell the user to re-run, rather
 *    than assuming Livewire is live afterwards.
 *
 * 3. EVERY STEP IS IDEMPOTENT AND REVERSIBLE-BY-INSPECTION. Existing files are
 *    never clobbered; vite.config.js is patched only when its shape is
 *    recognised, and the manual snippet is surfaced when it is not.
 */
final class FrontendInstaller
{
    /** @var list<array{step: string, status: string, detail: string}> */
    private array $log = [];

    /** @var \Closure(string): void|null */
    private ?\Closure $output = null;

    public function __construct(
        private readonly FrontendDetector $detector = new FrontendDetector,
        private readonly bool $dryRun = false,
        private readonly bool $runPackageManager = true,
    ) {}

    /** Stream subprocess output to the console. */
    public function onOutput(callable $callback): self
    {
        $this->output = \Closure::fromCallable($callback);

        return $this;
    }

    /** @return list<array{step: string, status: string, detail: string}> */
    public function log(): array
    {
        return $this->log;
    }

    // -----------------------------------------------------------------------
    // Livewire
    // -----------------------------------------------------------------------

    /**
     * Require livewire/livewire.
     *
     * Returns false when the install failed, so the caller can abort before
     * generating components that would 500 on first request.
     */
    public function installLivewire(): bool
    {
        $state = $this->detector->detect(fresh: true);

        if ($state->livewireUsable()) {
            $this->record('livewire', 'skipped', 'already installed' . ($state->livewireVersion !== null ? " ({$state->livewireVersion})" : ''));

            return true;
        }

        if ($state->livewireInstalled) {
            // Present but below v3. Upgrading someone's major version without
            // asking is not our call — the generated components use v3 syntax,
            // so we report and let them decide.
            $this->record('livewire', 'failed', "Livewire {$state->livewireVersion} is installed; the scaffold needs ^3.0. Upgrade manually, then re-run.");

            return false;
        }

        $constraint = (string) config('anvil.web.frontend.livewire_constraint', '^3.0');

        $ok = $this->run(
            [$this->composerBinary(), 'require', "livewire/livewire:{$constraint}", '--no-interaction'],
            'livewire',
            "composer require livewire/livewire:{$constraint}",
        );

        if ($ok) {
            $this->record(
                'livewire',
                'note',
                'Livewire is not autoloadable in the current process — re-run the generator so its provider is registered.',
            );
        }

        return $ok;
    }

    // -----------------------------------------------------------------------
    // Tailwind
    // -----------------------------------------------------------------------

    /**
     * Install and wire Tailwind. $major selects the toolchain:
     *   4 — @tailwindcss/vite plugin, `@import "tailwindcss"`, no config file
     *   3 — postcss + autoprefixer + tailwind.config.js content globs
     */
    public function installTailwind(int $major = 4): bool
    {
        $state = $this->detector->detect(fresh: true);

        if ($state->tailwindUsable()) {
            $this->record('tailwind', 'skipped', "already installed and wired (v{$state->tailwindMajor})");

            return true;
        }

        if (! $state->packageJsonPresent) {
            $this->record('tailwind', 'failed', 'no package.json found — this application has no asset build. Use --assets-mode=cdn instead.');

            return false;
        }

        // A detected install takes precedence over the requested version: we
        // wire up what is actually there rather than dragging a project across
        // a major boundary it did not ask for.
        if ($state->tailwindInstalled && $state->tailwindMajor > 0) {
            $major = $state->tailwindMajor;
        }

        $ok = $state->tailwindInstalled
            ? true
            : $this->installTailwindPackages($major);

        if (! $ok) {
            return false;
        }

        $ok = $major >= 4
            ? $this->wireTailwind4()
            : $this->wireTailwind3();

        $this->writeCssEntrypoint($major);

        return $ok;
    }

    private function installTailwindPackages(int $major): bool
    {
        $packages = $major >= 4
            ? ['tailwindcss@^4.0', '@tailwindcss/vite@^4.0']
            : ['tailwindcss@^3.4', 'postcss@^8.4', 'autoprefixer@^10.4'];

        return $this->run(
            array_merge([$this->npmBinary(), 'install', '-D'], $packages),
            'tailwind',
            'npm install -D ' . implode(' ', $packages),
        );
    }

    /** Tailwind 4: register the Vite plugin. */
    private function wireTailwind4(): bool
    {
        $relative = $this->detector->viteConfigPath();

        if ($relative === null) {
            $this->record('tailwind', 'manual', 'No vite.config.js found. ' . $this->viteSnippet());

            return true;
        }

        $path = $this->detector->path($relative);
        $contents = (string) file_get_contents($path);

        if (str_contains($contents, '@tailwindcss/vite')) {
            $this->record('vite', 'skipped', "{$relative} already registers the Tailwind plugin");

            return true;
        }

        $patched = $this->patchViteConfig($contents);

        if ($patched === null) {
            // Rewriting an unrecognised config by regex is how you corrupt
            // someone's build. Print the snippet and let them paste it.
            $this->record('vite', 'manual', "Could not safely patch {$relative}. " . $this->viteSnippet());

            return true;
        }

        if ($this->dryRun) {
            $this->record('vite', 'dry-run', "would add the Tailwind plugin to {$relative}");

            return true;
        }

        $this->backup($path);
        file_put_contents($path, $patched);
        $this->record('vite', 'success', "registered @tailwindcss/vite in {$relative}");

        return true;
    }

    /**
     * Insert the import and the plugin call.
     *
     * Returns null when the file does not match the expected Laravel shape,
     * signalling "hands off, tell the user".
     */
    private function patchViteConfig(string $contents): ?string
    {
        if (preg_match('/plugins\s*:\s*\[/', $contents) !== 1) {
            return null;
        }

        // Import goes after the last top-level import so ordering stays stable.
        if (
            preg_match_all('/^import .+;$/m', $contents, $matches, PREG_OFFSET_CAPTURE) === false
            || $matches[0] === []
        ) {
            return null;
        }

        $last = end($matches[0]);
        $insertAt = (int) $last[1] + strlen((string) $last[0]);

        $contents = substr($contents, 0, $insertAt)
            . "\nimport tailwindcss from '@tailwindcss/vite';"
            . substr($contents, $insertAt);

        $patched = preg_replace(
            '/(plugins\s*:\s*\[)/',
            "$1\n        tailwindcss(),",
            $contents,
            1,
        );

        return is_string($patched) ? $patched : null;
    }

    /** Tailwind 3: postcss config plus content globs covering generated Blade. */
    private function wireTailwind3(): bool
    {
        $postcssPath = $this->detector->path('postcss.config.js');

        if (! is_file($postcssPath)) {
            $this->write($postcssPath, <<<'JS'
                export default {
                    plugins: {
                        tailwindcss: {},
                        autoprefixer: {},
                    },
                };
                JS, 'postcss');
        } else {
            $this->record('postcss', 'skipped', 'postcss.config.js already exists');
        }

        $configPath = $this->detector->path('tailwind.config.js');

        if (is_file($configPath)) {
            $this->record('tailwind-config', 'skipped', 'tailwind.config.js already exists — verify its content globs cover resources/views');

            return true;
        }

        $namespace = str_replace('\\', '/', (string) config('anvil.web.livewire.namespace', 'App\\Livewire'));
        $livewirePath = './' . ltrim(preg_replace('#^App/#', 'app/', $namespace) ?? 'app/Livewire', '/');

        $this->write($configPath, <<<JS
            /** @type {import('tailwindcss').Config} */
            export default {
                content: [
                    './resources/**/*.blade.php',
                    './resources/**/*.js',
                    './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
                    '{$livewirePath}/**/*.php',
                ],
                theme: {
                    extend: {},
                },
                plugins: [],
            };
            JS, 'tailwind-config');

        return true;
    }

    /**
     * Ensure the CSS entrypoint pulls Tailwind in. Appends rather than
     * overwrites — the file usually already holds the app's own styles.
     */
    private function writeCssEntrypoint(int $major): void
    {
        $relative = $this->detector->findCssEntrypoint() ?? 'resources/css/app.css';
        $path = $this->detector->path($relative);
        $existing = is_file($path) ? (string) file_get_contents($path) : '';

        if (str_contains($existing, '@tailwind ') || preg_match('/@import\s+["\']tailwindcss/', $existing) === 1) {
            $this->record('css', 'skipped', "{$relative} already imports Tailwind");

            return;
        }

        // Tailwind 4 auto-detects sources but skips paths outside the CSS tree,
        // so Blade views and Livewire classes are declared explicitly.
        $directives = $major >= 4
            ? "@import \"tailwindcss\";\n@source \"../views\";\n@source \"../../app\";\n"
            : "@tailwind base;\n@tailwind components;\n@tailwind utilities;\n";

        if ($this->dryRun) {
            $this->record('css', 'dry-run', "would prepend Tailwind directives to {$relative}");

            return;
        }

        if ($existing !== '') {
            $this->backup($path);
        }

        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        file_put_contents($path, $directives . ($existing !== '' ? "\n" . $existing : ''));
        $this->record('css', 'success', "added Tailwind directives to {$relative}");
    }

    // -----------------------------------------------------------------------
    // Process handling
    // -----------------------------------------------------------------------

    private function run(array $command, string $step, string $label): bool
    {
        if ($this->dryRun) {
            $this->record($step, 'dry-run', $label);

            return true;
        }

        if (! $this->runPackageManager) {
            $this->record($step, 'manual', "skipped by request — run: {$label}");

            return true;
        }

        $timeout = (int) config('anvil.web.frontend.process_timeout', 600);

        try {
            $result = Process::path($this->detector->path())
                ->timeout(max(30, $timeout))
                ->run($command, function (string $type, string $buffer): void {
                    if ($this->output !== null) {
                        ($this->output)(rtrim($buffer));
                    }
                });
        } catch (\Throwable $e) {
            $this->record($step, 'failed', "{$label} — {$e->getMessage()}");

            return false;
        }

        if ($result->failed()) {
            $this->record($step, 'failed', "{$label} exited {$result->exitCode()}: " . trim($result->errorOutput() ?: $result->output()));

            return false;
        }

        $this->record($step, 'success', $label);

        return true;
    }

    private function composerBinary(): string
    {
        return (string) config('anvil.web.frontend.composer_binary', 'composer');
    }

    private function npmBinary(): string
    {
        return (string) config('anvil.web.frontend.npm_binary', 'npm');
    }

    // -----------------------------------------------------------------------
    // File helpers
    // -----------------------------------------------------------------------

    private function write(string $path, string $contents, string $step): void
    {
        $contents = rtrim($contents) . "\n";

        if ($this->dryRun) {
            $this->record($step, 'dry-run', 'would write ' . basename($path));

            return;
        }

        file_put_contents($path, $contents);
        $this->record($step, 'success', 'wrote ' . basename($path));
    }

    /** Timestamped copy before any in-place edit, so a bad patch is recoverable. */
    private function backup(string $path): void
    {
        if (! (bool) config('anvil.web.frontend.backup_before_patch', true)) {
            return;
        }

        $backup = $path . '.anvil-' . date('YmdHis') . '.bak';

        if (@copy($path, $backup)) {
            $this->record('backup', 'success', basename($backup));
        }
    }

    private function record(string $step, string $status, string $detail): void
    {
        $this->log[] = ['step' => $step, 'status' => $status, 'detail' => $detail];
    }

    private function viteSnippet(): string
    {
        return "Add manually:  import tailwindcss from '@tailwindcss/vite';  then include tailwindcss() in the plugins array.";
    }
}
