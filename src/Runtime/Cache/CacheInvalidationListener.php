<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Runtime\Cache;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;

/**
 * Invalidation, wired once for every model.
 *
 * WHY A WILDCARD LISTENER AND NOT GENERATED OBSERVERS
 *
 * A generated per-model observer would mean one class per table, a registration
 * step, and a silent hole for every model added without re-running the
 * generator. Eloquent already broadcasts `eloquent.saved: App\Models\Post`, so a
 * single wildcard subscription covers every model that exists now or later —
 * including models the user wrote by hand.
 *
 * WHAT THIS CANNOT SEE — read this before trusting the cache
 *
 * Eloquent events fire on model instances. These bypass them entirely:
 *
 *     Post::query()->where(...)->update([...]);   // no events
 *     Post::query()->where(...)->delete();        // no events
 *     Post::insert([...]);                        // no events
 *     DB::table('posts')->update([...]);          // no events
 *     DB::statement('...');                       // no events
 *
 * After any of those, call QueryCache::forget(Post::class) — or
 * $this->flushCache() from inside a service using CachesQueries. There is no way
 * for this listener to detect them, and pretending otherwise would be worse than
 * documenting it.
 *
 * WHY THE BUMP IS NOT DEFERRED TO COMMIT
 *
 * These events fire inside an open transaction. Bumping immediately means a
 * rollback leaves the stamp advanced, so the next read misses and re-queries —
 * a wasted query. Deferring to commit would instead leave a window where the
 * write is visible in the database but the old generation is still being served.
 * Wasting a query is the recoverable failure; serving stale data is not.
 * CachePolicy also suppresses caching inside transactions, so nothing written
 * mid-transaction is captured in the first place.
 */
final readonly class CacheInvalidationListener
{
    /** Events that mean "rows for this model changed". */
    private const EVENTS = [
        'eloquent.created: *',
        'eloquent.updated: *',
        'eloquent.deleted: *',
        'eloquent.restored: *',
        'eloquent.forceDeleted: *',
    ];

    public function __construct(private CacheStamps $stamps = new CacheStamps) {}

    public function subscribe(Dispatcher $events): void
    {
        foreach (self::EVENTS as $pattern) {
            $events->listen($pattern, $this->handle(...));
        }

        // Pivot changes are a write to the relationship, not to either model, so
        // Eloquent emits no created/updated for them. Both ends must be bumped:
        // a resource embedding either side is now wrong.
        $events->listen('eloquent.belongsToManyAttached: *', $this->handleRelation(...));
        $events->listen('eloquent.belongsToManyDetached: *', $this->handleRelation(...));
    }

    /**
     * @param  array<int, mixed>  $payload
     */
    public function handle(string $event, array $payload): void
    {
        $model = $payload[0] ?? null;

        if (! $model instanceof Model) {
            return;
        }

        $this->stamps->bump($model::class);
    }

    /**
     * @param  array<int, mixed>  $payload
     */
    public function handleRelation(string $event, array $payload): void
    {
        $parent = $payload[0] ?? null;

        if (! $parent instanceof Model) {
            return;
        }

        $this->stamps->bump($parent::class);

        // payload[1] is the relation name; resolving the far side through it lets
        // us invalidate both ends of the pivot.
        $relation = $payload[1] ?? null;

        if (! is_string($relation) || ! method_exists($parent, $relation)) {
            return;
        }

        try {
            $related = $parent->{$relation}()->getRelated();

            if ($related instanceof Model) {
                $this->stamps->bump($related::class);
            }
        } catch (\Throwable) {
            // A relation that cannot be resolved without a database round trip is
            // not worth failing a write over.
        }
    }
}
