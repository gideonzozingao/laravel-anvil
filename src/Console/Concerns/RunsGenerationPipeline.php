<?php

namespace Zuqongtech\LaravelAnvil\Console\Concerns;

use Illuminate\Console\Command;
use Zuqongtech\LaravelAnvil\Support\ConstraintAnalyzer;
use Zuqongtech\LaravelAnvil\Support\DatabaseInspector;
use Zuqongtech\LaravelAnvil\Support\FileWriter;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\GenerationOrchestrator;
use Zuqongtech\LaravelAnvil\Support\Helpers;
use Zuqongtech\LaravelAnvil\Support\ModelBuilder;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;
use Zuqongtech\LaravelAnvil\Support\RelationshipDetector;
use Zuqongtech\LaravelAnvil\Support\ReservedNames;
use Zuqongtech\LaravelAnvil\Support\WebStack;

/**
 * Shared generation pipeline used by anvil:generate and anvil:generate-web.
 *
 * A consuming command is responsible only for (a) validating config and
 * (b) building a GenerationOptions instance, then calling runPipeline().
 * Everything from component setup through the per-table loop, finalization
 * and summary lives here so the two commands stay thin and never diverge.
 *
 * @mixin Command
 */
trait RunsGenerationPipeline
{
    protected DatabaseInspector $inspector;

    protected RelationshipDetector $relationshipDetector;

    protected ConstraintAnalyzer $constraintAnalyzer;

    protected FileWriter $fileWriter;

    protected GenerationOrchestrator $orchestrator;

    /**
     * Run the full generation pipeline for the given options.
     */
    protected function runPipeline(GenerationOptions $options): int
    {
        $this->setupComponents($options);
        $this->displayGenerationPlan($options);

        // Enumerate tables across the selected schema(s). The selection comes from
        // --schema (csv or "all"); empty means the connection's default schema.
        $pairs = $this->inspector->getAllSchemaTables($options->getSchemaSelection());
        $tablesToProcess = $this->filterTables($pairs, $options);

        if (empty($tablesToProcess)) {
            $this->warn('⚠️  No tables found to process.');

            return Command::SUCCESS;
        }

        $this->info('📊 Found '.count($tablesToProcess)." table(s) to process.\n");

        // The map is also what --validate-fk validates, so build it for either flag.
        // Pass the {schema, table} pairs, not bare names: without the schema, an
        // inverse relationship on a cross-schema child resolves into the parent's
        // namespace and emits a ::class reference to a class never written.
        if ($options->withInverse || $options->validateFk) {
            $this->info('🔗 Building relationship map...');
            $this->relationshipDetector->buildForeignKeyMap($tablesToProcess);

            if ($options->validateFk) {
                $this->validateForeignKeys();
            }
        }

        if ($options->analyzeConstraints) {
            $this->analyzeConstraints(array_column($tablesToProcess, 'table'));
        }

        if ($options->validateFk) {
            $this->validateConstraintIntegrity(array_column($tablesToProcess, 'table'));
        }

        // Confirmation guard for large schemas
        $threshold = config('laravel-anvil.validation.confirm_threshold', 50);
        if (count($tablesToProcess) >= $threshold && ! $options->force) {
            if (! $this->confirm('⚠️  About to process '.count($tablesToProcess).' tables. Continue?')) {
                $this->info('Aborted.');

                return Command::SUCCESS;
            }
        }

        // ── Pass 1: per-model generation ─────────────────────────────────────
        $results = $this->generateArtifacts($tablesToProcess, $options);

        // ── Pass 2: finalization (OpenAPI root spec, Swagger UI, etc.) ────────
        $finalResults = $this->orchestrator->finalize($options);

        $this->displaySummary($results, $finalResults, $options);

        if ($options->showRecommendations) {
            $this->displayRecommendations(array_column($tablesToProcess, 'table'));
        }

        // Swagger UI URL hint
        if ($options->openApiUi && ! $options->dryRun) {
            $url = config('app.url').'/docs';
            $this->info("🌐 Swagger UI available at: {$url}");
        }

        return Command::SUCCESS;
    }

