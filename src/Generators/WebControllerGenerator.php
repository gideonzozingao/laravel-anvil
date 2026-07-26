<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Generators;

use Illuminate\Support\Str;
use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\Helpers;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates a full resource controller for the web scaffold (--web).
 *
 * Placed under App\Http\Controllers\Web\{Model}Controller so it never collides
 * with the plain ControllerGenerator (App\Http\Controllers) or the versioned API
 * controllers (App\Http\Controllers\Api\V{n}).
 *
 * Unlike the API controller it implements the full resource set including the form
 * pages — index, create, store, show, edit, update, destroy — returns Blade views,
 * and redirects with flash messages on writes. Writes are delegated to the model's
 * Service (shared with the API scaffold); validation uses the same
 * StoreXxx / UpdateXxx form requests.
 *
 * The listing supports search, sorting and page size straight from the query
 * string, all validated:
 *
 *   - page size is clamped to a maximum; an unclamped ?per_page= is an invitation
 *     to load the whole table
 *   - the sort column is checked against a generated whitelist, because a raw
 *     query parameter reaching orderBy() is an injection surface
 *   - search runs over text columns only, with LIKE wildcards escaped, and uses
 *     ILIKE on Postgres so it is case-insensitive there too
 *
 * restore / forceDelete actions are added for SoftDeletes models.
 */
final class WebControllerGenerator implements Generator
{
    private const SENSITIVE = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    public function supports(GenerationOptions $options): bool
    {
        return $options->web ?? false;
    }

    public function getName(): string
    {
        return 'WebController';
    }

    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $controllerName = $meta->model.'Controller';
        $dir = app_path('Http/Controllers/Web');
        $path = "{$dir}/{$controllerName}.php";
        $exists = file_exists($path);

        if ($exists && ! $options->force) {
            return [
                'type' => $this->getName(),
                'name' => $controllerName,
                'path' => $path,
                'status' => 'skipped',
                'reason' => 'already exists',
            ];
        }

