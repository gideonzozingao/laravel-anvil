<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

/**
 * Records what the schema looked like the last time Anvil ran, so a later run can
 * answer the question a schema-driven tool should answer instantly: what changed?
 *
 * Stored at .anvil/manifest.json — commit it. The value is in the delta between
 * what a teammate generated and what your database looks like now.
 *
 *   {
 *     "version": 1,
 *     "generated_at": "2026-07-25T09:14:00+00:00",
 *     "connection": "pgsql",
 *     "tables": {
 *       "vehicles": {
 *         "fingerprint": "9f2c…",
 *         "columns": { "id": "bigint|notnull", "vin": "varchar(17)|null" },
 *         "foreign_keys": ["make_id->vehicle_makes.id"],
 *         "primary_key": "id",
 *         "soft_deletes": true,
 *         "artifacts": ["app/Models/Vehicle.php", "openapi/v1/schemas/Vehicle.yaml"]
 *       }
 *     }
 *   }
 *
 * Fingerprints cover only what codegen depends on. A column comment changing does
 * not invalidate a model; a column type changing does.
 */
final class SchemaManifest
{
    public const VERSION = 1;

    private function __construct(
        /** @var array<string, mixed> */
        private array $data
    ) {}

    public static function path(): string
    {
        return base_path('.anvil/manifest.json');
    }

