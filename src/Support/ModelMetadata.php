<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

use Illuminate\Support\Str;

final class ModelMetadata
{
    public string $table;

    public string $model;

    /** Source schema (public, dbo, a MySQL database, etc.). Null when the engine has none (SQLite). */
    public ?string $schema = null;

    public array $columns = [];

    public array $foreignKeys = [];

    public array $indexes = [];

    public array $uniqueConstraints = [];

    public ?string $primaryKey = null;

    public array $compositePrimaryKey = [];

    public bool $softDeletes = false;

    public bool $timestamps = false;

    public ?array $constraintAnalysis = null;

    public array $inverseRelationships = [];

    /**
     * Relation method names, planned once and shared by every generator.
     *
     * @var array{belongsTo: array<string, string>, inverse: array<string, string>}|null
     */
    private ?array $relationNames = null;

    /** @var list<array{name: string, wanted: string, column: string, related: string}> */
    private array $relationCollisions = [];

    /**
     * Signature of the data the plan was built from. inverseRelationships is
     * filled by the relationship-map pass, which may run after this object is
     * constructed, so a stale plan must be detectable.
     */
    private ?string $relationSignature = null;

    public static function fromTable(string $table, DatabaseInspector $inspector, ?string $schema = null): self
    {
        $metadata = new self;
        $metadata->table = $table;
        $metadata->schema = $schema;
        $metadata->model = Helpers::tableToModelName($table);

        $tableMetadata = $inspector->getTableMetadata($table, $schema);

        $metadata->columns = $tableMetadata['columns'];
        $metadata->foreignKeys = $tableMetadata['foreign_keys'];
        $metadata->indexes = $tableMetadata['indexes'];
        $metadata->uniqueConstraints = $tableMetadata['unique_constraints'];
        $metadata->primaryKey = $tableMetadata['primary_key'];
        $metadata->compositePrimaryKey = $tableMetadata['composite_primary_key'];

        $columnNames = array_column($metadata->columns, 'name');
        $metadata->timestamps = in_array('created_at', $columnNames) && in_array('updated_at', $columnNames);
        $metadata->softDeletes = in_array('deleted_at', $columnNames);

        return $metadata;
    }

    // -----------------------------------------------------------------------
    // Relation naming — one plan, every generator
    // -----------------------------------------------------------------------

    /**
     * The relation method names for this model.
     *
     * Every generator that references a relation MUST read from here rather than
     * deriving a name itself. The model generator's methods, its @method
     * docblock, the API resource's whenLoaded() calls and the OpenAPI resource
     * schema properties all have to agree; three of them independently calling
     * Str::plural() is how a model ends up with two vehicleBookings() methods
     * and a resource that loads a relation which does not exist.
     *
     * Computed lazily and re-computed if inverseRelationships changes, so the
     * relationship-map pass can run before or after this is first touched.
     *
     * @return array{belongsTo: array<string, string>, inverse: array<string, string>}
     */
    public function relationNames(): array
    {
        $signature = $this->relationDataSignature();

        if ($this->relationNames === null || $this->relationSignature !== $signature) {
            $namer = RelationNamer::forModel($this);
            $plan = $namer->plan($this);

            $this->relationNames = [
                'belongsTo' => $this->sanitizeRelationNames($plan['belongsTo'] ?? []),
                'inverse' => $this->sanitizeRelationNames($plan['inverse'] ?? []),
            ];
            $this->relationCollisions = $namer->collisions();
            $this->relationSignature = $signature;
        }

        return $this->relationNames;
    }

    /**
     * @param  array<string, string>  $names
     * @return array<string, string>
     */
    private function sanitizeRelationNames(array $names): array
    {
        foreach ($names as $key => $name) {
            $names[$key] = ReservedNames::safeMethodName((string) $name);
        }

        return $names;
    }

    /**
     * The belongsTo method name for a foreign key column.
     *
     *   $meta->belongsToName('tenant_id')          // 'tenant'
     *   $meta->belongsToName('assigned_agent_id')  // 'assignedAgent'
     */
    public function belongsToName(string $column): ?string
    {
        return $this->relationNames()['belongsTo'][$column] ?? null;
    }

