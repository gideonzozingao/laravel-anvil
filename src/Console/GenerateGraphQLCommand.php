<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Console;

use Illuminate\Console\Command;
use Nuwave\Lighthouse\LighthouseServiceProvider;
use Zuqongtech\LaravelAnvil\Support\ApiVersionProfile;
use Zuqongtech\LaravelAnvil\Support\DatabaseInspector;
use Zuqongtech\LaravelAnvil\Support\EnumDetector;
use Zuqongtech\LaravelAnvil\Support\GraphQLSchemaBuilder;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates a Lighthouse GraphQL schema from the database.
 *
 *   php artisan anvil:generate-graphql
 *   php artisan anvil:generate-graphql --tables=vehicles --tables=users
 *   php artisan anvil:generate-graphql --guard=sanctum --policies
 *   php artisan anvil:generate-graphql --single-file --no-mutations
 *
 * Output:
 *
 *   graphql/schema.graphql        root — imports the rest, never overwritten
 *   graphql/scalars.graphql       scalar declarations
 *   graphql/enums.graphql         one enum per detected enum column
 *   graphql/types/Vehicle.graphql type + inputs + queries + mutations
 *
 * The root file is written once and then left alone: it is where hand-written
 * queries, custom mutations and subscriptions go. Everything under types/ is
 * regenerated freely.
 *
 * Requires nuwave/lighthouse. The command checks and explains rather than
 * emitting a schema nothing can serve.
 */

class GenerateGraphQLCommand extends Command
{

    protected $description = 'Generate a Lighthouse GraphQL schema (types, inputs, queries, mutations) from the database';
    protected $signature = 'anvil:forge-graphql
                            {--output=graphql   : Directory for the schema files}
                            {--api-version=1    : Version profile supplying hidden fields and pagination bounds}
                            {--guard=           : Auth guard for @guard (empty = none, "default" = @guard)}
                            {--policies         : Emit @can directives bound to the generated policies}
                            {--no-mutations     : Queries only — a read-only graph}
                            {--single-file      : One schema.graphql instead of a file per type}
                            {--connection=      : Database connection to introspect}
                            {--schema=          : Schema(s) to introspect}
                            {--tables=*         : Limit to specific tables}
                            {--ignore=*         : Exclude specific tables}
                            {--force            : Overwrite existing type files}
                            {--dry-run          : Preview without writing}';
    public function handle(): int
    {
        $connection = (string) ($this->option('connection') ?: config('database.default'));

        try {
            $inspector = new DatabaseInspector($connection);
        } catch (\Throwable $e) {
            $this->error('Could not connect to the database: ' . $e->getMessage());

            return self::FAILURE;
        }

        $tables = $this->introspect($inspector);

        if ($tables === []) {
            $this->components->warn('No tables matched.');

            return self::SUCCESS;
        }

        $this->applyRuntimeConfig();

        $profile = ApiVersionProfile::for($this->option('api-version'));
        $builder = new GraphQLSchemaBuilder($profile, $connection);

        $dir = base_path(trim((string) $this->option('output'), '/'));

        $this->summarise($profile, $tables, $dir, $connection);

        return $this->option('single-file')
            ? $this->writeSingleFile($builder, $tables, $dir)
            : $this->writeSplit($builder, $tables, $dir);
    }

