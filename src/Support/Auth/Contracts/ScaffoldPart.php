<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Auth\Contracts;

use Zuqongtech\LaravelAnvil\Support\Auth\AuthContext;
use Zuqongtech\LaravelAnvil\Support\Auth\ScaffoldWriter;

/**
 * One slice of the auth scaffold.
 *
 * Mirrors the package's existing Generator contract deliberately: supports() /
 * name() / a method that does the work and reports results. A part owns its
 * templates, decides whether it applies, and supplies its own post-install
 * notes — so the guidance printed after a run cannot drift from the code that
 * was actually written, which is what happens when one list at the bottom of a
 * 1,900-line class tries to describe everything above it.
 */
interface ScaffoldPart
{
    /** Whether this part applies to the given context. */
    public function supports(AuthContext $context): bool;

    /** Short label used in the console report. */
    public function name(): string;

    /** Emit every file this part owns through the writer. */
    public function emit(AuthContext $context, ScaffoldWriter $writer): void;

    /**
     * Manual steps this part's output depends on.
     *
     * @return list<string>
     */
    public function notes(AuthContext $context): array;
}
