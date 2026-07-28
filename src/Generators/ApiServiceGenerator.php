<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Generators;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Generators\Concerns\WritesVersionedFiles;
use Zuqongtech\LaravelAnvil\Support\ApiVersionProfile;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\Helpers;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates a per-version service SUBCLASS — opt-in, off by default.
 *
 *   App\Services\Api\V3\PaymentService extends App\Services\PaymentService
 *
 * WHY THIS IS A SUBCLASS AND NOT A COPY
 *
 * The service layer exists so business logic lives in exactly one place; the web
 * scaffold and every API version share it deliberately. Generating a full service
 * per version duplicates that logic, and the copies drift — a bug fixed in v3's
 * create() stays broken in v1's, silently, because nothing links them.
 *
 * What legitimately differs between versions is the SHAPE of input and output,
 * and that already has homes: form requests for input, resources for output.
 * Reach for a versioned service only when the BEHAVIOUR differs.
 *
 * WHERE CACHING LIVES
 *
 * In the SHARED service, not here. A service returns models and paginators, not
 * shaped payloads, so v1 and v3 asking for the same page want the same rows and
 * should share one cache entry. Duplicating the cache per version would multiply
 * memory for identical data.
 *
 * The exception is a version that overrides a read method. Then its results are
 * NOT the shared ones, and sharing a cache key would serve one version's rows to
 * another. That is why the generated subclass carries an explicit
 * $cacheVariant = null (share with the parent) plus a warning to delete that line
 * on the first read override — see CachesQueries for the full explanation.
 *
 * Enable with: anvil.api.versions.{v}.versioned_services => true
 *              or --versioned-services on anvil:generate-api
 */
final class ApiServiceGenerator implements Generator
{
    use WritesVersionedFiles;

    /** Marker used to recognise a file this generator wrote with cache wiring. */
    private const CACHE_MARKER = 'CachesQueries';

    #[\Override]
    public function supports(GenerationOptions $options): bool
    {
        if (! $options->api) {
            return false;
        }

        return (bool) ApiVersionProfile::for($options->apiVersion)->get('versioned_services', false);
    }

    #[\Override]
    public function getName(): string
    {
        return 'ApiService';
    }

    #[\Override]
    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $profile = $this->profile($options);

        $namespace = trim((string) $profile->get('namespaces.services', 'App\\Services\\Api'), '\\')
            .'\\'.$profile->segment();

        $results = [
            $this->writeClass(
                $this->getName(),
                $namespace,
                $meta->model.'Service',
                $options,
                fn (): string => $this->buildService($meta, $profile, $namespace, $options),
                // Never overwritten: once a version's behaviour has been
                // customised here, --force must not silently discard it.
                overwritable: false,
            ),
        ];

        // A file written before caching was enabled will never be updated,
        // because this generator refuses to overwrite. Saying nothing leaves the
        // operator wondering why --cache changed nothing.
        if (($stale = $this->staleFileWarning($meta, $profile, $namespace, $options)) !== null) {
            $results[] = $stale;
        }

