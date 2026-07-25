<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

use Illuminate\Support\Str;

/**
 * Names every relation method on one model, and guarantees the names are unique.
 *
 * Two mechanisms, and both are needed:
 *
 * 1. QUALIFIED NAMES. When a child table points at this parent more than once,
 *    the plural of the child model is ambiguous by itself. The qualifier comes
 *    from the foreign key column, with the key suffix and the parent's own name
 *    stripped out:
 *
 *      users ← vehicle_bookings.customer_id         → customerVehicleBookings
 *      users ← vehicle_bookings.assigned_agent_id   → assignedAgentVehicleBookings
 *      users ← vehicle_price_adjustment_logs.adjusted_by → adjustedVehiclePriceAdjustmentLogs
 *      users ← page_visits.user_id                  → pageVisits   (only one key: no qualifier)
 *
 * 2. A CLAIM REGISTRY. Qualification is a heuristic — it fails when the columns
 *    carry no distinguishing tokens (location_id, location_id_2). The registry
 *    is not a heuristic: every emitted name is recorded, and a name that is
 *    already taken gets a deterministic suffix instead. Reserved names (Eloquent's
 *    own API, and every column-derived accessor) are pre-claimed, so a relation
 *    can never shadow a column or override save()/delete().
 *
 * The point of doing this in one object is that the PHPDoc @method block and the
 * method bodies must agree. Generating them from two separate naming passes is
 * how a model ends up with three identical @method lines above three
 * differently-named methods.
 *
 * Usage in a generator:
 *
 *     $namer = RelationNamer::forModel($meta);
 *     $plan  = $namer->plan($meta);
 *
 *     // $plan['belongsTo']['tenant_id']                    === 'tenant'
 *     // $plan['inverse']['vehicle_bookings:customer_id']   === 'customerVehicleBookings'
 *
 * then read from $plan for BOTH the docblock and the method emission.
 */
final class RelationNamer
{
    /** customerVehicleBookings */
    public const STYLE_PREFIX = 'prefix';

    /** vehicleBookingsCustomer */
    public const STYLE_SUFFIX = 'suffix';

    /**
     * Column suffixes that mark a key rather than part of the relation name.
     * Order matters: longest/most specific first.
     *
     * @var list<string>
     */
    private const KEY_SUFFIXES = ['_uuid', '_ulid', '_id', '_fk', '_by', '_ref'];

    /**
     * Method names a generated relation must never take.
     *
     * @var list<string>
     */
    private const ELOQUENT_API = [
        'save',
        'saveQuietly',
        'delete',
        'deleteQuietly',
        'forceDelete',
        'restore',
        'trashed',
        'update',
        'updateQuietly',
        'fill',
        'refresh',
        'replicate',
        'query',
        'newQuery',
        'newCollection',
        'newModelQuery',
        'touch',
        'push',
        'getKey',
        'getKeyName',
        'getTable',
        'getConnection',
        'getConnectionName',
        'getAttribute',
        'setAttribute',
        'getAttributes',
        'getOriginal',
        'getChanges',
        'getDirty',
        'isDirty',
        'isClean',
        'wasChanged',
        'toArray',
        'toJson',
        'jsonSerialize',
        'attributesToArray',
        'relationsToArray',
        'getRouteKey',
        'getRouteKeyName',
        'resolveRouteBinding',
        'getFillable',
        'getGuarded',
        'getHidden',
        'getVisible',
        'getCasts',
        'casts',
        'getMorphClass',
        'getForeignKey',
        'getIncrementing',
        'usesTimestamps',
    ];

    /** @var array<string, true> */
    private array $taken = [];

    /** @var list<array{name: string, wanted: string, column: string, related: string}> */
    private array $collisions = [];

    /**
     * @param  list<string>  $reserved
     */
    public function __construct(
        private readonly string $parentTable,
        private readonly string $style = self::STYLE_PREFIX,
        array $reserved = [],
    ) {
        $this->taken = array_fill_keys($reserved, true);
    }

    public static function forModel(ModelMetadata $meta, ?string $style = null): self
    {
        $columns = [];

        foreach ($meta->columns as $column) {
            $name = (string) self::pick($column, ['name', 'column']);

            if ($name !== '') {
                $columns[] = $name;
            }
        }

        return self::forTable($meta->table, $columns, $style);
    }

