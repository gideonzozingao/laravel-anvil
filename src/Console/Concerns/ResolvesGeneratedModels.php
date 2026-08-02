<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Console\Concerns;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Zuqongtech\LaravelAnvil\Support\DatabaseInspector;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelDiscovery;
use Zuqongtech\LaravelAnvil\Support\ModelReference;
use Zuqongtech\LaravelAnvil\Support\ModelRegistry;
use Zuqongtech\LaravelAnvil\Support\ReservedNames;

/**
 * Owns the table → generated model mapping for a pipeline run.
 *
 * The pipeline used to have no such mapping: generateModel() computed the model
 * namespace locally and every dependent generator re-derived its own, which is
 * why schema-namespaced models regressed to App\Models\User in resources,
 * controllers, form requests and Livewire components. This concern makes the
 * FQCN a recorded value with exactly one producer.
 *
 * @mixin Command
 */
trait ResolvesGeneratedModels
{
    protected ?ModelRegistry $modelRegistry = null;

    /**
     * Tables referenced by a foreign key during the model phase, so the gate can
     * warn about relations pointing at schemas that were not part of this run.
     *
     * @var array<string, array{schema: ?string, table: string}>
     */
    protected array $referencedTables = [];

    protected function modelRegistry(): ModelRegistry
    {
        return $this->modelRegistry ??= new ModelRegistry;
    }

    /**
     * Where the manifest lives. Commit it — it is generation input, not a cache.
     */
    protected function modelManifestPath(): string
    {
        $configured = config('anvil.models.manifest', config('laravel-anvil.models.manifest'));

        if (is_string($configured) && $configured !== '') {
            return str_starts_with($configured, '/') ? $configured : base_path($configured);
        }

        return storage_path('anvil/models.json');
    }

    /**
     * Record a model the model phase just produced.
     *
     * $namespace must be the same variable handed to ModelBuilder, never a
     * recomputed one — that is the entire point of this method existing.
     */
    protected function registerGeneratedModel(
        string $namespace,
        string $modelName,
        string $table,
        ?string $schema,
        string $qualifiedTable,
    ): ModelReference {
        $reference = new ModelReference(
            trim($namespace, '\\').'\\'.$modelName,
            $table,
            $schema,
            $qualifiedTable,
        );

        $this->modelRegistry()->register($reference);

        return $reference;
    }

    /**
     * Note a foreign key target so the gate can flag cross-schema relations whose
     * model was never generated.
     *
     * @param  array<int, array<string, mixed>>  $foreignKeys
     */
    protected function noteReferencedTables(array $foreignKeys): void
    {
        foreach ($foreignKeys as $fk) {
            $table = $fk['referenced_table'] ?? null;

            if (! is_string($table) || $table === '') {
                continue;
            }

            $schema = $fk['referenced_schema'] ?? null;
            $schema = is_string($schema) && $schema !== '' ? $schema : null;

            $this->referencedTables[strtolower(($schema ?? '').'.'.$table)] = [
                'schema' => $schema,
                'table' => $table,
            ];
        }
    }

    /**
     * Populate the registry without generating anything: manifest first, then a
     * scan of the models already on disk.
     *
     * This is what makes "do not regenerate models on web/api runs" safe. The
     * namespaces are read back from the source files rather than recomputed, so
     * a manifest that was never committed still cannot produce a wrong import.
     */
    protected function loadModelRegistry(GenerationOptions $options, bool $preferDisk = false): ModelRegistry
    {
        $path = $this->modelManifestPath();

        if (! $preferDisk && File::exists($path)) {
            try {
                $this->modelRegistry = ModelRegistry::fromJson(File::get($path));

                $this->line(sprintf(
                    '  <fg=gray>Loaded %d model(s) from %s</>',
                    $this->modelRegistry->count(),
                    $this->relativeToBase($path),
                ));

                $this->warnOnManifestConnectionMismatch($options);
                $this->applyDefaultSchema($options);

                return $this->modelRegistry;
            } catch (\Throwable $e) {
                $this->components->warn(sprintf(
                    'Model manifest at %s could not be read (%s). Falling back to scanning %s.',
                    $this->relativeToBase($path),
                    $e->getMessage(),
                    $this->relativeToBase($this->modelsDirectory($options)),
                ));
            }
        }

        $directory = $this->modelsDirectory($options);

        $this->modelRegistry = ModelDiscovery::scan(
            $directory,
            $options->getNamespace(),
            $this->schemaSegmentMap($options),
        );

        $this->line(sprintf(
            '  <fg=gray>Discovered %d model(s) by scanning %s</>',
            $this->modelRegistry->count(),
            $this->relativeToBase($directory),
        ));

        $this->applyDefaultSchema($options);

        $unmappable = ModelDiscovery::unmappable($directory);

        if ($unmappable !== []) {
            $this->components->warn(sprintf(
                '%d model(s) declare no $table and cannot be mapped to a table: %s. '
                    .'Regenerate them with --models, or add an explicit $table.',
                count($unmappable),
                implode(', ', array_slice($unmappable, 0, 5)).(count($unmappable) > 5 ? ', …' : ''),
            ));
        }

        return $this->modelRegistry;
    }

