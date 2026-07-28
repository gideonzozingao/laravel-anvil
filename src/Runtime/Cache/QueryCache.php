<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Runtime\Cache;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;

/**
 * Read-through cache for query results.
 *
 * Responsibilities, in the order they matter:
 *
 *   1. Never return wrong data. Bypass entirely inside transactions, when the
 *      model opts out, or when the caller explicitly asks for fresh data.
 *   2. Survive a cache miss under load. A cold key hit by 500 concurrent
 *      requests must produce one database query, not 500.
 *   3. Be observable. Every call records HIT / MISS / STALE / BYPASS so the
 *      layer can be measured instead of believed.
 */
final class QueryCache
{
    public const HIT = 'HIT';

    public const MISS = 'MISS';

    public const STALE = 'STALE';

    public const BYPASS = 'BYPASS';

    /** @var array<string, int> Outcome tallies for the current request. */
    private array $stats = [self::HIT => 0, self::MISS => 0, self::STALE => 0, self::BYPASS => 0];

    private ?string $lastOutcome = null;

    private bool $bypassRequested = false;

    public function __construct(
        private readonly CachePolicy $policy = new CachePolicy,
        private readonly CacheStamps $stamps = new CacheStamps,
        private readonly ?CacheKey $keys = null,
    ) {}

    private function keys(): CacheKey
    {
        return $this->keys ?? new CacheKey($this->policy, $this->stamps);
    }

    /**
     * Resolve a value, from cache when possible.
     *
     * @template TValue
     *
     * @param  string  $model  FQCN of the primary model
     * @param  string  $profile  CachePolicy::PROFILE_*
     * @param  array<string, mixed>  $descriptor  Everything that varies the result
     * @param  Closure(): TValue  $callback
     * @param  list<string>  $dependsOn  Other models whose changes must invalidate this
     * @return TValue
     */
    public function remember(
        string $model,
        string $profile,
        array $descriptor,
        Closure $callback,
        array $dependsOn = [],
    ): mixed {
        if (! $this->shouldCache($model)) {
            $this->record(self::BYPASS);

            return $callback();
        }

        $ttl = $this->policy->ttl($model, $profile);

        if ($ttl <= 0) {
            $this->record(self::BYPASS);

            return $callback();
        }

        $key = $this->keys()->for($model, $profile, $descriptor, $dependsOn);
        $store = $this->policy->store();

        // Probed before writing so the outcome can be reported honestly. Two
        // reads on a miss is cheaper than guessing.
        $existing = $store->get($key);

        if ($existing !== null) {
            $this->record(self::HIT);

            return $this->unwrap($existing);
        }

        $stale = $this->policy->staleSeconds($model, $profile);

        // Laravel 11.23+ serves the old value while one caller refreshes it in the
        // terminating phase. Guarded by method_exists so the package still runs on
        // earlier framework versions.
        if ($stale > 0 && method_exists($store, 'flexible')) {
            $this->record(self::MISS);

            return $this->unwrap($store->flexible($key, [$ttl, $ttl + $stale], fn (): array => $this->wrap($callback())));
        }

        return $this->rememberWithLock($store, $key, $ttl, $callback);
    }

    /**
     * Miss path without stale-while-revalidate: one caller computes, the rest
     * wait briefly for the result.
     *
     * A failure to acquire is not an error — after the wait the value is
     * normally there. If it still is not, computing it directly is correct and
     * merely slower; refusing to answer would not be.
     */
    private function rememberWithLock(mixed $store, string $key, int $ttl, Closure $callback): mixed
    {
        $seconds = $this->policy->lockSeconds();
        $lock = null;

        try {
            $lock = $store->getStore() instanceof LockProvider
                ? $store->getStore()->lock($key.':lock', $seconds)
                : null;
        } catch (\Throwable) {
            $lock = null;
        }

        if ($lock === null) {
            $this->record(self::MISS);
            $value = $callback();
            $store->put($key, $this->wrap($value), $ttl);

            return $value;
        }

        try {
            $lock->block($seconds);

            // Someone else may have populated it while we waited.
            $existing = $store->get($key);

            if ($existing !== null) {
                $this->record(self::STALE);

                return $this->unwrap($existing);
            }

            $this->record(self::MISS);
            $value = $callback();
            $store->put($key, $this->wrap($value), $ttl);

            return $value;
        } catch (\Throwable) {
            $this->record(self::MISS);

            return $callback();
        } finally {
            try {
                $lock->release();
            } catch (\Throwable) {
                // Lock expired on its own; nothing to do.
            }
        }
    }

    /**
     * Invalidate everything derived from these models.
     *
     * Call this after any write that bypassed Eloquent events — `Model::query()
     * ->update()`, a raw statement, a bulk insert — because the listener cannot
     * see those.
     *
     * @param  string|list<string>  $models
     */
    public function forget(string|array $models): void
    {
        $this->stamps->bumpMany(is_array($models) ? $models : [$models]);
    }

    /** Force the next resolution past the cache, for this request only. */
    public function bypass(bool $bypass = true): self
    {
        $this->bypassRequested = $bypass && $this->policy->allowsBypass();

        return $this;
    }

    public function lastOutcome(): ?string
    {
        return $this->lastOutcome;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * One-line summary for a response header or a log line:
     * "HIT h=12 m=1 s=0 b=0".
     */
    public function summary(): string
    {
        return sprintf(
            '%s h=%d m=%d s=%d b=%d',
            $this->lastOutcome ?? '-',
            $this->stats[self::HIT],
            $this->stats[self::MISS],
            $this->stats[self::STALE],
            $this->stats[self::BYPASS],
        );
    }

    public function resetStats(): void
    {
        $this->stats = [self::HIT => 0, self::MISS => 0, self::STALE => 0, self::BYPASS => 0];
        $this->lastOutcome = null;
    }

    private function shouldCache(string $model): bool
    {
        return ! $this->bypassRequested
            && $this->policy->usableRightNow()
            && $this->policy->enabledFor($model);
    }

    /**
     * Values are wrapped so that a legitimately cached null is distinguishable
     * from a miss. Without this, "this id does not exist" is re-queried on every
     * request — the exact lookup an attacker would hammer.
     *
     * @return array{v: mixed}
     */
    private function wrap(mixed $value): array
    {
        return ['v' => $value];
    }

    private function unwrap(mixed $stored): mixed
    {
        return is_array($stored) && array_key_exists('v', $stored) ? $stored['v'] : $stored;
    }

    private function record(string $outcome): void
    {
        $this->lastOutcome = $outcome;
        $this->stats[$outcome] = ($this->stats[$outcome] ?? 0) + 1;
    }
}
