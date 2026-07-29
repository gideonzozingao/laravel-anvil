<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;

/**
 * The front-end stack the web scaffold targets: --stack=blade|livewire.
 *
 * Exists as a value object rather than a bare string because four different
 * places have to agree on the answer — the command's validation, the generation
 * plan printed before the run, the orchestrator's generator selection, and the
 * view set written to disk. A string literal compared with === in four files is
 * how "livewire" silently falls through to the Blade path.
 */
final readonly class WebStack implements \Stringable
{
    public const BLADE = 'blade';

    public const LIVEWIRE = 'livewire';

    /**
     * Artifact keys the orchestrator should run per stack. Shared keys first so a
     * diff between the two lists reads as "what Livewire adds".
     */
    private const GENERATORS = [
        self::BLADE => [
            'web-controller',
            'web-routes',
            'web-views',
        ],
        self::LIVEWIRE => [
            'web-controller',
            'web-routes',
            'web-views',
            'livewire-form',
            'livewire-table',
        ],
    ];

    /**
     * Blade files written per stack.
     *
     * With Livewire, create/edit become three-line wrappers around
     * <livewire:...> and the field markup moves into the component's own view —
     * so _form is NOT written, and writing it anyway leaves a stale partial that
     * looks authoritative.
     */
    private const VIEWS = [
        self::BLADE => ['index', 'create', 'edit', 'show', '_form'],
        self::LIVEWIRE => ['index', 'create', 'edit', 'show'],
    ];

    private function __construct(private string $value) {}

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::BLADE, self::LIVEWIRE];
    }

    /**
     * Build from a raw option value.
     *
     * @throws InvalidArgumentException when the value is not a known stack
     */
    public static function make(?string $value, string $fallback = self::BLADE): self
    {
        $normalized = self::normalize($value);

        if ($normalized === null) {
            $normalized = self::normalize($fallback);
        }

        if ($normalized === null) {
            throw new InvalidArgumentException(sprintf(
                'Unknown web stack "%s". Expected one of: %s.',
                (string) $value,
                implode(', ', self::all()),
            ));
        }

        return new self($normalized);
    }

    /**
     * Lenient normalisation, or null when unrecognised. Accepts the aliases
     * people actually type.
     */
    public static function normalize(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        if ($value === '') {
            return null;
        }

        return match ($value) {
            'blade', 'plain', 'none', 'html' => self::BLADE,
            'livewire', 'lw', 'wire', 'livewire3' => self::LIVEWIRE,
            default => null,
        };
    }

    public static function isValid(?string $value): bool
    {
        return self::normalize($value) !== null;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function isBlade(): bool
    {
        return $this->value === self::BLADE;
    }

    public function isLivewire(): bool
    {
        return $this->value === self::LIVEWIRE;
    }

    public function label(): string
    {
        return $this->isLivewire()
            ? 'Livewire components, Blade wrappers and web routes'
            : 'controllers, Blade views and web routes';
    }

    /**
     * @return list<string>
     */
    public function generators(): array
    {
        return self::GENERATORS[$this->value];
    }

    /**
     * @return list<string>
     */
    public function views(): array
    {
        return self::VIEWS[$this->value];
    }

    public function generates(string $artifactKey): bool
    {
        return in_array($artifactKey, $this->generators(), true);
    }

    // -----------------------------------------------------------------------
    // Availability
    // -----------------------------------------------------------------------

    /** Composer package required for this stack, if any. */
    public function requiredPackage(): ?string
    {
        return $this->isLivewire() ? 'livewire/livewire' : null;
    }

    public function isAvailable(): bool
    {
        return ! $this->isLivewire() || class_exists(Livewire::class);
    }

    /**
     * Message to print when the stack was requested but its package is missing.
     * Better to say so before writing a directory of components that fatal on
     * the first request.
     */
    public function unavailableMessage(): string
    {
        return sprintf(
            'The livewire stack requires %s. Install it first: composer require %s',
            (string) $this->requiredPackage(),
            (string) $this->requiredPackage(),
        );
    }

    // -----------------------------------------------------------------------
    // Livewire naming
    // -----------------------------------------------------------------------

    /**
     * Component namespace for a resource.
     *
     *   componentNamespace('App\Livewire', 'BillingPaymentSchedules')
     *       // App\Livewire\BillingPaymentSchedules
     *   componentNamespace('App\Livewire', 'BillingPaymentSchedules', 'Core')
     *       // App\Livewire\Core\BillingPaymentSchedules
     *
     * $schemaSegment comes from ModelMetadata::schemaNamespaceSegment(), so it is
     * already reserved-word safe.
     */
    public function componentNamespace(string $root, string $resourceStudly, ?string $schemaSegment = null): string
    {
        $root = trim($root, '\\');

        return $schemaSegment === null
            ? $root.'\\'.$resourceStudly
            : $root.'\\'.$schemaSegment.'\\'.$resourceStudly;
    }

    /**
     * Filesystem path for a component class, relative to the project root.
     */
    public function componentPath(string $basePath, string $resourceStudly, string $class, ?string $schemaSegment = null): string
    {
        $segments = array_filter([trim($basePath, '/'), $schemaSegment, $resourceStudly]);

        return implode('/', $segments).'/'.$class.'.php';
    }

    /**
     * The tag name Blade uses: <livewire:billing-payment-schedules.form />
     *
     * Livewire derives this from the component's position under App\Livewire, so
     * it MUST mirror componentNamespace() — including the schema segment, or the
     * tag resolves to nothing and Blade renders an empty element with no error.
     */
    public function componentAlias(string $resourceSlug, string $class = 'form', ?string $schemaSlug = null): string
    {
        $parts = array_filter([
            $schemaSlug !== null ? Str::kebab($schemaSlug) : null,
            Str::kebab($resourceSlug),
            Str::kebab($class),
        ]);

        return implode('.', $parts);
    }

    /**
     * Dot-notation view name for a component's own Blade file.
     *
     *   livewire.billing-payment-schedules.form
     */
    public function componentView(string $viewRoot, string $resourceSlug, string $class = 'form', ?string $schemaSlug = null): string
    {
        $parts = array_filter([
            trim(str_replace('/', '.', $viewRoot), '.'),
            $schemaSlug !== null ? Str::kebab($schemaSlug) : null,
            Str::kebab($resourceSlug),
            Str::kebab($class),
        ]);

        return implode('.', $parts);
    }
}
