<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Options;

/**
 * Which generators run.
 *
 * GenerationOptions currently carries around twenty independent booleans for this
 * — models, controllers, resources, observers, policies, formRequests, services,
 * repositories, gates, apiRoutes, factories, seeders, migrations, events,
 * listeners, tests, api, web, openApi — and every consumer has to know the whole
 * list. RunsGenerationPipeline's needsOrchestrator check is a nineteen-term
 * boolean expression that has to be edited whenever a generator is added, and
 * forgetting to is a silent no-op.
 *
 * A set makes membership the only question, so adding a generator touches one
 * constant instead of every consumer.
 */
final readonly class GeneratorSet
{
    // Core artefacts.
    public const MODELS = 'models';

    public const CONTROLLERS = 'controllers';

    public const RESOURCES = 'resources';

    public const OBSERVERS = 'observers';

    public const POLICIES = 'policies';

    public const FORM_REQUESTS = 'form_requests';

    public const SERVICES = 'services';

    public const REPOSITORIES = 'repositories';

    public const GATES = 'gates';

    public const FACTORIES = 'factories';

    public const SEEDERS = 'seeders';

    public const MIGRATIONS = 'migrations';

    public const EVENTS = 'events';

    public const LISTENERS = 'listeners';

    public const TESTS = 'tests';

    public const ENUMS = 'enums';

    // Scaffolds — each pulls in a family of generators.
    public const API = 'api';

    public const API_ROUTES = 'api_routes';

    public const WEB = 'web';

    public const OPEN_API = 'open_api';

    public const GRAPHQL = 'graphql';

    /** @var list<string> */
    public const ALL = [
        self::MODELS,
        self::CONTROLLERS,
        self::RESOURCES,
        self::OBSERVERS,
        self::POLICIES,
        self::FORM_REQUESTS,
        self::SERVICES,
        self::REPOSITORIES,
        self::GATES,
        self::FACTORIES,
        self::SEEDERS,
        self::MIGRATIONS,
        self::EVENTS,
        self::LISTENERS,
        self::TESTS,
        self::ENUMS,
        self::API,
        self::API_ROUTES,
        self::WEB,
        self::OPEN_API,
        self::GRAPHQL,
    ];

    /**
     * Generators that need the per-model orchestrator pass.
     *
     * This replaces the nineteen-term expression in RunsGenerationPipeline. A new
     * generator is added by naming it here, and nothing else changes.
     *
     * @var list<string>
     */
    private const NEEDS_ORCHESTRATOR = [
        self::CONTROLLERS,
        self::RESOURCES,
        self::OBSERVERS,
        self::POLICIES,
        self::FORM_REQUESTS,
        self::SERVICES,
        self::REPOSITORIES,
        self::GATES,
        self::API_ROUTES,
        self::FACTORIES,
        self::SEEDERS,
        self::MIGRATIONS,
        self::EVENTS,
        self::LISTENERS,
        self::TESTS,
        self::ENUMS,
        self::API,
        self::WEB,
        self::OPEN_API,
        self::GRAPHQL,
    ];

    /** @param list<string> $enabled */
    private function __construct(public array $enabled) {}

    /**
     * @param  iterable<string>  $names
     *
     * @throws \InvalidArgumentException on an unrecognised generator name
     */
    public static function of(iterable $names): self
    {
        $enabled = [];
        $unknown = [];

        foreach ($names as $name) {
            $normalised = self::normalise((string) $name);

            in_array($normalised, self::ALL, true)
                ? $enabled[$normalised] = true
                : $unknown[] = (string) $name;
        }

        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                "Unknown generator(s): %s.\nValid: %s",
                implode(', ', $unknown),
                implode(', ', self::ALL),
            ));
        }

        return new self(array_keys($enabled));
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function all(): self
    {
        return new self(self::ALL);
    }

    /**
     * What anvil:forge-webapp turns on.
     *
     * Services and form requests are not optional here — the generated
     * controllers depend on both — which is a constraint the command currently
     * expresses by hardcoding two array keys with a comment explaining why.
     */
    public static function webScaffold(bool $withModels = true): self
    {
        $names = [self::WEB, self::SERVICES, self::FORM_REQUESTS];

        if ($withModels) {
            $names[] = self::MODELS;
        }

        return self::of($names);
    }

    /** What anvil:generate-api turns on. */
    public static function apiScaffold(bool $withSpec = true, bool $withTests = true): self
    {
        $names = [self::API, self::SERVICES];

        if ($withSpec) {
            $names[] = self::OPEN_API;
        }

        if ($withTests) {
            $names[] = self::TESTS;
        }

        return self::of($names);
    }

    /** Spec only, no scaffold. */
    public static function specOnly(): self
    {
        return self::of([self::OPEN_API]);
    }

    // -----------------------------------------------------------------------
    // Membership
    // -----------------------------------------------------------------------

    public function has(string $generator): bool
    {
        return in_array(self::normalise($generator), $this->enabled, true);
    }

    public function hasAny(string ...$generators): bool
    {
        foreach ($generators as $generator) {
            if ($this->has($generator)) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->enabled === [];
    }

    /** Whether the per-model orchestrator pass is needed at all. */
    public function needsOrchestrator(): bool
    {
        return $this->hasAny(...self::NEEDS_ORCHESTRATOR);
    }

    // -----------------------------------------------------------------------
    // Composition
    // -----------------------------------------------------------------------

    public function plus(string ...$generators): self
    {
        return self::of([...$this->enabled, ...$generators]);
    }

    public function minus(string ...$generators): self
    {
        $remove = array_map(self::normalise(...), $generators);

        return new self(array_values(array_filter(
            $this->enabled,
            static fn (string $name): bool => ! in_array($name, $remove, true),
        )));
    }

    /**
     * Force a generator on or off from a boolean flag — the shape a console
     * option arrives in.
     */
    public function toggle(string $generator, bool $on): self
    {
        return $on ? $this->plus($generator) : $this->minus($generator);
    }

    /**
     * Human-readable list for the generation plan.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = $this->enabled;
        sort($names);

        return $names;
    }

    public function describe(): string
    {
        return $this->isEmpty() ? 'nothing' : implode(', ', $this->names());
    }

    private static function normalise(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', str_replace('-', '_', $name)));
    }
}
