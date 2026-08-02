<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Client;

/**
 * Everything the client generators need about one resource, resolved once.
 *
 * In the command this replaces, four separate methods each re-derived this
 * from `ModelMetadata` plus `ApiVersionProfile` — the interface builder, the
 * resource-module builder, the hooks builder and the barrel all independently
 * worked out names, key types and casing. Four derivations of one truth, with
 * no mechanism forcing them to agree; `types.ts` declaring `id: string` while
 * `posts.ts` typed the parameter `number` was a one-character change away at
 * any time.
 *
 * Resolve once, pass the result around. A generator that needs something not
 * on this object is a signal that the builder should learn to compute it, not
 * that the generator should reach back to `ModelMetadata`.
 */
final readonly class ClientResource
{
    /**
     * @param  list<ClientProperty>  $readable  properties present on a response
     * @param  list<ClientProperty>  $writable  properties accepted on create
     * @param  list<ClientProperty>  $relations  optional, only when eager-loaded
     * @param  list<string>  $relatedInterfaces  interfaces the relations
     *                                           reference, for ordering
     */
    public function __construct(
        public string $model,
        public string $interface,
        public string $input,
        public string $module,
        public string $path,
        public TsType $keyType,
        public array $readable,
        public array $writable,
        public array $relations,
        public array $relatedInterfaces,
        public bool $softDeletes,
        public ?string $description = null,
    ) {}

    /**
     * True when nothing on this resource may be written.
     *
     * A view, or a table of entirely read-only columns. The generator emits an
     * input type of `Record<string, never>` for these rather than an empty
     * interface, because an empty interface accepts any object in TypeScript —
     * `interface X {}` is satisfied by `{ anything: 1 }`, which is the exact
     * opposite of the intent.
     */
    public function isReadOnly(): bool
    {
        return $this->writable === [];
    }

    /**
     * @return list<ClientProperty> readable properties plus relations
     */
    public function responseProperties(): array
    {
        return [...$this->readable, ...$this->relations];
    }
}