    /**
     * @return array<string, ModelMetadata>
     */
    private function introspect(DatabaseInspector $inspector): array
    {
        $schema = $this->option('schema') ?: null;
        $only = array_map(strval(...), $this->option('tables') ?? []);
        $ignore = array_merge(
            (array) config('anvil.ignore_tables', []),
            array_map(strval(...), $this->option('ignore') ?? []),
        );

        $tables = [];

        foreach ($inspector->getAllSchemaTables($schema) as $row) {
            $table = (string) ($row['table'] ?? '');

            if ($table === '' || in_array($table, $ignore, true)) {
                continue;
            }

            if ($only !== [] && ! in_array($table, $only, true)) {
                continue;
            }

            try {
                $meta = ModelMetadata::fromTable($table, $inspector, $row['schema'] ?? $schema);
            } catch (\Throwable) {
                continue;
            }

            // A pivot with a composite key has no single ID for @find, and
            // Lighthouse cannot resolve mutations against it. Expose the
            // relationship through its parents instead.
            if (count($meta->compositePrimaryKey) > 1) {
                $this->line("    <fg=gray>skipped {$table}: composite key, reachable through its parents</>");

                continue;
            }

            $tables[$table] = $meta;
        }

        ksort($tables);

        return $tables;
    }

    private function applyRuntimeConfig(): void
    {
        config([
            'anvil.graphql.guard' => (string) $this->option('guard'),
            'anvil.graphql.policies' => (bool) $this->option('policies'),
        ]);
    }

    // -----------------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------------

    /**
     * @param  array<string, ModelMetadata>  $tables
     */
    private function writeSplit(GraphQLSchemaBuilder $builder, array $tables, string $dir): int
    {
        $written = $skipped = 0;

        // Root: written once, then left alone. It is where hand-written
        // operations live, and regenerating it would eat them.
        $written += $this->put($dir . '/schema.graphql', $this->rootSchema(), overwritable: false) ? 1 : 0;
        $written += $this->put($dir . '/scalars.graphql', $this->scalars()) ? 1 : 0;

        $enums = $builder->enums($tables);

        if ($enums !== '') {
            $written += $this->put($dir . '/enums.graphql', $this->header('Enums') . "\n" . $enums) ? 1 : 0;
        }

        foreach ($tables as $meta) {
            $path = $dir . '/types/' . $meta->model . '.graphql';
            $contents = $this->header($meta->model . ' — ' . $meta->table) . "\n" . $builder->model($meta);

            $this->put($path, $contents) ? $written++ : $skipped++;
        }

        return $this->finish($written, $skipped, $dir, count($tables));
    }

    /**
     * @param  array<string, ModelMetadata>  $tables
     */
    private function writeSingleFile(GraphQLSchemaBuilder $builder, array $tables, string $dir): int
    {
        $parts = [
            $this->header('Generated schema'),
            $this->scalars(),
            $builder->enums($tables),
        ];

        foreach ($tables as $meta) {
            $parts[] = $builder->model($meta);
        }

        $written = $this->put($dir . '/schema.graphql', implode("\n", array_filter($parts))) ? 1 : 0;

        return $this->finish($written, 0, $dir, count($tables));
    }

    private function put(string $path, string $contents, bool $overwritable = true): bool
    {
        $name = str_replace(base_path() . '/', '', $path);
        $exists = is_file($path);

        if ($exists && (! $overwritable || ! $this->option('force'))) {
            $this->line("    <fg=gray>–</> {$name} " . ($overwritable ? '(exists)' : '(never overwritten)'));

            return false;
        }

        if ($this->option('dry-run')) {
            $this->line("    <fg=cyan>◌</> {$name}");

            return false;
        }

        $dir = dirname($path);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            $this->error("Could not create {$dir}");

            return false;
        }

        if (file_put_contents($path, $contents) === false) {
            $this->line("    <fg=red>✘</> {$name}");

            return false;
        }

        $this->line("    <fg=green>✔</> {$name}");