    // -----------------------------------------------------------------------
    // Setup
    // -----------------------------------------------------------------------

    protected function setupComponents(GenerationOptions $options): void
    {
        $conn = $options->getConnection();
        $this->inspector = new DatabaseInspector($conn);
        $this->relationshipDetector = new RelationshipDetector($this->inspector);
        $this->constraintAnalyzer = new ConstraintAnalyzer($this->inspector);
        $this->fileWriter = new FileWriter(base_path(), $options->dryRun);
        $this->orchestrator = resolve(GenerationOrchestrator::class);

        $driver = $this->inspector->getDriver();
        $database = $this->inspector->getDatabaseName();
        $this->info("🔍 Connection [{$conn}] — driver: {$driver} — database: {$database}");

        if ($options->dryRun) {
            $this->warn('🔸 DRY RUN — no files will be written');
        }

        if ($options->api) {
            $versionLabel = $options->getApiVersionString();
            $this->info("🚀 Versioned API scaffold — {$versionLabel} (JSON-enforced)");
        }

        if ($options->web) {
            $stack = $this->webStack($options);
            $this->info('🌐 Web scaffold ['.$stack->value().'] — '.$stack->label());

            if (! $stack->isAvailable()) {
                $this->components->warn($stack->unavailableMessage());
            }
        }

        if ($options->openApi) {
            $fmt = strtoupper($options->openApiFormat);
            $mode = $options->openApiSingleFile ? 'single-file' : 'split-files';
            $this->info("📄 OpenAPI 3.1 — format: {$fmt} — mode: {$mode}");
        }

        $this->newLine();
    }

    /**
     * The front-end stack for this run.
     *
     * Reads the DTO field when it exists and falls back to config, so the trait
     * keeps working on a GenerationOptions that has not yet grown $stack — the
     * same tolerance the listener flags needed while they lived in config only.
     */
    protected function webStack(GenerationOptions $options): WebStack
    {
        $value = property_exists($options, 'stack') ? $options->stack : null;

        return WebStack::make(
            is_string($value) && $value !== '' ? $value : null,
            (string) config('anvil.web.stack', WebStack::BLADE),
        );
    }

    // -----------------------------------------------------------------------
    // Filtering
    // -----------------------------------------------------------------------

    /**
     * Filter the {schema, table} pairs by --tables / ignore rules.
     *
     * @param  list<array{schema: ?string, table: string}>  $pairs
     * @return list<array{schema: ?string, table: string}>
     */
    protected function filterTables(array $pairs, GenerationOptions $options): array
    {
        return array_values(array_filter($pairs, function (array $pair) use ($options): bool {
            $table = $pair['table'];

            if ($options->hasSpecificTables() && ! in_array($table, $options->tables, true)) {
                return false;
            }

            return ! Helpers::shouldIgnoreTable($table, $options->getAllIgnoredTables());
        }));
    }

    // -----------------------------------------------------------------------
    // Generation loop
    // -----------------------------------------------------------------------

