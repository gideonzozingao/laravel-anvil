<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Auth\Contracts;

use Zuqongtech\LaravelAnvil\Support\Auth\AuthContext;
use Zuqongtech\LaravelAnvil\Support\Auth\ScaffoldWriter;

/**
 * One independently emittable slice of the authentication scaffold: a screen, a
 * layout, the authorization layer, the route file.
 *
 * AuthScaffolder already documents its parts as `list<ScaffoldPart>` but the
 * import was commented out, so nothing enforced this shape. The failure mode is
 * quiet until it isn't: a part that forgets notes() throws
 * "Call to undefined method" from inside postInstallNotes(), i.e. AFTER every file
 * has been written, leaving a half-reported scaffold on disk.
 *
 * Implementations must be stateless. AuthScaffolder constructs each part once and
 * calls supports() twice — once during generate(), once during
 * postInstallNotes() — so anything a part remembers between those two calls is a
 * bug waiting for a second scaffolder instance.
 */
interface ScaffoldPart
{
    /**
     * Whether this part applies to the current context.
     *
     * Consulted before emit() AND before notes(), so a part that is skipped
     * contributes no post-install guidance either. This is the mechanism that
     * stops the "next steps" list describing files that were never written.
     */
    public function supports(AuthContext $context): bool;

    /**
     * Write this part's files through the writer.
     *
     * Must not write directly to disk: the writer owns force/backup/dry-run and
     * the results log the command reports from.
     */
    public function emit(AuthContext $context, ScaffoldWriter $writer): void;

    /**
     * Manual steps this part requires, in the order the operator should do them.
     *
     * Return the steps that cannot be automated — a migration to run, a config
     * key to set, a package to install. Not a description of what was generated;
     * the results table already covers that.
     *
     * @return list<string>
     */
    public function notes(AuthContext $context): array;
}
