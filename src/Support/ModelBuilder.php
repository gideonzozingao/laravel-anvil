<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ModelBuilder
{
    protected string $namespace;

    protected array $columns = [];

    protected array $foreignKeys = [];

    protected array $indexes = [];

    protected array $uniqueConstraints = [];

    protected ?string $primaryKey = 'id';

    protected array $compositePrimaryKey = [];

    protected bool $timestamps = true;

    protected bool $softDeletes = false;

    protected bool $withPhpDoc = true;

    protected bool $withInverse = true;

    protected bool $withConstraintComments = false;

    /**
     * Raw inverse-relationship entries. The 'method' value is a PREFERENCE, not
     * a decision: final names are resolved in resolvedRelationNames(), which is
     * the only point at which the number of keys pointing at each related model
     * is known.
     *
     * @var list<array{method: string, model: string, foreign_key: string, table: ?string, unique: bool, schema: ?string}>
     */
    protected array $inverseRelationships = [];

    protected ?array $constraintAnalysis = null;

    /** The actual DB table for `protected $table` — may be schema-qualified (e.g. "members_db.addresses"). */
    protected string $table;

    /**
     * Root models namespace (no schema segment). Used to resolve related models
     * when a foreign key points at a table in another schema.
     */
    protected ?string $rootNamespace = null;

    /**
     * The connection's default schema (public / dbo / the MySQL database name).
     *
     * Without this, a foreign key carrying referenced_schema="public" produces a
     * reference to App\Models\PublicSchema\Tenant while the Tenant model itself
     * is generated at App\Models\Tenant — a class that does not exist. The
     * pipeline suppresses the segment for the model being generated; this is how
     * the builder learns to suppress it for the models it points AT.
     */
    protected ?string $defaultSchema = null;

    /**
     * Memoised output of resolvedRelationNames(). Invalidated by any setter that
     * feeds into naming.
     *
     * @var array{belongsTo: array<string, string>, inverse: array<int, string>, collisions: list<array<string, string>>}|null
     */
    private ?array $resolvedNames = null;

    /**
     * FQCN => alias, for every class that needs a `use` statement in the
     * generated file. Populated during build().
     *
     * @var array<string, string>
     */
    private array $imports = [];

    /**
     * FQCN => alias already handed out, including classes that needed no import
     * (same namespace). Keeps repeat references stable.
     *
     * @var array<string, string>
     */
    private array $references = [];

    /**
     * lowercased alias => the FQCN currently holding it. Guards against two
     * different classes both wanting the short name `Tenant`.
     *
     * @var array<string, string>
     */
    private array $takenAliases = [];

    /**
     * Eloquent relation class per relation type, used for the native return type
     * on the generated method.
     */
    private const RELATION_RETURN_TYPES = [
        'belongsTo' => BelongsTo::class,
        'hasMany' => HasMany::class,
        'hasOne' => HasOne::class,
    ];

    public function __construct(protected string $tableName, string $namespace)
    {
        $this->namespace = Helpers::normalizeNamespace($namespace);
        // By default the DB table equals the (bare) table name used for naming.
        $this->table = $tableName;
    }

    /**
     * Override the DB table written to `protected $table`, independent of the
     * table used to derive the class name. Used for schema-qualified tables
     * (e.g. class "Address" but table "members_db.addresses").
     */
    public function setTable(string $table): self
    {
        $this->table = $table;

        return $this;
    }

    /**
     * Set the root models namespace (e.g. "App\Models") so cross-schema foreign
     * keys can resolve to "App\Models\{Schema}\{Model}". When null, related
     * models resolve within this model's own namespace (legacy behaviour).
     */
    public function setRootNamespace(?string $rootNamespace): self
    {
        $this->rootNamespace = $rootNamespace !== null ? Helpers::normalizeNamespace($rootNamespace) : null;

        return $this;
    }

    /**
     * Set the connection's default schema. Related models living in it resolve
     * to the root namespace with no schema segment.
     */
    public function setDefaultSchema(?string $defaultSchema): self
    {
        $this->defaultSchema = ($defaultSchema === null || trim($defaultSchema) === '')
            ? null
            : trim($defaultSchema);

        return $this;
    }

    /**
     * Set columns
     */
    public function setColumns(array $columns): self
    {
        $this->columns = $columns;
        $this->resolvedNames = null;

        return $this;
    }

    /**
     * Set foreign keys
     */
    public function setForeignKeys(array $foreignKeys): self
    {
        $this->foreignKeys = $foreignKeys;
        $this->resolvedNames = null;

        return $this;
    }

    /**
     * Set indexes
     */
    public function setIndexes(array $indexes): self
    {
        $this->indexes = $indexes;

        return $this;
    }

    /**
     * Set unique constraints
     */
    public function setUniqueConstraints(array $uniqueConstraints): self
    {
        $this->uniqueConstraints = $uniqueConstraints;

        return $this;
    }

    /**
     * Set primary key
     */
    public function setPrimaryKey(?string $primaryKey): self
    {
        $this->primaryKey = $primaryKey;

        return $this;
    }

    /**
     * Set composite primary key
     */
    public function setCompositePrimaryKey(array $compositePrimaryKey): self
    {
        $this->compositePrimaryKey = $compositePrimaryKey;

        return $this;
    }

    /**
     * Set timestamps
     */
    public function setTimestamps(bool $timestamps): self
    {
        $this->timestamps = $timestamps;

        return $this;
    }

    /**
     * Set soft deletes
     */
    public function setSoftDeletes(bool $softDeletes): self
    {
        $this->softDeletes = $softDeletes;

        return $this;
    }

    /**
     * Set with PHP doc
     */
    public function setWithPhpDoc(bool $withPhpDoc): self
    {
        $this->withPhpDoc = $withPhpDoc;

        return $this;
    }

    /**
     * Set with inverse relationships
     */
    public function setWithInverse(bool $withInverse): self
    {
        $this->withInverse = $withInverse;

        return $this;
    }

    /**
     * Set with constraint comments
     */
    public function setWithConstraintComments(bool $withConstraintComments): self
    {
        $this->withConstraintComments = $withConstraintComments;

        return $this;
    }

    /**
     * Set constraint analysis
     */
    public function setConstraintAnalysis(?array $constraintAnalysis): self
    {
        $this->constraintAnalysis = $constraintAnalysis;

        return $this;
    }

    /**
     * Add inverse relationship.
     *
     * $methodName is treated as a preference. When two entries name the same
     * related model — vehicle_bookings.customer_id and
     * vehicle_bookings.assigned_agent_id both pointing at users — both get
     * qualified from their foreign key at build time, producing
     * customerVehicleBookings() and assignedAgentVehicleBookings() instead of
     * two identical vehicleBookings() methods and a fatal redeclaration.
     *
     * The extra parameters are optional so existing callers keep working, but
     * omitting them is lossy:
     *   $relatedTable  gives better pluralisation and grouping than the model name
     *   $unique        emits hasOne instead of hasMany
     *   $relatedSchema resolves the related model across schemas
     */
    public function addInverseRelationship(
        string $methodName,
        string $relatedModel,
        string $foreignKey,
        ?string $relatedTable = null,
        bool $unique = false,
        ?string $relatedSchema = null,
    ): self {
        $this->inverseRelationships[] = [
            'method' => $methodName,
            'model' => $relatedModel,
            'foreign_key' => $foreignKey,
            'table' => $relatedTable,
            'unique' => $unique,
            'schema' => $relatedSchema,
        ];

        $this->resolvedNames = null;

        return $this;
    }

    /**
     * Build the model content.
     *
     * Order is load-bearing: buildRelationships() and buildClassDocBlock()
     * register the class imports that buildUses() renders, so buildUses() must
     * run last. Calling it first silently drops every import.
     */
    public function build(): string
    {
        $modelName = Helpers::tableToModelName($this->tableName);

        $this->resetImports($modelName);

        $relationships = $this->buildRelationships();
        $docBlock = $this->withPhpDoc ? $this->buildClassDocBlock() : '';
        $uses = $this->buildUses();

        $primaryKeyProperty = $this->buildPrimaryKeyProperty();
        $timestampsProperty = StubGenerator::timestampsStub($this->timestamps);
        $fillable = $this->buildFillable();
        $hidden = $this->buildHidden();
        $casts = $this->buildCasts();
        $dates = $this->buildDates();
        $constraintComments = $this->withConstraintComments ? $this->buildConstraintComments() : '';

        $generator = new StubGenerator([
            'namespace' => $this->namespace,
            'uses' => $uses,
            'docblock' => $docBlock,
            'class_name' => $modelName,
            'table' => $this->table,
            'primary_key' => $primaryKeyProperty,
            'timestamps' => $timestampsProperty,
            'fillable' => $fillable,
            'hidden' => $hidden,
            'casts' => $casts,
            'dates' => $dates,
            'constraint_comments' => $constraintComments,
            'relationships' => $relationships,
        ]);

        return $generator->generate();
    }

    // -----------------------------------------------------------------------
    // Imports — FQCN in, short alias + `use` statement out
    // -----------------------------------------------------------------------

    /**
     * Reset the import table for a fresh build and pre-claim the names that
     * cannot be given away.
     */
    private function resetImports(string $modelName): void
    {
        $this->imports = [];
        $this->references = [];
        $this->takenAliases = [];

        // The class being generated owns its own short name. A self-referential
        // FK (parent_id → same table) therefore emits a bare Foo::class with no
        // import, while a same-named table in another schema gets aliased.
        $this->takenAliases[strtolower($modelName)] = $this->namespace.'\\'.$modelName;

        // Relation return types keep their short names; a model unluckily called
        // "HasMany" is the one that gets aliased.
        foreach (self::RELATION_RETURN_TYPES as $relationClass) {
            $this->takenAliases[strtolower(class_basename($relationClass))] = $relationClass;
        }

        if ($this->softDeletes) {
            $this->takenAliases['softdeletes'] = SoftDeletes::class;
        }
    }

    /**
     * Register $fqcn for import and return the alias to write in the file.
     *
     * Aliases are disambiguated with the parent namespace segment, so a model
     * generated as App\Models\Tenant with a cross-schema FK to
     * App\Models\Core\Tenant emits `use App\Models\Core\Tenant as CoreTenant`
     * and references CoreTenant::class.
     */
    protected function importClass(string $fqcn): string
    {
        $fqcn = ltrim($fqcn, '\\');

        if (isset($this->references[$fqcn])) {
            return $this->references[$fqcn];
        }

        $short = class_basename($fqcn);
        $owner = str_contains($fqcn, '\\') ? substr($fqcn, 0, (int) strrpos($fqcn, '\\')) : '';
        $alias = $this->uniqueAlias($fqcn, $short);

        // A class in this file's own namespace needs no import — unless we had to
        // alias it, in which case the `use ... as ...` is what makes the alias real.
        if ($alias !== $short || $owner !== $this->namespace) {
            $this->imports[$fqcn] = $alias;
        }

        $this->references[$fqcn] = $alias;
        $this->takenAliases[strtolower($alias)] = $fqcn;

        return $alias;
    }

    /**
     * The token to write in generated code: "Tenant::class".
     */
    protected function classReference(string $fqcn): string
    {
        return $this->importClass($fqcn).'::class';
    }

    private function uniqueAlias(string $fqcn, string $short): string
    {
        $holder = fn (string $alias): ?string => $this->takenAliases[strtolower($alias)] ?? null;

        $held = $holder($short);

        if ($held === null || $held === $fqcn) {
            return $short;
        }

        // Prefix with the parent namespace segment: App\Models\Core\Tenant → CoreTenant.
        $segments = explode('\\', $fqcn);
        array_pop($segments);
        $parent = (string) array_pop($segments);

        if ($parent !== '') {
            $candidate = Str::studly($parent).$short;
            $held = $holder($candidate);

            if ($held === null || $held === $fqcn) {
                return $candidate;
            }
        }

        $suffix = 2;

        while (($held = $holder($short.$suffix)) !== null && $held !== $fqcn) {
            $suffix++;
        }

        return $short.$suffix;
    }

    // -----------------------------------------------------------------------
    // Relation naming — resolved once, used by the docblock AND the methods
    // -----------------------------------------------------------------------

    /**
     * Decide every relation method name for this model.
     *
     * Both buildClassDocBlock() and buildRelationships() read from here. That is
     * the invariant that matters: the moment those two derive names
     * independently, a model gets an @method line that does not match any method.
     *
     * @return array{belongsTo: array<string, string>, inverse: array<int, string>, collisions: list<array<string, string>>}
     */
    protected function resolvedRelationNames(): array
    {
        if ($this->resolvedNames !== null) {
            return $this->resolvedNames;
        }

        $namer = RelationNamer::forTable(
            $this->tableName,
            array_values(array_filter(array_column($this->columns, 'name'))),
        );

        $belongsTo = [];

        foreach ($this->foreignKeys as $fk) {
            $column = $fk['column'] ?? null;

            if ($column === null || $column === '') {
                continue;
            }

            $belongsTo[$column] = $this->safeMethodName(
                $namer->belongsTo($column, (string) ($fk['referenced_table'] ?? '')),
            );
        }

        $counts = [];

        foreach ($this->inverseRelationships as $row) {
            $key = $this->inverseGroupKey($row);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $inverse = [];

        foreach ($this->inverseRelationships as $index => $row) {
            $count = $counts[$this->inverseGroupKey($row)] ?? 1;

            if ($count < 2 && $row['method'] !== '') {
                $inverse[$index] = $this->safeMethodName(
                    $namer->preferred($row['method'], $row['foreign_key'], (string) $row['model']),
                );

                continue;
            }

            $inverse[$index] = $this->safeMethodName($namer->inverseForModel(
                (string) $row['model'],
                (string) $row['foreign_key'],
                $count,
                (bool) $row['unique'],
                (string) ($row['table'] ?? ''),
            ));
        }

        return $this->resolvedNames = [
            'belongsTo' => $belongsTo,
            'inverse' => $inverse,
            'collisions' => $namer->collisions(),
        ];
    }

    /**
     * Guard against redeclaring a method that Eloquent\Model or SoftDeletes
     * already defines. See ReservedNames for why this fatals rather than warns.
     */
    protected function safeMethodName(string $name): string
    {
        return ReservedNames::safeMethodName($name);
    }

    /**
     * Names that had to be altered because the preferred one was taken. Report
     * these in the run summary — they are the tables worth a human glance.
     *
     * @return list<array<string, string>>
     */
    public function relationCollisions(): array
    {
        return $this->resolvedRelationNames()['collisions'];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function inverseGroupKey(array $row): string
    {
        $table = (string) ($row['table'] ?? '');

        return $table !== '' ? $table : (string) $row['model'];
    }

    // -----------------------------------------------------------------------
    // Related-class resolution
    // -----------------------------------------------------------------------

    /**
     * Fully-qualified class name of a related model, honouring the foreign key's
     * schema when it is not the default one.
     */
    protected function relatedModelFqn(array $fk): string
    {
        $relatedModel = Helpers::tableToModelName($fk['referenced_table']);

        return $this->qualifyModel($relatedModel, $fk['referenced_schema'] ?? null);
    }

    /**
     * As relatedModelFqn(), for an inverse-relationship row.
     *
     * @param  array<string, mixed>  $row
     */
    protected function inverseModelFqn(array $row): string
    {
        return $this->qualifyModel((string) $row['model'], $row['schema'] ?? null);
    }

    /**
     * Resolve a model name to an FQCN, adding a schema segment only when the
     * related table lives in a NON-DEFAULT schema.
     */
    protected function qualifyModel(string $model, ?string $schema): string
    {
        // No schema information, or no root namespace to hang a segment off:
        // resolve inside this model's own namespace (legacy behaviour, and the
        // right guess for a same-schema sibling).
        if ($schema === null || trim($schema) === '' || $this->rootNamespace === null) {
            return $this->namespace.'\\'.$model;
        }

        $segment = $this->schemaSegment($schema);

        // Note this resolves off the ROOT namespace, not $this->namespace: a model
        // in App\Models\Core with a FK into the default schema must point at
        // App\Models\Tenant, not App\Models\Core\Tenant.
        return $segment === null
            ? $this->rootNamespace.'\\'.$model
            : $this->rootNamespace.'\\'.$segment.'\\'.$model;
    }

    /**
     * The namespace segment for a schema, or null when none is warranted.
     */
    protected function schemaSegment(string $schema): ?string
    {
        if (ReservedNames::isDefaultSchema($schema, $this->defaultSchema)) {
            return null;
        }

        return ReservedNames::namespaceSegment($schema);
    }

    protected static function isReservedNamespaceSegment(string $segment): bool
    {
        return ReservedNames::isReservedNamespaceSegment($segment);
    }

    // -----------------------------------------------------------------------
    // Rendering
    // -----------------------------------------------------------------------

    /**
     * Build uses statements.
     *
     * Entries are either a bare FQCN or "FQCN as Alias"; StubGenerator::usesStub()
     * only has to prefix `use ` and append `;`.
     */
    protected function buildUses(): string
    {
        $uses = [];

        if ($this->softDeletes) {
            $uses[SoftDeletes::class] = SoftDeletes::class;
        }

        foreach ($this->imports as $fqcn => $alias) {
            $uses[$fqcn] = $alias === class_basename($fqcn)
                ? $fqcn
                : $fqcn.' as '.$alias;
        }

        if ($uses === []) {
            return StubGenerator::usesStub([]);
        }

        // Alphabetical by FQCN, so the block is stable across runs (PSR-12).
        ksort($uses, SORT_STRING | SORT_FLAG_CASE);

        return StubGenerator::usesStub(array_values($uses));
    }

    /**
     * Build class-level DocBlock.
     *
     * @method lines stay fully qualified on purpose: \App\Models\Tenant is
     * unambiguous in a docblock regardless of what the import table decided, and
     * a leading backslash never needs aliasing.
     */
    protected function buildClassDocBlock(): string
    {
        $names = $this->resolvedRelationNames();

        $properties = [];
        $methods = [];

        // Add table information
        if ($this->withConstraintComments && $this->constraintAnalysis) {
            $properties[] = [
                'type' => '',
                'name' => '',
                'comment' => 'Table: '.$this->table,
            ];
        }

        // Add property documentation
        foreach ($this->columns as $column) {
            $phpType = Helpers::mapDatabaseTypeToPhp($column['type']);
            $phpType = Helpers::isNullableType($phpType, $column['nullable']);

            $comment = $column['comment'] ?: null;

            // Add constraint information to comment
            if ($this->withConstraintComments) {
                $constraintInfo = $this->getColumnConstraintInfo($column['name']);
                if ($constraintInfo) {
                    $comment = $comment ? sprintf('%s (%s)', $comment, $constraintInfo) : $constraintInfo;
                }
            }

            $properties[] = [
                'type' => $phpType,
                'name' => $column['name'],
                'comment' => $comment,
            ];
        }

        // Add relationship method documentation
        foreach ($this->foreignKeys as $fk) {
            $methodName = $names['belongsTo'][$fk['column'] ?? ''] ?? null;

            if ($methodName === null) {
                continue;
            }

            $relatedModel = Helpers::tableToModelName($fk['referenced_table']);

            $methods[] = [
                'return' => '\\'.$this->relatedModelFqn($fk),
                'name' => $methodName,
                'comment' => 'Get the related '.$relatedModel,
            ];
        }

        // Add inverse relationship documentation
        if ($this->withInverse) {
            foreach ($this->inverseRelationships as $index => $inverse) {
                $methodName = $names['inverse'][$index] ?? null;

                if ($methodName === null) {
                    continue;
                }

                $fqn = '\\'.$this->inverseModelFqn($inverse);

                // The foreign key is named because with two relations to the same
                // model it is the only thing distinguishing them.
                $methods[] = [
                    'return' => $inverse['unique']
                        ? $fqn
                        : sprintf('\Illuminate\Database\Eloquent\Collection<int, %s>', $fqn),
                    'name' => $methodName,
                    'comment' => sprintf(
                        'Get the related %s %s via %s',
                        $inverse['model'],
                        $inverse['unique'] ? 'record' : 'records',
                        $inverse['foreign_key'],
                    ),
                ];
            }
        }

        return StubGenerator::classDocBlock($properties, $methods);
    }

    /**
     * Get constraint information for a column
     */
    protected function getColumnConstraintInfo(string $columnName): ?string
    {
        $info = [];

        // Check if primary key
        if (in_array($columnName, $this->compositePrimaryKey)) {
            $info[] = 'PK';
        }

        // Check if foreign key
        foreach ($this->foreignKeys as $fk) {
            if ($fk['column'] === $columnName) {
                $info[] = sprintf('FK -> %s.%s', $fk['referenced_table'], $fk['referenced_column']);
            }
        }

        // Check if unique
        foreach ($this->uniqueConstraints as $constraint) {
            $constraintColumns = array_map(fn (array $col) => $col['name'], $constraint['columns']);
            if (in_array($columnName, $constraintColumns)) {
                $info[] = 'UNIQUE';
                break;
            }
        }

        // Check if indexed
        foreach ($this->indexes as $index) {
            if (! $index['primary'] && ! $index['unique']) {
                $indexColumns = array_map(fn (array $col) => $col['name'], $index['columns']);
                if (in_array($columnName, $indexColumns)) {
                    $info[] = 'INDEXED';
                    break;
                }
            }
        }

        return $info === [] ? null : implode(', ', $info);
    }

    /**
     * Build primary key property
     */
    protected function buildPrimaryKeyProperty(): string
    {
        // Handle composite primary keys
        if (count($this->compositePrimaryKey) > 1) {
            $indent = '    ';
            $innerIndent = '        ';

            $stub = "\n{$indent}/**\n";
            $stub .= $indent." * The primary key for the model.\n";
            $stub .= $indent." *\n";
            $stub .= $indent." * @var array<int, string>\n";
            $stub .= $indent." */\n";
            $stub .= $indent."protected \$primaryKey = [\n";

            foreach ($this->compositePrimaryKey as $column) {
                $stub .= "{$innerIndent}'{$column}',\n";
            }

            $stub .= $indent."];\n\n";
            $stub .= $indent."/**\n";
            $stub .= $indent." * Indicates if the IDs are auto-incrementing.\n";
            $stub .= $indent." *\n";
            $stub .= $indent." * @var bool\n";
            $stub .= $indent." */\n";

            return $stub.($indent.'public $incrementing = false;');
        }

        return StubGenerator::primaryKeyStub($this->primaryKey);
    }

    /**
     * Build fillable property
     */
    protected function buildFillable(): string
    {
        $fillable = [];

        foreach ($this->columns as $column) {
            $columnName = $column['name'];
            // Skip primary key, timestamps, and auto-increment columns
            if (in_array($columnName, $this->compositePrimaryKey)) {
                continue;
            }
            if ($columnName === $this->primaryKey) {
                continue;
            }
            if (Helpers::isTimestampColumn($columnName)) {
                continue;
            }
            if (str_contains((string) $column['extra'], 'auto_increment')) {
                continue;
            }

            $fillable[] = $columnName;
        }

        return StubGenerator::fillableStub($fillable);
    }

    /**
     * Build hidden property
     */
    protected function buildHidden(): string
    {
        $hidden = [];

        foreach ($this->columns as $column) {
            $columnName = $column['name'];

            // Hide password and remember_token columns
            if (in_array($columnName, ['password', 'remember_token'])) {
                $hidden[] = $columnName;
            }
        }

        return StubGenerator::hiddenStub($hidden);
    }

    /**
     * Build casts property
     */
    protected function buildCasts(): string
    {
        $casts = [];

        foreach ($this->columns as $column) {
            $columnName = $column['name'];
            $castType = Helpers::getCastType($column['type']);

            if ($castType && ! Helpers::isTimestampColumn($columnName)) {
                $casts[$columnName] = $castType;
            }
        }

        // Add email_verified_at if exists
        $columnNames = array_column($this->columns, 'name');
        if (in_array('email_verified_at', $columnNames)) {
            $casts['email_verified_at'] = 'datetime';
        }

        return StubGenerator::castsStub($casts);
    }

    /**
     * Build dates property (for older Laravel versions)
     */
    protected function buildDates(): string
    {
        return '';
    }

    /**
     * Build constraint comments section
     */
    protected function buildConstraintComments(): string
    {
        if (! $this->constraintAnalysis) {
            return '';
        }

        $comments = [];
        $indent = '    ';

        // Primary Key info
        if (! empty($this->constraintAnalysis['primary_key']['columns'])) {
            $pkType = $this->constraintAnalysis['primary_key']['type'];
            $pkCols = implode(', ', $this->constraintAnalysis['primary_key']['columns']);
            $comments[] = sprintf('Primary Key: %s (%s)', $pkCols, $pkType);
        }

        // Foreign Keys
        if (! empty($this->constraintAnalysis['foreign_keys'])) {
            $comments[] = 'Foreign Keys:';
            foreach ($this->constraintAnalysis['foreign_keys'] as $fk) {
                $ref = $fk['references'];
                $comments[] = sprintf('  - %s -> %s.%s', $fk['column'], $ref['table'], $ref['column']);
            }
        }

        // Unique Constraints
        if (! empty($this->constraintAnalysis['unique_constraints'])) {
            $comments[] = 'Unique Constraints:';
            foreach ($this->constraintAnalysis['unique_constraints'] as $constraint) {
                $cols = implode(', ', $constraint['columns']);
                $comments[] = sprintf('  - %s: (%s)', $constraint['name'], $cols);
            }
        }

        // Indexes
        $nonUniqueIndexes = array_filter(
            $this->constraintAnalysis['indexes'],
            fn (array $idx): bool => ! $idx['is_unique'] && ! $idx['is_primary'],
        );

        if ($nonUniqueIndexes !== []) {
            $comments[] = 'Indexes:';
            foreach ($nonUniqueIndexes as $index) {
                $cols = implode(', ', $index['columns']);
                $comments[] = sprintf('  - %s: (%s)', $index['name'], $cols);
            }
        }

        if ($comments === []) {
            return '';
        }

        $stub = "\n{$indent}/*\n";
        $stub .= $indent." * Database Constraints\n";
        $stub .= $indent.' * '.str_repeat('-', 50)."\n";
        foreach ($comments as $comment) {
            $stub .= sprintf('%s * %s%s', $indent, $comment, PHP_EOL);
        }

        return $stub.($indent." */\n");
    }

    /**
     * Build relationship methods.
     */
    protected function buildRelationships(): string
    {
        $names = $this->resolvedRelationNames();

        $relationships = [];

        // Build belongsTo relationships from foreign keys
        foreach ($this->foreignKeys as $fk) {
            $methodName = $names['belongsTo'][$fk['column'] ?? ''] ?? null;

            if ($methodName === null) {
                continue;
            }

            $relationships[] = $this->renderRelationship(
                'belongsTo',
                $methodName,
                $this->relatedModelFqn($fk),
                (string) $fk['column'],
                isset($fk['referenced_column']) ? (string) $fk['referenced_column'] : null,
            );
        }

        // Build hasMany/hasOne inverse relationships
        if ($this->withInverse) {
            foreach ($this->inverseRelationships as $index => $inverse) {
                $methodName = $names['inverse'][$index] ?? null;

                if ($methodName === null) {
                    continue;
                }

                $relationships[] = $this->renderRelationship(
                    $inverse['unique'] ? 'hasOne' : 'hasMany',
                    $methodName,
                    $this->inverseModelFqn($inverse),
                    (string) $inverse['foreign_key'],
                    null,
                );
            }
        }

        if ($relationships === []) {
            return '';
        }

        return "\n".implode("\n\n", $relationships)."\n";
    }

    /**
     * Render one relation method.
     *
     * Rendered here rather than in StubGenerator::relationshipStub() because the
     * related class and the return type both have to go through the import table,
     * which only this object owns. Output:
     *
     *     public function tenant(): BelongsTo
     *     {
     *         return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
     *     }
     */
    protected function renderRelationship(
        string $type,
        string $methodName,
        string $relatedFqcn,
        string $foreignKey,
        ?string $ownerKey,
    ): string {
        $relationClass = self::RELATION_RETURN_TYPES[$type] ?? null;

        // Register the model first so it keeps the natural short name when a
        // schema segment forces an alias somewhere.
        $related = $this->classReference($relatedFqcn);
        $returnType = $relationClass !== null ? $this->importClass($relationClass) : null;

        $arguments = [$related];

        if ($foreignKey !== '') {
            $arguments[] = "'{$foreignKey}'";
        }

        if ($ownerKey !== null && $ownerKey !== '') {
            $arguments[] = "'{$ownerKey}'";
        }

        $indent = '    ';
        $out = '';

        if ($this->withPhpDoc) {
            $out .= "{$indent}/**\n";
            $out .= "{$indent} * Get the {$methodName} relationship.\n";

            if ($relationClass !== null) {
                $out .= "{$indent} *\n";
                $out .= "{$indent} * @return \\{$relationClass}\n";
            }

            $out .= "{$indent} */\n";
        }

        $hint = $returnType !== null ? ': '.$returnType : '';

        $out .= "{$indent}public function {$methodName}(){$hint}\n";
        $out .= "{$indent}{\n";
        $out .= "{$indent}    return \$this->{$type}(".implode(', ', $arguments).");\n";
        $out .= "{$indent}}";

        return $out;
    }

    /**
     * Get model name
     */
    public function getModelName(): string
    {
        return Helpers::tableToModelName($this->tableName);
    }
}
