<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Generators;

use Illuminate\Support\Str;
use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Generators\Concerns\WritesVersionedFiles;
use Zuqongtech\LaravelAnvil\Support\ApiVersionProfile;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\Helpers;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates version-scoped API resources:
 *
 *   App\Http\Resources\Api\V1\ApiResource        (base, once per version)
 *   App\Http\Resources\Api\V1\UserResource
 *   App\Http\Resources\Api\V1\UserCollection     (pagination envelope)
 *
 * Child classes declare their payload in COLUMN names; the base class applies
 * the version's output casing and drops hidden columns. Hidden fields are
 * enforced twice — excluded at generation AND filtered at runtime — so adding a
 * secret column to the table cannot leak through a resource generated months
 * ago, and a hand-edit that re-adds it is still caught.
 *
 * Relation keys come from ModelMetadata::inverseName()/belongsToName(), so
 * whenLoaded() always names a method the model actually has.
 */
final class ApiResourceGenerator implements Generator
{
    use WritesVersionedFiles;

    #[\Override]
    public function supports(GenerationOptions $options): bool
    {
        // api ONLY — see ApiFormRequestGenerator: the core ResourceGenerator keeps
        // the unversioned App\Http\Resources classes for non-API runs.
        return $options->api;
    }

    #[\Override]
    public function getName(): string
    {
        return 'ApiResource';
    }

    #[\Override]
    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $profile = $this->profile($options);
        $namespace = $profile->resourceNamespace();

        return [
            $this->writeClass(
                $this->getName().'Base',
                $namespace,
                $profile->baseResourceClass(),
                $options,
                fn (): string => $this->buildBaseResource($profile),
                overwritable: false,
            ),
            $this->writeClass(
                $this->getName(),
                $namespace,
                $profile->resourceClass($meta->model),
                $options,
                fn (): string => $this->buildResource($meta, $profile, $options),
            ),
            $this->writeClass(
                $this->getName().'Collection',
                $namespace,
                $profile->collectionClass($meta->model),
                $options,
                fn (): string => $this->buildCollection($meta, $profile),
            ),
        ];
    }

    // -----------------------------------------------------------------------
    // Base resource
    // -----------------------------------------------------------------------

    protected function buildBaseResource(ApiVersionProfile $profile): string
    {
        $namespace = $profile->resourceNamespace();
        $class = $profile->baseResourceClass();
        $version = $profile->version;

        return <<<PHP
<?php

namespace {$namespace};

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base resource for API {$version}.
 *
 * Children implement fields() using COLUMN names. Presentation — key casing and
 * hidden-field removal — happens here, in one place, so a version's output shape
 * is defined by its configuration rather than by 32 hand-maintained files.
 */
abstract class {$class} extends JsonResource
{
    /**
     * column => the key clients receive.
     *
     * @var array<string, string>
     */
    protected array \$outbound = [];

    /**
     * Columns never present in output, whatever fields() returns.
     *
     * @var list<string>
     */
    protected array \$hidden = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request \$request): array
    {
        return \$this->present(\$this->fields(\$request));
    }

    /**
     * The payload, keyed by column name.
     *
     * @return array<string, mixed>
     */
    abstract protected function fields(Request \$request): array;

    /**
     * @param  array<string, mixed>  \$data
     * @return array<string, mixed>
     */
    protected function present(array \$data): array
    {
        \$out = [];

        foreach (\$data as \$key => \$value) {
            if (in_array(\$key, \$this->hidden, true)) {
                continue;
            }

            // MissingValue from whenLoaded()/when() must stay unwrapped so
            // JsonResource can strip it.
            \$out[\$this->outbound[\$key] ?? \$key] = \$value;
        }

        return \$out;
    }
}