    /**
     * Teach the registry which schema is the default, so a model registered as
     * {schema: "public"} and the same model rediscovered from a bare
     * `protected $table` key to one entry rather than two.
     */
    protected function applyDefaultSchema(GenerationOptions $options): void
    {
        $inspector = $this->modelSchemaInspector($options);

        if ($inspector instanceof DatabaseInspector) {
            $this->modelRegistry()->setDefaultSchema($inspector->defaultSchema());
        }
    }

    /**
     * Rebuild the manifest from the models already on disk, without regenerating
     * anything. This is the migration path for an application whose models were
     * generated before the manifest existed.
     */
    protected function refreshModelManifest(GenerationOptions $options): int
    {
        $this->info('📇 Rebuilding the model manifest from '.$this->relativeToBase($this->modelsDirectory($options)).'...');

        $registry = $this->loadModelRegistry($options, preferDisk: true);

        if ($registry->isEmpty()) {
            $this->newLine();
            $this->error('❌ No models found to index.');
            $this->line('   Generate them first: <fg=cyan>php artisan anvil:forge --models'.$this->schemaArgument($options).'</>');

            return Command::FAILURE;
        }

        $this->saveModelRegistry($options);
        $this->newLine();

        foreach ($registry->all() as $reference) {
            $this->line(sprintf(
                '   <fg=gray>%s</>  →  %s',
                str_pad($reference->qualifiedTable(), 34),
                $reference->fqcn(),
            ));
        }

        $this->newLine();
        $this->info('✅ Indexed '.$registry->count().' model(s).');

        return Command::SUCCESS;
    }

    /**
     * Refuse to generate dependent classes for tables with no generated model.
     *
     * Without this a missing model yields a syntactically valid `use App\Models\User;`
     * and the failure surfaces at runtime inside a controller. Failing here names
     * the table instead.
     *
     * @param  list<array{schema: ?string, table: string}>  $pairs
     */
    protected function assertModelsAvailable(array $pairs, GenerationOptions $options): bool
    {
        $registry = $this->modelRegistry();

        if ($registry->isEmpty()) {
            $this->newLine();
            $this->error('❌ No generated models are known, so no dependent classes can be generated.');
            $this->line('   Generate models first:');
            $this->line('     <fg=cyan>php artisan anvil:forge --models'.$this->schemaArgument($options).'</>');

            return false;
        }

        $missing = $registry->missingFor($pairs);

        if ($missing !== []) {
            $this->newLine();
            $this->error('❌ No generated model for '.count($missing).' target table(s):');

            foreach (array_slice($missing, 0, 20) as $target) {
                $this->line("   • {$target}");
            }

            if (count($missing) > 20) {
                $this->line('   • … '.(count($missing) - 20).' more');
            }

            $this->newLine();
            $this->line('   Generate models first, then re-run this command:');
            $this->line('     <fg=cyan>php artisan anvil:forge --models'.$this->schemaArgument($options).'</>');
            $this->line('   <fg=gray>Dependent classes are never emitted with a guessed model namespace.</>');

            return false;
        }

        $this->warnOnUnregisteredRelations($pairs);

        return true;
    }

    /**
     * Foreign keys that point at a table outside this run. The relation will be
     * emitted against a model that does not exist, so say so — but do not abort:
     * the referenced schema may legitimately be out of scope.
     *
     * @param  list<array{schema: ?string, table: string}>  $pairs
     */
    protected function warnOnUnregisteredRelations(array $pairs): void
    {
        if ($this->referencedTables === []) {
            return;
        }

        $inScope = [];

        foreach ($pairs as $pair) {
            $inScope[strtolower(($pair['schema'] ?? '').'.'.$pair['table'])] = true;
        }

        $orphans = [];

        foreach ($this->referencedTables as $key => $pair) {
            if (isset($inScope[$key])) {
                continue;
            }

            if ($this->modelRegistry()->has($pair['table'], $pair['schema'])) {
                continue;
            }

            $orphans[] = ($pair['schema'] !== null ? $pair['schema'].'.' : '').$pair['table'];
        }

        if ($orphans === []) {
            return;
        }

        sort($orphans);

        $this->components->warn(sprintf(
            '%d foreign key target(s) have no generated model: %s. Relations to them will reference a class that '
                .'does not exist. Include their schema with --schema, or --schema=all.',
            count($orphans),
            implode(', ', array_slice($orphans, 0, 8)).(count($orphans) > 8 ? ', …' : ''),
        ));
    }

