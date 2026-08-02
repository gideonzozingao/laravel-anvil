<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

use Zuqongtech\LaravelAnvil\Exceptions\AmbiguousModelException;
use Zuqongtech\LaravelAnvil\Exceptions\ModelNotRegisteredException;

/**
 * Authoritative table -> generated model mapping for a generation run.
 *
 * Two rules make this class trustworthy, and both are load-bearing:
 *
 *  1. It NEVER derives a namespace. It only records the FQCN the model phase
 *     actually wrote, or reads one back off disk. Any code path that computes
 *     "App\Models" . '\\' . Str::studly($schema) . '\\' . $model a second time is
 *     a second source of truth, and the two will drift.
 *
 *  2. A miss is an exception, not a fallback. `App\Models\User` is only ever
 *     correct by accident in a multi-schema database.
 *
 * The class is framework-free on purpose (paths and contents are passed in) so
 * it can be unit tested without booting Laravel, matching the docs-sync core.
 */
final class ModelRegistry
{
    public const MANIFEST_VERSION = 1;

    /** @var array<string, ModelReference> composite key => reference */
    private array $models = [];

    /** @var array<string, array<int, string>> lowercased bare table => composite keys */
    private array $byTable = [];

    private ?string $connection = null;

    /**
     * The connection's default schema (e.g. "public", "dbo"). Tables in this
     * schema are keyed identically to tables with no schema at all, so a model
     * registered as {schema: "public", table: "tenants"} during generation and the
     * same model rediscovered from a bare `protected $table = 'tenants'` resolve
     * to one entry instead of two.
     */
    private ?string $defaultSchema = null;

    private ?string $rootNamespace = null;

    private ?string $generatedAt = null;

    /**
     * Record a generated model. Later registrations for the same schema+table win,
     * so re-running the model phase refreshes rather than duplicates.
     */
    public function register(ModelReference $reference): self
    {
        $key = $this->key($reference->table(), $reference->schema());
        $tableKey = strtolower($reference->table());

        if (! isset($this->models[$key])) {
            $this->byTable[$tableKey][] = $key;
        }

        $this->models[$key] = $reference;

        return $this;
    }

    /**
     * Convenience registration from the values the model phase already has in hand.
     */
    public function registerModel(
        string $fqcn,
        string $table,
        ?string $schema = null,
        ?string $qualifiedTable = null,
    ): self {
        return $this->register(new ModelReference($fqcn, $table, $schema, $qualifiedTable));
    }

    /**
     * Look up a model, returning null when it is not registered.
     *
     * When $schema is null the lookup falls back to a bare-table match, but only
     * if that table is unambiguous across schemas.
     */
    public function find(string $table, ?string $schema = null): ?ModelReference
    {
        $bare = $this->bareTable($table);
        $schema ??= $this->schemaFromQualifiedTable($table);
        $schema = $this->normaliseSchema($schema);

        if ($schema !== null) {
            return $this->models[$this->key($bare, $schema)] ?? null;
        }

        $keys = $this->byTable[strtolower($bare)] ?? [];

        if ($keys === []) {
            return null;
        }

        if (count($keys) > 1) {
            throw AmbiguousModelException::for(
                $bare,
                array_map(fn (string $k): string => $this->models[$k]->fqcn(), $keys),
            );
        }

        return $this->models[$keys[0]];
    }

    /**
     * Look up a model or fail loudly. This is what generators should call.
     */
    public function resolve(string $table, ?string $schema = null): ModelReference
    {
        return $this->find($table, $schema)
            ?? throw ModelNotRegisteredException::for($this->bareTable($table), $schema);
    }

    public function has(string $table, ?string $schema = null): bool
    {
        try {
            return $this->find($table, $schema) !== null;
        } catch (AmbiguousModelException) {
            return true;
        }
    }

    /**
     * Which of the given {schema, table} pairs have no generated model yet.
     *
     * @param  array<int, array{schema?: string|null, table: string}>  $pairs
     * @return array<int, string> human-readable "schema.table" identifiers
     */
    public function missingFor(array $pairs): array
    {
        $missing = [];

        foreach ($pairs as $pair) {
            $table = $pair['table'] ?? '';

            if (! is_string($table) || $table === '') {
                continue;
            }

            $schema = $pair['schema'] ?? null;
            $schema = is_string($schema) && $schema !== '' ? $schema : null;

            if (! $this->has($table, $schema)) {
                $missing[] = $this->normaliseSchema($schema) !== null ? $schema.'.'.$table : $table;
            }
        }

        return $missing;
    }

    /**
     * @return array<int, ModelReference>
     */
    public function all(): array
    {
        return array_values($this->models);
    }

    /**
     * @return array<int, ModelReference>
     */
    public function forSchema(?string $schema): array
    {
        $normalised = $this->normaliseSchema($schema);
        $needle = $normalised === null ? null : strtolower($normalised);

        return array_values(array_filter(
            $this->models,
            function (ModelReference $ref) use ($needle): bool {
                $own = $this->normaliseSchema($ref->schema());
                $own = $own === null ? null : strtolower($own);

                return $own === $needle;
            },
        ));
    }

