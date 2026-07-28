<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Options;

/**
 * WHAT to generate from: the connection, the schemas, the table selection, and
 * where the resulting models live.
 *
 * Every command shares these — anvil:generate, anvil:generate-api,
 * anvil:forge-webapp and anvil:forge-auth all take the same six values, and each
 * currently re-implements the merge of --tables with --only and the coercion of
 * --schema into a list.
 */
final readonly class TargetOptions extends OptionBag
{
    /**
     * @param  list<string>  $tables  Empty means every non-ignored table
     * @param  list<string>  $ignore  Merged with the configured ignore list
     * @param  string|list<string>|null  $schemas  Name, CSV string, list, or "all"
     */
    public function __construct(
        public ?string $connection = null,
        public string|array|null $schemas = null,
        public array $tables = [],
        public array $ignore = [],
        public string $namespace = 'App\\Models',
        public string $path = 'app',
    ) {}

    public function connection(): string
    {
        return $this->connection ?? (string) config('database.default');
    }

    public function hasSpecificTables(): bool
    {
        return $this->tables !== [];
    }

    /**
     * The schema selection, normalised.
     *
     * Accepts a name, a CSV string, a list, or "all" — because that is what the
     * --schema option accepts, and the coercion was previously duplicated in
     * GenerationOptions and in the pipeline.
     *
     * @return list<string>|null null means the connection's default schema
     */
    public function schemaSelection(): ?array
    {
        if (in_array($this->schemas, [null, '', []], true)) {
            return null;
        }

        if (is_string($this->schemas)) {
            if (strtolower(trim($this->schemas)) === 'all') {
                return ['*'];
            }

            $parts = array_map(trim(...), explode(',', $this->schemas));

            return array_values(array_filter($parts, static fn (string $s): bool => $s !== ''));
        }

        return array_values(array_filter(
            array_map(strval(...), $this->schemas),
            static fn (string $s): bool => $s !== '',
        ));
    }

    public function generatesAllSchemas(): bool
    {
        return $this->schemaSelection() === ['*'];
    }

    /**
     * Command-line exclusions plus the configured ones.
     *
     * @return list<string>
     */
    public function allIgnored(): array
    {
        $configured = array_map(strval(...), (array) config('anvil.ignored_tables', []));

        return array_values(array_unique([...$this->ignore, ...$configured]));
    }

    /**
     * Merge a second table list in — the --only alias, which every command
     * currently unions by hand.
     *
     * @param  list<string>  $additional
     */
    public function withTables(array $additional): self
    {
        return $this->with([
            'tables' => array_values(array_unique([
                ...$this->tables,
                ...array_map(strval(...), $additional),
            ])),
        ]);
    }

    public function rootNamespace(): string
    {
        return trim($this->namespace, '\\');
    }
}
