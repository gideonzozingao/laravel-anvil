<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Generators\OpenApi\Concerns;

use Zuqongtech\LaravelAnvil\Support\GenerationOptions;

/**
 * Resolves the flags the OpenAPI generators depend on.
 *
 * Each flag is read from the options DTO *or* the runtime config, whichever says
 * yes. The redundancy is deliberate: GenerationOptions is populated from an
 * array whose key spelling has to match the DTO exactly, and when it doesn't the
 * failure is silent — supports() returns false, the pipeline runs to completion,
 * and nothing is written. The runtime config is set by
 * GenerateOpenApiCommand::applyRuntimeConfig() from the same CLI invocation, so
 * there is no spurious source for these values.
 *
 * PARENTHESES ARE LOAD-BEARING.
 *
 * `??` binds LOOSER than `||`, so the previous
 *
 *     return $options->openApi ?? false || (bool) config('anvil.openapi.enabled');
 *
 * parsed as `$options->openApi ?? (false || config(...))`. Every one of these
 * properties is a typed bool and therefore never null, so the right-hand side
 * was unreachable: the config fallback this trait exists to provide never fired
 * once. `--dry-run` set only in config wrote files; `anvil.runtime.force` was
 * ignored entirely.
 *
 * Once GenerationOptions carries a typed OpenApiOptions object, the config
 * fallbacks can go and these helpers collapse to plain property reads.
 */
trait ResolvesSpecOptions
{
    protected function specEnabled(GenerationOptions $options): bool
    {
        return $this->either($options->openApi ?? null, 'anvil.openapi.enabled', false);
    }

    protected function publishesUi(GenerationOptions $options): bool
    {
        return $this->either($options->openApiUi ?? null, 'anvil.openapi.ui', false);
    }

    /** Erring toward "write nothing" is the safe direction for a dry run. */
    protected function isDryRun(GenerationOptions $options): bool
    {
        return $this->either($options->dryRun ?? null, 'anvil.runtime.dry_run', false);
    }

    protected function overwrites(GenerationOptions $options): bool
    {
        return $this->either($options->force ?? null, 'anvil.runtime.force', false);
    }

    protected function splitFiles(): bool
    {
        return (bool) config('anvil.openapi.split_files', true);
    }

    /**
     * True when the DTO flag or the runtime config says yes.
     *
     * Kept as one helper so the precedence is written down exactly once.
     */
    private function either(?bool $flag, string $configKey, bool $default): bool
    {
        return ($flag ?? false) || (bool) config($configKey, $default);
    }
}
