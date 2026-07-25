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
 * Once GenerationOptions carries a typed OpenApiOptions object, the config
 * fallbacks can go and these helpers collapse to plain property reads.
 */
trait ResolvesSpecOptions
{
    protected function specEnabled(GenerationOptions $options): bool
    {
        return $options->openApi ?? false
            || (bool) config('anvil.openapi.enabled', false);
    }

    protected function publishesUi(GenerationOptions $options): bool
    {
        return $options->openApiUi ?? false
            || (bool) config('anvil.openapi.ui', false);
    }

    /** Erring toward "write nothing" is the safe direction for a dry run. */
    protected function isDryRun(GenerationOptions $options): bool
    {
        return $options->dryRun ?? false
            || (bool) config('anvil.runtime.dry_run', false);
    }

    protected function overwrites(GenerationOptions $options): bool
    {
        return $options->force ?? false
            || (bool) config('anvil.runtime.force', false);
    }

    protected function splitFiles(): bool
    {
        return (bool) config('anvil.openapi.split_files', true);
    }
}
