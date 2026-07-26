<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

use Illuminate\Support\Str;

/**
 * Builds Lighthouse SDL from the schema.
 *
 * GraphQL is a better fit for a schema-driven generator than REST is: the SDL type
 * IS the contract, so there is no separate spec to keep in sync. What Anvil adds is
 * the mapping from database reality to GraphQL convention, which is where
 * hand-written schemas usually go wrong.
 *
 * THREE THINGS THIS GETS RIGHT THAT ARE EASY TO GET WRONG BY HAND
 *
 * 1. @rename. GraphQL fields are camelCase by convention; columns rarely are. A
 *    field named createdAt without @rename(attribute: "created_at") simply
 *    resolves to null, silently, for every row.
 *
 * 2. Relation directives. @belongsTo and @hasMany make Lighthouse batch-load,
 *    which is the difference between one query and one per row. A plain field
 *    resolver here is the classic N+1, and it only shows up under load.
 *
 * 3. Hidden columns. A REST resource omits them by construction; a GraphQL type
 *    exposes every field it declares. Forgetting password here is a breach, not a
 *    bug.
 *
 * Versioning note: GraphQL has none. The ApiVersionProfile contributes hidden
 * fields, read-only columns and pagination bounds; its casing is deliberately
 * ignored, because a snake_case REST v1 should still produce a camelCase graph.
 */