        return $results;
    }

    /**
     * Warn when an existing versioned service predates the cache wiring.
     *
     * @return array<string, mixed>|null
     */
    private function staleFileWarning(
        ModelMetadata $meta,
        ApiVersionProfile $profile,
        string $namespace,
        GenerationOptions $options,
    ): ?array {
        if (! $this->cachingEnabled($options)) {
            return null;
        }

        $path = $profile->pathFor($namespace, $meta->model.'Service');

        if (! is_file($path)) {
            return null;
        }

        $contents = (string) @file_get_contents($path);

        if (str_contains($contents, self::CACHE_MARKER)) {
            return null;
        }

        return [
            'type' => $this->getName(),
            'name' => $meta->model.'Service',
            'path' => $path,
            'status' => 'warning',
            'reason' => 'exists without cache wiring; this generator never overwrites, so delete the file and re-run '
                .'to pick up the cache configuration',
        ];
    }

    private function buildService(
        ModelMetadata $meta,
        ApiVersionProfile $profile,
        string $namespace,
        GenerationOptions $options,
    ): string {
        $model = $meta->model;
        $service = $model.'Service';
        $version = $profile->version;
        $segment = $profile->segment();

        // The model's real FQCN. The previous template interpolated the bare
        // class name after a single backslash, producing "\Payment" — the root
        // namespace — so anyone copying the override example got a
        // class-not-found on a type that looks plausible.
        $modelFqn = trim($options->getNamespace(), '\\').'\\'.$model;

        $cacheBlock = $this->cachingEnabled($options)
            ? $this->cacheBlock($meta, $options)
            : '    //';

        return <<<PHP
<?php

namespace {$namespace};

use App\Services\\{$service} as SharedService;

/**
 * {$version}-specific behaviour for {$model}.
 *
 * Extends the shared service rather than replacing it, so anything not
 * overridden here — including future fixes — is inherited. Override a method
 * only when {$version} genuinely behaves differently:
 *
 *   public function create(array \$attributes): \\{$modelFqn}
 *   {
 *       // {$version}-specific pre-processing...
 *
 *       return parent::create(\$attributes);
 *   }
 *
 * Input and output SHAPE do not belong here — that is what
 * App\Http\Requests\Api\\{$segment} and App\Http\Resources\Api\\{$segment} are
 * for. If the only difference is field names or casing, this class should stay
 * empty.
 *
 * Anvil never overwrites this file once created.
 */
class {$service} extends SharedService
{
{$cacheBlock}
}

PHP;
    }

    /**
     * The cache declaration for a versioned subclass.
     *
     * $cacheVariant = null makes this class share the shared service's cache
     * entries, which is correct precisely while this class overrides nothing. The
     * comment is the important part of the output: the first read override makes
     * that line a cross-version data leak.
     */
    private function cacheBlock(ModelMetadata $meta, GenerationOptions $options): string
    {
        $modelFqn = '\\'.trim($options->getNamespace(), '\\').'\\'.$meta->model;
        $dependencies = $this->dependencyList($meta, $options);

        $depsBlock = $dependencies === ''
            ? ''
            : <<<PHP


    /**
     * Models this version's payload embeds. A change to any of them invalidates
     * this service's cached results.
     *
     * @var list<class-string>
     */
    protected array \$cacheDependencies = [
{$dependencies}
    ];
PHP;

        return <<<PHP
    /**
     * Shares the shared service's cache, because this class currently changes
     * nothing about how records are read.
     *
     * DELETE THIS LINE the moment you override a read method — paginate(), find(),
     * findOrFail(), or anything returning records. While it is null this class
     * and every other version resolve to the SAME cache key, so an override's
     * results would be served to other versions and theirs to this one.
     */
    protected ?string \$cacheVariant = null;

    /** The model whose cache generation this service keys against. */
    protected string \$cacheModel = {$modelFqn}::class;{$depsBlock}
PHP;
    }

    /**
     * The models this version's resource actually embeds.
     *
     * Derived from the same metadata ApiResourceGenerator uses to render
     * whenLoaded() calls, so the dependency list matches what the payload really
     * contains rather than every table the schema references.
     */
    private function dependencyList(ModelMetadata $meta, GenerationOptions $options): string
    {
        $root = trim($options->getNamespace(), '\\');
        $models = [];

        foreach ($meta->foreignKeys as $fk) {
            $column = (string) ($fk['column'] ?? '');

            if ($column === '' || $meta->belongsToName($column) === null) {
                continue;
            }

            $models[] = Helpers::tableToModelName((string) $fk['referenced_table']);
        }

        foreach ($meta->inverseRelationships as $row) {
            $table = (string) ($row['table'] ?? '');
            $column = (string) ($row['column'] ?? $row['foreign_key'] ?? '');

            if ($table === '' || $meta->inverseName($table, $column) === null) {
                continue;
            }

            $models[] = Helpers::tableToModelName($table);
        }

        // The service's own model is added by CachesQueries; listing it here
        // would only produce a duplicate stamp lookup.
        $models = array_values(array_filter(
            array_unique($models),
            static fn (string $name): bool => $name !== $meta->model,
        ));

        sort($models);

        return implode("\n", array_map(
            static fn (string $name): string => sprintf('        \\%s\\%s::class,', $root, $name),
            $models,
        ));
    }

    private function cachingEnabled(GenerationOptions $options): bool
    {
        return ($options->cache ?? false) || (bool) config('anvil.cache.enabled', false);
    }
}
