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
                            {--all                    : Generate every artifact type for all tables}
                            {--models                 : Generate Eloquent models (always on)}
                            {--controllers            : Generate standard resource controllers}
                            {--resources              : Generate API resource classes}
                            {--observers              : Generate model observers}
                            {--policies               : Generate authorization policies}
                            {--form-requests          : Generate StoreXxx/UpdateXxx form request classes}
                            {--services               : Generate service classes with lifecycle hooks}
                            {--repositories           : Generate repository interface + Eloquent implementation}
                            {--gates                  : Append Gate definitions to AuthServiceProvider}
                            {--api-routes             : Append apiResource routes to routes/api.php (legacy)}
                            {--factories              : Generate model factories with type-inferred Faker definitions}
                            {--seeders                : Generate database seeders}
                            {--migrations             : Reverse-engineer tables into Schema::create() migrations}
                            {--events                 : Generate Created/Updated/Deleted event classes}
                            {--tests                  : Generate Feature test classes for all CRUD endpoints}
                            {--api                    : Generate a versioned JSON API scaffold with ForceJson enforcement}
                            {--api-version=1          : Version number for --api scaffold (e.g. 1, 2, v2)}
                            {--namespace=App\\Models   : Namespace for generated models}
                            {--connection=            : Database connection to introspect}
                            {--tables=*               : Limit generation to these tables}
                            {--ignore=*               : Exclude these tables from generation}
                            {--only=*                 : Alias for --tables}
                            {--path=app               : Base path for generated models}
                            {--force                  : Overwrite existing files without prompting}
                            {--backup                 : Backup existing files before overwriting}
                            {--dry-run                : Preview actions without writing any files}
                            {--with-phpdoc            : Add PHPDoc blocks to generated models}
                            {--with-inverse           : Generate inverse relationships (hasMany, hasOne)}
                            {--with-constraints       : Embed constraint metadata in model comments}
                            {--validate-fk            : Validate all foreign key references}
                            {--analyze-constraints    : Display a constraint summary before generating}
                            {--show-recommendations   : Show schema optimisation suggestions}';

    protected $description = 'Generate the full Laravel application scaffold from live database introspection.
  Use --api [--api-version=N] to generate a versioned JSON API with ForceJson enforcement.';

    // -----------------------------------------------------------------------
    // Internal components
    // -----------------------------------------------------------------------

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
        $this->info('Validating configuration...');
        $validator = new ConfigValidator;

        if (! $validator->validate()) {
            $this->displayValidationErrors($validator);

            return Command::FAILURE;
        }

        if ($validator->hasWarnings()) {
            $this->displayValidationWarnings($validator);
        }

        $this->info("✅ Configuration is valid.\n");

        // Parse options
        $options = GenerationOptions::fromCommand($this);

        // --only is an alias for --tables
        if ($this->option('only')) {
            $options = GenerationOptions::fromArray(
                array_merge($options->toArray(), [
                    'tables' => array_merge($options->tables, $this->option('only')),
                ])
            );
        }

        // Warn about --api mutual exclusions
        if ($options->api && $options->controllers) {
            $this->warn('⚠️  --controllers is ignored when --api is set; versioned API controllers will be generated instead.');
        }
        if ($options->api && $options->apiRoutes && ! $options->api) {
            $this->warn('⚠️  --api-routes is superseded by --api; versioned route files will be created.');
        }

        // Initialise components
        $this->setupComponents($options);

        // Display plan
        $this->displayGenerationPlan($options);

        // Discover tables
        $allTables = $this->inspector->getAllTables();
        $tablesToProcess = $this->filterTables($allTables, $options);

        if (empty($tablesToProcess)) {
            $this->warn('⚠️  No tables found to process.');

            return Command::SUCCESS;
        }

        $this->info('Found '.count($tablesToProcess)." table(s) to process.\n");

        // Relationship map
        if ($options->withInverse) {
            $this->info('Building relationship map...');
            $this->relationshipDetector->buildForeignKeyMap($allTables);

            if ($options->validateFk) {
                $this->validateForeignKeys();
            }
        }

        // Optional pre-generation analysis
        if ($options->analyzeConstraints) {
            $this->analyzeConstraints($tablesToProcess);
        }

        if ($options->validateFk) {
            $this->validateConstraintIntegrity($tablesToProcess);
        }

        // Confirmation for large table sets
        $threshold = config('anvil.validation.confirm_threshold', 50);
        if (count($tablesToProcess) >= $threshold && ! $options->force) {
            if (! $this->confirm('⚠️  About to process '.count($tablesToProcess).' tables. Continue?')) {
                $this->info('Aborted.');

                return Command::SUCCESS;
            }
        }

        // Generate
        $results = $this->generateArtifacts($tablesToProcess, $options);

        // Summary
        $this->displaySummary($results, $options);

        if ($options->showRecommendations) {
            $this->displayRecommendations($tablesToProcess);
        }

        return Command::SUCCESS;
    }

    // -----------------------------------------------------------------------
    // Setup
    // -----------------------------------------------------------------------

    protected function setupComponents(GenerationOptions $options): void
    {
        $connectionName = $options->getConnection();
        $this->inspector = new DatabaseInspector($connectionName);
        $this->relationshipDetector = new RelationshipDetector($this->inspector);
        $this->constraintAnalyzer = new ConstraintAnalyzer($this->inspector);
        $this->fileWriter = new FileWriter(base_path(), $options->dryRun);
        $this->orchestrator = app(GenerationOrchestrator::class);

        $driver = $this->inspector->getDriver();
        $database = $this->inspector->getDatabaseName();
        $this->info("🔍 Inspecting [{$connectionName}] — driver: {$driver}, database: {$database}...");

        if ($options->dryRun) {
            $this->warn('🔸 DRY RUN — no files will be written');
        }

        if ($options->api) {
            $versionLabel = $options->getApiVersionString();
            $this->info("🚀 Versioned API scaffold — {$versionLabel} (JSON-enforced)");
        }
    }

    // -----------------------------------------------------------------------
    // Filtering
    // -----------------------------------------------------------------------

    protected function filterTables(array $allTables, GenerationOptions $options): array
    {
        if ($options->hasSpecificTables()) {
            $allTables = array_intersect($allTables, $options->tables);
        }

        $ignored = $options->getAllIgnoredTables();

        return array_values(array_filter(
            $allTables,
            fn ($table) => ! Helpers::shouldIgnoreTable($table, $ignored),
        ));
    }

    // -----------------------------------------------------------------------
    // Generation loop
    // -----------------------------------------------------------------------

    protected function generateArtifacts(array $tables, GenerationOptions $options): array
    {
        $allResults = [];
        $bar = $this->output->createProgressBar(count($tables));
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
        $bar->setMessage('Starting...');
        $bar->start();

        foreach ($tables as $table) {
            $bar->setMessage("Processing {$table}");

            try {
                // Always generate the model first
                $modelResult = $this->generateModel($table, $options);

                // Build the rich DTO for all other generators
                $meta = ModelMetadata::fromTable($table, $this->inspector);

                if ($options->withInverse) {
                    $meta->inverseRelationships = $this->relationshipDetector->getInverseRelationships($table);
                }
                if ($options->withConstraints) {
                    $meta->constraintAnalysis = $this->constraintAnalyzer->analyzeTable($table);
                }

                // Run all other generators through the orchestrator
                $artifactResults = [];

                $needsOrchestrator = $options->controllers || $options->resources
                    || $options->observers || $options->policies
                    || $options->formRequests || $options->services
                    || $options->repositories || $options->gates
                    || $options->apiRoutes || $options->factories
                    || $options->seeders || $options->migrations
                    || $options->events || $options->tests
                    || $options->api;

                if ($needsOrchestrator) {
                    $orchestratorResults = $this->orchestrator->generate([$meta], $options);
                    $artifactResults = $orchestratorResults[0]['artifacts'] ?? [];
                }

                $allResults[] = [
                    'table' => $table,
                    'model' => $modelResult,
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
        $basePath = $options->getPath();

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
                $this->line("  Backed up to: {$backupPath}");
            }
        }

        $metadata = $this->inspector->getTableMetadata($table);
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
                $builder->addInverseRelationship(
                    $relation['method'],
                    $relation['model'],
                    $relation['foreign_key'],
                );
            }
        }

        $modelContent = $builder->build();
        $writeResult = $this->fileWriter->writeModel($modelContent, $namespace, $modelName, $basePath);

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
        ];
    }

    // -----------------------------------------------------------------------
    // Output helpers
    // -----------------------------------------------------------------------

    protected function displayGenerationPlan(GenerationOptions $options): void
    {
        $generators = $options->getEnabledGenerators();

        if (! empty($generators)) {
            $this->info('Generation plan:');
            $this->line('   Artifacts: '.implode(', ', $generators));

            if ($options->api) {
                $versionString = $options->getApiVersionString();
                $versionSlug = $options->getApiVersionSlug();
                $this->line("   API version  : {$versionString}");
                $this->line("   Controllers  : App\\Http\\Controllers\\Api\\{$versionString}\\");
                $this->line("   Route file   : routes/api/{$versionSlug}.php");
                $this->line('   JSON enforcer: App\\Http\\Middleware\\ForceJsonResponse');
                $this->line('   Provider     : App\\Providers\\ForceJsonApiServiceProvider');
            }

            $this->newLine();
        }
    }

    protected function displaySummary(array $results, GenerationOptions $options): void
    {
        $this->info('Generation Summary');

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
                    $artifactStats[$type] ??= ['success' => 0, 'skipped' => 0, 'failed' => 0, 'updated' => 0, 'dry-run' => 0];
                    if (isset($artifactStats[$type][$s])) {
                        $artifactStats[$type][$s]++;
                    }
                }
            }
        }

        $this->line("\n   Models:");
        $this->line("      ✅ Created/updated : {$modelStats['success']}");
        $this->line("      ⏭️  Skipped         : {$modelStats['skipped']}");

        foreach ($artifactStats as $type => $stats) {
            $this->line("\n   {$type}:");
            $this->line("      ✅ Created/updated : {$stats['success']}");
            if (($stats['skipped'] ?? 0) > 0) {
                $this->line("      ⏭️  Skipped         : {$stats['skipped']}");
            }
            if (($stats['updated'] ?? 0) > 0) {
                $this->line("      🔄 Updated         : {$stats['updated']}");
            }
        }

        if ($options->withInverse) {
            $pivotTables = $this->relationshipDetector->getPivotTables();
            if (! empty($pivotTables)) {
                $this->newLine();
                $this->info('🔄 Detected pivot tables:');
                foreach ($pivotTables as $pivot) {
                    $this->line("   {$pivot['pivot_table']}: {$pivot['model1']} ↔ {$pivot['model2']}");
                }
            }
        }

        $this->newLine();

        if ($options->api) {
            $versionSlug = $options->getApiVersionSlug();
            $versionString = $options->getApiVersionString();
            $this->info("🚀 Versioned API ({$versionString}) scaffold complete.");
            $this->line("   Route file : routes/api/{$versionSlug}.php");
            $this->line('   All requests and exceptions are locked to JSON via ForceJsonApiServiceProvider.');
            $this->newLine();
        }

        $this->info('✅ Done!');

        if ($options->dryRun) {
            $this->warn('🔸 Dry run — no files were written.');
        }
    }

    protected function validateForeignKeys(): void
    {
        $issues = $this->relationshipDetector->validateForeignKeys();

        if (! empty($issues)) {
            $this->warn("\n⚠️  Found ".count($issues).' FK issue(s):');
            foreach ($issues as $issue) {
                $this->line("   - {$issue['table']}.{$issue['column']}: {$issue['issue']}");
            }
        } else {
            $this->info('✅ All FK references are valid.');
        }
    }

    protected function validateConstraintIntegrity(array $tables): void
    {
        $issues = $this->constraintAnalyzer->validateConstraintIntegrity($tables);

        if (! empty($issues)) {
            $this->warn("\n⚠️  Found ".count($issues).' constraint issue(s):');
            foreach ($issues as $issue) {
                $this->line("   - [{$issue['type']}] {$issue['message']}");
            }
        } else {
            $this->info('✅ Constraint integrity OK.');
        }
    }

    protected function analyzeConstraints(array $tables): void
    {
        $this->info("Analyzing constraints...\n");
        $summary = $this->constraintAnalyzer->getConstraintSummary($tables);

        $this->line("  Tables            : {$summary['total_tables']}");
        $this->line("  With primary keys : {$summary['tables_with_pk']}");
        if ($summary['tables_without_pk'] > 0) {
            $this->warn("  Without PKs       : {$summary['tables_without_pk']}");
        }
        $this->line("  Foreign keys      : {$summary['total_foreign_keys']}");
        $this->line("  Indexes           : {$summary['total_indexes']}");
        $this->line("  Unique constraints: {$summary['total_unique_constraints']}");
        $this->newLine();
    }

    protected function displayRecommendations(array $tables): void
    {
        $this->info('Optimisation Recommendations:'."\n");
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
                    $this->line("  {$icon} [{$rec['category']}] {$rec['message']}");
                    $this->line("     → {$rec['suggestion']}");
                }
                $this->newLine();
            }
        }

        if (! $any) {
            $this->info('✅ No recommendations — schema looks great!');
        }
    }

    protected function displayValidationErrors(ConfigValidator $validator): void
    {
        $this->error("\n❌ Configuration validation failed:\n");
        foreach ($validator->getFormattedErrors() as $error) {
            $this->line("  - {$error}");
        }
        $this->newLine();
        $this->error('Fix config issues in config/anvil.php and retry.');
    }

    protected function displayValidationWarnings(ConfigValidator $validator): void
    {
        $this->warn('⚠️  Warnings:');
        foreach ($validator->getFormattedWarnings() as $w) {
            $this->line("  - {$w}");
        }
        $this->newLine();
    }
}