    /**
     * Persist the manifest so later --api / --web runs need neither the model
     * phase nor a disk scan.
     */
    protected function saveModelRegistry(GenerationOptions $options): ?string
    {
        $registry = $this->modelRegistry()
            ->setConnection($options->getConnection())
            ->setRootNamespace($options->getNamespace());

        if ($registry->isEmpty()) {
            return null;
        }

        $path = $this->modelManifestPath();

        if ($options->dryRun) {
            $this->line(sprintf(
                '  <fg=gray>🔸 Would write model manifest (%d entries) → %s</>',
                $registry->count(),
                $this->relativeToBase($path),
            ));

            return null;
        }

        try {
            File::ensureDirectoryExists(dirname((string) $path));
            File::put($path, $registry->toJson());
        } catch (\Throwable $e) {
            $this->components->warn(sprintf(
                'Could not write the model manifest to %s (%s). Dependent generators will fall back to scanning '
                    .'app/Models, which still resolves namespaces correctly but costs a filesystem walk.',
                $this->relativeToBase($path),
                $e->getMessage(),
            ));

            return null;
        }

        $this->line(sprintf(
            '  <fg=gray>📇 Model manifest (%d entries) → %s</>',
            $registry->count(),
            $this->relativeToBase($path),
        ));

        return $path;
    }

    /**
     * Make the registry reachable by the generators.
     *
     * Bound on the container so a generator can type-hint it, and pushed onto the
     * orchestrator when it exposes a setter. Neither makes a generator *use* it —
     * each one still has to stop deriving its own FQCN.
     */
    protected function shareModelRegistry(): void
    {
        $registry = $this->modelRegistry();

        app()->instance(ModelRegistry::class, $registry);

        if (isset($this->orchestrator) && method_exists($this->orchestrator, 'setModelRegistry')) {
            $this->orchestrator->setModelRegistry($registry);
        }
    }

    /**
     * Namespace segment => schema name, for recovering the schema of a model whose
     * $table is bare (search_path set on the connection).
     *
     * Built with ReservedNames::namespaceSegment(), the same helper generateModel()
     * and ModelBuilder use, so the two cannot disagree on Public vs PublicSchema.
     *
     * @return array<string, string>
     */
    protected function schemaSegmentMap(GenerationOptions $options): array
    {
        $inspector = $this->modelSchemaInspector($options);

        if (! $inspector instanceof DatabaseInspector) {
            return [];
        }

        $default = $inspector->defaultSchema();
        $map = [];

        foreach ($inspector->resolveSchemas($options->getSchemaSelection()) as $schema) {
            if (in_array($schema, [null, '', $default], true)) {
                continue;
            }

            $map[ReservedNames::namespaceSegment($schema)] = $schema;
        }

        return $map;
    }

    /**
     * The inspector, if the pipeline has already built one. Discovery must work
     * before setupComponents() in a command that only wants to read the registry.
     */
    protected function modelSchemaInspector(GenerationOptions $options): ?DatabaseInspector
    {
        if (isset($this->inspector) && $this->inspector instanceof DatabaseInspector) {
            return $this->inspector;
        }

        try {
            return new DatabaseInspector($options->getConnection());
        } catch (\Throwable) {
            return null;
        }
    }

    protected function modelsDirectory(GenerationOptions $options): string
    {
        $namespace = trim($options->getNamespace(), '\\');
        $base = trim($options->getPath(), '/');

        // "App\Models" under base path "app" → app/Models.
        $segments = explode('\\', $namespace);
        array_shift($segments); // drop the "App" that $base already represents

        return base_path(trim($base.'/'.implode('/', $segments), '/'));
    }

    protected function warnOnManifestConnectionMismatch(GenerationOptions $options): void
    {
        $manifestConnection = $this->modelRegistry()->connection();
        $current = $options->getConnection();

        if ($manifestConnection === null || $manifestConnection === $current) {
            return;
        }

        $this->components->warn(sprintf(
            'The model manifest was generated against connection [%s] but this run targets [%s]. '
                .'Re-run with --models to refresh it if the schemas differ.',
            $manifestConnection,
            $current,
        ));
    }

    protected function schemaArgument(GenerationOptions $options): string
    {
        $selection = $options->getSchemaSelection();

        if (is_array($selection)) {
            $selection = implode(',', array_map(strval(...), $selection));
        }

        $selection = is_string($selection) ? trim($selection) : '';

        return $selection === '' ? '' : ' --schema='.$selection;
    }

    protected function relativeToBase(string $path): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
