<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Runtime\Cache;

use Closure;
use Illuminate\Contracts\Pagination\Paginator;

/**
 * What a generated service mixes in to become cached.
 *
 * The generated class supplies the model it serves and, optionally, the models
 * its payload embeds; everything about keys, TTLs and invalidation stays in this
 * package, so a fix reaches every generated service without regenerating
 * anything.
 *
 *     use CachesQueries;
 *
 *     protected string $cacheModel = \App\Models\Post::class;
 *     protected array $cacheDependencies = [\App\Models\User::class];
 *
 *     public function paginate(int $perPage = 15): Paginator
 *     {
 *         return $this->cacheList(
 *             ['per_page' => $perPage, 'page' => request()->integer('page', 1)],
 *             fn () => $this->query()->paginate($perPage),
 *         );
 *     }
 *
 * ── THE VARIANT, AND WHY IT DEFAULTS TO THE CLASS NAME ──────────────────────
 *
 * Subclasses are the problem case. With --versioned-services, every API version
 * gets its own service extending the shared one:
 *
 *     App\Services\Api\V1\PaymentService extends App\Services\PaymentService
 *     App\Services\Api\V3\PaymentService extends App\Services\PaymentService
 *
 * All three declare the same $cacheModel. If V3 overrides paginate() to apply a
 * different scope — which is the entire reason a versioned service exists — then
 * V1 and V3 build the SAME cache key for the same page, and whichever runs first
 * serves its rows to the other. A v3 caller receives v1's filtered result set,
 * or worse, the reverse.
 *
 * So the variant defaults to static::class: a subclass is its own cache
 * namespace unless it says otherwise. Correctness first.
 *
 * The cost is duplicate entries when the subclass changes nothing, which is the
 * common case for a generated-but-untouched version. Such a class opts back into
 * sharing with:
 *
 *     protected ?string $cacheVariant = null;
 *
 * and MUST remove that line the moment it overrides a read method.
 */
trait CachesQueries
{
    private ?QueryCache $anvilQueryCache = null;

    protected function queryCache(): QueryCache
    {
        return $this->anvilQueryCache ??= resolve(QueryCache::class);
    }

    /**
     * A single record: longer TTL, since one row changes less often than a page
     * of them.
     *
     * @template T
     *
     * @param  array<string, mixed>  $extra
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function cacheFind(int|string $id, Closure $callback, array $extra = []): mixed
    {
        return $this->queryCache()->remember(
            $this->cacheModel(),
            CachePolicy::PROFILE_SINGLE,
            $this->descriptor(['id' => $id] + $extra),
            $callback,
            $this->cacheDependencies(),
        );
    }

    /**
     * A page of records.
     *
     * The descriptor MUST contain the page number. A paginator caches its own
     * current page, so omitting it serves page 1's rows under every ?page=.
     *
     * @template T of Paginator|iterable
     *
     * @param  array<string, mixed>  $descriptor
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function cacheList(array $descriptor, Closure $callback): mixed
    {
        return $this->queryCache()->remember(
            $this->cacheModel(),
            CachePolicy::PROFILE_LIST,
            $this->descriptor($descriptor),
            $callback,
            $this->cacheDependencies(),
        );
    }

    /**
     * Counts, sums, exists checks. Shortest TTL: cheapest to recompute, most
     * visibly wrong when stale.
     *
     * @template T
     *
     * @param  array<string, mixed>  $descriptor
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function cacheAggregate(array $descriptor, Closure $callback): mixed
    {
        return $this->queryCache()->remember(
            $this->cacheModel(),
            CachePolicy::PROFILE_AGGREGATE,
            $this->descriptor($descriptor),
            $callback,
            $this->cacheDependencies(),
        );
    }

    /**
     * Long-lived cache for reference data — lookup tables, enumerations,
     * anything that changes on a deploy rather than on a request.
     *
     * @template T
     *
     * @param  array<string, mixed>  $descriptor
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function cacheReference(array $descriptor, Closure $callback): mixed
    {
        return $this->queryCache()->remember(
            $this->cacheModel(),
            CachePolicy::PROFILE_REFERENCE,
            $this->descriptor($descriptor),
            $callback,
            $this->cacheDependencies(),
        );
    }

    /**
     * Invalidate this model's cache explicitly.
     *
     * Needed after writes Eloquent never saw: Model::query()->update(),
     * ->delete() on a builder, insert(), raw statements, truncate(). The event
     * listener cannot observe any of those.
     */
    protected function flushCache(): void
    {
        $this->queryCache()->forget([$this->cacheModel(), ...$this->cacheDependencies()]);
    }

    /**
     * Fold the variant into every descriptor, in one place, so no individual
     * call site can forget it.
     *
     * @param  array<string, mixed>  $descriptor
     * @return array<string, mixed>
     */
    private function descriptor(array $descriptor): array
    {
        $variant = $this->cacheVariant();

        // Omitted when null so a service that opts into sharing produces exactly
        // the key its parent would.
        return $variant === null ? $descriptor : ['__variant' => $variant] + $descriptor;
    }

    /**
     * The cache namespace for this class. See the class docblock.
     *
     * Returning null shares entries with the parent — only safe while this class
     * overrides no read method.
     */
    protected function cacheVariant(): ?string
    {
        if (property_exists($this, 'cacheVariant')) {
            /** @var string|null $declared */
            $declared = $this->cacheVariant;

            return $declared === null || $declared === '' ? null : (string) $declared;
        }

        return static::class;
    }

    /**
     * The model this service caches for.
     */
    protected function cacheModel(): string
    {
        if (property_exists($this, 'cacheModel') && is_string($this->cacheModel) && $this->cacheModel !== '') {
            return $this->cacheModel;
        }

        if (property_exists($this, 'model') && is_string($this->model) && $this->model !== '') {
            return $this->model;
        }

        // App\Services\Api\V3\PaymentService -> App\Models\Payment
        $guess = 'App\\Models\\'.preg_replace('/Service$/', '', class_basename(static::class));

        return ltrim($guess, '\\');
    }

    /**
     * Models whose changes must also invalidate this service's entries.
     *
     * Declare every model the RESOURCE embeds, not every model the table
     * references. A payment resource rendering its customer's name depends on
     * Customer; one exposing only customer_id does not. Over-declaring costs
     * extra misses; under-declaring serves a renamed customer under the old name
     * until the TTL expires.
     *
     * @return list<string>
     */
    protected function cacheDependencies(): array
    {
        if (property_exists($this, 'cacheDependencies') && is_array($this->cacheDependencies)) {
            return array_values(array_filter($this->cacheDependencies, is_string(...)));
        }

        return [];
    }
}