    /**
     * The hasMany / hasOne method name for a child table + its foreign key.
     *
     *   $meta->inverseName('vehicle_bookings', 'customer_id')
     *       // 'customerVehicleBookings'
     */
    public function inverseName(string $childTable, string $column): ?string
    {
        return $this->relationNames()['inverse'][$childTable.':'.$column] ?? null;
    }

    /**
     * Names that had to be altered because the preferred one was taken. Worth
     * reporting in the run summary — these are the tables a human should look at.
     *
     * @return list<array{name: string, wanted: string, column: string, related: string}>
     */
    public function relationCollisions(): array
    {
        $this->relationNames();

        return $this->relationCollisions;
    }

    /** Force the next relationNames() call to re-plan. */
    public function forgetRelationNames(): void
    {
        $this->relationNames = null;
        $this->relationSignature = null;
        $this->relationCollisions = [];
    }

    /**
     * Cheap fingerprint of everything the plan depends on.
     */
    private function relationDataSignature(): string
    {
        return md5(serialize([
            $this->table,
            array_map(
                static fn ($fk): array => is_array($fk) ? array_intersect_key($fk, array_flip(['column', 'referenced_table'])) : [],
                $this->foreignKeys,
            ),
            $this->inverseRelationships,
            array_column($this->columns, 'name'),
            config('anvil.relationships.inverse_naming'),
        ]));
    }

    // -----------------------------------------------------------------------
    // Schema qualification
    // -----------------------------------------------------------------------

    /**
     * True when this table lives in a non-default schema and therefore needs
     * schema-qualified output (table name, namespace segment, route/view prefix).
     *
     * @param  string|null  $defaultSchema  The driver's default (public/dbo/database); pass to suppress qualification for it.
     */
    public function isSchemaQualified(?string $defaultSchema = null): bool
    {
        if ($this->schema === null || $this->schema === '') {
            return false;
        }

        return $defaultSchema === null || ! ReservedNames::isDefaultSchema($this->schema, $defaultSchema);
    }

    /**
     * The value for the model's `protected $table`. Schema-qualified when the
     * table is in a non-default schema (e.g. "auth.users"); bare otherwise.
     *
     * Eloquent's grammar quotes a dotted table per driver, so "auth.users"
     * becomes "auth"."users" (pgsql), `auth`.`users` (mysql), [auth].[users] (sqlsrv).
     */
    public function qualifiedTable(?string $defaultSchema = null): string
    {
        return $this->isSchemaQualified($defaultSchema)
            ? $this->schema.'.'.$this->table
            : $this->table;
    }

    /**
     * StudlyCase namespace segment for this table's schema (e.g. "auth" → "Auth").
     * Returns null when there is nothing to add (no schema, or the default one
     * when $defaultSchema is supplied).
     *
     * NOTE: PHP reserved words are not legal namespace segments. A schema named
     * "public" would yield App\Models\Public\Tenant, which is a parse error on
     * older PHP and confuses some static analysers even where it parses — hence
     * the suffix applied by ReservedNames::namespaceSegment().
     */
    public function schemaNamespaceSegment(?string $defaultSchema = null): ?string
    {
        if (! $this->isSchemaQualified($defaultSchema)) {
            return null;
        }

        return ReservedNames::namespaceSegment($this->schema);
    }

    /**
     * The fully-qualified class name of this model under $rootNamespace, with the
     * schema segment applied only when it is warranted.
     *
     * Use this from the resource / OpenAPI / controller generators instead of
     * concatenating a segment by hand — that is what produced references to
     * App\Models\PublicSchema\Tenant for a model written to App\Models\Tenant.
     */
    public function modelFqn(string $rootNamespace, ?string $defaultSchema = null): string
    {
        $rootNamespace = Helpers::normalizeNamespace($rootNamespace);
        $segment = $this->schemaNamespaceSegment($defaultSchema);

        return $segment === null
            ? $rootNamespace.'\\'.$this->model
            : $rootNamespace.'\\'.$segment.'\\'.$this->model;
    }

    /**
     * kebab-case schema prefix for routes/views/slugs (e.g. "billing").
     * Null when not schema-qualified.
     */
    public function schemaSlug(?string $defaultSchema = null): ?string
    {
        if (! $this->isSchemaQualified($defaultSchema)) {
            return null;
        }

        return Str::kebab(str_replace(['.', ' '], '_', $this->schema));
    }
}