final readonly class GraphQLSchemaBuilder
{
    public function __construct(
        private ApiVersionProfile $profile,
        private string $connection,
    ) {}

    // -----------------------------------------------------------------------
    // Per-model schema
    // -----------------------------------------------------------------------

    /**
     * Type, inputs, queries and mutations for one model.
     */
    public function model(ModelMetadata $meta): string
    {
        $sections = array_filter([
            $this->type($meta),
            $this->inputs($meta),
            $this->queries($meta),
            $this->mutations($meta),
        ]);

        return implode("\n\n", $sections)."\n";
    }

    private function type(ModelMetadata $meta): string
    {
        $model = $meta->model;
        $fields = [];

        foreach ($meta->columns as $column) {
            $name = (string) $column['name'];

            // A declared field is a readable field. There is no "hidden" in SDL.
            if ($this->profile->isHidden($name)) {
                continue;
            }

            $fields[] = $this->field($meta, $column);
        }

        foreach ($this->relationFields($meta) as $relation) {
            $fields[] = $relation;
        }

        $body = implode("\n", $fields);
        $description = $this->describe("A {$this->humanise($model)} record.", $meta);

        return <<<GRAPHQL
{$description}
type {$model} {
{$body}
}
GRAPHQL;
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private function field(ModelMetadata $meta, array $column): string
    {
        $name = (string) $column['name'];
        $graphName = Str::camel($name);
        $type = $this->graphType($meta, $column);

        $directives = [];

        // Without this the resolver looks for a property matching the field name.
        if ($graphName !== $name) {
            $directives[] = "@rename(attribute: \"{$name}\")";
        }

        $suffix = $directives === [] ? '' : ' '.implode(' ', $directives);

        return "    {$graphName}: {$type}{$suffix}";
    }

    /**
     * @return list<string>
     */
    private function relationFields(ModelMetadata $meta): array
    {
        $fields = [];

        foreach ($meta->foreignKeys as $fk) {
            $column = (string) ($fk['column'] ?? '');
            $method = $meta->belongsToName($column);

            if ($method === null) {
                continue;
            }

            $related = Helpers::tableToModelName((string) $fk['referenced_table']);
            $nullable = $this->columnNullable($meta, $column) ? '' : '!';

            // @belongsTo batches through a data loader; a hand-written resolver
            // here would query once per parent row.
            $fields[] = "    {$method}: {$related}{$nullable} @belongsTo";
        }

        foreach ($meta->inverseRelationships as $row) {
            $table = (string) ($row['table'] ?? '');
            $column = (string) ($row['column'] ?? $row['foreign_key'] ?? '');
            $method = $table !== '' ? $meta->inverseName($table, $column) : null;

            if ($method === null) {
                continue;
            }

            $related = Helpers::tableToModelName($table);

            // Paginated rather than a bare list: an unbounded [Type!]! on a
            // collection is how a single query pulls a million rows.
            $fields[] = sprintf(
                '    %s: [%s!]! @hasMany(type: PAGINATOR, defaultCount: %d, maxCount: %d)',
                $method,
                $related,
                $this->profile->perPageDefault(),
                $this->profile->perPageMax(),
            );
        }

        return $fields;
    }

    // -----------------------------------------------------------------------
    // Inputs
    // -----------------------------------------------------------------------

    private function inputs(ModelMetadata $meta): string
    {
        $model = $meta->model;
        $create = [];
        $update = [];

        $key = $meta->primaryKey ?? 'id';
        $update[] = "    {$key}: ID!";

        foreach ($meta->columns as $column) {
            $name = (string) $column['name'];

            if ($this->profile->isReadOnly($name) || $name === $meta->primaryKey) {
                continue;
            }

            // Writable but never readable: password belongs in the input only.
            if ($this->profile->isHidden($name) && $name !== 'password') {
                continue;
            }

            $graphName = Str::camel($name);
            $rename = $graphName !== $name ? " @rename(attribute: \"{$name}\")" : '';
            $rules = $this->rules($meta, $column);
            $rulesDirective = $rules === [] ? '' : ' @rules(apply: ['.implode(', ', array_map(
                static fn (string $rule): string => '"'.$rule.'"',
                $rules,
            )).'])';

            $required = ! ($column['nullable'] ?? false) && ($column['default'] ?? null) === null;
            $type = $this->graphType($meta, $column, forInput: true);

            $create[] = "    {$graphName}: {$type}".($required ? '!' : '').$rename.$rulesDirective;

            // Everything is optional on update — that is what a partial update is.
            $update[] = "    {$graphName}: {$type}{$rename}{$rulesDirective}";
        }

        $createBody = implode("\n", $create);
        $updateBody = implode("\n", $update);

        return <<<GRAPHQL
"Fields accepted when creating a {$this->humanise($model)}."
input Create{$model}Input {
{$createBody}
}

"Fields accepted when updating a {$this->humanise($model)}. All optional except the key."
input Update{$model}Input {
{$updateBody}
}
GRAPHQL;
    }

    /**
     * Validation runs server-side through @rules, mirroring the form requests.
     *
     * @param  array<string, mixed>  $column
     * @return list<string>
     */
    private function rules(ModelMetadata $meta, array $column): array
    {
        $name = (string) $column['name'];
        $type = strtolower((string) preg_replace('/\(.*\)/', '', (string) ($column['type'] ?? '')));
        $rules = [];

        if (str_contains(strtolower($name), 'email')) {
            $rules[] = 'email';
        }

        if ($name === 'password') {
            $rules[] = 'min:8';
        }

        $length = $column['length'] ?? $column['character_maximum_length'] ?? null;

        if ($length !== null && str_contains($type, 'char')) {
            $rules[] = 'max:'.(int) $length;
        }

        foreach ($meta->foreignKeys as $fk) {
            if (($fk['column'] ?? null) === $name) {
                $rules[] = sprintf('exists:%s,%s', $fk['referenced_table'], $fk['referenced_column'] ?? 'id');

                break;
            }
        }

        foreach ($meta->uniqueConstraints as $constraint) {
            $columns = array_column($constraint['columns'] ?? [], 'name');

            if (count($columns) === 1 && $columns[0] === $name) {
                // Note: no ignore clause. On update this rejects the row's own
                // value; add Rule::unique()->ignore() in a @validator class.
                $rules[] = sprintf('unique:%s,%s', $meta->table, $name);

                break;
            }
        }

        return array_values(array_unique($rules));
    }

    // -----------------------------------------------------------------------
    // Queries & mutations
    // -----------------------------------------------------------------------

    private function queries(ModelMetadata $meta): string
    {
        $model = $meta->model;
        $single = Str::camel($model);
        $plural = Str::camel(Str::pluralStudly($model));
        $key = $meta->primaryKey ?? 'id';

        $guard = $this->guardDirective();
        $canView = $this->canDirective('view', findKey: $key);
        $canViewAny = $this->canDirective('viewAny');

        $filters = $this->filterArguments($meta);
        $sortable = $this->sortableColumns($meta);
        $trashed = $meta->softDeletes ? ' @softDeletes' : '';

        return <<<GRAPHQL
extend type Query {
    "A single {$this->humanise($model)} by key."
    {$single}({$key}: ID! @eq): {$model} @find{$guard}{$canView}

    "Paginated {$this->humanise(Str::pluralStudly($model))}."
    {$plural}(
{$filters}
        orderBy: _ @orderBy(columns: [{$sortable}])
    ): [{$model}!]! @paginate(defaultCount: {$this->profile->perPageDefault()}, maxCount: {$this->profile->perPageMax()}){$trashed}{$guard}{$canViewAny}
}
GRAPHQL;
    }

    private function mutations(ModelMetadata $meta): string
    {
        $model = $meta->model;
        $key = $meta->primaryKey ?? 'id';
        $guard = $this->guardDirective();

        $softDeletes = $meta->softDeletes ? <<<GRAPHQL


    "Restore a soft-deleted {$this->humanise($model)}."
    restore{$model}({$key}: ID!): {$model} @restore{$guard}{$this->canDirective('restore', findKey: $key)}

    "Permanently remove a {$this->humanise($model)}."
    forceDelete{$model}({$key}: ID!): {$model} @forceDelete{$guard}{$this->canDirective('forceDelete', findKey: $key)}
GRAPHQL : '';

        return <<<GRAPHQL
extend type Mutation {
    create{$model}(input: Create{$model}Input! @spread): {$model}! @create{$guard}{$this->canDirective('create')}

    update{$model}(input: Update{$model}Input! @spread): {$model}! @update{$guard}{$this->canDirective('update', findKey: $key)}

    delete{$model}({$key}: ID!): {$model} @delete{$guard}{$this->canDirective('delete', findKey: $key)}{$softDeletes}
}
GRAPHQL;
    }

    /**
     * Filter arguments for the collection query. Restricted to indexed and
     * low-cardinality columns: every filter is a WHERE a client can trigger, and
     * an unindexed one is a sequential scan on demand.
     */
    private function filterArguments(ModelMetadata $meta): string
    {
        $indexed = [];

        foreach ($meta->indexes as $index) {
            foreach (array_column($index['columns'] ?? [], 'name') as $column) {
                $indexed[] = (string) $column;
            }
        }

        foreach ($meta->foreignKeys as $fk) {
            $indexed[] = (string) ($fk['column'] ?? '');
        }

        $arguments = [];

        foreach ($meta->columns as $column) {
            $name = (string) $column['name'];

            if ($this->profile->isHidden($name) || ! in_array($name, $indexed, true)) {
                continue;
            }

            $graphName = Str::camel($name);
            $type = $this->graphType($meta, $column, forInput: true, bare: true);
            $rename = $graphName !== $name ? " key: \"{$name}\"" : '';

            $arguments[] = "        {$graphName}: {$type} @eq(".trim($rename).')';
        }

        // A text search over the searchable columns, if any exist.
        $searchable = $this->searchableColumns($meta);

        if ($searchable !== []) {
            $arguments[] = sprintf(
                '        search: String @whereAny(columns: [%s])',
                implode(', ', array_map(static fn (string $c): string => '"'.$c.'"', $searchable)),
            );
        }

        return $arguments === []
            ? '        "No indexed columns to filter on."'
            : implode("\n", $arguments);
    }

    // -----------------------------------------------------------------------
    // Enums
    // -----------------------------------------------------------------------

    /**
     * SDL enums for every detected enum column.
     *
     * @param  array<string, ModelMetadata>  $tables
     */
    public function enums(array $tables): string
    {
        $blocks = [];
        $seen = [];

        foreach ($tables as $meta) {
            foreach (EnumDetector::forTable($meta, $this->connection) as $enum) {
                if (isset($seen[$enum->enumName])) {
                    continue;
                }

                $seen[$enum->enumName] = true;
                $cases = [];

                foreach ($enum->cases as $value => $case) {
                    // SCREAMING_SNAKE is the GraphQL convention for enum values;
                    // @enum maps it back to what the column stores.
                    $constant = strtoupper(Str::snake($case));
                    $cases[] = "    {$constant} @enum(value: \"{$value}\")";
                }

                $body = implode("\n", $cases);

                $blocks[] = <<<GRAPHQL
"Values of {$enum->table}.{$enum->column}."
enum {$enum->enumName} {
{$body}
}
GRAPHQL;
            }
        }

        return $blocks === [] ? '' : implode("\n\n", $blocks)."\n";
    }

    // -----------------------------------------------------------------------
    // Type mapping
    // -----------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $column
     */
    private function graphType(ModelMetadata $meta, array $column, bool $forInput = false, bool $bare = false): string
    {
        $name = (string) $column['name'];

        if ($name === ($meta->primaryKey ?? 'id')) {
            return $bare ? 'ID' : 'ID!';
        }

        $enums = EnumDetector::forTable($meta, $this->connection);
        $type = isset($enums[$name])
            ? $enums[$name]->enumName
            : $this->scalarFor((string) ($column['type'] ?? 'varchar'), $name);

        if ($bare) {
            return $type;
        }

        // Non-null only on output: an input field's optionality is expressed by
        // the caller, and the ! is added by the input builder where appropriate.
        $required = ! ($column['nullable'] ?? false) && ! $forInput;

        return $type.($required ? '!' : '');
    }

    private function scalarFor(string $dbType, string $columnName): string
    {
        $type = strtolower((string) preg_replace('/\(.*\)/', '', $dbType));

        if ($dbType === 'tinyint(1)') {
            return 'Boolean';
        }

        return match (true) {
            str_contains($type, 'bool') => 'Boolean',
            (bool) preg_match('/(bigint|int8)/', $type) => 'ID',
            (bool) preg_match('/(int|serial)/', $type) => 'Int',
            (bool) preg_match('/(decimal|numeric|money)/', $type) => 'String',
            (bool) preg_match('/(float|double|real)/', $type) => 'Float',
            str_contains($type, 'uuid') => 'ID',
            str_contains($type, 'json') => 'JSON',
            (bool) preg_match('/timestamptz|with time zone/', $type) => 'DateTimeTz',
            (bool) preg_match('/(timestamp|datetime)/', $type) => 'DateTime',
            $type === 'date' => 'Date',
            default => 'String',
        };
    }

    // -----------------------------------------------------------------------
    // Directives
    // -----------------------------------------------------------------------

    private function guardDirective(): string
    {
        $guard = (string) config('anvil.graphql.guard', '');

        return match (true) {
            $guard === 'none' || $guard === '' => '',
            $guard === 'default' => ' @guard',
            default => " @guard(with: [\"{$guard}\"])",
        };
    }

    /**
     * @can maps straight onto the policies anvil:generate already produces.
     */
    private function canDirective(string $ability, ?string $findKey = null): string
    {
        if (! config('anvil.graphql.policies', false)) {
            return '';
        }

        return $findKey === null
            ? " @can(ability: \"{$ability}\")"
            : " @can(ability: \"{$ability}\", find: \"{$findKey}\")";
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function sortableColumns(ModelMetadata $meta): string
    {
        $columns = array_values(array_filter(
            array_map(strval(...), array_column($meta->columns, 'name')),
            fn (string $name): bool => ! $this->profile->isHidden($name),
        ));

        return implode(', ', array_map(static fn (string $c): string => '"'.$c.'"', $columns));
    }

    /**
     * @return list<string>
     */
    private function searchableColumns(ModelMetadata $meta): array
    {
        $names = [];

        foreach ($meta->columns as $column) {
            $name = (string) $column['name'];
            $type = strtolower((string) preg_replace('/\(.*\)/', '', (string) ($column['type'] ?? '')));

            if ($this->profile->isHidden($name) || ! preg_match('/(char|text)/', $type)) {
                continue;
            }

            $names[] = $name;
        }

        return array_slice($names, 0, 6);
    }

    private function columnNullable(ModelMetadata $meta, string $column): bool
    {
        foreach ($meta->columns as $candidate) {
            if ((string) $candidate['name'] === $column) {
                return (bool) ($candidate['nullable'] ?? false);
            }
        }

        return true;
    }

    private function describe(string $text, ModelMetadata $meta): string
    {
        return '"'.$text.' Generated from '.$meta->table.'."';
    }

    private function humanise(string $model): string
    {
        return strtolower(Str::headline($model));
    }
}
