<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

use RuntimeException;
use Zuqongtech\LaravelAnvil\Support\Auth\AuthContext;
use Zuqongtech\LaravelAnvil\Support\Auth\Contracts\ScaffoldPart;
use Zuqongtech\LaravelAnvil\Support\Auth\Parts\AuthorizationPart;
use Zuqongtech\LaravelAnvil\Support\Auth\Parts\EmailVerificationPart;
use Zuqongtech\LaravelAnvil\Support\Auth\Parts\GuestLayoutPart;
use Zuqongtech\LaravelAnvil\Support\Auth\Parts\LoginPart;
use Zuqongtech\LaravelAnvil\Support\Auth\Parts\PasswordResetPart;
use Zuqongtech\LaravelAnvil\Support\Auth\Parts\RegisterPart;
use Zuqongtech\LaravelAnvil\Support\Auth\Parts\RoutesPart;
use Zuqongtech\LaravelAnvil\Support\Auth\Parts\TwoFactorPart;
use Zuqongtech\LaravelAnvil\Support\Auth\ScaffoldWriter;
use Zuqongtech\LaravelAnvil\Support\Auth\TokenMap;

/**
 * Assembles the authentication scaffold from independent parts.
 *
 * This class used to be 1,900 lines carrying twelve responsibilities: schema
 * introspection, token substitution, a UI kit, seven screens' worth of templates,
 * migrations, authorization, routes, file IO and post-install guidance. Each of
 * those now lives in a class that can be read, tested and changed on its own; what
 * remains here is ordering and aggregation.
 *
 * ORDER MATTERS in one place only: RoutesPart is emitted last, because it names
 * every component class and reads the context's feature flags to decide which
 * imports and route entries to include.
 */
class AuthScaffolder
{
    private readonly AuthContext $context;

    private readonly ScaffoldWriter $writer;

    /** @var list<ScaffoldPart> */
    private readonly array $parts;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected DatabaseInspector $inspector,
        protected array $config,
    ) {
        $this->context = AuthContext::make($inspector, $config);
        $this->writer = new ScaffoldWriter($this->context, new TokenMap($this->context));
        $this->parts = [
            new GuestLayoutPart,
            new LoginPart,
            new RegisterPart,
            new PasswordResetPart,
            new EmailVerificationPart,
            new TwoFactorPart,
            new AuthorizationPart,
            // Last: needs to know which components exist.
            new RoutesPart,
        ];
    }

    public function context(): AuthContext
    {
        return $this->context;
    }

    /** Retained for the command's summary table. */
    public function rbacDetected(): bool
    {
        return $this->context->rbac;
    }

    /**
     * A reason the scaffold cannot run, or null.
     *
     * generate() enforces this itself now. It previously relied on the command
     * remembering to ask, and the command did not — which made every check in
     * AuthContext::validate() dead code.
     */
    public function validate(): ?string
    {
        return $this->context->validate();
    }

    /**
     * @return list<array{type: string, name: string, status: string, reason?: string, path?: string}>
     *
     * @throws RuntimeException when the context is not viable
     */
    public function generate(): array
    {
        // Half a scaffold is worse than none: the operator gets a login screen
        // that cannot authenticate and no signal that anything was wrong.
        throw_if(($problem = $this->validate()) !== null, RuntimeException::class, $problem);

        foreach ($this->parts as $part) {
            if (! $part->supports($this->context)) {
                continue;
            }

            $part->emit($this->context, $this->writer);
        }

        return $this->writer->results();
    }

    /**
     * Manual steps, collected from the parts that actually ran.
     *
     * Previously one hand-maintained list at the bottom of the class tried to
     * describe everything above it, and drifted from it. Now a part that stops
     * needing a step stops mentioning it — including the "require auth.php" step,
     * which belongs to RoutesPart and was hardcoded here even on runs where
     * RoutesPart was skipped.
     *
     * @return list<string>
     */
    public function postInstallNotes(): array
    {
        $notes = [];

        foreach ($this->parts as $part) {
            if (! $part->supports($this->context)) {
                continue;
            }

            foreach ($part->notes($this->context) as $note) {
                $notes[] = $note;
            }
        }

        return array_values(array_unique($notes));
    }
}
