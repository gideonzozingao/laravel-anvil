<?php

namespace Zuqongtech\LaravelAnvil\Console;

use Illuminate\Console\Command;
use Zuqongtech\LaravelAnvil\Support\ConfigValidator;
use Zuqongtech\LaravelAnvil\Support\ConstraintAnalyzer;
use Zuqongtech\LaravelAnvil\Support\DatabaseInspector;
use Zuqongtech\LaravelAnvil\Support\FileWriter;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\GenerationOrchestrator;
use Zuqongtech\LaravelAnvil\Support\Helpers;
use Zuqongtech\LaravelAnvil\Support\ModelBuilder;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;
use Zuqongtech\LaravelAnvil\Support\RelationshipDetector;

class GenerateModelsFromDatabase extends Command
{
    protected $signature = 'anvil:generate
                            {--all                    : Generate every artifact type}
                            {--models                 : Eloquent models (always on)}
                            {--controllers            : Resource controllers}
                            {--resources              : API resource classes}
                            {--observers              : Model observers}
                            {--policies               : Authorization policies}
                            {--form-requests          : StoreXxx / UpdateXxx form requests}
                            {--services               : Service classes with lifecycle hooks}
                            {--repositories           : Repository interface + Eloquent implementation}
                            {--gates                  : Gate definitions appended to AuthServiceProvider}
                            {--api-routes             : apiResource routes appended to routes/api.php}
                            {--factories              : Model factories with Faker-inferred definitions}
                            {--seeders                : Database seeders}
                            {--migrations             : Reverse-engineered Schema::create() migrations}
                            {--events                 : Created / Updated / Deleted event classes}
                            {--tests                  : Feature test classes for all CRUD endpoints}
                            {--api                    : Generate a versioned JSON API scaffold with ForceJson enforcement}
                            {--api-version=1          : Version number for --api scaffold (e.g. 1, 2, v2)}
                            {--openapi                : Generate OpenAPI 3.1 specification}
                            {--openapi-format=yaml    : Output format: yaml (default) or json}
                            {--openapi-single-file    : Merge all schemas and paths into one file}
                            {--openapi-ui             : Publish Swagger UI to public/docs/}
                            {--namespace=App\\Models  : Namespace for generated models}
                            {--connection=            : Database connection to introspect}
                            {--tables=*               : Limit generation to specific tables}
                            {--ignore=*               : Exclude specific tables}
                            {--only=*                 : Alias for --tables}
                            {--path=app               : Base path for generated models}
                            {--force                  : Overwrite existing files without prompting}
                            {--backup                 : Backup existing files before overwriting}
                            {--dry-run                : Preview without writing files}
                            {--with-phpdoc            : Add PHPDoc blocks to models}
                            {--with-inverse           : Generate inverse relationships}
                            {--with-constraints       : Embed constraint metadata in model comments}
                            {--validate-fk            : Validate all foreign key references}
                            {--analyze-constraints    : Show constraint summary before generation}
                            {--show-recommendations   : Show schema optimisation suggestions}';

    protected $description = 'Generate a complete Laravel application scaffold from live database introspection';

    protected DatabaseInspector $inspector;
    protected RelationshipDetector $relationshipDetector;
    protected ConstraintAnalyzer $constraintAnalyzer;
    protected FileWriter $fileWriter;
    protected GenerationOrchestrator $orchestrator;

    // -----------------------------------------------------------------------
    // Entry point
    // -----------------------------------------------------------------------

