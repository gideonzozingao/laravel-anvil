<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Runtime\Cache;

use Illuminate\Database\Eloquent\Builder;

/**
 * Builds cache keys.
 *
 * Shape:
 *
 *   anvil:v1:api_key:list:api_key@1712…+user@1712…:auth:default:7:9f3c1a…
 *   │     │  │        │    │                       │            │
 *   │     │  │        │    │                       │            └ descriptor digest
 *   │     │  │        │    │                       └ scope (who is asking)
 *   │     │  │        │    └ composite dependency stamps
 *   │     │  │        └ profile (volatility class)
 *   │     │  └ model
 *   │     └ key schema version — bump to orphan every key after a shape change
 *   └ configurable prefix
 *
 * The readable segments are there so a human staring at `redis --scan` output can
 * tell what a key is for. The digest at the end carries the parts that vary
 * without bound.
 *
 * TWO THINGS THE DIGEST MUST INCLUDE AND ONE IT MUST NOT
 *
 * Must: every input that changes the result — page, page size, sort column and
 * direction, filters, eager loads, and the API version, because v1 and v2 shape
 * the same row differently and share this cache.
 *
 * Must: bound parameter VALUES when keying off a query. A digest of the SQL
 * template alone collides across every distinct binding, which is the classic
 * way to serve one tenant's rows to another.
 *
 * Must not: anything unstable across processes — closures, object identity,
 * `spl_object_hash`. A key that differs between php-fpm workers never hits.
 */
final readonly class CacheKey
{
    /**
     * Bump when the meaning of any key segment changes, to orphan the old
     * generation instead of misreading it.
     */
    private const SCHEMA = 'v1';

    public function __construct(
        private CachePolicy $policy = new CachePolicy,
        private CacheStamps $stamps = new CacheStamps,
    ) {}

    /**
     * @param  string  $model  FQCN of the primary model
     * @param  string  $profile  CachePolicy::PROFILE_*
     * @param  array<string, mixed>  $descriptor  Everything that varies the result
     * @param  list<string>  $dependsOn  Additional models this result embeds
     */
    public function for(string $model, string $profile, array $descriptor, array $dependsOn = []): string
    {
        return implode(':', [
            $this->policy->prefix(),
            self::SCHEMA,
            $this->policy->normaliseModel($model),
            $profile,
            $this->stamps->composite([$model, ...$dependsOn]),
            $this->policy->scope(),
            $this->digest($descriptor),
        ]);
    }

    /**
     * Stable digest of an arbitrarily nested descriptor.
     *
     * Keys are sorted recursively, so ['page' => 1, 'sort' => 'id'] and
     * ['sort' => 'id', 'page' => 1] are one cache entry rather than two.
     *
     * @param  array<string, mixed>  $descriptor
     */
    public function digest(array $descriptor): string
    {
        $normalised = $this->normalise($descriptor);

        // xxh128 where available: same collision resistance for this purpose,
        // several times faster than sha1 on the short strings we hash here.
        $algo = in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'sha1';

        return substr(hash($algo, json_encode(
            $normalised,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        ) ?: ''), 0, 32);
    }

    /**
     * Describe an Eloquent builder well enough to key off it.
     *
     * SQL plus bindings plus eager loads. Without the bindings every
     * `where('tenant_id', ?)` shares one key.
     *
     * @return array<string, mixed>
     */
    public function describeQuery(Builder $query): array
    {
        return [
            'sql' => $query->toSql(),
            'bindings' => array_map($this->scalarise(...), $query->getQuery()->getBindings()),
            'eager' => array_keys($query->getEagerLoads()),
        ];
    }

    /**
     * Recursively sort and flatten into JSON-stable values.
     */
    private function normalise(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];

            foreach ($value as $k => $v) {
                $out[$k] = $this->normalise($v);
            }

            if (! array_is_list($out)) {
                ksort($out);
            }

            return $out;
        }

        return $this->scalarise($value);
    }

    private function scalarise(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_scalar($value) => $value,
            $value instanceof \DateTimeInterface => $value->format('c'),
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \UnitEnum => $value->name,
            // Anything with a stable string form is fine; object identity is not.
            is_object($value) && method_exists($value, '__toString') => (string) $value,
            is_object($value) => $value::class,
            default => gettype($value),
        };
    }
}