        if ($options->dryRun) {
            return [
                'type' => $this->getName(),
                'name' => $controllerName,
                'path' => $path,
                'status' => 'dry-run',
                'action' => $exists ? 'would overwrite' : 'would create',
            ];
        }

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $this->build($meta, $options));

        return [
            'type' => $this->getName(),
            'name' => $controllerName,
            'path' => $path,
            'status' => 'success',
            // Captured before the write, so a new file is not reported as an
            // overwrite.
            'action' => $exists ? 'overwritten' : 'created',
        ];
    }

    protected function build(ModelMetadata $meta, GenerationOptions $options): string
    {
        if ($options->isLivewire()) {
            return $this->buildLivewire($meta, $options);
        }

        $model = $meta->model;
        $service = $model.'Service';
        $storeReq = 'Store'.$model.'Request';
        $updateReq = 'Update'.$model.'Request';
        $var = lcfirst($model);
        $pluralVar = lcfirst(Str::pluralStudly($model));
        $slug = Helpers::modelToRouteName($model);
        $title = Str::headline($model);
        $fullModel = trim($options->getNamespace(), '\\').'\\'.$model;

        $perPage = (int) config('anvil.web.per_page', 15);
        $perPageOptions = $this->phpList(array_map(intval(...), (array) config('anvil.web.per_page_options', [10, 15, 25, 50, 100])), false);
        $searchable = $this->phpList($this->searchableColumns($meta));
        $sortable = $this->phpList($this->sortableColumns($meta));
        $defaultSort = $this->defaultSort($meta);

        $softDeleteMethods = $meta->softDeletes
            ? $this->softDeleteMethods($model, $slug, $title)
            : '';

        return <<<PHP
<?php

namespace App\Http\Controllers\Web;

use {$fullModel};
use App\Http\Controllers\Controller;
use App\Http\Requests\\{$storeReq};
use App\Http\Requests\\{$updateReq};
use App\Services\\{$service};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class {$model}Controller extends Controller
{
    /** Default rows per page. */
    private const PER_PAGE = {$perPage};

    /** Page sizes a client may choose; anything else falls back to PER_PAGE. */
    private const PER_PAGE_OPTIONS = {$perPageOptions};

    /**
     * Columns the search box scans. Text columns only.
     *
     * @var list<string>
     */
    private const SEARCHABLE = {$searchable};

    /**
     * Columns that may be sorted. A whitelist, not a suggestion: an unchecked
     * ?sort= value reaching orderBy() would be an injection surface.
     *
     * @var list<string>
     */
    private const SORTABLE = {$sortable};

    public function __construct(
        protected readonly {$service} \$service,
    ) {}

    /**
     * Paginated, searchable, sortable listing of {$model} records.
     */
    public function index(Request \$request): View
    {
        \${$pluralVar} = \$this->query(\$request)->paginate(\$this->perPage(\$request))->withQueryString();

        return view('{$slug}.index', compact('{$pluralVar}'));
    }

    /**
     * Show the form for creating a new {$model}.
     */
    public function create(): View
    {
        return view('{$slug}.create');
    }

    /**
     * Store a newly created {$model}.
     */
    public function store({$storeReq} \$request): RedirectResponse
    {
        \${$var} = \$this->service->create(\$request->validated());

        return redirect()
            ->route('{$slug}.show', \${$var})
            ->with('success', '{$title} created.');
    }

    /**
     * Display the specified {$model}.
     */
    public function show(int|string \$id): View
    {
        \${$var} = \$this->service->findOrFail(\$id);

        return view('{$slug}.show', compact('{$var}'));
    }

    /**
     * Show the form for editing the specified {$model}.
     */
    public function edit(int|string \$id): View
    {
        \${$var} = \$this->service->findOrFail(\$id);

        return view('{$slug}.edit', compact('{$var}'));
    }

    /**
     * Update the specified {$model}.
     */
    public function update({$updateReq} \$request, int|string \$id): RedirectResponse
    {
        \${$var} = \$this->service->update(\$id, \$request->validated());

        return redirect()
            ->route('{$slug}.show', \${$var})
            ->with('success', '{$title} updated.');
    }

    /**
     * Remove the specified {$model}.
     */
    public function destroy(int|string \$id): RedirectResponse
    {
        \$this->service->delete(\$id);

        return redirect()
            ->route('{$slug}.index')
            ->with('success', '{$title} deleted.');
    }{$softDeleteMethods}

    /**
     * Build the listing query.
     *
     * Reads go straight to the model; writes stay in the service. Keeping the
     * query here means search and sort do not depend on the service exposing a
     * filter API.
     */
    protected function query(Request \$request): Builder
    {
        \$search = trim((string) \$request->query('q', ''));

        return {$model}::query()
            ->when(\$search !== '' && self::SEARCHABLE !== [], function (Builder \$query) use (\$search): void {
                // ILIKE on Postgres; LIKE is case-sensitive there, unlike MySQL.
                \$operator = \$query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

                // Escape the LIKE metacharacters so a user searching for "50%"
                // does not match everything.
                \$term = '%' . addcslashes(\$search, '%_\\\\') . '%';

                \$query->where(function (Builder \$sub) use (\$operator, \$term): void {
                    foreach (self::SEARCHABLE as \$column) {
                        \$sub->orWhere(\$column, \$operator, \$term);
                    }
                });
            })
            ->orderBy(\$this->sortColumn(\$request), \$this->sortDirection(\$request));
    }

    /**
     * Requested page size, restricted to the configured options.
     */
    protected function perPage(Request \$request): int
    {
        \$requested = (int) \$request->query('per_page', (string) self::PER_PAGE);

        return in_array(\$requested, self::PER_PAGE_OPTIONS, true) ? \$requested : self::PER_PAGE;
    }

    /**
     * Requested sort column, or the default when it is not whitelisted.
     */
    protected function sortColumn(Request \$request): string
    {
        \$column = (string) \$request->query('sort', '{$defaultSort}');

        return in_array(\$column, self::SORTABLE, true) ? \$column : '{$defaultSort}';
    }

    protected function sortDirection(Request \$request): string
    {
        return strtolower((string) \$request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
    }
}

PHP;
    }

    protected function softDeleteMethods(string $model, string $slug, string $title): string
    {
        return <<<PHP


    /**
     * Restore a soft-deleted {$model}.
     */
    public function restore(int|string \$id): RedirectResponse
    {
        \$this->service->restore(\$id);

        return redirect()
            ->route('{$slug}.index')
            ->with('success', '{$title} restored.');
    }

    /**
     * Permanently delete a {$model}.
     */
    public function forceDelete(int|string \$id): RedirectResponse
    {
        \$this->service->forceDelete(\$id);

        return redirect()
            ->route('{$slug}.index')
            ->with('success', '{$title} permanently deleted.');
    }
PHP;
    }

    /**
     * Livewire stack: the controller only renders the Blade wrappers that mount
     * the components. All writes happen inside the components, so there are no
     * store/update/destroy actions here (and no write routes).
     */
    protected function buildLivewire(ModelMetadata $meta, GenerationOptions $options): string
    {
        $model = $meta->model;
        $slug = Helpers::modelToRouteName($model);

        return <<<PHP
<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Web (Livewire) controller for {$model}.
 *
 * Only the GET endpoints live here — each renders a thin Blade view that mounts
 * the matching Livewire component. Creating, updating and deleting {$model}
 * records happens inside those components (App\Livewire\\...), so this controller
 * has no store/update/destroy actions.
 */
class {$model}Controller extends Controller
{
    /**
     * List {$model} records (renders <livewire:{$slug}.index/>).
     */
    public function index(): View
    {
        return view('{$slug}.index');
    }

    /**
     * Show the create form (renders <livewire:{$slug}.form/>).
     */
    public function create(): View
    {
        return view('{$slug}.create');
    }

    /**
     * Show a single {$model} (renders <livewire:{$slug}.show :record-id/>).
     */
    public function show(int|string \$id): View
    {
        return view('{$slug}.show', ['recordId' => \$id]);
    }

    /**
     * Show the edit form (renders <livewire:{$slug}.form :record-id/>).
     */
    public function edit(int|string \$id): View
    {
        return view('{$slug}.edit', ['recordId' => \$id]);
    }
}

PHP;
    }

    // -----------------------------------------------------------------------
    // Column selection
    // -----------------------------------------------------------------------

    /**
     * @return list<string>
     */
    protected function searchableColumns(ModelMetadata $meta): array
    {
        $skip = array_merge(['created_at', 'updated_at', 'deleted_at', $meta->primaryKey], self::SENSITIVE);
        $names = [];

        foreach ($meta->columns as $col) {
            $name = (string) $col['name'];

            if (in_array($name, $skip, true)) {
                continue;
            }

            $type = strtolower((string) preg_replace('/\(.*\)/', '', (string) ($col['type'] ?? '')));

            if (in_array(trim($type), [
                'char',
                'character',
                'varchar',
                'character varying',
                'string',
                'text',
                'tinytext',
                'mediumtext',
                'longtext',
                'citext',
                'clob',
                'nchar',
                'nvarchar',
            ], true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Everything except sensitive columns can be sorted — including timestamps,
     * which is usually what a user wants.
     *
     * @return list<string>
     */
    protected function sortableColumns(ModelMetadata $meta): array
    {
        $names = array_values(array_filter(
            array_map(strval(...), array_column($meta->columns, 'name')),
            static fn (string $name): bool => ! in_array($name, self::SENSITIVE, true),
        ));

        return $names === [] ? [$meta->primaryKey ?? 'id'] : $names;
    }

    protected function defaultSort(ModelMetadata $meta): string
    {
        $columns = array_map(strval(...), array_column($meta->columns, 'name'));

        if (in_array('created_at', $columns, true)) {
            return 'created_at';
        }

        if (is_string($meta->primaryKey) && in_array($meta->primaryKey, $columns, true)) {
            return $meta->primaryKey;
        }

        return $columns[0] ?? 'id';
    }

    /**
     * Render a PHP array literal.
     *
     * @param  list<string|int>  $items
     */
    protected function phpList(array $items, bool $quote = true): string
    {
        if ($items === []) {
            return '[]';
        }

        $rendered = array_map(
            static fn ($item): string => $quote ? "'".addslashes((string) $item)."'" : (string) (int) $item,
            $items,
        );

        return '['.implode(', ', $rendered).']';
    }
}
