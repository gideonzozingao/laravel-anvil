<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Runtime\Cache;

/**
 * Per-model generation counters — the invalidation mechanism.
 *
 * WHY STAMPS INSTEAD OF CACHE TAGS
 *
 * Tags look like the obvious answer and are the wrong one here:
 *
 *   - The file, database and array stores do not support them at all, so a
 *     package that relies on tags only works on Redis/Memcached.
 *   - Flushing a tag on Redis does not delete the members; it invalidates the
 *     tag set and leaves the entries as orphans until they expire anyway.
 *   - Deleting by pattern needs SCAN across the keyspace, which is O(keys).
 *
 * A stamp is one integer per model, embedded in every key derived from that
 * model. Bumping it makes every existing key unreachable in a single atomic
 * increment, on every store, in O(1). Old entries are never read again and are
 * reclaimed by their own TTL.
 *
 * The trade-off, stated plainly: superseded entries occupy memory until they
 * expire. With the default TTLs that is at most a few minutes of garbage, which
 * is a good price for correctness on every driver.
 *
 * WHY A MISSING STAMP INITIALISES FROM THE CLOCK
 *
 * If a lost stamp restarted at 1, and entries written under the previous
 * generation 1 were still live, those stale entries would become readable
 * again — cache eviction would resurrect old data. Seeding from the current
 * unix timestamp means a stamp can never re-enter a range it has already used,
 * so the worst case after a cache flush is a cold cache, not a wrong one.
 */
final class CacheStamps
{
    /** @var array<string, int> Per-request memo so one request reads each stamp once. */
    private array $memo = [];

    public function __construct(private readonly CachePolicy $policy = new CachePolicy) {}

    /**
     * Current generation for a model, seeded if absent.
     */
    public function current(string $model): int
    {
        $key = $this->stampKey($model);

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $store = $this->policy->store();
        $value = $store->get($key);

        if (! is_numeric($value)) {
            $value = $this->seed();

            // forever(): a stamp MUST outlive every entry derived from it. An
            // expiring stamp is the resurrection bug described above.
            $store->forever($key, $value);
        }

        return $this->memo[$key] = (int) $value;
    }

    /**
     * Invalidate everything derived from this model.
     *
     * increment() is atomic on Redis/Memcached. When it returns false — the file
     * and database stores do not implement it for a missing key — we fall back
     * to a read-modify-write. That path can lose a concurrent bump, which costs
     * an extra generation, never a stale read: both writers still move the stamp
     * forward, so neither one's old keys stay reachable.
     */
    public function bump(string $model): int
    {
        $key = $this->stampKey($model);
        $store = $this->policy->store();

        unset($this->memo[$key]);

        $next = $store->increment($key);

        if (! is_numeric($next)) {
            $next = $this->seed();
            $store->forever($key, $next);
        }

        return $this->memo[$key] = (int) $next;
    }

    /**
     * Bump several models at once — for a write that touches a pivot, or an
     * explicit invalidation after a bulk query that bypassed model events.
     *
     * @param  iterable<string>  $models
     * @return array<string, int>
     */
    public function bumpMany(iterable $models): array
    {
        $bumped = [];

        foreach ($models as $model) {
            $bumped[$model] = $this->bump($model);
        }

        return $bumped;
    }

    /**
     * The composite stamp for a query and everything it depends on.
     *
     * This is how relationship invalidation works without any cascade logic: a
     * resource that embeds its author declares [Post::class, User::class], so the
     * key contains both generations. Renaming the user bumps the user stamp,
     * which changes the post's key, which misses. No graph traversal, no
     * bookkeeping about who embeds whom at write time.
     *
     * @param  list<string>  $models
     */
    public function composite(array $models): string
    {
        $parts = [];

        foreach (array_unique($models) as $model) {
            $parts[$this->policy->normaliseModel($model)] = $this->current($model);
        }

        // Sorted so the same dependency set always produces the same key
        // regardless of the order the caller listed them in.
        ksort($parts);

        $out = [];

        foreach ($parts as $name => $stamp) {
            $out[] = $name.'@'.$stamp;
        }

        return implode('+', $out);
    }

    /** Drop the per-request memo — needed in long-running workers between jobs. */
    public function flushMemo(): void
    {
        $this->memo = [];
    }

    private function stampKey(string $model): string
    {
        return $this->policy->prefix().':stamp:'.$this->policy->normaliseModel($model);
    }

    private function seed(): int
    {
        return time();
    }
}
