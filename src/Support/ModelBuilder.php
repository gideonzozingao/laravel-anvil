<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

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
     * Memoised output of resolvedRelationNames(). Invalidated by any setter that
     * feeds into naming.
     *
     * @var array{belongsTo: array<string, string>, inverse: array<int, string>, collisions: list<array<string, string>>}|null
     */
    private ?array $resolvedNames = null;

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
     * The extra parameters are optional so existing callers keep working:
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
     * Build the model content
     */
    public function build(): string
    {
        $modelName = Helpers::tableToModelName($this->tableName);
        $uses = $this->buildUses();
        $docBlock = $this->withPhpDoc ? $this->buildClassDocBlock() : '';
        $primaryKeyProperty = $this->buildPrimaryKeyProperty();
        $timestampsProperty = StubGenerator::timestampsStub($this->timestamps);
        $fillable = $this->buildFillable();
        $hidden = $this->buildHidden();
        $casts = $this->buildCasts();
        $dates = $this->buildDates();
        $relationships = $this->buildRelationships();
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

        // belongsTo first: these derive straight from a column and are the names
        // a developer expects, so they win any contest with an inverse relation.
        $belongsTo = [];

        foreach ($this->foreignKeys as $fk) {
            $column = $fk['column'] ?? null;

            if ($column === null || $column === '') {
                continue;
            }

            $belongsTo[$column] = $namer->belongsTo($column, (string) ($fk['referenced_table'] ?? ''));
        }

        // How many entries point at each related model? That count is the sole
        // trigger for qualifying a name.
        $counts = [];

        foreach ($this->inverseRelationships as $row) {
            $key = $this->inverseGroupKey($row);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $inverse = [];

        foreach ($this->inverseRelationships as $index => $row) {
            $count = $counts[$this->inverseGroupKey($row)] ?? 1;

            if ($count < 2 && $row['method'] !== '') {
                // Unambiguous: honour the caller's name, but still claim it so a
                // clash with a belongsTo relation or a column cannot slip through.
                $inverse[$index] = $namer->preferred($row['method'], $row['foreign_key'], (string) $row['model']);

                continue;
            }

            $inverse[$index] = $namer->inverseForModel(
                (string) $row['model'],
                (string) $row['foreign_key'],
                $count,
                (bool) $row['unique'],
                (string) ($row['table'] ?? ''),
            );
        }

        return $this->resolvedNames = [
            'belongsTo' => $belongsTo,
            'inverse' => $inverse,
            'collisions' => $namer->collisions(),
        ];
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

    /**
     * Fully-qualified class name of a related model, honouring the foreign key's
     * schema when present (so a cross-schema FK resolves to App\Models\{Schema}\{Model}).
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
     * Resolve a model name to an FQCN, adding a schema segment when the related
     * table lives elsewhere.
     */
    protected function qualifyModel(string $model, ?string $schema): string
    {
        if ($schema === null || $schema === '' || $this->rootNamespace === null) {
            return $this->namespace.'\\'.$model;
        }

        $segment = Str::studly(str_replace(['.', '-', ' '], '_', $schema));

        // "public" is not a legal namespace segment — App\Models\Public\Tenant
        // fails to parse on some versions and confuses static analysis on the
        // rest.
        if (self::isReservedNamespaceSegment($segment)) {
            $segment .= 'Schema';
        }

        return $this->rootNamespace.'\\'.$segment.'\\'.$model;
    }

    protected static function isReservedNamespaceSegment(string $segment): bool
    {
        static $reserved = [
            'public',
            'private',
            'protected',
            'static',
            'class',
            'interface',
            'trait',
            'enum',
            'function',
            'const',
            'namespace',
            'use',
            'new',
            'return',
            'list',
            'array',
            'default',
            'match',
            'fn',
            'readonly',
            'never',
            'void',
            'null',
            'true',
            'false',
            'int',
            'float',
            'string',
            'bool',
            'object',
            'iterable',
            'callable',
            'mixed',
            'parent',
            'self',
        ];

        return in_array(strtolower($segment), $reserved, true);
    }

    /**
     * Build uses statements
     */
    protected function buildUses(): string
    {
        $uses = [];

        if ($this->softDeletes) {
            $uses[] = SoftDeletes::class;
        }

        return StubGenerator::usesStub($uses);
    }

    /**
     * Build class-level DocBlock
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
        $nonUniqueIndexes = array_filter($this->constraintAnalysis['indexes'], fn (array $idx): bool => ! $idx['is_unique'] && ! $idx['is_primary']);
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
     * Build relationship methods
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

            $relationships[] = StubGenerator::relationshipStub(
                'belongsTo',
                $methodName,
                $this->relatedModelFqn($fk),
                $fk['column'],
                $fk['referenced_column'],
                $this->withPhpDoc
            );
        }

        // Build hasMany/hasOne inverse relationships
        if ($this->withInverse) {
            foreach ($this->inverseRelationships as $index => $inverse) {
                $methodName = $names['inverse'][$index] ?? null;

                if ($methodName === null) {
                    continue;
                }

                $relationships[] = StubGenerator::relationshipStub(
                    $inverse['unique'] ? 'hasOne' : 'hasMany',
                    $methodName,
                    $this->inverseModelFqn($inverse),
                    $inverse['foreign_key'],
                    null,
                    $this->withPhpDoc
                );
            }
        }

        if ($relationships === []) {
            return '';
        }

        return "\n".implode("\n\n", $relationships)."\n";
    }

    /**
     * Get model name
     */
    public function getModelName(): string
    {
        return Helpers::tableToModelName($this->tableName);
    }
}
