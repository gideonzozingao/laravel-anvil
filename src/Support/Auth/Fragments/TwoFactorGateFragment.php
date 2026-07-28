<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Auth\Fragments;

use Zuqongtech\LaravelAnvil\Support\Auth\AuthContext;

/**
 * The hand-off from Login to the two-factor challenge.
 *
 * Separate from TwoFactorPart because it is code injected into somebody else's
 * template, and because Login must remain generatable with 2FA switched off.
 *
 * The pending challenge now carries an expiry. Previously the session key had no
 * lifetime, so a half-completed sign-in stayed redeemable indefinitely — a
 * browser left open on the challenge screen was a standing credential.
 */
final readonly class TwoFactorGateFragment
{
    /** How long a pending challenge stays redeemable, in seconds. */
    public const PENDING_SECONDS = 300;

    public function __construct(private AuthContext $context) {}

    public function applies(): bool
    {
        return $this->context->twoFactor;
    }

    public function gate(): string
    {
        if (! $this->applies()) {
            return '';
        }

        return strtr(<<<'PHP'

        if (! is_null($user->two_factor_secret) && ! is_null($user->two_factor_confirmed_at)) {
            session([
                'login.2fa.id' => $user->getKey(),
                'login.2fa.remember' => $this->remember,
                'login.2fa.expires_at' => now()->addSeconds(%PENDING%)->getTimestamp(),
            ]);

            Auth::guard('%GUARD%')->logout();

            return $this->redirect(route('two-factor.login'), navigate: true);
        }
PHP, ['%PENDING%' => (string) self::PENDING_SECONDS]);
    }
}
