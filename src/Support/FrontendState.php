<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

/**
 * Immutable snapshot of the host application's frontend tooling.
 *
 * Produced by FrontendDetector, consumed by FrontendInstaller and the console
 * commands. Nothing here touches the filesystem — it is a value object, so it
 * can be built once per run and passed around without re-probing disk.
 */
final readonly class FrontendState
{
    /**
     * @param  bool  $livewireInstalled     livewire/livewire resolvable via Composer
     * @param  string|null  $livewireVersion  Installed pretty version, when known
     * @param  bool  $tailwindInstalled     tailwindcss present in package.json
     * @param  int  $tailwindMajor          Major version, or 0 when undetermined
     * @param  bool  $packageJsonPresent    base_path('package.json') exists
     * @param  bool  $nodeModulesPresent    Dependencies actually installed
     * @param  bool  $viteConfigPresent     vite.config.js|ts exists
     * @param  bool  $tailwindWiredToVite   @tailwindcss/vite plugin referenced
     * @param  bool  $postcssConfigured     Tailwind 3 style postcss pipeline
     * @param  string|null  $cssEntrypoint  Relative path to the CSS entry, if found
     * @param  bool  $cssImportsTailwind    Entry CSS already pulls Tailwind in
     */
    public function __construct(
        public bool $livewireInstalled,
        public ?string $livewireVersion,
        public bool $tailwindInstalled,
        public int $tailwindMajor,
        public bool $packageJsonPresent,
        public bool $nodeModulesPresent,
        public bool $viteConfigPresent,
        public bool $tailwindWiredToVite,
        public bool $postcssConfigured,
        public ?string $cssEntrypoint,
        public bool $cssImportsTailwind,
    ) {}

    /**
     * True when Tailwind is installed AND actually wired into a build, rather
     * than merely sitting in package.json. A dependency nobody imports produces
     * unstyled views, which is the confusing failure mode we want to catch.
     */
    public function tailwindUsable(): bool
    {
        if (! $this->tailwindInstalled || ! $this->cssImportsTailwind) {
            return false;
        }

        return $this->tailwindMajor >= 4
            ? $this->tailwindWiredToVite
            : $this->postcssConfigured;
    }

    /** Livewire 3+ is required by the generated components (wire:model.live etc.). */
    public function livewireUsable(): bool
    {
        if (! $this->livewireInstalled) {
            return false;
        }

        if ($this->livewireVersion === null) {
            return true;
        }

        return version_compare(ltrim($this->livewireVersion, 'v'), '3.0.0', '>=');
    }

    /** @return list<string> Human-readable notes for `anvil:frontend --check`. */
    public function summary(): array
    {
        $notes = [];

        $notes[] = $this->livewireInstalled
            ? 'Livewire: installed' . ($this->livewireVersion !== null ? " ({$this->livewireVersion})" : '')
            : 'Livewire: not installed';

        if ($this->livewireInstalled && ! $this->livewireUsable()) {
            $notes[] = 'Livewire: version below 3.0 — generated components use Livewire 3 syntax';
        }

        $notes[] = match (true) {
            ! $this->tailwindInstalled => 'Tailwind: not installed',
            $this->tailwindMajor > 0 => "Tailwind: installed (v{$this->tailwindMajor})",
            default => 'Tailwind: installed (version undetermined)',
        };

        if ($this->tailwindInstalled && ! $this->tailwindUsable()) {
            $notes[] = 'Tailwind: installed but not wired into a build — views will render unstyled';
        }

        if (! $this->packageJsonPresent) {
            $notes[] = 'Node: no package.json — this looks like a build-less application';
        } elseif (! $this->nodeModulesPresent) {
            $notes[] = 'Node: dependencies declared but node_modules is missing — run npm install';
        }

        return $notes;
    }
}
