<?php

namespace Zuqongtech\LaravelAnvil\DocsSync;

use Zuqongtech\LaravelAnvil\DocsSync\Php\SourceTokens;

/**
 * Finds the classes whose payloads should be reconciled against the spec.
 *
 * Scans configured roots on disk rather than asking the container, because a docs
 * command must work even when a resource class is broken -- which is precisely when
 * you need the drift report. Class names come from the source, not from file paths,
 * so a non-standard PSR-4 root does not break discovery.
 */
final readonly class TargetDiscovery
{
    /** @param list<array{path: string, kind: string}> $roots */
    public function __construct(private array $roots = []) {}

    /**
     * @return list<SyncTarget>
     */
    public function discover(SyncOptions $options): array
    {
        $roots = $options->roots !== [] ? $options->roots : $this->roots;
        $targets = [];
        $seen = [];

        foreach ($roots as $root) {
            foreach ($this->phpFiles($root['path']) as $file) {
                $tokens = SourceTokens::fromFile($file);

                if ($tokens === null) {
                    continue;
                }

                $class = $tokens->fullyQualifiedClassName();

                if ($class === null || isset($seen[$class])) {
                    continue;
                }

                $kind = $root['kind'];

                // A resource living under a Requests root (or vice versa) is a
                // misfile, not a reason to read it with the wrong reader.
                $short = ComponentNaming::shortName($class);
                $looksRequest = str_ends_with($short, 'Request');
                $looksResponse = str_ends_with($short, 'Resource') || str_ends_with($short, 'Collection');

                if ($looksRequest) {
                    $kind = CodeShape::REQUEST;
                } elseif ($looksResponse) {
                    $kind = CodeShape::RESPONSE;
                } else {
                    continue; // not a payload class
                }

                if ($kind === CodeShape::REQUEST && ! $options->wantsRequests()) {
                    continue;
                }

                if ($kind === CodeShape::RESPONSE && ! $options->wantsResponses()) {
                    continue;
                }

                $model = ComponentNaming::modelFor($class);
                $version = self::versionFor($class, $file);

                if (! $options->matchesModel($model) || ! $options->matchesVersion($version)) {
                    continue;
                }

                $seen[$class] = true;
                $targets[] = new SyncTarget($class, $file, $kind, $model, $version);
            }
        }

        usort($targets, static fn (SyncTarget $a, SyncTarget $b): int => [$a->model, $a->kind, $a->class] <=> [$b->model, $b->kind, $b->class]);

        return $targets;
    }

    /**
     * Components claimed by more than one class. Reported and skipped rather than
     * resolved arbitrarily: whichever class won would overwrite the other's shape
     * on every run, and the two would fight forever.
     *
     * @param  list<SyncTarget>  $targets
     * @param  array<string, string>  $resolved  component => class
     * @return array<string, list<string>>
     */
    public static function collisions(array $targets, array $resolved): array
    {
        $byComponent = [];

        foreach ($targets as $target) {
            $component = $resolved[$target->class] ?? null;

            if ($component === null) {
                continue;
            }

            $byComponent[$component][] = $target->class;
        }

        return array_filter($byComponent, static fn (array $classes): bool => count($classes) > 1);
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && strtolower($entry->getExtension()) === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * API version from the namespace or path (`...\Resources\V7\X` -> `v7`).
     */
    public static function versionFor(string $class, string $file): ?string
    {
        if (preg_match('/\\\\(v\d+[a-z]*)\\\\/i', $class, $m) === 1) {
            return strtolower($m[1]);
        }

        if (preg_match('#[/\\\\](v\d+[a-z]*)[/\\\\]#i', $file, $m) === 1) {
            return strtolower($m[1]);
        }

        return null;
    }
}
