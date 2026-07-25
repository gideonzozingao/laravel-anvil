<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Generators\Concerns;

use Zuqongtech\LaravelAnvil\Support\ApiVersionProfile;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;

/**
 * File writing and per-version settings for the versioned API generators.
 *
 * The exists/force/dry-run/mkdir dance was copy-pasted into roughly twenty
 * generators, and every copy is a chance to forget the dry-run check — which is
 * exactly what happened in the OpenAPI schema and path generators, where a dry
 * run reported "success" while writing nothing. One implementation, one place to
 * get it right.
 */
trait WritesVersionedFiles
{
    private ?ApiVersionProfile $profile = null;

    protected function profile(GenerationOptions $options): ApiVersionProfile
    {
        return $this->profile ??= ApiVersionProfile::for($options->apiVersion);
    }

    /**
     * Write a generated class, honouring force / backup / dry-run.
     *
     * @param  callable(): string  $content  built lazily, so a skip costs nothing
     * @return array<string, string>
     */
    protected function writeClass(
        string $type,
        string $namespace,
        string $class,
        GenerationOptions $options,
        callable $content,
        bool $overwritable = true,
    ): array {
        $path = $this->profile($options)->pathFor($namespace, $class);
        $exists = file_exists($path);

        if ($exists && (! $overwritable || ! $options->force)) {
            return [
                'type' => $type,
                'name' => $class,
                'path' => $path,
                'status' => 'skipped',
                'reason' => $overwritable ? 'already exists' : 'exists (never overwritten)',
            ];
        }

        if ($options->dryRun) {
            return [
                'type' => $type,
                'name' => $class,
                'path' => $path,
                'status' => 'dry-run',
                'action' => $exists ? 'would overwrite' : 'would create',
            ];
        }

        $dir = dirname((string) $path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if ($exists && $options->backup) {
            @copy($path, $path.'.bak.'.date('YmdHis'));
        }

        // Temp file + rename: a run killed mid-write otherwise leaves a
        // truncated PHP file that fatals the moment it is autoloaded.
        $temp = $path.'.anvil-tmp';

        if (file_put_contents($temp, $content()) === false || ! rename($temp, $path)) {
            @unlink($temp);

            return [
                'type' => $type,
                'name' => $class,
                'path' => $path,
                'status' => 'failed',
                'reason' => 'could not write file',
            ];
        }

        return [
            'type' => $type,
            'name' => $class,
            'path' => $path,
            'status' => 'success',
            // Computed BEFORE writing. The previous version checked file_exists()
            // after the write, so it always reported "overwritten".
            'action' => $exists ? 'overwritten' : 'created',
        ];
    }
}