    public static function load(): self
    {
        $path = self::path();

        if (! is_file($path)) {
            return self::empty();
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        if (! is_array($decoded) || ($decoded['version'] ?? null) !== self::VERSION) {
            // A manifest from an older format is not worth migrating: the next
            // run rewrites it, and treating everything as "new" is the safe read.
            return self::empty();
        }

        return new self($decoded);
    }

    public static function empty(): self
    {
        return new self([
            'version' => self::VERSION,
            'generated_at' => null,
            'connection' => null,
            'tables' => [],
        ]);
    }

    public function exists(): bool
    {
        return $this->data['generated_at'] !== null;
    }

    public function generatedAt(): ?string
    {
        return $this->data['generated_at'];
    }

    public function connection(): ?string
    {
        return $this->data['connection'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function tables(): array
    {
        return $this->data['tables'] ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function table(string $table): ?array
    {
        return $this->data['tables'][$table] ?? null;
    }

    // -----------------------------------------------------------------------
    // Recording
    // -----------------------------------------------------------------------

    public function record(ModelMetadata $meta): void
    {
        $entry = self::describe($meta);

        // Artifacts are recorded separately and must survive a re-describe.
        $entry['artifacts'] = $this->data['tables'][$meta->table]['artifacts'] ?? [];

        $this->data['tables'][$meta->table] = $entry;
    }

    /**
     * @param  list<string>  $paths  absolute or base-relative
     */
    public function recordArtifacts(string $table, array $paths): void
    {
        $relative = array_values(array_unique(array_map(
            static fn (string $path): string => ltrim(str_replace(base_path(), '', $path), '/\\'),
            $paths,
        )));

        sort($relative);

        $this->data['tables'][$table]['artifacts'] = $relative;
    }

    public function forget(string $table): void
    {
        unset($this->data['tables'][$table]);
    }

    public function save(?string $connection = null): bool
    {
        $this->data['generated_at'] = date('c');
        $this->data['connection'] = $connection ?? $this->data['connection'];

        ksort($this->data['tables']);

        $dir = dirname(self::path());

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return false;
        }

        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json !== false && file_put_contents(self::path(), $json."\n") !== false;
    }

    // -----------------------------------------------------------------------
    // Describing & fingerprinting
    // -----------------------------------------------------------------------

    /**
     * The subset of a table's shape that codegen actually depends on.
     *
     * @return array<string, mixed>
     */
    public static function describe(ModelMetadata $meta): array
    {
        $columns = [];

        foreach ($meta->columns as $column) {
            $name = (string) $column['name'];

            $columns[$name] = implode('|', array_filter([
                strtolower((string) ($column['type'] ?? 'unknown')),
                ($column['nullable'] ?? false) ? 'null' : 'notnull',
                ($column['default'] ?? null) !== null ? 'default' : null,
            ]));
        }

        ksort($columns);

        $foreignKeys = [];

        foreach ($meta->foreignKeys as $fk) {
            $foreignKeys[] = sprintf(
                '%s->%s.%s',
                $fk['column'] ?? '?',
                $fk['referenced_table'] ?? '?',
                $fk['referenced_column'] ?? 'id',
            );
        }

        sort($foreignKeys);

        $unique = [];

        foreach ($meta->uniqueConstraints as $constraint) {
            $unique[] = implode(',', array_column($constraint['columns'] ?? [], 'name'));
        }

        sort($unique);

        $entry = [
            'columns' => $columns,
            'foreign_keys' => $foreignKeys,
            'unique' => $unique,
            'primary_key' => $meta->primaryKey,
            'composite_key' => $meta->compositePrimaryKey,
            'soft_deletes' => $meta->softDeletes,
            'timestamps' => $meta->timestamps,
            'schema' => $meta->schema,
        ];

        $entry['fingerprint'] = substr(hash('sha256', (string) json_encode($entry)), 0, 16);

        return $entry;
    }

    // -----------------------------------------------------------------------
    // Diffing
    // -----------------------------------------------------------------------

    /**
     * Compare the recorded schema against the live one.
     *
     * @param  array<string, ModelMetadata>  $current  keyed by table
     * @return array{
     *     added: list<string>,
     *     removed: list<string>,
     *     changed: array<string, array{columns: array<string, array{0: ?string, 1: ?string}>, keys: list<string>, flags: list<string>}>,
     *     unchanged: list<string>
     * }
     */
    public function diff(array $current): array
    {
        $recorded = $this->tables();

        $added = array_values(array_diff(array_keys($current), array_keys($recorded)));
        $removed = array_values(array_diff(array_keys($recorded), array_keys($current)));

        sort($added);
        sort($removed);

        $changed = [];
        $unchanged = [];

        foreach ($current as $table => $meta) {
            if (! isset($recorded[$table])) {
                continue;
            }

            $now = self::describe($meta);
            $before = $recorded[$table];

            if (($before['fingerprint'] ?? null) === $now['fingerprint']) {
                $unchanged[] = $table;

                continue;
            }

            $changed[$table] = [
                'columns' => self::columnDelta($before['columns'] ?? [], $now['columns']),
                'keys' => self::listDelta($before['foreign_keys'] ?? [], $now['foreign_keys'], 'FK'),
                'flags' => self::flagDelta($before, $now),
            ];
        }

        ksort($changed);
        sort($unchanged);

        return compact('added', 'removed', 'changed', 'unchanged');
    }

    /**
     * @param  array<string, string>  $before
     * @param  array<string, string>  $after
     * @return array<string, array{0: ?string, 1: ?string}> column => [before, after]
     */
    private static function columnDelta(array $before, array $after): array
    {
        $delta = [];

        foreach ($after as $name => $signature) {
            if (! array_key_exists($name, $before)) {
                $delta[$name] = [null, $signature];
            } elseif ($before[$name] !== $signature) {
                $delta[$name] = [$before[$name], $signature];
            }
        }

        foreach ($before as $name => $signature) {
            if (! array_key_exists($name, $after)) {
                $delta[$name] = [$signature, null];
            }
        }

        ksort($delta);

        return $delta;
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return list<string>
     */
    private static function listDelta(array $before, array $after, string $label): array
    {
        $out = [];

        foreach (array_diff($after, $before) as $item) {
            $out[] = "+ {$label} {$item}";
        }

        foreach (array_diff($before, $after) as $item) {
            $out[] = "- {$label} {$item}";
        }

        sort($out);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    private static function flagDelta(array $before, array $after): array
    {
        $flags = [];

        foreach (['primary_key', 'soft_deletes', 'timestamps', 'schema'] as $key) {
            $was = $before[$key] ?? null;
            $now = $after[$key] ?? null;

            if ($was === $now) {
                continue;
            }

            $flags[] = sprintf(
                '%s: %s → %s',
                $key,
                self::readable($was),
                self::readable($now),
            );
        }

        return $flags;
    }

    private static function readable(mixed $value): string
    {
        return match (true) {
            $value === null => 'none',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => $value === [] ? 'none' : implode(',', $value),
            default => (string) $value,
        };
    }

    /**
     * Files recorded for tables that no longer exist — the stale artifacts a
     * regeneration will never clean up on its own.
     *
     * @param  list<string>  $removedTables
     * @return array<string, list<string>>
     */
    public function orphanedArtifacts(array $removedTables): array
    {
        $orphans = [];

        foreach ($removedTables as $table) {
            $paths = $this->data['tables'][$table]['artifacts'] ?? [];

            $existing = array_values(array_filter(
                $paths,
                static fn (string $path): bool => is_file(base_path($path)),
            ));

            if ($existing !== []) {
                $orphans[$table] = $existing;
            }
        }

        return $orphans;
    }
}
