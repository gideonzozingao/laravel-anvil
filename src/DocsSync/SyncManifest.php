<?php

namespace Zuqongtech\LaravelAnvil\DocsSync;

/**
 * Fingerprints of what was synced, stored next to the spec. Commit this file.
 *
 * It does two jobs:
 *
 *   1. Skip components whose source has not changed, which is what makes `--check`
 *      cheap enough to run on every commit.
 *   2. Distinguish "the code changed" from "somebody edited the spec directly".
 *      Direct edits are allowed -- prose lives there -- but a direct STRUCTURAL edit
 *      to a managed schema is worth reporting, because that is how two sources of
 *      truth start disagreeing without anyone noticing.
 */
final class SyncManifest
{
    private const VERSION = 1;

    /** @param array<string, array{source: string, spec: string}> $entries */
    private function __construct(
        private readonly string $path,
        private array $entries = [],
    ) {}

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            return new self($path);
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || (int) ($decoded['version'] ?? 0) !== self::VERSION) {
            return new self($path);
        }

        $entries = [];

        foreach (($decoded['components'] ?? []) as $name => $entry) {
            if (is_array($entry) && isset($entry['source'], $entry['spec'])) {
                $entries[(string) $name] = ['source' => (string) $entry['source'], 'spec' => (string) $entry['spec']];
            }
        }

        return new self($path, $entries);
    }

    public function sourceChanged(string $component, ?string $fingerprint): bool
    {
        if ($fingerprint === null) {
            return true;
        }

        return ($this->entries[$component]['source'] ?? null) !== $fingerprint;
    }

    /**
     * True when the spec no longer matches what sync last wrote -- i.e. a human
     * edited it. Not an error, but reportable.
     */
    public function specEditedByHand(string $component, string $specFingerprint): bool
    {
        $known = $this->entries[$component]['spec'] ?? null;

        return $known !== null && $known !== $specFingerprint;
    }

    public function knows(string $component): bool
    {
        return isset($this->entries[$component]);
    }

    public function record(string $component, ?string $sourceFingerprint, string $specFingerprint): void
    {
        $this->entries[$component] = [
            'source' => (string) $sourceFingerprint,
            'spec' => $specFingerprint,
        ];
    }

    public function forget(string $component): void
    {
        unset($this->entries[$component]);
    }

    /**
     * Written with sorted keys so the file is stable across runs; an unstable
     * manifest would conflict on every merge.
     */
    public function save(): bool
    {
        $entries = $this->entries;
        ksort($entries);

        $payload = json_encode(
            ['version' => self::VERSION, 'components' => $entries],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            return false;
        }

        return @file_put_contents($this->path, $payload."\n") !== false;
    }

    public function path(): string
    {
        return $this->path;
    }
}
