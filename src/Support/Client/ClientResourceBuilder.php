<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Client;

use Illuminate\Support\Str;
use Zuqongtech\LaravelAnvil\Support\ApiVersionProfile;
use Zuqongtech\LaravelAnvil\Support\Helpers;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Turns `ModelMetadata` + `ApiVersionProfile` into a {@see ClientResource}.
 *
 * This is where all the casing and visibility rules concentrate. Nothing
 * downstream of here calls `isHidden()`, `isReadOnly()`, `outboundMap()` or
 * `inboundMap()` — the generators consume resolved property lists and never
 * ask the profile anything.
 */
final readonly class ClientResourceBuilder
{
    public function __construct(
        private ApiVersionProfile $profile,
        private ClientNaming $naming,
        private TypeScriptTypeMapper $types,
    ) {}

    public function build(ModelMetadata $meta): ClientResource
    {
        return new ClientResource(
            model: $meta->model,
            interface: $this->naming->interface($meta),
            input: $this->naming->input($meta),
            module: $this->naming->module($meta),
            path: $this->naming->path($meta),
            keyType: $this->types->forKey($this->keyColumn($meta)),
            readable: $this->readable($meta),
            writable: $this->writable($meta),
            relations: $this->relations($meta),
            relatedInterfaces: $this->relatedInterfaces($meta),
            softDeletes: $meta->softDeletes,
            description: $meta->comment ?? null,
        );
    }

    /**
     * @return list<ClientProperty>
     */
    private function readable(ModelMetadata $meta): array
    {
        $names = array_map(
            static fn (array $column): string => (string) $column['name'],
            $meta->columns,
        );

        // One call for the whole set. The original mapped a single column at a
        // time — `outboundMap([$name])[$name]` — which is correct only if the
        // mapping is context-free. A profile that de-duplicates collisions
        // across the set (two columns casing onto one key) cannot do so when
        // it is only ever shown one column.
        $map = $this->profile->outboundMap($names);

        $properties = [];

        foreach ($meta->columns as $column) {
            $name = (string) $column['name'];

            if ($this->profile->isHidden($name)) {
                continue;
            }

            $properties[] = new ClientProperty(
                name: $map[$name] ?? $name,
                type: $this->types->forColumn($column),
                optional: false,
                comment: $this->columnComment($column),
            );
        }

        return $properties;
    }

    /**
     * @return list<ClientProperty>
     */
    private function writable(ModelMetadata $meta): array
    {
        $names = array_map(
            static fn (array $column): string => (string) $column['name'],
            $meta->columns,
        );

        // Reversed once, here. The original called `array_flip()` on a
        // single-entry inbound map at every call site, which silently picks an
        // arbitrary winner whenever two wire keys map to one column.
        $inbound = array_flip($this->profile->inboundMap($names));

        $properties = [];
        $primaryKey = $meta->primaryKey ?? 'id';

        foreach ($meta->columns as $column) {
            $name = (string) $column['name'];

            if (
                $name === $primaryKey
                || $this->profile->isHidden($name)
                || $this->profile->isReadOnly($name)
            ) {
                continue;
            }

            $properties[] = new ClientProperty(
                name: $inbound[$name] ?? $name,
                type: $this->types->forColumn($column),
                optional: $this->isOptionalOnCreate($column),
                comment: $this->columnComment($column),
            );
        }

        return $properties;
    }

    /**
     * @return list<ClientProperty>
     */
    private function relations(ModelMetadata $meta): array
    {
        $properties = [];
        $seen = [];

        foreach ($meta->foreignKeys as $fk) {
            $column = (string) ($fk['column'] ?? '');
            $method = $meta->belongsToName($column);

            if ($method === null) {
                continue;
            }

            $related = Helpers::tableToModelName((string) $fk['referenced_table']);
            $snake = Str::snake($method);
            $key = $this->profile->outboundMap([$snake])[$snake] ?? $method;

            // Two foreign keys to one parent produce two relation methods, and
            // `RelationNamer` disambiguates them on the PHP side. If they reach
            // here already colliding, emitting both would produce a duplicate
            // interface property, which is a TypeScript error rather than a
            // silent overwrite — so drop the duplicate and let the PHP-side
            // detection be the thing that reports it.
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $properties[] = new ClientProperty(
                name: $key,
                type: TsType::reference($related),
                optional: true,
                comment: 'Present only when eager-loaded.',
            );
        }

        return $properties;
    }

    /**
     * @return list<string>
     */
    private function relatedInterfaces(ModelMetadata $meta): array
    {
        $interfaces = [];

        foreach ($meta->foreignKeys as $fk) {
            $related = Helpers::tableToModelName((string) ($fk['referenced_table'] ?? ''));

            if ($related !== '' && $related !== $meta->model) {
                $interfaces[$related] = true;
            }
        }

        return array_keys($interfaces);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function keyColumn(ModelMetadata $meta): ?array
    {
        $primaryKey = $meta->primaryKey ?? 'id';

        foreach ($meta->columns as $column) {
            if ((string) $column['name'] === $primaryKey) {
                return $column;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private function isOptionalOnCreate(array $column): bool
    {
        // Nullable or defaulted means the caller may omit it. Note that a
        // default of `null` is indistinguishable from no default in most
        // introspection output, which is why nullability is checked first
        // rather than relying on the default alone.
        return (bool) ($column['nullable'] ?? false)
            || ($column['default'] ?? null) !== null
            || (bool) ($column['auto_increment'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private function columnComment(array $column): ?string
    {
        $comment = trim((string) ($column['comment'] ?? ''));

        return $comment === '' ? null : $comment;
    }
}
