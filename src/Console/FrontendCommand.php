<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Console;

use Illuminate\Console\Command;
use Zuqongtech\LaravelAnvil\Support\FrontendDetector;
use Zuqongtech\LaravelAnvil\Support\FrontendInstaller;

/**
 * Inspect or install the frontend dependencies the web scaffold expects.
 *
 * Exists as its own command so that installation never has to be entangled
 * with a generation run. The recommended sequence on a fresh project is:
 *
 *     php artisan anvil:frontend --install --stack=livewire
 *     php artisan anvil:generate-web --stack=livewire
 *
 * `--check` is read-only and exits non-zero when something is missing, which
 * makes it usable as a CI guard.
 */
final class FrontendCommand extends Command
{


    protected $description = 'Check or install the frontend assets used by the Anvil web scaffold';
    protected $signature = 'anvil:frontend
        {--check : Report the current state and exit without changing anything}
        {--install : Install whatever is missing}
        {--stack=blade : Which web stack the project targets (blade|livewire)}
        {--tailwind-version= : Tailwind major version to install when missing (3 or 4)}
        {--no-package-manager : Write config files but only print the composer/npm commands}
        {--dry-run : Show what would happen without writing or installing}';
    public function handle(): int
    {
        $detector = new FrontendDetector;
        $state = $detector->detect();
        $stack = strtolower((string) $this->option('stack')) === 'livewire' ? 'livewire' : 'blade';

        $this->components->info('Anvil — frontend assets');

        foreach ($state->summary() as $note) {
            $this->line('  <fg=gray>·</> ' . $note);
        }

        $missing = [];

        if ($stack === 'livewire' && ! $state->livewireUsable()) {
            $missing[] = 'livewire';
        }

        if (! $state->tailwindUsable()) {
            $missing[] = 'tailwind';
        }

        $this->newLine();

        if ($missing === []) {
            $this->components->info('Everything the web scaffold needs is present.');

            return self::SUCCESS;
        }

        if (! (bool) $this->option('install')) {
            $this->components->warn('Missing: ' . implode(', ', $missing));
            $this->line('  Run <fg=yellow>php artisan anvil:frontend --install</> to set them up,');
            $this->line('  or generate with <fg=yellow>--assets-mode=cdn</> to skip the asset build entirely.');

            // --check is a gate; a bare invocation is informational.
            return (bool) $this->option('check') ? self::FAILURE : self::SUCCESS;
        }

        $installer = new FrontendInstaller(
            detector: $detector,
            dryRun: (bool) $this->option('dry-run'),
            runPackageManager: ! (bool) $this->option('no-package-manager'),
        );

        $installer->onOutput(fn(string $line) => $this->line('  <fg=gray>' . $line . '</>'));

        $ok = true;

        if (in_array('livewire', $missing, true)) {
            $ok = $installer->installLivewire();
        }

        if ($ok && in_array('tailwind', $missing, true)) {
            $version = (int) ($this->option('tailwind-version') ?? config('anvil.web.frontend.tailwind_version', 4));
            $ok = $installer->installTailwind($version >= 3 ? $version : 4);
        }

        foreach ($installer->log() as $entry) {
            $colour = match ($entry['status']) {
                'success' => 'green',
                'failed' => 'red',
                'manual', 'note' => 'yellow',
                default => 'gray',
            };

            $this->line(sprintf('  <fg=%s>%-8s</> <fg=gray>%s</> %s', $colour, $entry['status'], str_pad($entry['step'], 16), $entry['detail']));
        }

        $this->newLine();

        if (! $ok) {
            $this->components->error('Frontend setup did not complete.');

            return self::FAILURE;
        }

        $this->components->info('Frontend assets ready. Run `npm run build` (or `npm run dev`) to compile.');

        return self::SUCCESS;
    }
}