    public function handle(): int
    {
        $this->info('🔧 Validating configuration...');
        $validator = new ConfigValidator;

        if (! $validator->validate()) {
            $this->displayValidationErrors($validator);

            return Command::FAILURE;
        }

        if ($validator->hasWarnings()) {
            $this->displayValidationWarnings($validator);
        }

        $this->info("✅ Configuration valid.\n");

        $options = GenerationOptions::fromCommand($this);

        if ($this->option('only')) {
            $options = GenerationOptions::fromArray(array_merge(
                $options->toArray(),
                ['tables' => array_merge($options->tables, $this->option('only'))],
            ));
        }

        // Warn about --api mutual exclusions
        if ($options->api && $options->controllers) {
            $this->warn('⚠️  --controllers is ignored when --api is set; versioned API controllers will be generated instead.');
        }

        $this->setupComponents($options);
        $this->displayGenerationPlan($options);

        $allTables       = $this->inspector->getAllTables();
        $tablesToProcess = $this->filterTables($allTables, $options);

        if (empty($tablesToProcess)) {
            $this->warn('⚠️  No tables found to process.');

            return Command::SUCCESS;
        }

        $this->info('📊 Found ' . count($tablesToProcess) . " table(s) to process.\n");

        if ($options->withInverse) {
            $this->info('🔗 Building relationship map...');
            $this->relationshipDetector->buildForeignKeyMap($allTables);
            if ($options->validateFk) {
                $this->validateForeignKeys();
            }
        }

        if ($options->analyzeConstraints) {
            $this->analyzeConstraints($tablesToProcess);
        }

        if ($options->validateFk) {
            $this->validateConstraintIntegrity($tablesToProcess);
        }

        // Confirmation guard for large schemas
        $threshold = config('laravel-anvil.validation.confirm_threshold', 50);
        if (count($tablesToProcess) >= $threshold && ! $options->force) {
            if (! $this->confirm('⚠️  About to process ' . count($tablesToProcess) . ' tables. Continue?')) {
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
            $this->displayRecommendations($tablesToProcess);
        }

        // Swagger UI URL hint
        if ($options->openApiUi && ! $options->dryRun) {
            $url = config('app.url') . '/docs';
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
        $this->inspector            = new DatabaseInspector($conn);
        $this->relationshipDetector = new RelationshipDetector($this->inspector);
        $this->constraintAnalyzer   = new ConstraintAnalyzer($this->inspector);
        $this->fileWriter           = new FileWriter(base_path(), $options->dryRun);
        $this->orchestrator         = app(GenerationOrchestrator::class);

        $driver   = $this->inspector->getDriver();
        $database = $this->inspector->getDatabaseName();
        $this->info("🔍 Connection [{$conn}] — driver: {$driver} — database: {$database}");

        if ($options->dryRun) {
            $this->warn('🔸 DRY RUN — no files will be written');
        }

        if ($options->api) {
            $versionLabel = $options->getApiVersionString();
            $this->info("🚀 Versioned API scaffold — {$versionLabel} (JSON-enforced)");
        }

        if ($options->openApi) {
            $fmt  = strtoupper($options->openApiFormat);
            $mode = $options->openApiSingleFile ? 'single-file' : 'split-files';
            $this->info("📄 OpenAPI 3.1 — format: {$fmt} — mode: {$mode}");
        }

        $this->newLine();
    }

    // -----------------------------------------------------------------------
    // Filtering
    // -----------------------------------------------------------------------

    protected function filterTables(array $allTables, GenerationOptions $options): array
    {
        if ($options->hasSpecificTables()) {
            $allTables = array_intersect($allTables, $options->tables);
        }

        return array_values(array_filter(
            $allTables,
            fn ($t) => ! Helpers::shouldIgnoreTable($t, $options->getAllIgnoredTables()),
        ));
    }

    // -----------------------------------------------------------------------
    // Generation loop
    // -----------------------------------------------------------------------

    protected function generateArtifacts(array $tables, GenerationOptions $options): array
    {
        $allResults = [];
        $bar        = $this->output->createProgressBar(count($tables));
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
        $bar->setMessage('Starting...');
        $bar->start();

        foreach ($tables as $table) {
            $bar->setMessage("Processing {$table}");

            try {
                $modelResult = $this->generateModel($table, $options);

                $meta = ModelMetadata::fromTable($table, $this->inspector);

                if ($options->withInverse) {
                    $meta->inverseRelationships = $this->relationshipDetector->getInverseRelationships($table);
                }
                if ($options->withConstraints) {
                    $meta->constraintAnalysis = $this->constraintAnalyzer->analyzeTable($table);
                }

                $artifactResults   = [];
                $needsOrchestrator = $options->controllers || $options->resources
                    || $options->observers || $options->policies
                    || $options->formRequests || $options->services
                    || $options->repositories || $options->gates
                    || $options->apiRoutes || $options->factories
                    || $options->seeders || $options->migrations
                    || $options->events || $options->tests
                    || $options->api || $options->openApi;

                if ($needsOrchestrator) {
                    $orchestratorResults = $this->orchestrator->generate([$meta], $options);
                    $artifactResults     = $orchestratorResults[0]['artifacts'] ?? [];
                }

                $allResults[] = [
                    'table'     => $table,
                    'model'     => $modelResult,
                    'artifacts' => $artifactResults,
                ];
            } catch (\Exception $e) {
                $this->error("\n❌ Failed processing '{$table}': {$e->getMessage()}");
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

    protected function generateModel(string $table, GenerationOptions $options): array
    {
        $modelName = Helpers::tableToModelName($table);
        $namespace = $options->getNamespace();
        $basePath  = $options->getPath();

        if (! Helpers::isValidClassName($modelName)) {
            throw new \Exception("Invalid model name: {$modelName}");
        }

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

        $metadata            = $this->inspector->getTableMetadata($table);
        $columns             = $metadata['columns'];
        $foreignKeys         = $metadata['foreign_keys'];
        $primaryKey          = $metadata['primary_key'];
        $compositePrimaryKey = $metadata['composite_primary_key'];
        $indexes             = $metadata['indexes'];
        $uniqueConstraints   = $metadata['unique_constraints'];

        $columnNames    = array_column($columns, 'name');
        $hasTimestamps  = in_array('created_at', $columnNames) && in_array('updated_at', $columnNames);
        $hasSoftDeletes = in_array('deleted_at', $columnNames);

        $constraintAnalysis = $options->withConstraints
            ? $this->constraintAnalyzer->analyzeTable($table)
            : null;

        $builder = new ModelBuilder($table, $namespace);
        $builder->setColumns($columns)
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
            $inverseRelations = $this->relationshipDetector->getInverseRelationships($table);
            foreach ($inverseRelations as $relation) {
                $builder->addInverseRelationship($relation['method'], $relation['model'], $relation['foreign_key']);
            }
        }

        $writeResult = $this->fileWriter->writeModel($builder->build(), $namespace, $modelName, $basePath);

        return [
            'table'                 => $table,
            'model'                 => $modelName,
            'status'                => $writeResult['written'] ? 'success' : 'failed',
            'path'                  => $writeResult['relative_path'],
            'existed'               => $writeResult['existed'],
            'message'               => $writeResult['message'],
            'columns'               => count($columns),
            'relationships'         => count($foreignKeys),
            'inverse_relationships' => count($inverseRelations),
            'indexes'               => count($indexes),
            'unique_constraints'    => count($uniqueConstraints),
        ];
    }

    // -----------------------------------------------------------------------
    // Output helpers
    // -----------------------------------------------------------------------

    protected function displayGenerationPlan(GenerationOptions $options): void
    {
        $generators = $options->getEnabledGenerators();

        if (! empty($generators)) {
            $this->info('📋 Generation plan: ' . implode(', ', $generators));

            if ($options->api) {
                $versionString = $options->getApiVersionString();
                $versionSlug   = $options->getApiVersionSlug();
                $this->line("   API version  : {$versionString}");
                $this->line("   Controllers  : App\\Http\\Controllers\\Api\\{$versionString}\\");
                $this->line("   Route file   : routes/api/{$versionSlug}.php");
                $this->line('   JSON enforcer: App\\Http\\Middleware\\ForceJsonResponse');
                $this->line('   Provider     : App\\Providers\\ForceJsonApiServiceProvider');
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

        $modelStats    = ['success' => 0, 'skipped' => 0, 'failed' => 0];
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
                    $s    = $a['status'] ?? 'unknown';
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
            if (($stats['success'] ?? 0) > 0) { $parts[] = "✅ {$stats['success']}"; }
            if (($stats['merged']  ?? 0) > 0) { $parts[] = "🔀 {$stats['merged']} merged"; }
            if (($stats['updated'] ?? 0) > 0) { $parts[] = "🔄 {$stats['updated']} updated"; }
            if (($stats['skipped'] ?? 0) > 0) { $parts[] = "⏭️  {$stats['skipped']} skipped"; }
            if (($stats['failed']  ?? 0) > 0) { $parts[] = "❌ {$stats['failed']} failed"; }
            $this->line("   {$type}: " . implode('  ', $parts));
        }

        // Finalization results (OpenAPI root spec, Swagger UI)
        if (! empty($finalResults)) {
            $this->newLine();
            $this->info('   Post-generation:');
            foreach ($finalResults as $r) {
                $icon = match ($r['status'] ?? '') {
                    'success' => '✅',
                    'dry-run' => '🔸',
                    'failed'  => '❌',
                    default   => '•',
                };
                $name = $r['name'] ?? $r['type'] ?? '?';
                $path = isset($r['path']) ? " → {$r['path']}" : '';
                $url  = isset($r['url'])  ? " 🌐 {$r['url']}" : '';
                $this->line("      {$icon} {$name}{$path}{$url}");
            }
        }

        // API scaffold summary
        if ($options->api) {
            $versionSlug   = $options->getApiVersionSlug();
            $versionString = $options->getApiVersionString();
            $this->newLine();
            $this->info("🚀 Versioned API ({$versionString}) scaffold complete.");
            $this->line("   Route file : routes/api/{$versionSlug}.php");
            $this->line('   All requests and exceptions locked to JSON via ForceJsonApiServiceProvider.');
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

    protected function validateForeignKeys(): void
    {
        $issues = $this->relationshipDetector->validateForeignKeys();
        if (! empty($issues)) {
            $this->warn('⚠️  FK issues: ' . count($issues));
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
            $this->warn('⚠️  Constraint issues: ' . count($issues));
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
                        'warning'      => '⚠️ ',
                        'performance'  => '⚡',
                        'optimization' => '🔧',
                        default        => 'ℹ️ ',
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