    /**
     * For callers that hold a table name and column list rather than a
     * ModelMetadata — ModelBuilder, for instance.
     *
     * @param  list<string>  $columnNames
     */
    public static function forTable(string $table, array $columnNames = [], ?string $style = null): self
    {
        return new self(
            $table,
            self::normaliseStyle($style ?? config('anvil.relationships.inverse_naming')),
            self::reservedNames($columnNames),
        );
    }

    // -----------------------------------------------------------------------
    // Planning — name everything for a model in one pass
    // -----------------------------------------------------------------------

    /**
     * Name every relation on this model.
     *
     * belongsTo names are claimed first: they derive directly from a column and
     * are the names a developer will expect, so they win any contest with an
     * inverse relation.
     *
     * @return array{belongsTo: array<string, string>, inverse: array<string, string>}
     *                                                                                 belongsTo keyed by FK column; inverse keyed by "childTable:column"
     */
    public function plan(ModelMetadata $meta): array
    {
        $belongsTo = [];

        foreach ($meta->foreignKeys as $fk) {
            $column = (string) self::pick($fk, ['column', 'from_column', 'local_column']);
            $relatedTable = (string) self::pick($fk, ['referenced_table', 'related_table', 'to_table', 'table']);

            if ($column === '') {
                continue;
            }

            $belongsTo[$column] = $this->belongsTo($column, $relatedTable);
        }

        $inverse = [];

        foreach (self::groupByChildTable($meta->inverseRelationships) as $childTable => $keys) {
            $count = count($keys);

            foreach ($keys as $row) {
                $column = (string) self::pick($row, ['column', 'foreign_key', 'from_column', 'local_column']);

                if ($column === '') {
                    continue;
                }

                $singular = (bool) (self::pick($row, ['unique', 'is_unique', 'has_one']) ?? false);

                $inverse[$childTable.':'.$column] = $this->inverse($childTable, $column, $count, $singular);
            }
        }

        return ['belongsTo' => $belongsTo, 'inverse' => $inverse];
    }

    // -----------------------------------------------------------------------
    // Individual sides
    // -----------------------------------------------------------------------

    /**
     * belongsTo: the column IS the name. tenant_id → tenant, assigned_agent_id
     * → assignedAgent, adjusted_by → adjusted.
     */
    public function belongsTo(string $foreignKey, string $relatedTable = ''): string
    {
        $stem = $this->stripKeySuffix($foreignKey);

        $name = $stem !== ''
            ? Str::camel($stem)
            : Str::camel(Str::singular($relatedTable !== '' ? Helpers::tableToModelName($relatedTable) : $foreignKey));

        return $this->claim($name, $foreignKey, $relatedTable);
    }

    /**
     * hasMany / hasOne: the child model, qualified by the FK column when the
     * child points at this parent more than once.
     *
     * @param  int  $keysToThisParent  how many FKs the child table has to this parent
     */
    public function inverse(string $childTable, string $foreignKey, int $keysToThisParent = 1, bool $singular = false): string
    {
        return $this->inverseForModel(
            Helpers::tableToModelName($childTable),
            $foreignKey,
            $keysToThisParent,
            $singular,
            $childTable,
        );
    }

    /**
     * Claim a name the caller already decided on. Returned as-is when free,
     * disambiguated when not — so an existing generator's naming stays stable
     * while still being protected from collisions.
     */
    public function preferred(string $name, string $foreignKey, string $related = ''): string
    {
        return $this->claim($name, $foreignKey, $related);
    }

    /**
     * As inverse(), but starting from the related MODEL name rather than its
     * table — ModelBuilder's inverse rows carry the model, not the table.
     */
    public function inverseForModel(
        string $relatedModel,
        string $foreignKey,
        int $keysToThisParent = 1,
        bool $singular = false,
        string $context = '',
    ): string {
        $childTable = $context !== '' ? $context : $relatedModel;
        $related = $singular ? Str::singular($relatedModel) : Str::plural($relatedModel);

        if ($keysToThisParent < 2) {
            return $this->claim(Str::camel($related), $foreignKey, $childTable);
        }

        $qualifier = $this->qualifier($foreignKey);

        if ($qualifier === '') {
            // Nothing distinguishing in the column name — go straight to the
            // column, which is unique by definition.
            return $this->claim(
                Str::camel($related).'By'.Str::studly($foreignKey),
                $foreignKey,
                $childTable,
            );
        }

        $name = $this->style === self::STYLE_SUFFIX
            ? Str::camel($related).Str::studly($qualifier)
            : Str::camel($qualifier).Str::studly($related);

        return $this->claim($name, $foreignKey, $childTable);
    }

