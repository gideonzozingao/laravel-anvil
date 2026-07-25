<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

use Illuminate\Support\Str;

/**
 * Resolves the settings for one API version.
 *
 * Config shape — anvil.api.defaults is the baseline, anvil.api.versions.{vN}
 * deep-merges over it, so a version only states what it changes:
 *
 *   'api' => [
 *       'defaults' => [
 *           'pagination' => ['default' => 15, 'max' => 100, 'param' => 'per_page'],
 *           'case'       => ['request' => 'snake', 'response' => 'snake'],
 *           'hidden'     => ['password', 'remember_token'],
 *       ],
 *       'versions' => [
 *           'v1' => [],                                        // pure defaults
 *           'v2' => [
 *               'case'       => ['request' => 'camel', 'response' => 'camel'],
 *               'pagination' => ['default' => 25, 'param' => 'perPage'],
 *               'hidden'     => ['password', 'remember_token', 'internal_notes'],
 *           ],
 *       ],
 *   ],
 *
 * This object is the single authority for those values. The form requests, the
 * API resources, the controllers AND the OpenAPI schemas all read from it —
 * otherwise v2 documents snake_case while its resources emit camelCase, and the
 * spec becomes a lie that nobody notices until a client integrates.
 */
final readonly class ApiVersionProfile
{
    private function __construct(
        public string $version,
        private array $settings,
    ) {}

    public static function for(int|string|null $version = null): self
    {
        $normalised = OpenApiLocator::normaliseVersion(
            $version ?? config('anvil.openapi.api_version', config('anvil.api.version', 'v1')),
        );

        return new self($normalised, self::mergeDeep(
            (array) config('anvil.api.defaults', []),
            (array) config("anvil.api.versions.{$normalised}", []),
        ));
    }

    /** Dot-notation access into the merged settings. */
    public function get(string $key, mixed $default = null): mixed
    {
        $cursor = $this->settings;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return $default;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    // -----------------------------------------------------------------------
    // Pagination
    // -----------------------------------------------------------------------

    public function perPageDefault(): int
    {
        return max(1, (int) $this->get('pagination.default', config('anvil.api.pagination', 15)));
    }

    public function perPageMax(): int
    {
        return max($this->perPageDefault(), (int) $this->get('pagination.max', 100));
    }

    /**
     * The query parameter clients send. Defaults to the response casing applied
     * to "per_page", so a camelCase version gets ?perPage=25 without having to
     * say so.
     */
    public function perPageParam(): string
    {
        $configured = $this->get('pagination.param');

        return is_string($configured) && $configured !== ''
            ? $configured
            : KeyCase::convert('per_page', $this->responseCase());
    }

    public function pageParam(): string
    {
        $configured = $this->get('pagination.page_param');

        return is_string($configured) && $configured !== '' ? $configured : 'page';
    }

    // -----------------------------------------------------------------------
    // Key casing
    // -----------------------------------------------------------------------

    /** Casing clients SEND. Internally everything stays snake_case (column names). */
    public function requestCase(): string
    {
        return KeyCase::normalise($this->get('case.request', KeyCase::SNAKE));
    }

    /** Casing clients RECEIVE. */
    public function responseCase(): string
    {
        return KeyCase::normalise($this->get('case.response', KeyCase::SNAKE));
    }

    public function transformsRequest(): bool
    {
        return $this->requestCase() !== KeyCase::SNAKE;
    }

    public function transformsResponse(): bool
    {
        return $this->responseCase() !== KeyCase::SNAKE;
    }

    /**
     * apiKey => column, for the request side. Built from the real column list so
     * the mapping is exact rather than a lossy runtime round-trip
     * (addressLine1 → address_line1 ≠ address_line_1).
     *
     * @param  list<string>  $columns
     * @return array<string, string>
     */
    public function inboundMap(array $columns): array
    {
        return KeyCase::map($columns, $this->requestCase());
    }

    /**
     * column => apiKey, for the response side.
     *
     * @param  list<string>  $columns
     * @return array<string, string>
     */
    public function outboundMap(array $columns): array
    {
        $map = [];

        foreach ($columns as $column) {
            $map[$column] = KeyCase::convert($column, $this->responseCase());
        }

        return $map;
    }

    // -----------------------------------------------------------------------
    // Hidden / excluded fields
    // -----------------------------------------------------------------------

    /**
     * Columns never present in a response. Falls back to the package-wide
     * hidden_field_patterns so a version that says nothing still hides secrets.
     *
     * @return list<string>
     */
    public function hiddenFields(): array
    {
        $configured = $this->get('hidden');

        if (is_array($configured)) {
            return array_values(array_map(strval(...), $configured));
        }

        return array_values(array_map(strval(...), (array) config('anvil.hidden_field_patterns', [])));
    }

    /**
     * Substring match, matching how hidden_field_patterns is written
     * ('token' catching both api_token and remember_token).
     */
    public function isHidden(string $column): bool
    {
        $column = strtolower($column);

        foreach ($this->hiddenFields() as $pattern) {
            $pattern = strtolower($pattern);

            if ($column === $pattern || str_contains($column, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Columns a client may never WRITE, even though they might be readable.
     * Distinct from hidden: 'password' is writable on store but never returned.
     *
     * @return list<string>
     */
    public function readOnlyFields(): array
    {
        return array_values(array_map(strval(...), (array) $this->get('read_only', [
            'id',
            'created_at',
            'updated_at',
            'deleted_at',
        ])));
    }

    public function isReadOnly(string $column): bool
    {
        return in_array($column, $this->readOnlyFields(), true);
    }

    // -----------------------------------------------------------------------
    // Namespaces & paths
    // -----------------------------------------------------------------------

    /** 'V1' — the StudlyCase segment used in namespaces. */
    public function segment(): string
    {
        return 'V'.ltrim($this->version, 'vV');
    }

    /**
     * Whether per-model classes get their own subdirectory:
     *
     *   true  → App\Http\Requests\Api\V1\User\StoreRequest
     *   false → App\Http\Requests\Api\V1\StoreUserRequest
     *
     * Worth leaving on: 32 tables is 64 request classes in one directory.
     */
    public function groupsByModel(): bool
    {
        return (bool) $this->get('group_by_model', true);
    }

    public function requestNamespace(?string $model = null): string
    {
        $base = $this->join((string) $this->get('namespaces.requests', 'App\\Http\\Requests\\Api'), $this->segment());

        return $model !== null && $this->groupsByModel() ? $this->join($base, $model) : $base;
    }

    public function resourceNamespace(): string
    {
        return $this->join((string) $this->get('namespaces.resources', 'App\\Http\\Resources\\Api'), $this->segment());
    }

    public function controllerNamespace(): string
    {
        return $this->join((string) $this->get('namespaces.controllers', 'App\\Http\\Controllers\\Api'), $this->segment());
    }

    /** Class name for a request, honouring group_by_model. */
    public function requestClass(string $model, string $action): string
    {
        return $this->groupsByModel()
            ? Str::studly($action).'Request'
            : Str::studly($action).$model.'Request';
    }

    public function resourceClass(string $model): string
    {
        return $model.'Resource';
    }

    public function collectionClass(string $model): string
    {
        return $model.'Collection';
    }

    /** The shared base classes each version gets, so per-model files stay thin. */
    public function baseRequestClass(): string
    {
        return 'ApiFormRequest';
    }

    public function baseResourceClass(): string
    {
        return 'ApiResource';
    }

    public function baseRequestNamespace(): string
    {
        return $this->join((string) $this->get('namespaces.requests', 'App\\Http\\Requests\\Api'), $this->segment());
    }

    /**
     * Map a PSR-4 namespace under App\ onto a filesystem path.
     */
    public function pathFor(string $namespace, string $class): string
    {
        $relative = ltrim($namespace, '\\');

        if ($relative === 'App') {
            $relative = '';
        } elseif (str_starts_with($relative, 'App\\')) {
            $relative = substr($relative, 4);
        }

        $relative = trim(str_replace('\\', '/', $relative), '/');

        return app_path(($relative === '' ? '' : $relative.'/').$class.'.php');
    }

    private function join(string ...$parts): string
    {
        return implode('\\', array_map(
            static fn (string $part): string => trim($part, '\\'),
            array_filter($parts, static fn (string $part): bool => trim($part, '\\') !== ''),
        ));
    }

    /**
     * Recursive merge where scalars and lists from the override win outright —
     * a version's 'hidden' list REPLACES the default rather than appending, so a
     * version can also hide less.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private static function mergeDeep(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && ! array_is_list($value)) {
                $base[$key] = self::mergeDeep($base[$key], $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
