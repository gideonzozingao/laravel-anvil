<?php

namespace Zuqongtech\LaravelAnvil\Support;

use Illuminate\Database\Eloquent\SoftDeletes;

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

    protected array $inverseRelationships = [];

    protected ?array $constraintAnalysis = null;

    /** The actual DB table for `protected $table` — may be schema-qualified (e.g. "members_db.addresses"). */
    protected string $table;

    /**
     * Root models namespace (no schema segment). Used to resolve related models
     * when a foreign key points at a table in another schema.
     */
    protected ?string $rootNamespace = null;

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

        return $this;
    }

    /**
     * Set foreign keys
     */
    public function setForeignKeys(array $foreignKeys): self
    {
        $this->foreignKeys = $foreignKeys;

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
     * Add inverse relationship
     */
    public function addInverseRelationship(string $methodName, string $relatedModel, string $foreignKey): self
    {
        $this->inverseRelationships[] = [
            'method' => $methodName,
            'model' => $relatedModel,
            'foreign_key' => $foreignKey,
        ];

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

    /**
     * Fully-qualified class name of a related model, honouring the foreign key's
     * schema when present (so a cross-schema FK resolves to App\Models\{Schema}\{Model}).
     */
    protected function relatedModelFqn(array $fk): string
    {
        $relatedModel = Helpers::tableToModelName($fk['referenced_table']);
        $schema = $fk['referenced_schema'] ?? null;

        // No schema info, or no root namespace configured → resolve in this model's namespace.
        if ($schema === null || $schema === '' || $this->rootNamespace === null) {
            return $this->namespace.'\\'.$relatedModel;
        }

        $segment = \Illuminate\Support\Str::studly(str_replace(['.', '-', ' '], '_', $schema));

        return $this->rootNamespace.'\\'.$segment.'\\'.$relatedModel;
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
            $methodName = Helpers::foreignKeyToRelationName($fk['column']);
            $relatedModel = Helpers::tableToModelName($fk['referenced_table']);

            $methods[] = [
                'return' => '\\'.$this->relatedModelFqn($fk),
                'name' => $methodName,
                'comment' => 'Get the related '.$relatedModel,
            ];
        }

        // Add inverse relationship documentation
        foreach ($this->inverseRelationships as $inverse) {
            $methods[] = [
                'return' => sprintf('\Illuminate\Database\Eloquent\Collection<int, \%s\%s>', $this->namespace, $inverse['model']),
                'name' => $inverse['method'],
                'comment' => sprintf('Get the related %s records', $inverse['model']),
            ];
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
        $relationships = [];

        // Build belongsTo relationships from foreign keys
        foreach ($this->foreignKeys as $fk) {
            $methodName = Helpers::foreignKeyToRelationName($fk['column']);
            $fullRelatedModel = $this->relatedModelFqn($fk);

            $relationships[] = StubGenerator::relationshipStub(
                'belongsTo',
                $methodName,
                $fullRelatedModel,
                $fk['column'],
                $fk['referenced_column'],
                $this->withPhpDoc
            );
        }

        // Build hasMany/hasOne inverse relationships
        if ($this->withInverse) {
            foreach ($this->inverseRelationships as $inverse) {
                $fullRelatedModel = $this->namespace.'\\'.$inverse['model'];

                $relationships[] = StubGenerator::relationshipStub(
                    'hasMany',
                    $inverse['method'],
                    $fullRelatedModel,
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