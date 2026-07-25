<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Generators;

use Illuminate\Support\Str;
use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Generators\Concerns\WritesVersionedFiles;
use Zuqongtech\LaravelAnvil\Support\ApiVersionProfile;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\Helpers;
use Zuqongtech\LaravelAnvil\Support\KeyCase;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates version-scoped form requests:
 *
 *   App\Http\Requests\Api\V1\ApiFormRequest        (base, once per version)
 *   App\Http\Requests\Api\V1\User\StoreRequest
 *   App\Http\Requests\Api\V1\User\UpdateRequest
 *   App\Http\Requests\Api\V1\User\IndexRequest     (pagination + filters)
 *
 * Each request carries an explicit $inbound map (apiKey => column) built from the
 * real column list. That map is the whole point: converting keys at runtime with
 * Str::snake() is LOSSY — address_line_1 camelises to addressLine1, which snakes
 * back to address_line1, and the field then silently fails to validate or save
 * with nothing reported anywhere.
 *
 * Set anvil.api.versions.{v}.case.request to camel|studly|kebab to change the
 * casing clients send; rules stay keyed by column because the base class
 * rewrites the payload before validation runs.
 */
final class ApiFormRequestGenerator implements Generator
{
    use WritesVersionedFiles;

    #[\Override]
    public function supports(GenerationOptions $options): bool
    {
        // api ONLY: the core FormRequestGenerator still owns the unversioned
        // App\Http\Requests classes for non-API runs. Both firing would write two
        // sets of requests for the same models.
        return $options->api;
    }

    #[\Override]
    public function getName(): string
    {
        return 'ApiRequest';
    }

    #[\Override]
    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $profile = $this->profile($options);

        $results = [
            // Base class is never overwritten: it is the natural place for
            // project-wide request behaviour.
            $this->writeClass(
                $this->getName().'Base',
                $profile->baseRequestNamespace(),
                $profile->baseRequestClass(),
                $options,
                fn (): string => $this->buildBaseRequest($profile),
                overwritable: false,
            ),
        ];

        foreach (['index', 'store', 'update'] as $action) {
            $results[] = $this->writeClass(
                $this->getName(),
                $profile->requestNamespace($meta->model),
                $profile->requestClass($meta->model, $action),
                $options,
                fn (): string => $this->buildRequest($meta, $profile, $action),
            );
        }

        foreach (KeyCase::lossyRoundTrips(array_column($meta->columns, 'name'), $profile->requestCase()) as $column => $naive) {
            $results[] = [
                'type' => $this->getName(),
                'name' => "{$meta->model}.{$column}",
                'status' => 'warning',
                'reason' => "mapped explicitly; a runtime Str::snake() would yield {$naive}",
            ];
        }

        return $results;
    }

    // -----------------------------------------------------------------------
    // Base request
    // -----------------------------------------------------------------------

    protected function buildBaseRequest(ApiVersionProfile $profile): string
    {
        $namespace = $profile->baseRequestNamespace();
        $class = $profile->baseRequestClass();
        $version = $profile->version;
        $param = $profile->perPageParam();
        $default = $profile->perPageDefault();
        $max = $profile->perPageMax();
        $pageParam = $profile->pageParam();

        return <<<PHP
<?php

namespace {$namespace};

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Base form request for API {$version}.
 *
 * Inbound keys are translated to column names through the \$inbound map each
 * child class declares. The map is generated from the real column list rather
 * than computed at runtime, because the reverse casing trip is lossy:
 * addressLine1 snakes to address_line1, not address_line_1.
 */
abstract class {$class} extends FormRequest
{
    /** Query parameter clients use to set the page size. */
    public const PER_PAGE_PARAM = '{$param}';

    public const PER_PAGE_DEFAULT = {$default};

    public const PER_PAGE_MAX = {$max};

    public const PAGE_PARAM = '{$pageParam}';

    /**
     * apiKey => column. Empty means the payload already uses column names.
     *
     * @var array<string, string>
     */
    protected array \$inbound = [];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Rewrite the payload to column names before validation, so rules(),
     * validated() and the service layer all speak the database's language.
     */
    protected function prepareForValidation(): void
    {
        if (\$this->inbound === []) {
            return;
        }

        \$mapped = [];

        foreach (\$this->all() as \$key => \$value) {
            \$mapped[\$this->inbound[\$key] ?? \$key] = \$value;
        }

        \$this->replace(\$mapped);
    }

    /**
     * Report errors under the keys the CLIENT sent. Without this a camelCase
     * client posting assignedAgentId receives an error keyed assigned_agent_id,
     * which no client-side form can bind to.
     */
    protected function failedValidation(Validator \$validator): void
    {
        \$outbound = array_flip(\$this->inbound);
        \$errors = [];

        foreach (\$validator->errors()->messages() as \$field => \$messages) {
            \$errors[\$outbound[\$field] ?? \$field] = \$messages;
        }

        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'The given data was invalid.',
            'errors' => \$errors,
        ], 422));
    }

    /** Page size, clamped to this version's maximum. */
    public function perPage(): int
    {
        \$requested = (int) \$this->input(static::PER_PAGE_PARAM, static::PER_PAGE_DEFAULT);

        return max(1, min(\$requested, static::PER_PAGE_MAX));
    }

    /**
     * The record id from the route, for unique rules that must ignore the row
     * being updated.
     */
    public function routeId(): int|string|null
    {
        \$parameters = \$this->route()?->parameters() ?? [];
        \$first = reset(\$parameters);

        return is_object(\$first) && method_exists(\$first, 'getKey') ? \$first->getKey() : (\$first ?: null);
    }
}

