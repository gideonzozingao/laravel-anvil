<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Console\Concerns;

use Zuqongtech\LaravelAnvil\Support\FrontendDetector;
use Zuqongtech\LaravelAnvil\Support\FrontendInstaller;

/**
 * Frontend-asset preflight for the web scaffold commands.
 *
 * Runs BEFORE any generator, because a Composer install cannot take effect in
 * the process that performs it: the autoloader is already built, so a freshly
 * required Livewire is not registered until the next boot. Installing first and
 * asking the operator to re-run is the only honest sequence.
 *
 * The gate is deliberately conservative:
 *   missing dependency + --with-* or --install-assets -> install
 *   missing dependency + interactive terminal         -> ask
 *   missing dependency + --no-interaction             -> warn, and abort only
 *                                                        when the chosen stack
 *                                                        genuinely cannot work
 *
 * NOTE: the flags themselves are declared in each command's $signature, not
 * here. Laravel builds a signature-based command's definition through
 * configureUsingFluentDefinition() and never calls getOptions(), so options
 * returned from a trait method would be silently dropped.
 */
trait InstallsFrontendAssets
{
    /**
     * Returns null to continue into generation, or the exit code the command
     * should return.
     *
     * SUCCESS is returned after a successful Livewire install — the run stops,
     * but nothing went wrong, and a CI pipeline should not see a failure.
     */
    protected function preflightFrontendAssets(string $stack, string $assetsMode, int $tailwindVersion): ?int
    {
        if ((bool) $this->option('skip-asset-check')) {
            return null;
        }

        $detector = new FrontendDetector;
        $state = $detector->detect();

        $needsLivewire = $stack === 'livewire' && ! $state->livewireUsable();
        $needsTailwind = $assetsMode === 'vite' && ! $state->tailwindUsable();

        // Only narrate when there is something to say. A clean project should
        // not have to read a report about dependencies it already has.
        if (! $needsLivewire && ! $needsTailwind) {
            return null;
        }

        $this->components->info('Frontend preflight');

        foreach ($state->summary() as $note) {
            $this->line('  <fg=gray>·</> '.$note);
        }

        $this->newLine();

        $installer = new FrontendInstaller(
            detector: $detector,
            dryRun: (bool) $this->option('dry-run'),
            runPackageManager: ! (bool) $this->option('no-package-manager'),
        );

        $installer->onOutput(fn (string $line) => $this->line('  <fg=gray>'.$line.'</>'));

        $livewireInstalled = false;
        $ok = true;

        if ($needsLivewire) {
            if ($this->shouldInstall('with-livewire', 'Livewire 3 is required by --stack=livewire. Install it now?')) {
                $ok = $installer->installLivewire();
                $livewireInstalled = $ok;
            } else {
                // Fatal: the components would resolve to nothing at runtime.
                $this->reportLog($installer);
                $this->components->error(
                    'Livewire is missing — the generated components cannot run without livewire/livewire ^3.0.',
                );
                $this->line('  Re-run with <fg=yellow>--with-livewire</>, install it yourself, or use <fg=yellow>--stack=blade</>.');

                return self::FAILURE;
            }
        }

        if ($ok && $needsTailwind) {
            if ($this->shouldInstall('with-tailwind', 'Tailwind CSS is not wired into this project. Install it now?')) {
                $ok = $installer->installTailwind($tailwindVersion);
            } else {
                // Not fatal: unstyled views are ugly, not broken.
                $this->components->warn(
                    'Tailwind is not wired up — generated views will render unstyled. '
                        .'--assets-mode=cdn is a build-free alternative.',
                );
            }
        }

        $this->reportLog($installer);

        if (! $ok) {
            $this->components->error('Frontend setup did not complete. Nothing has been generated.');

            return self::FAILURE;
        }

        // The install we just performed is invisible to this process.
        if ($livewireInstalled && ! (bool) $this->option('dry-run')) {
            $this->newLine();
            $this->components->info('Livewire installed.');
            $this->line('  It is not autoloadable in this process yet — re-run the same command to generate the components.');

            return self::SUCCESS;
        }

        $this->newLine();

        return null;
    }

    private function shouldInstall(string $flag, string $question): bool
    {
        if ((bool) $this->option($flag) || (bool) $this->option('install-assets')) {
            return true;
        }

        if (! $this->input->isInteractive() || (bool) $this->option('no-interaction')) {
            return false;
        }

        return (bool) $this->confirm($question, default: false);
    }

    private function reportLog(FrontendInstaller $installer): void
    {
        foreach ($installer->log() as $entry) {
            $colour = match ($entry['status']) {
                'success' => 'green',
                'failed' => 'red',
                'manual', 'note' => 'yellow',
                default => 'gray',
            };

            $this->line(sprintf(
                '  <fg=%s>%-8s</> <fg=gray>%s</> %s',
                $colour,
                $entry['status'],
                str_pad($entry['step'], 16),
                $entry['detail'],
            ));
        }
    }
}
