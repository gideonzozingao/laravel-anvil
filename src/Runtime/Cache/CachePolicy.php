<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Runtime\Cache;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every "should we cache this, and for how long" decision, in one place.
 *
 * Resolution order for any model is: per-model override -> named profile ->
 * global default. So a volatile table can opt out entirely and a lookup table
 * can be cached for an hour without touching generated code.
 */
final class CachePolicy
{
    /** Query shapes with distinct volatility, hence distinct TTLs. */
    public const PROFILE_SINGLE = 'single';

    public const PROFILE_LIST = 'list';

    public const PROFILE_AGGREGATE = 'aggregate';

    public const PROFILE_REFERENCE = 'reference';

    /** @var array<string, array<string, mixed>> Resolved per-model config, memoised per request. */
    private array $resolved = [];

    public function enabled(): bool
    {
        return (bool) config('anvil.cache.enabled', true);
    }

    /**
     * Caching is suppressed inside an open transaction.
     *
     * Two reasons, both about correctness rather than performance. A read issued
     * mid-transaction can see uncommitted rows, and storing that under a key
     * visible to other requests publishes data that may never commit. And a
     * transaction that rolls back would leave entries describing a state the
     * database never reached.
     */
    public function usableRightNow(): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        try {
            return DB::transactionLevel() === 0;
        } catch (\Throwable) {
            // No database connection configured — nothing to be inconsistent with.
            return true;
        }
    }

    public function store(): Repository
    {
        $store = config('anvil.cache.store');

        return $store === null || $store === ''
            ? Cache::store()
            : Cache::store((string) $store);
    }

    public function prefix(): string
    {
        $prefix = trim((string) config('anvil.cache.prefix', 'anvil'), ':');

        return $prefix !== '' ? $prefix : 'anvil';
    }

    public function enabledFor(string $model): bool
    {
        return $this->enabled() && (bool) ($this->forModel($model)['enabled'] ?? true);
    }

    /**
     * TTL in seconds, with jitter applied.
     *
     * Jitter matters more than it looks: without it, everything warmed by the
     * same deploy or the same crawler expires in the same second, and the next
     * request storm hits the database simultaneously. A few percent of spread
     * turns a spike into a slope.
     */
    public function ttl(string $model, string $profile): int
    {
        $config = $this->forModel($model);

        $ttl = (int) (
            $config['ttl'][$profile]
            ?? config("anvil.cache.ttl.{$profile}", $this->defaultTtl($profile))
        );

        if ($ttl <= 0) {
            return 0;
        }

        $jitter = (float) config('anvil.cache.jitter', 0.1);

        if ($jitter <= 0.0) {
            return $ttl;
        }

        $spread = (int) round($ttl * min($jitter, 0.5));

        return max(1, $ttl + random_int(-$spread, $spread));
    }

    /**
     * Seconds a stale value may still be served while a fresh one is computed.
     * 0 disables stale-while-revalidate and falls back to lock-and-recompute.
     */
    public function staleSeconds(string $model, string $profile): int
    {
        $config = $this->forModel($model);

        return max(0, (int) (
            $config['stale'][$profile]
            ?? config('anvil.cache.stale_while_revalidate', 30)
        ));
    }

    public function lockSeconds(): int
    {
        return max(1, (int) config('anvil.cache.lock_seconds', 5));
    }

    /**
     * Discriminator that keeps one caller's results out of another's.
     *
     * This is the single most dangerous knob in the whole layer. If a listing is
     * filtered by policy, tenancy or ownership and the scope is 'none', the first
     * requester's rows get served to everybody. 'auth' is therefore the default,
     * and 'none' should only be chosen for genuinely public, unfiltered reads.
     */
    public function scope(): string
    {
        $mode = strtolower(trim((string) config('anvil.cache.scope', 'auth')));

        return match ($mode) {
            'none' => 'public',
            'tenant' => $this->tenantScope(),
            default => $this->authScope(),
        };
    }

    private function authScope(): string
    {
        try {
            if (! app()->bound('auth')) {
                return 'guest';
            }

            $guard = auth();
            $id = $guard->id();

            if ($id === null) {
                return 'guest';
            }

            // Guard name included: the same numeric id under two guards is two
            // different principals.
            $name = method_exists($guard, 'getDefaultDriver') ? (string) $guard->getDefaultDriver() : 'default';

            return $name.':'.$id;
        } catch (\Throwable) {
            // Console runs, queue workers, anything without a session: treat as
            // its own bucket rather than sharing the public one.
            return 'no-context';
        }
    }

    private function tenantScope(): string
    {
        $resolver = config('anvil.cache.tenant_resolver');

        if (is_callable($resolver)) {
            $tenant = $resolver();

            if (is_scalar($tenant) && (string) $tenant !== '') {
                return 'tenant:'.$tenant;
            }
        }

        // Falling back to auth rather than 'public': a misconfigured tenant
        // resolver must not collapse every tenant into one shared bucket.
        return $this->authScope();
    }

    /**
     * True when the caller may explicitly ask for uncached data.
     *
     * Off in production by default — an attacker who can force every request
     * past the cache has a cheap amplification primitive.
     */
    public function allowsBypass(): bool
    {
        if (! (bool) config('anvil.cache.allow_bypass', false)) {
            return false;
        }

        try {
            return ! app()->isProduction() || (bool) config('anvil.cache.allow_bypass_in_production', false);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function forModel(string $model): array
    {
        $model = ltrim($model, '\\');

        if (isset($this->resolved[$model])) {
            return $this->resolved[$model];
        }

        $models = (array) config('anvil.cache.models', []);
        $config = (array) ($models[$model] ?? $models[class_basename($model)] ?? []);

        // A named profile expands into the ttl map, then explicit keys win.
        if (isset($config['profile']) && is_string($config['profile'])) {
            $profile = (array) config("anvil.cache.profiles.{$config['profile']}", []);
            $config = array_replace_recursive($profile, $config);
        }

        return $this->resolved[$model] = $config;
    }

    private function defaultTtl(string $profile): int
    {
        return match ($profile) {
            self::PROFILE_SINGLE => 300,
            self::PROFILE_LIST => 60,
            self::PROFILE_AGGREGATE => 30,
            self::PROFILE_REFERENCE => 3600,
            default => 60,
        };
    }

    public function normaliseModel(string $model): string
    {
        return Str::snake(class_basename(ltrim($model, '\\')));
    }
}
