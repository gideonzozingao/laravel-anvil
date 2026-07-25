<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Generators;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Generators\Concerns\WritesVersionedFiles;
use Zuqongtech\LaravelAnvil\Support\ApiVersionProfile;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates a per-version service SUBCLASS — opt-in, off by default.
 *
 *   App\Services\Api\V2\UserService extends App\Services\UserService
 *
 * WHY THIS IS OFF BY DEFAULT
 *
 * The service layer exists so business logic lives in exactly one place; the web
 * scaffold and every API version share it deliberately. Generating a full service
 * per version duplicates that logic, and the copies drift — a bug fixed in v2's
 * create() stays broken in v1's, silently, because nothing links them.
 *
 * What legitimately differs between versions is the SHAPE of input and output,
 * and that already has homes: form requests for input, resources for output.
 * Reach for a versioned service only when the BEHAVIOUR differs — v2 charging a
 * card that v1 invoiced, say.
 *
 * So when this is enabled it emits an empty subclass with the override points
 * documented, rather than a copy. Everything not overridden keeps following the
 * shared implementation, including later fixes to it.
 *
 * Enable with: anvil.api.versions.{v}.versioned_services => true
 */
final class ApiServiceGenerator implements Generator
{
    use WritesVersionedFiles;

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

        return [
            $this->writeClass(
                $this->getName(),
                $namespace,
                $meta->model.'Service',
                $options,
                fn (): string => $this->buildService($meta, $profile, $namespace),
                // Never overwritten: once a version's behaviour has been
                // customised here, --force must not silently discard it.
                overwritable: false,
            ),
        ];
    }

    protected function buildService(ModelMetadata $meta, ApiVersionProfile $profile, string $namespace): string
    {
        $model = $meta->model;
        $service = $model.'Service';
        $version = $profile->version;

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
 *   public function create(array \$attributes): \\{$model}
 *   {
 *       // {$version}-specific pre-processing…
 *
 *       return parent::create(\$attributes);
 *   }
 *
 * Input and output SHAPE do not belong here — that is what
 * App\Http\Requests\Api\\{$profile->segment()} and
 * App\Http\Resources\Api\\{$profile->segment()} are for. If the only difference
 * is field names or casing, this class should stay empty.
 *
 * Anvil never overwrites this file once created.
 */
class {$service} extends SharedService
{
    //
}

PHP;
    }
}
