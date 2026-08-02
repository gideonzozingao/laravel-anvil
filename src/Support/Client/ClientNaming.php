<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Client;

use Illuminate\Support\Str;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Every name the client generator emits, in one place.
 *
 * The command this replaces built names inline with `'list'.$model.'s'`, which
 * is correct for `Post` and wrong for most other things:
 *
 *   Category  -> listCategorys   (want listCategories)
 *   Status    -> listStatuss     (want listStatuses)
 *   Person    -> listPersons     (want listPeople)
 *
 * Worse, the module name used `Str::plural(Str::kebab($model))` — a *correct*
 * pluralisation — so the barrel exported `./categories` while the function
 * inside it was `listCategorys`. Two pluralisation strategies in one file.
 * There is now one.
 */
final readonly class ClientNaming
{
    /**
     * @param  \Closure(ModelMetadata): string|null  $pathResolver
     *                                                              Resolves the URL path segment for a resource. Defaults to the
     *                                                              kebab-cased plural, which matches the convention the API route
     *                                                              generator uses today — but see the note on {@see path()}.
     */
    public function __construct(
        private ?\Closure $pathResolver = null,
    ) {}

    /** The interface name: `PriceHistory`. */
    public function interface(ModelMetadata $meta): string
    {
        return $meta->model;
    }

    /** The create/update body type: `PriceHistoryInput`. */
    public function input(ModelMetadata $meta): string
    {
        return $meta->model.'Input';
    }

    /** The module filename without extension: `price-histories`. */
    public function module(ModelMetadata $meta): string
    {
        return Str::kebab(Str::plural($meta->model));
    }

    /**
     * The URL path segment: `price-histories`.
     *
     * Kept distinct from {@see module()} even though they currently agree,
     * because they answer different questions — one is a filename, the other
     * is a route. They can and do diverge (a resource served at `/v1/people`
     * from a `Person` model whose route was customised).
     *
     * This is the highest-risk derivation in the generator: `ApiRouteGenerator`
     * computes the same path independently, and nothing checks that the two
     * agree. Injecting a resolver here is the seam through which both can be
     * made to read one source — ideally the generated OpenAPI spec, which is
     * the only artifact that records what the routes actually are.
     */
    public function path(ModelMetadata $meta): string
    {
        return $this->pathResolver !== null
            ? ($this->pathResolver)($meta)
            : $this->module($meta);
    }

    /** `listPriceHistories` */
    public function listFn(ModelMetadata $meta): string
    {
        return 'list'.Str::plural($meta->model);
    }

    /** `getPriceHistory` */
    public function getFn(ModelMetadata $meta): string
    {
        return 'get'.$meta->model;
    }

    public function createFn(ModelMetadata $meta): string
    {
        return 'create'.$meta->model;
    }

    public function updateFn(ModelMetadata $meta): string
    {
        return 'update'.$meta->model;
    }

    /**
     * `deletePriceHistory`.
     *
     * Not `remove` — the README's example output says `remove`, the command
     * emitted `delete`. Worth settling in one direction; `delete` matches the
     * HTTP verb and the PHP side, so it wins here.
     */
    public function deleteFn(ModelMetadata $meta): string
    {
        return 'delete'.$meta->model;
    }

    public function restoreFn(ModelMetadata $meta): string
    {
        return 'restore'.$meta->model;
    }

    public function forceDeleteFn(ModelMetadata $meta): string
    {
        return 'forceDelete'.$meta->model;
    }

    /** `PriceHistoryKeys` */
    public function queryKeysConst(ModelMetadata $meta): string
    {
        return $meta->model.'Keys';
    }

    /** The React Query cache key root: `price-histories`. */
    public function queryKeyRoot(ModelMetadata $meta): string
    {
        return $this->module($meta);
    }

    /** `usePriceHistories` */
    public function listHook(ModelMetadata $meta): string
    {
        return 'use'.Str::plural($meta->model);
    }

    /** `usePriceHistory` */
    public function detailHook(ModelMetadata $meta): string
    {
        return 'use'.$meta->model;
    }

    public function createHook(ModelMetadata $meta): string
    {
        return 'useCreate'.$meta->model;
    }

    public function updateHook(ModelMetadata $meta): string
    {
        return 'useUpdate'.$meta->model;
    }

    public function deleteHook(ModelMetadata $meta): string
    {
        return 'useDelete'.$meta->model;
    }

    /**
     * Guards against a model whose plural equals its singular — `Series`,
     * `Equipment`, `Data`. Without this, `listSeries` and `useSeries` would
     * collide with the detail hook `useSeries`, and TypeScript would fail on a
     * duplicate export with an error that points at the generated file rather
     * than at the cause.
     */
    public function collidesOnPlural(ModelMetadata $meta): bool
    {
        return Str::plural($meta->model) === $meta->model;
    }
}