PHP;
    }

    // -----------------------------------------------------------------------
    // Per-model requests
    // -----------------------------------------------------------------------

    protected function buildRequest(ModelMetadata $meta, ApiVersionProfile $profile, string $action): string
    {
        $namespace = $profile->requestNamespace($meta->model);
        $class = $profile->requestClass($meta->model, $action);
        $base = $profile->baseRequestClass();
        $baseNamespace = $profile->baseRequestNamespace();

        $import = $baseNamespace === $namespace ? '' : "use {$baseNamespace}\\{$base};\n";

        if ($action === 'index') {
            return $this->buildIndexRequest($meta, $profile, $namespace, $class, $base, $import);
        }

        $writable = $this->writableColumns($meta, $profile);
        $inbound = $profile->inboundMap(array_column($writable, 'name'));

        $inboundBlock = $this->renderMap($inbound);
        $rulesBlock = $this->renderRules($meta, $writable, $action === 'update');
        $usesRule = str_contains($rulesBlock, 'Rule::');
        $ruleImport = $usesRule ? "use Illuminate\Validation\Rule;\n" : '';

        $verb = $action === 'update' ? 'Update' : 'Create';

        return <<<PHP
<?php

namespace {$namespace};

{$import}{$ruleImport}
/**
 * {$verb} a {$meta->model} via the {$profile->version} API.
 *
 * Rules are keyed by COLUMN name — the base class has already translated the
 * inbound payload — while errors are reported back under the client's own keys.
 */
class {$class} extends {$base}
{
    /**
     * apiKey => column, generated from the {$meta->table} table.
     *
     * @var array<string, string>
     */
    protected array \$inbound = [
{$inboundBlock}
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
{$rulesBlock}
        ];
    }
}

PHP;
    }

    protected function buildIndexRequest(
        ModelMetadata $meta,
        ApiVersionProfile $profile,
        string $namespace,
        string $class,
        string $base,
        string $import,
    ): string {
        $param = $profile->perPageParam();
        $pageParam = $profile->pageParam();
        $sortable = $this->sortableColumns($meta, $profile);
        $sortList = implode(',', $sortable);

        return <<<PHP
<?php

namespace {$namespace};

{$import}
/**
 * Validates listing parameters for {$meta->model} ({$profile->version}).
 *
 * Page size is clamped by the base class rather than trusted, so a client cannot
 * ask for 100000 rows.
 */
class {$class} extends {$base}
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            '{$pageParam}' => ['sometimes', 'integer', 'min:1'],
            '{$param}' => ['sometimes', 'integer', 'min:1', 'max:' . static::PER_PAGE_MAX],
            'sort' => ['sometimes', 'string', 'in:{$sortList}'],
            'direction' => ['sometimes', 'string', 'in:asc,desc'],
        ];
    }

    /** Column to order by, validated against the table's real columns. */
    public function sortColumn(): ?string
    {
        \$sort = \$this->input('sort');

        return is_string(\$sort) && \$sort !== '' ? \$sort : null;
    }

    public function sortDirection(): string
    {
        return strtolower((string) \$this->input('direction', 'asc')) === 'desc' ? 'desc' : 'asc';
    }
}