PHP;
    }

    // -----------------------------------------------------------------------
    // Model resource
    // -----------------------------------------------------------------------

    protected function buildResource(ModelMetadata $meta, ApiVersionProfile $profile, GenerationOptions $options): string
    {
        $namespace = $profile->resourceNamespace();
        $class = $profile->resourceClass($meta->model);
        $base = $profile->baseResourceClass();
        $modelFqn = trim($options->getNamespace(), '\\').'\\'.$meta->model;

        $visible = array_values(array_filter(
            $meta->columns,
            static fn (array $col): bool => ! $profile->isHidden((string) $col['name']),
        ));

        $outbound = $profile->outboundMap(array_column($visible, 'name'));
        $hidden = array_values(array_filter(
            array_column($meta->columns, 'name'),
            $profile->isHidden(...),
        ));

        $outboundBlock = $this->renderMap($outbound, ' => ');
        $hiddenBlock = implode('', array_map(
            static fn (string $name): string => sprintf("        '%s',\n", $name),
            $hidden,
        ));
        $fieldsBlock = $this->renderFields($meta, $visible);
        $relationsBlock = $this->renderRelations($meta, $profile);

        $hiddenProperty = $hidden === [] ? '' : <<<PHP

        /**
         * Never serialised — enforced here as well as omitted from \$outbound, so a
         * hand-edit to fields() cannot leak them.
         *
         * @var list<string>
         */
        protected array \$hidden = [
                {$hiddenBlock}   
                 ];

        PHP;

        return <<<PHP
            <?php

            namespace {$namespace};

            use Illuminate\Http\Request;

            /**
             * {$meta->model} as exposed by the {$profile->version} API.
             *
             * @mixin \\{$modelFqn}
             */
            class {$class} extends {$base}
            {
                /**
                 * column => api key, generated for {$profile->version} ({$profile->responseCase()} case).
                 *
                 * @var array<string, string>
                 */
                protected array \$outbound = [
                    {$outboundBlock}
                ];
                    {$hiddenProperty}
                /**
                 * @return array<string, mixed>
                 */
                protected function fields(Request \$request): array
                {
                    return [
                        {$fieldsBlock}{$relationsBlock}
                    ];
                }
            }

        PHP;
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     */
    protected function renderFields(ModelMetadata $meta, array $columns): string
    {
        $lines = [];

        foreach ($columns as $col) {
            $name = (string) $col['name'];
            $type = strtolower((string) ($col['type'] ?? ''));

            // Dates as ISO 8601: Carbon's default string form is not a format
            // any client should have to parse.
            $accessor = match (true) {
                (bool) preg_match('/(timestamp|datetime)/', $type) => "\$this->{$name}?->toIso8601String()",
                $type === 'date' => "\$this->{$name}?->toDateString()",
                default => "\$this->{$name}",
            };

            $lines[] = sprintf("            '%s' => %s,", $name, $accessor);
        }

        return implode("\n", $lines);
    }

    /**
     * Relations are keyed by their API name but LOADED by the method name the
     * model actually declares — the two differ once a child table points at the
     * same parent twice (customerVehicleBookings vs assignedAgentVehicleBookings).
     */
    protected function renderRelations(ModelMetadata $meta, ApiVersionProfile $profile): string
    {
        $lines = [];

        foreach ($meta->foreignKeys as $fk) {
            $column = (string) ($fk['column'] ?? '');
            $method = $meta->belongsToName($column);

            if ($method === null || $column === '') {
                continue;
            }

            $related = Helpers::tableToModelName((string) $fk['referenced_table']);
            $key = Str::snake($method);

            $lines[] = sprintf(
                "            '%s' => %sResource::make(\$this->whenLoaded('%s')),",
                $key,
                $related,
                $method,
            );
        }

        foreach ($meta->inverseRelationships as $row) {
            $table = (string) ($row['table'] ?? '');
            $column = (string) ($row['column'] ?? $row['foreign_key'] ?? '');
            $method = $table !== '' ? $meta->inverseName($table, $column) : null;

            if ($method === null) {
                continue;
            }

            $related = Helpers::tableToModelName($table);
            $key = Str::snake($method);

            $lines[] = sprintf(
                "            '%s' => %sResource::collection(\$this->whenLoaded('%s')),",
                $key,
                $related,
                $method,
            );
        }

        return $lines === [] ? '' : "\n\n".implode("\n", $lines);
    }

    // -----------------------------------------------------------------------
    // Collection
    // -----------------------------------------------------------------------

    protected function buildCollection(ModelMetadata $meta, ApiVersionProfile $profile): string
    {
        $namespace = $profile->resourceNamespace();
        $class = $profile->collectionClass($meta->model);
        $resource = $profile->resourceClass($meta->model);
        $version = $profile->version;

        $metaKeys = $this->paginationMetaKeys($profile);

        return <<<PHP
            <?php

            namespace {$namespace};

            use Illuminate\Http\Request;
            use Illuminate\Http\Resources\Json\ResourceCollection;

            /**
             * Paginated {$meta->model} collection for {$version}.
             *
             * The meta block is emitted in this version's key casing, matching the
             * PaginationMeta schema in the OpenAPI spec.
             */
            class {$class} extends ResourceCollection
            {
                public \$collects = {$resource}::class;

                /**
                 * @return array<string, mixed>
                 */
                public function toArray(Request \$request): array
                {
                    return [
                        'data' => \$this->collection,
                        'meta' => [
                        {$metaKeys}
                        ],
                    ];
                }

                /**
                 * @return array<string, mixed>
                 */
                public function with(Request \$request): array
                {
                    return ['success' => true, 'version' => '{$version}'];
                }
            }

        PHP;
    }

    protected function paginationMetaKeys(ApiVersionProfile $profile): string
    {
        $map = $profile->outboundMap([
            'current_page',
            'last_page',
            'per_page',
            'total',
            'from',
            'to',
        ]);

        $accessors = [
            'current_page' => 'currentPage()',
            'last_page' => 'lastPage()',
            'per_page' => 'perPage()',
            'total' => 'total()',
            'from' => 'firstItem()',
            'to' => 'lastItem()',
        ];

        $lines = [];

        foreach ($accessors as $column => $accessor) {
            $lines[] = sprintf("                '%s' => \$this->%s,", $map[$column] ?? $column, $accessor);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, string>  $map
     */
    protected function renderMap(array $map, string $arrow = ' => '): string
    {
        $lines = [];

        foreach ($map as $key => $value) {
            $lines[] = sprintf("        '%s'%s'%s',", $key, $arrow, $value);
        }

        return implode("\n", $lines);
    }
}
