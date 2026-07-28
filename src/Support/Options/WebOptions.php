<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Options;

/**
 * The shape of the web scaffold.
 *
 * These eight values are currently spread across four places: four
 * resolveX() methods on GenerateWebCommand, an applyRuntimeConfig() that pushes
 * them into config under two roots, the generators that read them back out of
 * config, and a summarise() that re-derives descriptions of them. The value
 * object collapses that to one: resolve once, pass the object, read properties.
 *
 * Validation lives here rather than in the command, so anvil:generate --web and
 * anvil:forge-webapp cannot disagree about what a valid stack or page size is.
 */
final readonly class WebOptions extends OptionBag
{
    public const STACK_BLADE = 'blade';

    public const STACK_LIVEWIRE = 'livewire';

    public const ASSETS_CDN = 'cdn';

    public const ASSETS_VITE = 'vite';

    public const ASSETS_NONE = 'none';

    /** @var list<string> */
    public const STACKS = [self::STACK_BLADE, self::STACK_LIVEWIRE];

    /** @var list<string> */
    public const ASSET_MODES = [self::ASSETS_CDN, self::ASSETS_VITE, self::ASSETS_NONE];

    /** @var list<int> */
    public const TAILWIND_VERSIONS = [3, 4];

    /** The page sizes offered in the generated per-page dropdown. */
    private const PAGE_SIZE_CHOICES = [10, 15, 25, 50, 100];

    /**
     * Above this a generated listing renders slowly enough to look broken, which
     * is a worse outcome than refusing the value.
     */
    private const PAGE_SIZE_CEILING = 500;

    public function __construct(
        public string $stack = self::STACK_BLADE,
        public int $perPage = 15,
        public string $assetsMode = self::ASSETS_CDN,
        public int $tailwindVersion = 4,
        public ?string $layout = null,
        public bool $generateLayout = true,
        public bool $generateNav = true,
    ) {}

    /**
     * A reason these options cannot be used, or null.
     *
     * Returns the message rather than throwing: a console command wants to print
     * it and exit non-zero, not catch an exception to do the same thing.
     */
    public function validate(): ?string
    {
        if (! in_array($this->stack, self::STACKS, true)) {
            return sprintf('Unknown stack "%s". Use one of: %s.', $this->stack, implode(', ', self::STACKS));
        }

        if (! in_array($this->assetsMode, self::ASSET_MODES, true)) {
            return sprintf(
                'Unknown assets mode "%s". Use one of: %s.',
                $this->assetsMode,
                implode(', ', self::ASSET_MODES),
            );
        }

        if (! in_array($this->tailwindVersion, self::TAILWIND_VERSIONS, true)) {
            return sprintf(
                'Unsupported Tailwind version "%d". Use one of: %s.',
                $this->tailwindVersion,
                implode(', ', array_map(strval(...), self::TAILWIND_VERSIONS)),
            );
        }

        if ($this->perPage < 1) {
            return 'Page size must be a positive integer.';
        }

        if ($this->perPage > self::PAGE_SIZE_CEILING) {
            return sprintf(
                'Page size of %d is above the ceiling of %d. Generated listings would time out before they render.',
                $this->perPage,
                self::PAGE_SIZE_CEILING,
            );
        }

        if ($this->layout !== null && $this->layout === '') {
            return 'Layout cannot be an empty string. Omit it to use the configured default.';
        }

        return null;
    }

    public function isLivewire(): bool
    {
        return $this->stack === self::STACK_LIVEWIRE;
    }

    public function layoutView(): string
    {
        return $this->layout ?? (string) config('anvil.web.layout', 'layouts.anvil');
    }

    public function layoutPath(): string
    {
        return resource_path('views/'.str_replace('.', '/', $this->layoutView()).'.blade.php');
    }

    /**
     * True when the views will extend a layout that does not exist and will not
     * be generated — which renders as a missing-view error on first request.
     */
    public function layoutIsMissing(): bool
    {
        return ! $this->generateLayout && ! file_exists($this->layoutPath());
    }

    /**
     * Page sizes for the generated dropdown, including the configured default.
     *
     * A custom page size has to appear in the list, or the select opens showing a
     * value the operator never chose — the kind of detail that only surfaces once
     * somebody uses the feature.
     *
     * @return list<int>
     */
    public function pageSizeChoices(): array
    {
        $choices = self::PAGE_SIZE_CHOICES;

        if (! in_array($this->perPage, $choices, true)) {
            $choices[] = $this->perPage;
            sort($choices);
        }

        return array_values($choices);
    }

    public function buildsAssets(): bool
    {
        return $this->assetsMode === self::ASSETS_VITE;
    }

    public function describeStack(): string
    {
        return $this->isLivewire() ? 'Blade + Livewire 3' : 'Blade + Tailwind';
    }

    public function describeAssets(): string
    {
        return match ($this->assetsMode) {
            self::ASSETS_VITE => 'Vite build (@vite directive in the layout)',
            self::ASSETS_NONE => 'none — you wire up your own CSS',
            default => 'Tailwind Play CDN (development only)',
        };
    }

    /**
     * Advisories worth printing before generation, not after.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];

        if ($this->layoutIsMissing()) {
            $warnings[] = sprintf(
                'The views will extend "%s", which does not exist at %s. Create it, drop --no-layout, or pass a '
                    .'different --layout.',
                $this->layoutView(),
                ltrim(str_replace(base_path(), '', $this->layoutPath()), '/'),
            );
        }

        if ($this->assetsMode === self::ASSETS_CDN) {
            $warnings[] = 'The Tailwind Play CDN compiles styles in the browser and is not for production. Run '
                .'`php artisan anvil:frontend --install`, then regenerate with --assets-mode=vite.';
        }

        if ($this->assetsMode === self::ASSETS_NONE) {
            $warnings[] = 'assets-mode=none: the layout will load no CSS. Generated views render unstyled.';
        }

        return $warnings;
    }

    /**
     * The config keys the generators read.
     *
     * Kept here so the projection lives beside the values it projects, rather
     * than in a command method that has to be kept in step with both sides.
     *
     * @return array<string, mixed>
     */
    public function runtimeConfig(): array
    {
        $values = [
            'web.per_page' => $this->perPage,
            'web.per_page_options' => $this->pageSizeChoices(),
            'web.frontend.mode' => $this->assetsMode,
            'web.frontend.tailwind_version' => $this->tailwindVersion,
            'web.generate_layout' => $this->generateLayout,
            'web.generate_nav' => $this->generateNav,
        ];

        // Only set when supplied, so a bare run does not stomp the configured
        // layout with a flag default.
        if ($this->layout !== null) {
            $values['web.layout'] = $this->layout;
        }

        return $values;
    }
}