PHP;
    }

    // -----------------------------------------------------------------------
    // Column selection & rule inference
    // -----------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    protected function writableColumns(ModelMetadata $meta, ApiVersionProfile $profile): array
    {
        return array_values(array_filter($meta->columns, function (array $col) use ($meta, $profile): bool {
            $name = (string) $col['name'];

            return $name !== $meta->primaryKey
                && ! in_array($name, $meta->compositePrimaryKey, true)
                && ! $profile->isReadOnly($name)
                && ! Helpers::isTimestampColumn($name)
                && ! str_contains((string) ($col['extra'] ?? ''), 'auto_increment');
        }));
    }

    /**
     * Sorting is restricted to real columns, minus hidden ones — an unvalidated
     * sort parameter is an injection surface.
     *
     * @return list<string>
     */
    protected function sortableColumns(ModelMetadata $meta, ApiVersionProfile $profile): array
    {
        $columns = array_values(array_filter(
            array_column($meta->columns, 'name'),
            static fn (string $name): bool => ! $profile->isHidden($name),
        ));

        return $columns === [] ? [$meta->primaryKey ?? 'id'] : $columns;
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     */
    protected function renderRules(ModelMetadata $meta, array $columns, bool $forUpdate): string
    {
        $lines = [];

        foreach ($columns as $col) {
            $rules = $this->rulesFor($meta, $col, $forUpdate);

            $rendered = implode(', ', array_map(
                static fn (string $rule): string => str_starts_with($rule, 'Rule::') ? $rule : "'{$rule}'",
                $rules,
            ));

            $lines[] = sprintf("            '%s' => [%s],", $col['name'], $rendered);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $col
     * @return list<string>
     */
    protected function rulesFor(ModelMetadata $meta, array $col, bool $forUpdate): array
    {
        $name = (string) $col['name'];
        $type = strtolower((string) ($col['type'] ?? 'string'));
        $nullable = (bool) ($col['nullable'] ?? false);
        $hasDefault = ($col['default'] ?? null) !== null;

        $rules = [];

        // PUT/PATCH: only validate what was sent.
        if ($forUpdate) {
            $rules[] = 'sometimes';
        }

        if ($nullable) {
            $rules[] = 'nullable';
        } elseif (! $forUpdate && ! $hasDefault) {
            $rules[] = 'required';
        }

        $rules = array_merge($rules, $this->typeRules($name, $type, $col));

        // Uniqueness — ignoring the current row on update.
        if ($this->isUnique($meta, $name)) {
            $rules[] = $forUpdate
                ? "Rule::unique('{$meta->table}', '{$name}')->ignore(\$this->routeId())"
                : "Rule::unique('{$meta->table}', '{$name}')";
        }

        // Referential integrity — the DB enforces it anyway, but a 422 beats a
        // 500 from a constraint violation.
        foreach ($meta->foreignKeys as $fk) {
            if (($fk['column'] ?? null) === $name) {
                $rules[] = sprintf('exists:%s,%s', $fk['referenced_table'], $fk['referenced_column'] ?? 'id');

                break;
            }
        }

        return array_values(array_unique($rules));
    }

    /**
     * @param  array<string, mixed>  $col
     * @return list<string>
     */
    protected function typeRules(string $name, string $type, array $col): array
    {
        $lower = strtolower($name);
        $length = $col['length'] ?? $col['character_maximum_length'] ?? null;

        // Name-based rules first: an email column is a string, but 'email' is the
        // rule that actually matters.
        if (str_contains($lower, 'email')) {
            return array_filter(['email:rfc', 'string', $length ? 'max:'.(int) $length : null]);
        }

        if ($lower === 'password') {
            return ['string', 'min:8', 'max:255'];
        }

        if (str_contains($lower, 'url') || str_contains($lower, 'website')) {
            return ['url', 'string', 'max:2048'];
        }

        return match (true) {
            str_contains($type, 'uuid') => ['uuid'],
            str_contains($type, 'json') => ['array'],
            str_contains($type, 'bool') || $type === 'tinyint(1)' => ['boolean'],
            str_contains($type, 'int') => ['integer'],
            (bool) preg_match('/(decimal|numeric|float|double|real|money)/', $type) => ['numeric'],
            str_contains($type, 'timestamp') || str_contains($type, 'datetime') => ['date'],
            $type === 'date' => ['date_format:Y-m-d'],
            str_contains($type, 'time') => ['date_format:H:i:s'],
            str_contains($type, 'text') => ['string'],
            default => array_filter(['string', $length ? 'max:'.(int) $length : 'max:255']),
        };
    }

    protected function isUnique(ModelMetadata $meta, string $column): bool
    {
        foreach ($meta->uniqueConstraints as $constraint) {
            $columns = array_column($constraint['columns'] ?? [], 'name');

            // Single-column constraints only: a composite unique needs a rule
            // across several fields, which is a hand-written concern.
            if (count($columns) === 1 && $columns[0] === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $map
     */
    protected function renderMap(array $map): string
    {
        $lines = [];

        foreach ($map as $apiKey => $column) {
            $lines[] = sprintf("        '%s' => '%s',", $apiKey, $column);
        }

        return implode("\n", $lines);
    }
}
