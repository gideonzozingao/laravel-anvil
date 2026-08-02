<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

/**
 * A collision-safe `use` statement table for a single generated file.
 *
 * ModelBuilder already had an import table of its own; every *other* generator
 * (resources, controllers, form requests, Livewire components, policies…) was
 * writing `use` lines ad hoc, which is why schema-namespaced models regressed to
 * `App\Models\User`. This class is the shared implementation so there is exactly
 * one place where an import is turned into a local symbol.
 *
 * Usage:
 *
 *   $imports = new ImportSet('App\Http\Resources\Core');
 *   $imports->reserve('UserResource');                       // the class being generated
 *   $local = $imports->add('App\Models\Core\User');          // "User"
 *   $other = $imports->add('App\Models\Auth\User');          // "AuthUser"
 *   $imports->render();                                      // the use block
 */
final class ImportSet
{
    /** @var array<string, string> fqcn => local symbol */
    private array $aliases = [];

    /** @var array<string, string> lowercased local symbol => owning fqcn ('' when merely reserved) */
    private array $taken = [];

    /** @var array<string, true> fqcn set that must NOT be rendered (same namespace as the file) */
    private array $sameNamespace = [];

    private readonly ?string $currentNamespace;

    public function __construct(?string $currentNamespace = null)
    {
        $this->currentNamespace = $currentNamespace === null ? null : trim($currentNamespace, '\\');
    }

    /**
     * Claim local symbols that must never be handed to an import — typically the
     * name of the class being generated, plus any trait or interface written
     * into the stub by hand.
     */
    public function reserve(string ...$names): self
    {
        foreach ($names as $name) {
            $short = $this->shortNameOf($name);

            if ($short === '') {
                continue;
            }

            $this->taken[strtolower($short)] ??= '';
        }

        return $this;
    }

    /**
     * Register a class and return the local symbol to write in the file body.
     */
    public function add(string $fqcn): string
    {
        $fqcn = self::normalise($fqcn);

        throw_if($fqcn === '', \InvalidArgumentException::class, 'Cannot import an empty class name.');

        if (isset($this->aliases[$fqcn])) {
            return $this->aliases[$fqcn];
        }

        $short = $this->shortNameOf($fqcn);

        // A class in this file's own namespace is referenced bare and cannot be
        // aliased, so it takes the short name unconditionally and is not rendered.
        if ($this->currentNamespace !== null && $this->namespaceOf($fqcn) === $this->currentNamespace) {
            $this->aliases[$fqcn] = $short;
            $this->taken[strtolower($short)] = $fqcn;
            $this->sameNamespace[$fqcn] = true;

            return $short;
        }

        $this->aliases[$fqcn] = $alias = $this->pickAlias($fqcn, $short);
        $this->taken[strtolower($alias)] = $fqcn;

        return $alias;
    }

    /**
     * Register a class and return a `Local::class` expression.
     */
    public function reference(string $fqcn): string
    {
        return $this->add($fqcn).'::class';
    }

    public function has(string $fqcn): bool
    {
        return isset($this->aliases[self::normalise($fqcn)]);
    }

    public function aliasFor(string $fqcn): ?string
    {
        return $this->aliases[self::normalise($fqcn)] ?? null;
    }

    /**
     * @return array<string, string> fqcn => local symbol, for the lines that will render
     */
    public function all(): array
    {
        return array_diff_key($this->aliases, $this->sameNamespace);
    }

    public function isEmpty(): bool
    {
        return $this->all() === [];
    }

    /**
     * Render the use block, one statement per line, alphabetised by FQCN.
     * Returns an empty string when there is nothing to import, so a stub can
     * interpolate it without leaving a blank line behind.
     */
    public function render(string $indent = ''): string
    {
        $renderable = $this->all();

        if ($renderable === []) {
            return '';
        }

        $keys = array_keys($renderable);
        usort($keys, strcasecmp(...));

        $lines = [];

        foreach ($keys as $fqcn) {
            $alias = $renderable[$fqcn];
            $short = $this->shortNameOf($fqcn);

            $lines[] = $alias === $short
                ? $indent.'use '.$fqcn.';'
                : $indent.'use '.$fqcn.' as '.$alias.';';
        }

        return implode("\n", $lines);
    }

    /**
     * Choose an unused local symbol for $fqcn, widening the alias by walking back
     * up the namespace before falling back to a numeric suffix.
     */
    private function pickAlias(string $fqcn, string $short): string
    {
        if (! $this->isTaken($short)) {
            return $short;
        }

        $segments = explode('\\', $fqcn);
        array_pop($segments); // drop the class name; $short already holds it

        $candidate = $short;

        for ($i = count($segments) - 1; $i >= 0; $i--) {
            $candidate = $segments[$i].$candidate;

            if (! $this->isTaken($candidate)) {
                return $candidate;
            }
        }

        $suffix = 2;

        while ($this->isTaken($short.$suffix)) {
            $suffix++;
        }

        return $short.$suffix;
    }

    private function isTaken(string $symbol): bool
    {
        return array_key_exists(strtolower($symbol), $this->taken);
    }

    /**
     * Strip surrounding whitespace and leading/trailing namespace separators, so
     * "\\App\\Models\\Core\\User" and " App\\Models\\Core\\User " are one key.
     */
    private static function normalise(string $fqcn): string
    {
        return trim($fqcn, " \t\n\r\0\x0B\\");
    }

    private function shortNameOf(string $fqcn): string
    {
        $fqcn = self::normalise($fqcn);
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    private function namespaceOf(string $fqcn): string
    {
        $fqcn = self::normalise($fqcn);
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? '' : substr($fqcn, 0, $pos);
    }
}
