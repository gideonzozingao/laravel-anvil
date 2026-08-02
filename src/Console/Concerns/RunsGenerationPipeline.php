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
 * Shared generation pipeline used by anvil:forge, anvil:api and anvil:generate-web.
 *
 * A consuming command is responsible only for (a) validating config and
 * (b) building a GenerationOptions instance, then calling runPipeline().
 *
 * The pipeline runs in two ordered phases, and the ordering is load-bearing:
 *
 *   Phase 1 — models only. Every model is written and its FQCN recorded in the
 *             ModelRegistry, then the manifest is persisted.
 *   Phase 2 — everything else. Nothing is emitted until every target table has a
 *             known model, so no generator ever has to guess a namespace.
 *
 * These used to be interleaved inside one per-table loop, which meant a resource
 * for core.vehicles could be written before auth.users existed, and no component
 * downstream had any record of the namespace generateModel() had chosen. That is
 * how schema-namespaced models regressed to App\Models\User in resources,
 * controllers, form requests and Livewire components.
 *
 * Phase 1 runs only when the command owns the model phase (see
 * shouldGenerateModels()). anvil:api and anvil:generate-web do not: they load the
 * registry instead, so a web or API run never regenerates models.
 *
 * @mixin Command
 */
trait RunsGenerationPipeline
{
    use ResolvesGeneratedModels;

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
            $this->reportNoTablesFound($pairs, $options);

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

        // ── Phase 1: models ──────────────────────────────────────────────────
        // Either generate them (and record every FQCN), or load what a previous
        // --models run produced. Never both, and never a guess.
        if ($this->shouldGenerateModels($options)) {
            $modelResults = $this->generateModels($tablesToProcess, $options);
            $this->saveModelRegistry($options);
        } else {
            $this->info('📇 Resolving previously generated models...');
            $this->loadModelRegistry($options);
            $modelResults = $this->reuseExistingModels($tablesToProcess);
            $this->newLine();
        }

        $this->shareModelRegistry();

        // ── Phase 2: everything else ─────────────────────────────────────────
        $needsOrchestrator = $this->needsOrchestrator($options);

        if ($needsOrchestrator && ! $this->assertModelsAvailable($tablesToProcess, $options)) {
            return Command::FAILURE;
        }

        $results = $needsOrchestrator
            ? $this->generateDependentArtifacts($tablesToProcess, $options, $modelResults)
            : array_values($modelResults);

        // ── Phase 3: finalization (OpenAPI root spec, Swagger UI, etc.) ───────
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
    // Phase control
    // -----------------------------------------------------------------------

    /**
     * Whether this command owns the model phase.
     *
     * Only a command that declares --models does. anvil:api and anvil:generate-web
     * deliberately do not, so a web or API run reads the registry rather than
     * rewriting models underneath it — which was also silently reverting any hand
     * edits made to a generated model between runs.
     *
     * A bare invocation with no artifact flags still means "models", preserving
     * the previous default behaviour of anvil:forge.
     */
    protected function shouldGenerateModels(GenerationOptions $options): bool
    {
        $definition = $this->getDefinition();

        if (! $definition->hasOption('models')) {
            return false;
        }

        if ($definition->hasOption('all') && $this->option('all')) {
            return true;
        }

        if ($this->option('models')) {
            return true;
        }

        // A bare invocation — no dependent artifact requested — still means
        // "models", preserving anvil:forge's previous default.
        //
        // This deliberately does NOT ask hasAnyArtifacts(): $models defaults to
        // true, so that method is true for every invocation and the fallback never
        // fired. `php artisan anvil:forge` with no flags therefore took the load
        // path and generated nothing.
        return ! $this->needsOrchestrator($options);
    }

