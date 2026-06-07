<?php

namespace Zuqongtech\LaravelAnvil\Support;

namespace Zuqongtech\LaravelAnvil\Support;

final class ModelMetadata
{
    public string $table;

    public string $model;

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

    public static function fromTable(string $table, DatabaseInspector $inspector): self
    {
        $metadata = new self;
        $metadata->table = $table;
        $metadata->model = Helpers::tableToModelName($table);

        $tableMetadata = $inspector->getTableMetadata($table);
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
}