    // -----------------------------------------------------------------------
    // Reporting
    // -----------------------------------------------------------------------

    /**
     * Names that had to be altered because the preferred one was taken. Surface
     * these in the run summary: they are the schemas worth a human glance.
     *
     * @return list<array{name: string, wanted: string, column: string, related: string}>
     */
    public function collisions(): array
    {
        return $this->collisions;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->taken);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Strip the key suffix and the parent's own name, leaving the qualifier.
     *
     *   users   + customer_id        → customer
     *   users   + assigned_agent_id  → assignedAgent  (as tokens: assigned_agent)
     *   users   + user_id            → ''             (nothing but the parent)
     *   users   + adjusted_by        → adjusted
     *   locations + pickup_location_id → pickup
     */
    private function qualifier(string $foreignKey): string
    {
        $stem = $this->stripKeySuffix($foreignKey);
        $parent = Str::singular($this->parentTable);

        $tokens = array_values(array_filter(
            explode('_', $stem),
            static fn (string $token): bool => ! in_array($token, ['', $parent, Str::plural($parent)], true),
        ));

        return implode('_', $tokens);
    }

    private function stripKeySuffix(string $column): string
    {
        foreach (self::KEY_SUFFIXES as $suffix) {
            if (str_ends_with($column, $suffix) && strlen($column) > strlen($suffix)) {
                return substr($column, 0, -strlen($suffix));
            }
        }

        return $column;
    }

    /**
     * Record a name, or derive a free one if it is taken.
     */
    private function claim(string $wanted, string $column, string $related): string
    {
        $wanted = lcfirst($wanted);

        if ($wanted === '') {
            $wanted = 'related'.Str::studly($column);
        }

        if (! isset($this->taken[$wanted])) {
            $this->taken[$wanted] = true;

            return $wanted;
        }

        // First fallback: qualify by column. Deterministic, so regenerating the
        // same schema produces the same file.
        $byColumn = $wanted.'By'.Str::studly($column);

        if (! isset($this->taken[$byColumn])) {
            $this->taken[$byColumn] = true;
            $this->collisions[] = ['name' => $byColumn, 'wanted' => $wanted, 'column' => $column, 'related' => $related];

            return $byColumn;
        }

        // Second fallback: numeric. Ugly, but a file PHP can parse beats a
        // fatal redeclaration that takes down route:list and every request.
        $i = 2;

        while (isset($this->taken[$wanted.$i])) {
            $i++;
        }

        $this->taken[$wanted.$i] = true;
        $this->collisions[] = ['name' => $wanted.$i, 'wanted' => $wanted, 'column' => $column, 'related' => $related];

        return $wanted.$i;
    }

    /**
     * Eloquent's API plus every column-derived accessor: a "vehicleBookings"
     * column would otherwise collide with the relation of the same name.
     *
     * @return list<string>
     */
    private static function reservedNames(array $columnNames): array
    {
        $names = self::ELOQUENT_API;

        foreach ($columnNames as $name) {
            $name = (string) $name;

            if ($name !== '') {
                $names[] = Str::camel($name);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Group inverse relationships by the child table that owns the foreign key.
     * The count per group is what triggers qualification.
     *
     * @param  array<int, array<string, mixed>>  $inverseRelationships
     * @return array<string, list<array<string, mixed>>>
     */
    public static function groupByChildTable(array $inverseRelationships): array
    {
        $grouped = [];

        foreach ($inverseRelationships as $row) {
            if (! is_array($row)) {
                continue;
            }

            $table = (string) self::pick($row, ['table', 'child_table', 'related_table', 'from_table', 'referencing_table']);

            if ($table === '') {
                continue;
            }

            $grouped[$table][] = $row;
        }

        return $grouped;
    }

    /**
     * Read the first key present. ModelMetadata::$inverseRelationships is filled
     * by the relationship-map pass rather than fromTable(), so the exact key
     * names are not guaranteed — this tolerates the common spellings instead of
     * silently producing empty names.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private static function pick(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return $row[$key];
            }
        }

        return null;
    }

    private static function normaliseStyle(mixed $style): string
    {
        return strtolower((string) $style) === self::STYLE_SUFFIX
            ? self::STYLE_SUFFIX
            : self::STYLE_PREFIX;
    }
}