    protected function generateArtifacts(array $tables, GenerationOptions $options): array
    {
        $allResults = [];
        $defaultSchema = $this->inspector->defaultSchema();
        $bar = $this->output->createProgressBar(count($tables));
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
        $bar->setMessage('Starting...');
        $bar->start();

        foreach ($tables as $pair) {
            $table = $pair['table'];
            $schema = $pair['schema'];
            $label = ($schema !== null && $schema !== $defaultSchema) ? "{$schema}.{$table}" : $table;
            $bar->setMessage("Processing {$label}");

            try {
                $modelResult = $this->generateModel($table, $options, $schema);

                $meta = ModelMetadata::fromTable($table, $this->inspector, $schema);

                if ($options->withInverse) {
                    $meta->inverseRelationships = $this->relationshipDetector->getInverseRelationships($table, $schema);
                }
                if ($options->withConstraints) {
                    $meta->constraintAnalysis = $this->constraintAnalyzer->analyzeTable($table);
                }

                $artifactResults = [];
                $needsOrchestrator = $options->controllers || $options->resources
                    || $options->observers || $options->policies
                    || $options->formRequests || $options->services
                    || $options->repositories || $options->gates
                    || $options->apiRoutes || $options->factories
                    || $options->seeders || $options->migrations
                    || $options->events || $options->tests
                    || $options->api || $options->openApi
                    || $options->web;

                if ($needsOrchestrator) {
                    $orchestratorResults = $this->orchestrator->generate([$meta], $options);
                    $artifactResults = $orchestratorResults[0]['artifacts'] ?? [];
                }

                $allResults[] = [
                    'table' => $label,
                    'model' => $modelResult,
                    'artifacts' => $artifactResults,
                ];
            } catch (\Exception $e) {
                $this->error("\n❌ Failed processing '{$label}': {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        return $allResults;
    }

    // -----------------------------------------------------------------------
    // Model generation
    // -----------------------------------------------------------------------

    protected function generateModel(string $table, GenerationOptions $options, ?string $schema = null): array
    {
        $modelName = Helpers::tableToModelName($table);
        $defaultSchema = $this->inspector->defaultSchema();
        $isQualified = ! in_array($schema, [null, '', $defaultSchema], true);

        // Schema segment (e.g. "Core") so cross-schema tables of the same name
        // don't collide: App\Models\Core\Employer at app/Models/Core/Employer.php.
        // Must go through ReservedNames — ModelBuilder resolves related models the
        // same way, and if the two disagree on the segment (Public vs PublicSchema)
        // every relation points at a class that was never written.
        $segment = $isQualified ? ReservedNames::namespaceSegment($schema) : null;
        $namespace = $segment !== null ? $options->getNamespace().'\\'.$segment : $options->getNamespace();
        $basePath = $options->getPath();

        // The table the model binds to — schema-qualified when not the default schema.
        $modelTable = $isQualified ? $schema.'.'.$table : $table;

        throw_unless(Helpers::isValidClassName($modelName), \Exception::class, "Invalid model name: {$modelName}");

        $modelExists = $this->fileWriter->modelExists($namespace, $modelName, $basePath);

        if ($modelExists && ! $options->force && ! $options->dryRun) {
            return ['table' => $table, 'model' => $modelName, 'status' => 'skipped', 'reason' => 'already exists'];
        }

        if ($modelExists && $options->backup && ! $options->dryRun) {
            $backupPath = $this->fileWriter->backupModel($namespace, $modelName, $basePath);
            if ($backupPath) {
                $this->line("  💾 Backed up: {$backupPath}");
            }
        }

        $metadata = $this->inspector->getTableMetadata($table, $schema);
        $columns = $metadata['columns'];
        $foreignKeys = $metadata['foreign_keys'];
        $primaryKey = $metadata['primary_key'];
        $compositePrimaryKey = $metadata['composite_primary_key'];
        $indexes = $metadata['indexes'];
        $uniqueConstraints = $metadata['unique_constraints'];

        $columnNames = array_column($columns, 'name');
        $hasTimestamps = in_array('created_at', $columnNames) && in_array('updated_at', $columnNames);
        $hasSoftDeletes = in_array('deleted_at', $columnNames);

        $constraintAnalysis = $options->withConstraints
            ? $this->constraintAnalyzer->analyzeTable($table)
            : null;

        $builder = new ModelBuilder($table, $namespace);
        $builder->setTable($modelTable)
            ->setRootNamespace($options->getNamespace())
            // Without this the builder cannot tell "public" from a real schema and
            // emits App\Models\PublicSchema\Tenant for a model written to
            // App\Models\Tenant.
            ->setDefaultSchema($defaultSchema)
            ->setColumns($columns)
            ->setForeignKeys($foreignKeys)
            ->setIndexes($indexes)
            ->setUniqueConstraints($uniqueConstraints)
            ->setPrimaryKey($primaryKey)
            ->setCompositePrimaryKey($compositePrimaryKey)
            ->setTimestamps($hasTimestamps)
            ->setSoftDeletes($hasSoftDeletes)
            ->setWithPhpDoc($options->withPhpDoc)
            ->setWithInverse($options->withInverse)
            ->setWithConstraintComments($options->withConstraints)
            ->setConstraintAnalysis($constraintAnalysis);

        $inverseRelations = [];
        if ($options->withInverse) {
            $inverseRelations = $this->relationshipDetector->getInverseRelationships($table, $schema);

            foreach ($inverseRelations as $relation) {
                // Pass the child table, its uniqueness and its schema. Dropping
                // them makes every inverse a hasMany, groups collisions by model
                // name instead of table, and loses cross-schema resolution.
                $builder->addInverseRelationship(
                    $relation['method'],
                    $relation['model'],
                    $relation['foreign_key'],
                    $relation['table'] ?? $relation['source_table'] ?? null,
                    (bool) ($relation['unique'] ?? false),
                    $relation['schema'] ?? $relation['source_schema'] ?? null,
                );
            }
        }

        $content = $builder->build();
        $collisions = $builder->relationCollisions();

        $writeResult = $this->fileWriter->writeModel($content, $namespace, $modelName, $basePath);

        return [
            'table' => $table,
            'model' => $modelName,
            'status' => $writeResult['written'] ? 'success' : 'failed',
            'path' => $writeResult['relative_path'],
            'existed' => $writeResult['existed'],
            'message' => $writeResult['message'],
            'columns' => count($columns),
            'relationships' => count($foreignKeys),
            'inverse_relationships' => count($inverseRelations),
            'indexes' => count($indexes),
            'unique_constraints' => count($uniqueConstraints),
            'collisions' => $collisions,
        ];
    }

    // -----------------------------------------------------------------------
    // Output helpers
    // -----------------------------------------------------------------------

    protected function displayGenerationPlan(GenerationOptions $options): void
    {
        $generators = $options->getEnabledGenerators();

        if (! empty($generators)) {
            $this->info('📋 Generation plan: '.implode(', ', $generators));

            if ($options->api) {
                $versionString = $options->getApiVersionString();
                $versionSlug = $options->getApiVersionSlug();
                $this->line("   API version  : {$versionString}");
                $this->line("   Controllers  : App\\Http\\Controllers\\Api\\{$versionString}\\");
                $this->line("   Route file   : routes/api/{$versionSlug}.php");
                $this->line('   JSON enforcer: App\\Http\\Middleware\\ForceJsonResponse');
                $this->line('   Provider     : App\\Providers\\ForceJsonApiServiceProvider');
            }

            if ($options->web) {
                $stack = $this->webStack($options);
                $routeFile = config('anvil.web.route_file', 'routes/web.php');
                $layout = config('anvil.web.layout', 'layouts.anvil');

                $this->line('   Stack        : '.$stack->value());
                $this->line('   Controllers  : App\\Http\\Controllers\\Web\\');
                $this->line("   Route file   : {$routeFile}");
                $this->line('   Views        : resources/views/{resource}/ ('.implode(', ', $stack->views()).')');
                $this->line("   Layout       : {$layout}");

                if ($stack->isLivewire()) {
                    $componentRoot = config('anvil.livewire.namespace', 'App\\Livewire');
                    $viewRoot = config('anvil.livewire.view_path', 'resources/views/livewire');
                    $this->line("   Components   : {$componentRoot}\\{Resource}\\{Form, Table}");
                    $this->line("   Component views: {$viewRoot}/{resource}/{form, table}.blade.php");
                    $this->line('   Form state   : untyped properties + NormalizesFormState');
                }
            }

            if ($options->openApi) {
                $path = config('laravel-anvil.openapi.output_path', 'openapi');
                $this->line("   OpenAPI output → {$path}/");
            }

            $this->newLine();
        }
    }

    protected function displaySummary(array $results, array $finalResults, GenerationOptions $options): void
    {
        $this->info('📊 Summary');

        $modelStats = ['success' => 0, 'skipped' => 0, 'failed' => 0];
        $artifactStats = [];

        foreach ($results as $result) {
            $status = $result['model']['status'] ?? 'unknown';
            if (isset($modelStats[$status])) {
                $modelStats[$status]++;
            }

            foreach ($result['artifacts'] ?? [] as $artifact) {
                $artifacts = isset($artifact['type']) ? [$artifact] : (array) $artifact;
                foreach ($artifacts as $a) {
                    $type = $a['type'] ?? 'unknown';
                    $s = $a['status'] ?? 'unknown';
                    $artifactStats[$type] ??= ['success' => 0, 'skipped' => 0, 'failed' => 0, 'merged' => 0, 'updated' => 0, 'dry-run' => 0];
                    if (isset($artifactStats[$type][$s])) {
                        $artifactStats[$type][$s]++;
                    }
                }
            }
        }

        $this->line("\n   Models:");
        $this->line("      ✅ {$modelStats['success']} created/updated   ⏭️  {$modelStats['skipped']} skipped");

        foreach ($artifactStats as $type => $stats) {
            $parts = [];
            if (($stats['success'] ?? 0) > 0) {
                $parts[] = "✅ {$stats['success']}";
            }
            if (($stats['merged'] ?? 0) > 0) {
                $parts[] = "🔀 {$stats['merged']} merged";
            }
            if (($stats['updated'] ?? 0) > 0) {
                $parts[] = "🔄 {$stats['updated']} updated";
            }
            if (($stats['skipped'] ?? 0) > 0) {
                $parts[] = "⏭️  {$stats['skipped']} skipped";
            }
            if (($stats['failed'] ?? 0) > 0) {
                $parts[] = "❌ {$stats['failed']} failed";
            }
            $this->line("   {$type}: ".implode('  ', $parts));
        }

        $this->displayRelationCollisions($results);

        // Finalization results (OpenAPI root spec, Swagger UI)
        if (! empty($finalResults)) {
            $this->newLine();
            $this->info('   Post-generation:');
            foreach ($finalResults as $r) {
                $icon = match ($r['status'] ?? '') {
                    'success' => '✅',
                    'dry-run' => '🔸',
                    'failed' => '❌',
                    default => '•',
                };
                $name = $r['name'] ?? $r['type'] ?? '?';
                $path = isset($r['path']) ? " → {$r['path']}" : '';
                $url = isset($r['url']) ? " 🌐 {$r['url']}" : '';
                $this->line("      {$icon} {$name}{$path}{$url}");
            }
        }

        // API scaffold summary
        if ($options->api) {
            $versionSlug = $options->getApiVersionSlug();
            $versionString = $options->getApiVersionString();
            $this->newLine();
            $this->info("🚀 Versioned API ({$versionString}) scaffold complete.");
            $this->line("   Route file : routes/api/{$versionSlug}.php");
            $this->line('   All requests and exceptions locked to JSON via ForceJsonApiServiceProvider.');
        }

        // Web scaffold summary
        if ($options->web) {
            $stack = $this->webStack($options);
            $routeFile = config('anvil.web.route_file', 'routes/web.php');
            $this->newLine();
            $this->info('🌐 Web scaffold complete ['.$stack->value().'].');
            $this->line('   Controllers : App\\Http\\Controllers\\Web\\');
            $this->line("   Routes      : {$routeFile} (Route::resource within the configured middleware group)");
            $this->line('   Views       : resources/views/{resource}/ ('.implode(', ', $stack->views()).')');

            if ($stack->isLivewire()) {
                $componentRoot = config('anvil.livewire.namespace', 'App\\Livewire');
                $this->line("   Components  : {$componentRoot}\\{Resource}\\ (Form, Table)");
                $this->line('   create/edit views are wrappers — the fields live in the component view.');
            }
        }

        // Pivot tables
        if ($options->withInverse) {
            $pivots = $this->relationshipDetector->getPivotTables();
            if (! empty($pivots)) {
                $this->newLine();
                $this->info('🔄 Pivot tables:');
                foreach ($pivots as $p) {
                    $this->line("   {$p['pivot_table']}: {$p['model1']} ↔ {$p['model2']}");
                }
            }
        }

        $this->newLine();
        $this->info('✅ Done!');

        if ($options->dryRun) {
            $this->warn('🔸 Dry run — no files written.');
        }
    }

    /**
     * Relation names that had to be altered because the preferred one was taken.
     * These are the tables worth a human glance — a generated name that reads
     * oddly is usually a schema that wants an explicit relation name.
     */
    protected function displayRelationCollisions(array $results): void
    {
        $rows = [];

        foreach ($results as $result) {
            foreach ($result['model']['collisions'] ?? [] as $collision) {
                if (! is_array($collision)) {
                    continue;
                }

                $name = (string) ($collision['name'] ?? '');
                $wanted = (string) ($collision['wanted'] ?? '');

                if ($name === '' && $wanted === '') {
                    continue;
                }

                $detail = trim(implode(' ', array_filter([
                    isset($collision['column']) ? "via {$collision['column']}" : null,
                    isset($collision['related']) ? "→ {$collision['related']}" : null,
                ])));

                $rows[] = sprintf(
                    '   %s: %s → %s%s',
                    $result['table'] ?? '?',
                    $wanted !== '' ? $wanted : '?',
                    $name !== '' ? $name : '?',
                    $detail !== '' ? "  ({$detail})" : '',
                );
            }
        }

        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->warn('⚠️  Renamed relations ('.count($rows).'):');
        foreach ($rows as $row) {
            $this->line($row);
        }
    }

    protected function validateForeignKeys(): void
    {
        $issues = $this->relationshipDetector->validateForeignKeys();
        if (! empty($issues)) {
            $this->warn('⚠️  FK issues: '.count($issues));
            foreach ($issues as $i) {
                $this->line("   - {$i['table']}.{$i['column']}: {$i['issue']}");
            }
        } else {
            $this->info('✅ All FK references valid.');
        }
    }

    protected function validateConstraintIntegrity(array $tables): void
    {
        $issues = $this->constraintAnalyzer->validateConstraintIntegrity($tables);
        if (! empty($issues)) {
            $this->warn('⚠️  Constraint issues: '.count($issues));
            foreach ($issues as $i) {
                $this->line("   - [{$i['type']}] {$i['message']}");
            }
        } else {
            $this->info('✅ Constraint integrity OK.');
        }
    }

    protected function analyzeConstraints(array $tables): void
    {
        $s = $this->constraintAnalyzer->getConstraintSummary($tables);
        $this->info("🔍 Constraints:\n  Tables: {$s['total_tables']}  PKs: {$s['tables_with_pk']}  FKs: {$s['total_foreign_keys']}  Indexes: {$s['total_indexes']}\n");
    }

    protected function displayRecommendations(array $tables): void
    {
        $this->info("💡 Recommendations:\n");
        $any = false;

        foreach ($tables as $table) {
            $analysis = $this->constraintAnalyzer->analyzeTable($table);
            if (! empty($analysis['recommendations'])) {
                $any = true;
                $this->line("Table: <comment>{$table}</comment>");
                foreach ($analysis['recommendations'] as $rec) {
                    $icon = match ($rec['type']) {
                        'warning' => '⚠️ ',
                        'performance' => '⚡',
                        'optimization' => '🔧',
                        default => 'ℹ️ ',
                    };
                    $this->line("  {$icon} {$rec['message']}");
                    $this->line("     → {$rec['suggestion']}");
                }
                $this->newLine();
            }
        }

        if (! $any) {
            $this->info('✅ No recommendations — schema looks great!');
        }
    }

    protected function displayValidationErrors($validator): void
    {
        $this->error("\n❌ Config validation failed:\n");
        foreach ($validator->getFormattedErrors() as $e) {
            $this->line("  - {$e}");
        }
        $this->newLine();
        $this->error('Fix issues in config/laravel-anvil.php and retry.');
    }

    protected function displayValidationWarnings($validator): void
    {
        $this->warn('⚠️  Warnings:');
        foreach ($validator->getFormattedWarnings() as $w) {
            $this->line("  - {$w}");
        }
        $this->newLine();
    }
}