    /**
     * Whether anything besides models is being generated.
     *
     * Delegates to the DTO rather than re-listing seventeen flags: this method and
     * GenerationOptions::hasDependentArtifacts() were near-identical copies that
     * had already drifted — this one omitted $listeners.
     */
    protected function needsOrchestrator(GenerationOptions $options): bool
    {
        if (method_exists($options, 'hasDependentArtifacts')) {
            return $options->hasDependentArtifacts();
        }

        return $options->controllers || $options->resources
            || $options->observers || $options->policies
            || $options->formRequests || $options->services
            || $options->repositories || $options->gates
            || $options->apiRoutes || $options->factories
            || $options->seeders || $options->migrations
            || $options->events || $options->listeners || $options->tests
            || $options->api || $options->openApi
            || $options->web;
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

        // Must be set before anything is registered or resolved: it is what makes
        // {schema: "public", table: "tenants"} and a bare `protected $table` key to
        // the same entry instead of looking like two different models.
        $this->modelRegistry()->setDefaultSchema($this->inspector->defaultSchema());

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
    // Phase 1 — models
    // -----------------------------------------------------------------------

    /**
     * Generate every model and record its FQCN. Nothing else is emitted here.
     *
     * @param  list<array{schema: ?string, table: string}>  $tables
     * @return array<string, array<string, mixed>> keyed by display label
     */
    protected function generateModels(array $tables, GenerationOptions $options): array
    {
        $results = [];
        $bar = $this->output->createProgressBar(count($tables));
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
        $bar->setMessage('Starting models...');
        $bar->start();

        foreach ($tables as $pair) {
            $label = $this->tableLabel($pair);
            $bar->setMessage("Model {$label}");

            try {
                $results[$label] = [
                    'table' => $label,
                    'model' => $this->generateModel($pair['table'], $options, $pair['schema']),
                    'artifacts' => [],
                ];
            } catch (\Exception $e) {
                $this->error("\n❌ Failed generating model for '{$label}': {$e->getMessage()}");

                $results[$label] = [
                    'table' => $label,
                    'model' => ['table' => $pair['table'], 'status' => 'failed', 'message' => $e->getMessage()],
                    'artifacts' => [],
                ];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        return $results;
    }

    /**
     * Build the per-table result rows for a run that did not generate models.
     *
     * @param  list<array{schema: ?string, table: string}>  $tables
     * @return array<string, array<string, mixed>>
     */
    protected function reuseExistingModels(array $tables): array
    {
        $results = [];

        foreach ($tables as $pair) {
            $label = $this->tableLabel($pair);
            $reference = null;

            try {
                $reference = $this->modelRegistry()->find($pair['table'], $pair['schema']);
            } catch (\Throwable) {
                // Ambiguity is reported by the gate with the full candidate list.
            }

            $results[$label] = [
                'table' => $label,
                'model' => $reference !== null
                    ? [
                        'table' => $pair['table'],
                        'model' => $reference->shortName(),
                        'fqcn' => $reference->fqcn(),
                        'status' => 'reused',
                    ]
                    : [
                        'table' => $pair['table'],
                        'status' => 'missing',
                    ],
                'artifacts' => [],
            ];
        }

        return $results;
    }

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

        // Record the namespace here, at the one place that decides it, and before
        // any early return. A model that already exists on disk is still a model
        // the dependent generators must be able to import — skipping registration
        // on the "already exists" path is precisely how phase 2 loses a namespace
        // and falls back to App\Models\{Model}.
        $reference = $this->registerGeneratedModel($namespace, $modelName, $table, $schema, $modelTable);

        $modelExists = $this->fileWriter->modelExists($namespace, $modelName, $basePath);

        if ($modelExists && ! $options->force && ! $options->dryRun) {
            return [
                'table' => $table,
                'model' => $modelName,
                'fqcn' => $reference->fqcn(),
                'status' => 'skipped',
                'reason' => 'already exists',
            ];
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

        // Remember what this table points at so the phase 2 gate can flag relations
        // aimed at a schema that was left out of --schema.
        $this->noteReferencedTables($foreignKeys);

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
            'fqcn' => $reference->fqcn(),
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
    // Phase 2 — dependent artifacts
    // -----------------------------------------------------------------------

    /**
     * Generate everything that depends on a model, now that every model exists and
     * every namespace is known.
     *
     * @param  list<array{schema: ?string, table: string}>  $tables
     * @param  array<string, array<string, mixed>>  $modelResults
     * @return list<array<string, mixed>>
     */
    protected function generateDependentArtifacts(array $tables, GenerationOptions $options, array $modelResults): array
    {
        $allResults = [];
        $bar = $this->output->createProgressBar(count($tables));
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
        $bar->setMessage('Starting artifacts...');
        $bar->start();

        foreach ($tables as $pair) {
            $table = $pair['table'];
            $schema = $pair['schema'];
            $label = $this->tableLabel($pair);
            $bar->setMessage("Artifacts {$label}");

            $row = $modelResults[$label] ?? ['table' => $label, 'model' => ['status' => 'unknown'], 'artifacts' => []];

            try {
                $meta = ModelMetadata::fromTable($table, $this->inspector, $schema);

                $this->attachModelReference($meta, $table, $schema);

                if ($options->withInverse) {
                    $meta->inverseRelationships = $this->relationshipDetector->getInverseRelationships($table, $schema);
                }
                if ($options->withConstraints) {
                    $meta->constraintAnalysis = $this->constraintAnalyzer->analyzeTable($table);
                }

                $orchestratorResults = $this->orchestrator->generate([$meta], $options);
                $row['artifacts'] = $orchestratorResults[0]['artifacts'] ?? [];
            } catch (\Exception $e) {
                $this->error("\n❌ Failed processing '{$label}': {$e->getMessage()}");
            }

            $allResults[] = $row;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        return $allResults;
    }

    /**
     * Hand the resolved ModelReference to the metadata object so the generators can
     * read the model FQCN instead of deriving one.
     *
     * Guarded because ModelMetadata may not carry the field yet — the same
     * tolerance webStack() applies to $options->stack. Once ModelMetadata has
     * setModelReference(), drop the property_exists branch.
     */
    protected function attachModelReference(ModelMetadata $meta, string $table, ?string $schema): void
    {
        $reference = $this->modelRegistry()->find($table, $schema);

        if ($reference === null) {
            return;
        }

        if (method_exists($meta, 'setModelReference')) {
            $meta->setModelReference($reference);

            return;
        }

        if (property_exists($meta, 'modelReference')) {
            $meta->modelReference = $reference;
        }
    }

    /**
     * Display label for a table: schema-qualified only when it is not the default.
     *
     * @param  array{schema: ?string, table: string}  $pair
     */
    protected function tableLabel(array $pair): string
    {
        $schema = $pair['schema'] ?? null;
        $default = $this->inspector->defaultSchema();

        return ($schema !== null && $schema !== $default)
            ? "{$schema}.{$pair['table']}"
            : $pair['table'];
    }

    // -----------------------------------------------------------------------
    // Diagnostics
    // -----------------------------------------------------------------------

    /**
     * Explain an empty table list instead of dead-ending on a bare warning.
     *
     * With no --schema, getAllSchemaTables() resolves to the connection's default
     * schema alone — "public" on Postgres. A database that keeps everything in
     * named schemas therefore reports zero tables while holding hundreds, and the
     * old one-line warning gave no indication that other schemas existed. This
     * distinguishes the three real causes and prints the command that fixes each.
     *
     * @param  list<array{schema: ?string, table: string}>  $pairs  pre-filter enumeration
     */
    protected function reportNoTablesFound(array $pairs, GenerationOptions $options): void
    {
        $selection = $options->getSchemaSelection();
        $searched = $this->inspector->resolveSchemas($selection);
        $searchedLabel = $searched === [] ? '(none)' : implode(', ', $searched);

        // Case 1: the schemas were scanned and did hold tables, so --tables or the
        // ignore list removed everything.
        if ($pairs !== []) {
            $this->warn(sprintf(
                '⚠️  Found %d table(s) in [%s], but --tables / ignore rules excluded all of them.',
                count($pairs),
                $searchedLabel,
            ));

            if ($options->hasSpecificTables()) {
                $available = array_values(array_unique(array_column($pairs, 'table')));
                sort($available);

                $this->line('   Requested: '.implode(', ', $options->tables));
                $this->line('   Available: '.implode(', ', array_slice($available, 0, 15)).(count($available) > 15 ? ', …' : ''));
                $this->line('   <fg=gray>--tables matches bare table names, without a schema prefix.</>');
            }

            return;
        }

        $this->warn(sprintf('⚠️  No tables found in schema [%s].', $searchedLabel));

        // Case 2: other schemas exist and hold tables. This is the common one on a
        // multi-schema database invoked without --schema.
        $populated = $this->populatedSchemasOutsideSelection($searched);

        if ($populated !== []) {
            $this->newLine();
            $this->line('   This database keeps its tables in other schemas:');

            $width = max(array_map(strlen(...), array_keys($populated)));

            foreach ($populated as $schema => $count) {
                $this->line(sprintf('     <fg=cyan>%s</>  %d table(s)', str_pad((string) $schema, $width), $count));
            }

            $names = implode(',', array_keys($populated));
            $verb = $this->shouldGenerateModels($options) ? '--models' : trim($this->invokedArtifactFlags());

            $this->newLine();
            $this->line('   Generate from them:');
            $this->line(sprintf('     <fg=cyan>php artisan %s %s --schema="%s"</>', $this->getName(), $verb, $names));
            $this->line('   Or every non-system schema:');
            $this->line(sprintf('     <fg=cyan>php artisan %s %s --schema=all</>', $this->getName(), $verb));
            $this->newLine();
            $this->line('   <fg=gray>Quote the value. An unquoted list containing a space is split by the shell,</>');
            $this->line('   <fg=gray>which is what produces "No arguments expected for ..." from Symfony.</>');

            return;
        }

        // Case 3: genuinely empty.
        $this->newLine();
        $this->line(sprintf(
            '   No non-system schema on connection [%s] contains a base table.',
            $options->getConnection(),
        ));
        $this->line('   <fg=gray>Check that migrations have run against this database, and that the</>');
        $this->line('   <fg=gray>connection user can see the catalog (Postgres hides tables the role</>');
        $this->line('   <fg=gray>has no privilege on).</>');
    }

    /**
     * Schemas outside the searched set that actually contain tables, table count
     * descending then name, so the biggest ones are suggested first.
     *
     * @param  list<string>  $searched
     * @return array<string, int>
     */
    protected function populatedSchemasOutsideSelection(array $searched): array
    {
        $lowerSearched = array_map(strtolower(...), $searched);
        $counts = [];

        try {
            $schemas = $this->inspector->getSchemas();
        } catch (\Throwable) {
            return [];
        }

        foreach ($schemas as $schema) {
            if (in_array(strtolower((string) $schema), $lowerSearched, true)) {
                continue;
            }

            try {
                $count = count($this->inspector->getTablesInSchema($schema));
            } catch (\Throwable) {
                continue;
            }

            if ($count > 0) {
                $counts[$schema] = $count;
            }
        }

        uksort($counts, static fn (string $a, string $b): int => $counts[$b] <=> $counts[$a] ?: strcasecmp($a, $b));

        return $counts;
    }

    /**
     * The artifact flags this invocation actually used, so the suggested command
     * repeats the operator's intent rather than defaulting to --models.
     */
    protected function invokedArtifactFlags(): string
    {
        $definition = $this->getDefinition();
        $flags = [];

        foreach (
            [
                'all',
                'models',
                'controllers',
                'resources',
                'form-requests',
                'policies',
                'observers',
                'services',
                'repositories',
                'factories',
                'seeders',
                'migrations',
                'events',
                'listeners',
                'tests',
                'gates',
                'api-routes',
            ] as $option
        ) {
            if ($definition->hasOption($option) && $this->option($option)) {
                $flags[] = '--'.$option;
            }
        }

        return $flags === [] ? '--models' : implode(' ', $flags);
    }

    // -----------------------------------------------------------------------
    // Output helpers
    // -----------------------------------------------------------------------

    protected function displayGenerationPlan(GenerationOptions $options): void
    {
        $generators = $options->getEnabledGenerators();

        if (! empty($generators)) {
            $this->info('📋 Generation plan: '.implode(', ', $generators));

            $ownsModels = $this->shouldGenerateModels($options);

            if ($this->needsOrchestrator($options)) {
                $this->line($ownsModels
                    ? '   Phase 1      : models (namespaces recorded to the model manifest)'
                    : '   Phase 1      : reuse existing models (no models will be regenerated)');
                $this->line('   Phase 2      : dependent classes, importing models from the manifest');
            } elseif ($ownsModels) {
                $this->line('   Phase 1      : models only — no dependent classes this run');
            }

            $this->line('   Manifest     : '.$this->relativeToBase($this->modelManifestPath()));

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
                    // Through the DTO, not config() directly: this block read
                    // anvil.livewire.namespace while GenerationOptions read
                    // anvil.web.livewire.namespace, so configuring either made the
                    // plan describe a location the generator would not use.
                    $componentRoot = $options->getLivewireNamespace();
                    $viewRoot = $options->getLivewireViewPath();
                    $this->line("   Components   : {$componentRoot}\\{Resource}\\{Form, Table}");
                    $this->line("   Component views: {$viewRoot}/{resource}/{form, table}.blade.php");
                    $this->line('   Form state   : untyped properties (never a scalar type — see FormStateProperty)');
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

        $modelStats = ['success' => 0, 'skipped' => 0, 'failed' => 0, 'reused' => 0, 'missing' => 0];
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

        if ($modelStats['reused'] > 0 || $modelStats['missing'] > 0) {
            $line = "      ♻️  {$modelStats['reused']} reused from the manifest";

            if ($modelStats['missing'] > 0) {
                $line .= "   ❌ {$modelStats['missing']} missing";
            }

            $this->line($line);
        } else {
            $this->line("      ✅ {$modelStats['success']} created/updated   ⏭️  {$modelStats['skipped']} skipped");
        }

        if ($modelStats['failed'] > 0) {
            $this->line("      ❌ {$modelStats['failed']} failed");
        }

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
                $componentRoot = $options->getLivewireNamespace();
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
