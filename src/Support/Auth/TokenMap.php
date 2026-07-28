<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Auth;

/**
 * Resolves %TOKEN% placeholders in nowdoc templates.
 *
 * Templates stay nowdoc so generated `$vars`, `{{ }}` and `@directives` survive
 * verbatim — no PHP interpolation anywhere in the output path.
 *
 * THE SINGLE-PASS RULE
 *
 * strtr() does not re-scan text it has just substituted. So a value containing
 * its own tokens must be resolved BEFORE it enters the map, or those tokens reach
 * the output literally.
 *
 * The old design handled this by resolving feature blocks inside tokens(), which
 * meant the token map knew what belonged in the Login component. Now render()
 * takes per-call fragments and resolves them against the scalars first, so a part
 * composes its own blocks and the map stays a flat list of scalars.
 */
final readonly class TokenMap
{
    /** @var array<string, string> */
    private array $scalars;

    public function __construct(AuthContext $context)
    {
        $this->scalars = [
            '%AUTH_NS%' => $context->namespace,
            '%USER_FQN%' => $context->appNamespace().'Models\\User',
            '%USER%' => 'User',
            '%GUARD%' => $context->guard,
            '%LAYOUT%' => $context->layoutView(),
            '%DEFAULT_ROLE%' => $context->defaultRole ?? '',
            '%ROLES_TABLE%' => $context->rolesTable,
            '%PERMISSIONS_TABLE%' => $context->permissionsTable,
            '%USERS_TABLE%' => $context->usersTable,
            '%LOCK_THRESHOLD%' => (string) $context->lockThreshold,
            '%LOCK_MINUTES%' => (string) $context->lockMinutes,
            '%THROTTLE_MAX%' => (string) $context->throttleMax,
        ];
    }

    /**
     * @param  array<string, string>  $fragments  Multi-line blocks that may themselves contain scalar tokens
     */
    public function render(string $template, array $fragments = []): string
    {
        $resolved = [];

        foreach ($fragments as $token => $body) {
            // Resolved against scalars first — see the class docblock.
            $resolved[$token] = strtr($body, $this->scalars);
        }

        return strtr($template, $this->scalars + $resolved);
    }

    /** Resolve scalars in a standalone string, for values built outside a template. */
    public function scalar(string $value): string
    {
        return strtr($value, $this->scalars);
    }
}
