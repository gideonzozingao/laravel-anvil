<?php

namespace Zuqongtech\LaravelAnvil\DocsSync;

use Throwable;
use Zuqongtech\LaravelAnvil\Contracts\ShapeReader;
use Zuqongtech\LaravelAnvil\DocsSync\Mappers\ColumnSchemas;
use Zuqongtech\LaravelAnvil\DocsSync\Readers\FormRequestShapeReader;
use Zuqongtech\LaravelAnvil\DocsSync\Readers\ResourceShapeReader;

/**
 * Single authority for reconciling the OpenAPI spec with hand-edited payload code.
 *
 * The console command, the `--docs-sync` pipeline flag and the optional local
 * auto-sync hook are all thin adapters over `sync()`, so one set of safety rules
 * lives in one place:
 *
 *   - components without the `x-anvil.managed` marker are never rewritten (adopt
 *     explicitly with --adopt)
 *   - components marked `managed: false` are never touched, even with --adopt
 *   - partial reads never prune
 *   - unresolved properties defer to the spec
 *   - --check never writes anything at all
 *
 * What it deliberately does NOT do: rewrite `paths`. Endpoint structure comes from
 * routes and controllers, which `anvil:generate --openapi` already owns. Growing a
 * second, subtly different path generator here would be a bug factory, so sync
 * reports missing components and tells you to regenerate instead.
 */
