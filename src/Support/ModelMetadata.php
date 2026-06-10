<?php

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

        return $defaultSchema === null || $this->schema !== $defaultSchema;
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
     */
    public function schemaNamespaceSegment(?string $defaultSchema = null): ?string
    {
        if (! $this->isSchemaQualified($defaultSchema)) {
            return null;
        }

        return Str::studly(str_replace(['.', '-', ' '], '_', $this->schema));
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