    public function count(): int
    {
        return count($this->models);
    }

    public function isEmpty(): bool
    {
        return $this->models === [];
    }

    /**
     * Fold another registry into this one; the other registry's entries win.
     */
    public function merge(self $other): self
    {
        foreach ($other->all() as $reference) {
            $this->register($reference);
        }

        $this->connection ??= $other->connection;
        $this->rootNamespace ??= $other->rootNamespace;
        $this->defaultSchema ??= $other->defaultSchema;

        return $this;
    }

    public function setConnection(?string $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    public function connection(): ?string
    {
        return $this->connection;
    }

    /**
     * Set the connection's default schema. Call this before registering or
     * resolving anything: it changes how keys are computed.
     */
    public function setDefaultSchema(?string $defaultSchema): self
    {
        $normalised = $defaultSchema === null || $defaultSchema === '' ? null : $defaultSchema;

        if ($normalised === $this->defaultSchema) {
            return $this;
        }

        $this->defaultSchema = $normalised;

        // Existing entries were keyed under the old default; rebuild rather than
        // leave a mix of conventions in the index.
        $existing = $this->all();
        $this->models = [];
        $this->byTable = [];

        foreach ($existing as $reference) {
            $this->register($reference);
        }

        return $this;
    }

    public function defaultSchema(): ?string
    {
        return $this->defaultSchema;
    }

    public function setRootNamespace(?string $rootNamespace): self
    {
        $this->rootNamespace = $rootNamespace === null ? null : trim($rootNamespace, '\\');

        return $this;
    }

    public function rootNamespace(): ?string
    {
        return $this->rootNamespace;
    }

    public function generatedAt(): ?string
    {
        return $this->generatedAt;
    }

    /**
     * Serialise to the manifest array written between runs.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $models = array_map(
            static fn (ModelReference $ref): array => $ref->toArray(),
            array_values($this->models),
        );

        usort($models, static fn (array $a, array $b): int => strcasecmp($a['fqcn'], $b['fqcn']));

        return [
            'version' => self::MANIFEST_VERSION,
            'generated_at' => $this->generatedAt ?? gmdate('c'),
            'connection' => $this->connection,
            'default_schema' => $this->defaultSchema,
            'root_namespace' => $this->rootNamespace,
            'models' => $models,
        ];
    }

    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $registry = new self;

        $version = $data['version'] ?? null;

        if ($version !== null && (! is_int($version) || $version > self::MANIFEST_VERSION)) {
            throw new \RuntimeException(sprintf(
                'Model manifest version [%s] is newer than this version of Anvil understands (%d). Regenerate models.',
                is_scalar($version) ? (string) $version : gettype($version),
                self::MANIFEST_VERSION,
            ));
        }

        $connection = $data['connection'] ?? null;
        $root = $data['root_namespace'] ?? null;
        $generatedAt = $data['generated_at'] ?? null;

        $defaultSchema = $data['default_schema'] ?? null;

        $registry->connection = is_string($connection) && $connection !== '' ? $connection : null;
        $registry->defaultSchema = is_string($defaultSchema) && $defaultSchema !== '' ? $defaultSchema : null;
        $registry->rootNamespace = is_string($root) && $root !== '' ? trim($root, '\\') : null;
        $registry->generatedAt = is_string($generatedAt) && $generatedAt !== '' ? $generatedAt : null;

        $models = $data['models'] ?? [];

        throw_unless(is_array($models), \RuntimeException::class, 'Model manifest "models" key must be an array.');

        foreach ($models as $model) {
            if (is_array($model)) {
                $registry->register(ModelReference::fromArray($model));
            }
        }

        return $registry;
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        throw_unless(is_array($decoded), \RuntimeException::class, 'Model manifest is not a JSON object.');

        return self::fromArray($decoded);
    }

    /**
     * Composite key: schema (or the empty string for the default schema) + table,
     * both lowercased, because catalog identifiers are case-insensitive in practice.
     */
    private function key(string $table, ?string $schema): string
    {
        return strtolower(($this->normaliseSchema($schema) ?? '').'.'.$table);
    }

    /**
     * Collapse the connection's default schema onto null so both spellings of
     * "the default schema" produce one key.
     */
    private function normaliseSchema(?string $schema): ?string
    {
        if ($schema === null || $schema === '') {
            return null;
        }

        if ($this->defaultSchema !== null && strcasecmp($schema, $this->defaultSchema) === 0) {
            return null;
        }

        return $schema;
    }

    /**
     * Accept either "users" or "core.users" as a lookup key.
     */
    private function bareTable(string $table): string
    {
        $pos = strrpos($table, '.');

        return $pos === false ? $table : substr($table, $pos + 1);
    }

    private function schemaFromQualifiedTable(string $table): ?string
    {
        $pos = strrpos($table, '.');

        if ($pos === false || $pos === 0) {
            return null;
        }

        return substr($table, 0, $pos);
    }
}
