<?php

namespace Zuqongtech\LaravelAnvil\DocsSync;

/**
 * Maps a class to the OpenAPI component it documents.
 *
 * This is deliberately a CANDIDATE generator rather than a single formula. Anvil's
 * generators have used more than one convention, and a hard-coded guess would
 * cause sync to create a duplicate component (`VehicleStoreRequest`) next to the
 * real one (`StoreVehicleRequest`) instead of updating it -- leaving the spec with
 * two schemas, one of them permanently stale.
 *
 * So: generate candidates, let the caller pick the first that already exists in
 * the spec, and fall back to the preferred form only when none do. An explicit
 * `anvil.openapi.sync.schema_names` entry always wins.
 */
final readonly class ComponentNaming
{
    /** @param array<string, string> $overrides FQCN or short name => component name */
    public function __construct(private array $overrides = []) {}

    public static function shortName(string $class): string
    {
        $parts = explode('\\', str_replace('/', '\\', $class));

        return end($parts);
    }

    /**
     * The model/entity name a class documents. `VehicleResource` -> `Vehicle`,
     * `StoreVehicleRequest` -> `Vehicle`, `VehicleCollection` -> `Vehicle`.
     */
    public static function modelFor(string $class): string
    {
        $short = self::shortName($class);

        $short = preg_replace('/^(Store|Update|Create|Patch|Upsert|Destroy|Delete|Index|Show)/', '', $short) ?? $short;
        $short = preg_replace('/(Resource|Collection|Request|Payload|Dto|DTO)$/', '', $short) ?? $short;

        return $short === '' ? self::shortName($class) : $short;
    }

    /**
     * Ordered component-name candidates. First existing wins; first overall is the
     * name used when the component must be created.
     *
     * @return list<string>
     */
    public function candidatesFor(string $class, string $kind): array
    {
        $short = self::shortName($class);

        if ($override = $this->override($class, $short)) {
            return [$override];
        }

        $model = self::modelFor($class);

        if ($kind === CodeShape::RESPONSE) {
            return array_values(array_unique(array_filter([
                $model,
                $short,
                preg_replace('/(Resource|Collection)$/', '', $short) ?: null,
                $model.'Resource',
            ])));
        }

        // Requests: the verb prefix matters, so preserve it in every form.
        $verb = preg_match('/^(Store|Update|Create|Patch|Upsert)/', $short, $m) === 1 ? $m[1] : '';
        $base = preg_replace('/Request$/', '', $short) ?? $short;

        return array_values(array_unique(array_filter([
            $short,
            $base,
            $verb !== '' ? $model.$verb.'Request' : null,
            $verb !== '' ? $model.$verb.'Payload' : null,
            $model.'Request',
        ])));
    }

    private function override(string $class, string $short): ?string
    {
        foreach ([$class, ltrim($class, '\\'), $short] as $key) {
            if (isset($this->overrides[$key]) && $this->overrides[$key] !== '') {
                return $this->overrides[$key];
            }
        }

        return null;
    }

    /**
     * Component name for a nested resource referenced inside `toArray()`, used to
     * build `$ref`s. Always the first candidate: a nested ref has no spec context
     * to disambiguate against at read time.
     */
    public function referenceFor(string $resourceClass): string
    {
        return $this->candidatesFor($resourceClass, CodeShape::RESPONSE)[0]
            ?? self::modelFor($resourceClass);
    }
}