final readonly class DocsSynchronizer
{
    /** @var list<ShapeReader> */
    private array $readers;

    /**
     * @param  list<ShapeReader>|null  $readers  full override; null builds the default set
     * @param  list<ShapeReader>  $extraReaders  prepended to the defaults, so they win
     *                                           for classes they support while the built-in
     *                                           readers still handle everything else
     */
    public function __construct(
        private SpecFiles $spec,
        private TargetDiscovery $discovery,
        ?array $readers = null,
        private SchemaDiff $differ = new SchemaDiff,
        private ?ComponentNaming $naming = null,
        private ?string $manifestPath = null,
        /** @var list<string> */
        private array $enumNamespaces = [],
        /** @var list<ShapeReader> */
        private array $extraReaders = [],
    ) {
        $this->readers = $readers ?? [];
    }

    public function sync(SyncOptions $options): SyncReport
    {
        $report = new SyncReport($options->check);

        if (! $this->spec->exists()) {
            $report->add(
                SyncReport::FAILED,
                '(spec)',
                $this->spec->rootPath(),
                'No spec found. Run `php artisan anvil:generate --openapi` first.',
            );

            return $report;
        }

        try {
            $componentSchemas = $this->spec->componentSchemas();
        } catch (Throwable $e) {
            $report->add(SyncReport::FAILED, '(spec)', $this->spec->rootPath(), 'Spec could not be parsed: '.$e->getMessage());

            return $report;
        }

        $naming = $this->naming ?? new ComponentNaming;
        $columns = ColumnSchemas::fromComponents($componentSchemas);
        $readers = $this->readers !== [] ? $this->readers : $this->defaultReaders($columns, $naming);

        $targets = $this->discovery->discover($options);

        if ($targets === []) {
            $report->add(SyncReport::SKIPPED, '(discovery)', '', 'No resource or form request classes found in the configured roots.');

            return $report;
        }

        // Resolve component names first, so collisions are detected before any write.
        $resolved = [];

        foreach ($targets as $target) {
            $resolved[$target->class] = $this->resolveComponent($naming, $target, $componentSchemas);
        }

        $collisions = TargetDiscovery::collisions($targets, $resolved);

        $manifest = SyncManifest::load(
            $this->manifestPath ?? $this->spec->directory().DIRECTORY_SEPARATOR.'.anvil-sync.json',
        );

        $merger = new SpecMerger($options->preservesAnnotations(), $options->prune);

        foreach ($targets as $target) {
            $component = $resolved[$target->class];

            if (isset($collisions[$component])) {
                $report->add(
                    SyncReport::SKIPPED,
                    $component,
                    $target->class,
                    'Several classes claim this component ('.implode(', ', $collisions[$component]).'); set anvil.openapi.sync.schema_names to disambiguate.',
                );

                continue;
            }

            $this->syncTarget($target, $component, $componentSchemas, $readers, $merger, $manifest, $options, $report);
        }

        if ($options->writes() && $this->spec->hasPendingWrites()) {
            try {
                $report->recordWrites($this->spec->flush());
                $manifest->save();
            } catch (Throwable $e) {
                $report->add(SyncReport::FAILED, '(spec)', $this->spec->rootPath(), 'Write failed: '.$e->getMessage());
            }
        }

        return $report;
    }

    /**
     * @param  array<string, array<string, mixed>>  $componentSchemas
     * @param  list<ShapeReader>  $readers
     */
    private function syncTarget(
        SyncTarget $target,
        string $component,
        array $componentSchemas,
        array $readers,
        SpecMerger $merger,
        SyncManifest $manifest,
        SyncOptions $options,
        SyncReport $report,
    ): void {
        $reader = null;

        foreach ($readers as $candidate) {
            if ($candidate->supports($target->class)) {
                $reader = $candidate;

                break;
            }
        }

        if ($reader === null) {
            $report->add(SyncReport::SKIPPED, $component, $target->class, 'No reader supports this class.');

            return;
        }

        try {
            $shape = $reader->read($target->class, [
                'file' => $target->file,
                'model' => $target->model,
                'version' => $target->version,
            ]);
        } catch (Throwable $e) {
            $report->add(SyncReport::FAILED, $component, $target->class, 'Reader error: '.$e->getMessage());

            return;
        }

        if ($shape === null) {
            $report->add(
                SyncReport::SKIPPED,
                $component,
                $target->class,
                $target->kind === CodeShape::REQUEST
                    ? 'Could not read rules() -- it does not return an array literal directly.'
                    : 'Could not read toArray() -- it is missing, or does not return an array literal directly.',
            );

            return;
        }

        $existing = $componentSchemas[$component] ?? null;

        if ($existing === null) {
            // The spec has no such component. Sync does not invent endpoints, so
            // creating a floating schema nothing references would be misleading
            // unless the user explicitly opted in.
            if (! $options->adopt) {
                $report->add(
                    SyncReport::SKIPPED,
                    $component,
                    $target->class,
                    'No such component in the spec. Run `anvil:generate --openapi` to create the endpoint, or --adopt to add the schema.',
                );

                return;
            }

            $existing = [];
        }

        if (SpecFiles::isOptedOut($existing)) {
            $report->add(SyncReport::SKIPPED, $component, $target->class, 'Component is marked x-anvil.managed=false.');

            return;
        }

        $isNew = $existing === [];
        $managed = ! $isNew && SpecFiles::isManaged($existing);

        if (! $managed && ! $isNew && ! $options->adopt) {
            $report->add(
                SyncReport::SKIPPED,
                $component,
                $target->class,
                'Component is not managed by sync yet -- re-run with --adopt to take ownership.',
            );

            return;
        }

        $changes = $this->differ->compare($shape, $existing);
        $specFingerprint = $isNew ? '' : $this->spec->fingerprintFor($component);
        $notes = $shape->notes;

        if (! $isNew && $manifest->specEditedByHand($component, $specFingerprint)) {
            $notes[] = 'The spec for this component was edited directly since the last sync. Prose is preserved; structure will be taken from code.';
        }

        if ($changes === [] && $managed) {
            $report->add(SyncReport::UNCHANGED, $component, $target->class, '', [], $notes);
            $manifest->record($component, $shape->fingerprint, $specFingerprint);

            return;
        }

        // Check mode: report, write nothing. A CI gate that mutates the working tree
        // is a trap -- the build "passes" on the second run and hides the drift.
        if ($options->check) {
            $report->add(SyncReport::STALE, $component, $target->class, 'Spec does not match the code.', $changes, $notes);

            return;
        }

        try {
            $result = $merger->merge($shape, $existing, $component);
        } catch (Throwable $e) {
            $report->add(SyncReport::FAILED, $component, $target->class, 'Merge error: '.$e->getMessage());

            return;
        }

        if ($result['pruned'] !== []) {
            $notes[] = 'Removed from the spec: '.implode(', ', $result['pruned']).'.';
        }

        if ($result['protected'] !== []) {
            $notes[] = 'Left untouched (x-anvil.managed=false): '.implode(', ', $result['protected']).'.';
        }

        if ($unresolved = $shape->unresolvedNames()) {
            $notes[] = 'Could not type: '.implode(', ', $unresolved).'. Annotate these in the spec and sync will keep your annotation.';
        }

        if ($options->dryRun) {
            $report->add(SyncReport::STALE, $component, $target->class, 'Would update (dry run).', $changes, $notes);

            return;
        }

        if ($isNew) {
            $this->spec->createComponentSchema($component, $result['schema']);
        } else {
            $this->spec->putComponentSchema($component, $result['schema']);
        }

        $manifest->record($component, $shape->fingerprint, sha1((string) json_encode(self::withoutMarker($result['schema']))));

        $report->add(SyncReport::SYNCED, $component, $target->class, '', $changes, $notes);
    }

    /**
     * Pick the component this class documents: the first candidate that already
     * exists in the spec, falling back to the preferred name. This is what stops
     * sync creating `VehicleStoreRequest` alongside the real `StoreVehicleRequest`.
     *
     * @param  array<string, array<string, mixed>>  $componentSchemas
     */
    private function resolveComponent(ComponentNaming $naming, SyncTarget $target, array $componentSchemas): string
    {
        $candidates = $naming->candidatesFor($target->class, $target->kind);

        foreach ($candidates as $candidate) {
            if (isset($componentSchemas[$candidate])) {
                return $candidate;
            }
        }

        return $candidates[0] ?? ComponentNaming::shortName($target->class);
    }

    /**
     * Custom readers first, built-ins after. Order is the resolution order -- the
     * first reader whose supports() returns true wins -- so prepending lets an app
     * take over one class shape without having to reimplement the other.
     *
     * @return list<ShapeReader>
     */
    private function defaultReaders(ColumnSchemas $columns, ComponentNaming $naming): array
    {
        return [
            ...$this->extraReaders,
            new ResourceShapeReader($columns, $naming),
            new FormRequestShapeReader($columns, true, $this->enumNamespaces),
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function withoutMarker(array $schema): array
    {
        unset($schema['x-anvil']);

        return $schema;
    }
}
