<?php

namespace Zuqongtech\LaravelAnvil\DocsSync;

/**
 * Immutable option bag for one sync run.
 *
 * Built with `fromArray()` and case/separator-insensitive key normalisation, matching
 * the convention the package's other option classes already use, so `dry_run`,
 * `dryRun` and `dry-run` are the same option.
 */
final readonly class SyncOptions
{
    public const ONLY_ALL = 'all';

    public const ONLY_REQUESTS = 'requests';

    public const ONLY_RESPONSES = 'responses';

    /**
     * @param  list<string>  $models  empty = every discovered model
     * @param  list<array{path: string, kind: string}>  $roots
     */
    public function __construct(
        public array $models = [],
        public ?string $version = null,
        public string $only = self::ONLY_ALL,
        public bool $check = false,
        public bool $dryRun = false,
        public bool $diff = false,
        public bool $adopt = false,
        public bool $prune = true,
        public bool $quiet = false,
        public bool $breakingOnly = false,
        public array $roots = [],
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $get = static function (string $key, mixed $default = null) use ($input): mixed {
            $normalised = static fn (string $k): string => strtolower(str_replace(['-', '_'], '', $k));
            $target = $normalised($key);

            foreach ($input as $candidate => $value) {
                if ($normalised((string) $candidate) === $target) {
                    return $value;
                }
            }

            return $default;
        };

        $only = strtolower((string) $get('only', self::ONLY_ALL));

        if (! in_array($only, [self::ONLY_ALL, self::ONLY_REQUESTS, self::ONLY_RESPONSES], true)) {
            $only = self::ONLY_ALL;
        }

        $models = $get('models', []);
        $version = $get('version');

        return new self(
            models: array_values(array_filter(array_map(strval(...), is_array($models) ? $models : [$models]))),
            version: $version === null || $version === '' ? null : self::normaliseVersion((string) $version),
            only: $only,
            check: (bool) $get('check', false),
            dryRun: (bool) $get('dryRun', false),
            diff: (bool) $get('diff', false),
            adopt: (bool) $get('adopt', false),
            // `--check` must never write, so pruning is irrelevant there; keeping it
            // true is harmless because the merge result is discarded.
            prune: ! (bool) $get('noPrune', false),
            quiet: (bool) $get('quiet', false),
            breakingOnly: (bool) $get('breakingOnly', false),
            roots: self::normaliseRoots($get('roots', [])),
        );
    }

    public function wantsResponses(): bool
    {
        return $this->only !== self::ONLY_REQUESTS;
    }

    public function wantsRequests(): bool
    {
        return $this->only !== self::ONLY_RESPONSES;
    }

    public function writes(): bool
    {
        return ! $this->check && ! $this->dryRun;
    }

    public function preservesAnnotations(): bool
    {
        return true;
    }

    public function matchesModel(string $model): bool
    {
        if ($this->models === []) {
            return true;
        }

        foreach ($this->models as $candidate) {
            if (strcasecmp($candidate, $model) === 0) {
                return true;
            }

            // Accept table-ish input too (`vehicles` for `Vehicle`).
            $normalised = str_replace(['_', '-', ' '], '', strtolower($candidate));

            if ($normalised === strtolower($model) || $normalised === strtolower($model).'s') {
                return true;
            }
        }

        return false;
    }

    public function matchesVersion(?string $version): bool
    {
        if ($this->version === null || $version === null) {
            return true;
        }

        return strcasecmp($this->version, $version) === 0;
    }

    public function withRoots(array $roots): self
    {
        return new self(
            $this->models,
            $this->version,
            $this->only,
            $this->check,
            $this->dryRun,
            $this->diff,
            $this->adopt,
            $this->prune,
            $this->quiet,
            $this->breakingOnly,
            self::normaliseRoots($roots),
        );
    }

    private static function normaliseVersion(string $version): string
    {
        $version = strtolower(trim($version));

        return str_starts_with($version, 'v') ? $version : 'v'.$version;
    }

    /**
     * @return list<array{path: string, kind: string}>
     */
    private static function normaliseRoots(mixed $roots): array
    {
        if (! is_array($roots)) {
            return [];
        }

        $normalised = [];

        foreach ($roots as $root) {
            if (is_string($root)) {
                $normalised[] = ['path' => $root, 'kind' => CodeShape::RESPONSE];

                continue;
            }

            if (! is_array($root) || ! isset($root['path'])) {
                continue;
            }

            $kind = strtolower((string) ($root['kind'] ?? CodeShape::RESPONSE));

            $normalised[] = [
                'path' => (string) $root['path'],
                'kind' => $kind === CodeShape::REQUEST ? CodeShape::REQUEST : CodeShape::RESPONSE,
            ];
        }

        return $normalised;
    }
}