        return true;
    }

    // -----------------------------------------------------------------------
    // Static files
    // -----------------------------------------------------------------------

    private function rootSchema(): string
    {
        $guard = $this->option('guard') !== '' ? "\n" . <<<'GRAPHQL'

"The signed-in user."
extend type Query {
    me: User @auth
}
GRAPHQL : '';

        return <<<GRAPHQL
"""
Root GraphQL schema.

Anvil writes this file once and never touches it again — hand-written queries,
mutations and subscriptions belong here. Everything under types/ is regenerated,
so do not edit those.
"""

scalar Date @scalar(class: "Nuwave\\\\Lighthouse\\\\Schema\\\\Types\\\\Scalars\\\\Date")
scalar DateTime @scalar(class: "Nuwave\\\\Lighthouse\\\\Schema\\\\Types\\\\Scalars\\\\DateTime")
scalar DateTimeTz @scalar(class: "Nuwave\\\\Lighthouse\\\\Schema\\\\Types\\\\Scalars\\\\DateTimeTz")

#import scalars.graphql
#import enums.graphql
#import types/*.graphql

type Query
type Mutation
{$guard}
GRAPHQL;
    }

    private function scalars(): string
    {
        return <<<'GRAPHQL'
"""
Scalars beyond the Lighthouse defaults.

JSON needs mll-lab/graphql-php-scalars:
    composer require mll-lab/graphql-php-scalars
"""

scalar JSON @scalar(class: "MLL\\GraphQLScalars\\JSON")

GRAPHQL;
    }

    private function header(string $title): string
    {
        return <<<GRAPHQL
# {$title}
# Generated by zuqongtech/laravel-anvil — regenerated with --force.
# Hand-written operations belong in schema.graphql, which is never overwritten.

GRAPHQL;
    }

    // -----------------------------------------------------------------------
    // Reporting
    // -----------------------------------------------------------------------

    /**
     * @param  array<string, ModelMetadata>  $tables
     */
    private function summarise(ApiVersionProfile $profile, array $tables, string $dir, string $connection): void
    {
        $enumCount = 0;

        foreach ($tables as $meta) {
            $enumCount += count(EnumDetector::forTable($meta, $connection));
        }

        $guard = (string) $this->option('guard');

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>⚒  Anvil — GraphQL schema</>');
        $this->table(['', ''], array_filter([
            ['Stack', 'Lighthouse (SDL)'],
            ['Types', (string) count($tables)],
            ['Enums', $enumCount > 0 ? (string) $enumCount : 'none detected'],
            ['Mutations', $this->option('no-mutations') ? 'omitted' : 'create, update, delete (+ restore/forceDelete)'],
            ['Guard', $guard === '' ? 'none — the graph is public' : $guard],
            ['Authorization', $this->option('policies') ? '@can, bound to the generated policies' : 'none'],
            ['Pagination', sprintf('%d default, %d max', $profile->perPageDefault(), $profile->perPageMax())],
            ['Hidden fields', implode(', ', $profile->hiddenFields()) ?: 'none'],
            ['Output', str_replace(base_path() . '/', '', $dir)],
        ]));
        $this->newLine();

        if (! class_exists(LighthouseServiceProvider::class)) {
            $this->components->warn(
                'nuwave/lighthouse is not installed, so nothing will serve this schema: '
                    . 'composer require nuwave/lighthouse'
            );
        }

        if ($guard === '') {
            // Worth saying plainly. A GraphQL endpoint with no guard exposes every
            // type and mutation to anonymous callers, and unlike REST there is no
            // route list that makes that obvious.
            $this->components->warn(
                'No --guard: every query and mutation will be publicly callable. Pass --guard=sanctum (or "default").'
            );
        }
    }

    private function finish(int $written, int $skipped, string $dir, int $types): int
    {
        $this->newLine();
        $this->line("  {$written} file(s) written, {$skipped} skipped, {$types} type(s)");
        $this->newLine();

        $this->line('  <options=bold>Next</>');
        $this->line('    <fg=gray>composer require nuwave/lighthouse mll-lab/graphql-php-scalars</>');
        $this->line('    <fg=gray>php artisan vendor:publish --tag=lighthouse-config</>');
        $this->line('    <fg=gray>config/lighthouse.php → \'schema_path\' => base_path(\'' . str_replace(base_path() . '/', '', $dir) . '/schema.graphql\')</>');
        $this->line('    <fg=gray>php artisan lighthouse:validate-schema</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
