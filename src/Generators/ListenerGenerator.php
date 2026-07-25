<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Generators;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates handlers for the classes produced by EventGenerator.
 *
 * Two shapes, chosen with --listener-style:
 *
 *   per-event (default)
 *     App\Listeners\{Model}\CreatedListener   handle({Model}Created $event)
 *     App\Listeners\{Model}\UpdatedListener
 *     App\Listeners\{Model}\DeletedListener
 *     App\Listeners\{Model}\RestoredListener  (when the model soft-deletes)
 *
 *   subscriber
 *     App\Listeners\{Model}EventSubscriber    one class, one method per event
 *
 * Laravel 11+ discovers listeners under app/Listeners by convention: the
 * handle() parameter type IS the registration, so per-event listeners need no
 * provider mapping. Subscribers are NOT discovered — the generated class
 * documents the one-line Event::subscribe() call needed.
 *
 * --queued-listeners makes each listener implement ShouldQueue. It applies to
 * the per-event style only; a subscriber's methods are plain callbacks and
 * cannot be queued as a unit.
 *
 * Listeners are useless without their events, so anvil:generate implies
 * --events whenever --listeners is passed.
 */
final class ListenerGenerator implements Generator
{
    public const STYLE_PER_EVENT = 'per-event';

    public const STYLE_SUBSCRIBER = 'subscriber';

    #[\Override]
    public function supports(GenerationOptions $options): bool
    {
        return $this->enabled($options);
    }

    #[\Override]
    public function getName(): string
    {
        return 'Listener';
    }

    #[\Override]
    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $actions = ['Created', 'Updated', 'Deleted'];

        if ($meta->softDeletes) {
            $actions[] = 'Restored';
        }

        if ($this->style($options) === self::STYLE_SUBSCRIBER) {
            return [$this->generateSubscriber($meta, $actions, $options)];
        }

        $results = [];

        foreach ($actions as $action) {
            $results[] = $this->generateListener($meta, $action, $options);
        }

        return $results;
    }

    // ── Per-event listeners ─────────────────────────────────────────────────

    protected function generateListener(ModelMetadata $meta, string $action, GenerationOptions $options): array
    {
        $namespace = $this->listenerNamespace($options).'\\'.$meta->model;
        $class = $action.'Listener';

        return $this->write(
            $namespace,
            $class,
            $options,
            fn (): string => $this->buildListener($meta, $action, $namespace, $class, $options),
        );
    }

    protected function buildListener(
        ModelMetadata $meta,
        string $action,
        string $namespace,
        string $class,
        GenerationOptions $options,
    ): string {
        $event = $meta->model.$action;
        $eventFqcn = $this->eventNamespace($options).'\\'.$event;
        $variable = lcfirst($meta->model);
        $queued = $this->queued($options);

        $docComment = match ($action) {
            'Created' => "Reacts to a new {$meta->model} being persisted.",
            'Updated' => "Reacts to an existing {$meta->model} changing.",
            'Deleted' => "Reacts to a {$meta->model} being deleted.",
            'Restored' => "Reacts to a soft-deleted {$meta->model} being restored.",
            default => "Reacts to {$meta->model} {$action}.",
        };

        $uses = ["use {$eventFqcn};"];
        $implements = '';
        $body = '';

        if ($queued) {
            $uses[] = 'use Illuminate\Contracts\Queue\ShouldQueue;';
            $uses[] = 'use Illuminate\Queue\InteractsWithQueue;';
            $implements = ' implements ShouldQueue';

            $body = <<<'PHP'
    use InteractsWithQueue;

    /**
     * The number of times the queued listener may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     */
    public int $backoff = 10;

    /**
     * The queue this listener is dispatched onto (null = default).
     */
    public ?string $queue = null;


PHP;
        }

        $failed = '';

        if ($queued) {
            $failed = <<<PHP

    /**
     * Handle a failure of the queued listener.
     */
    public function failed({$event} \$event, \Throwable \$exception): void
    {
        // TODO: report the failure, or leave to the default exception handler.
    }

PHP;
        }

        $useBlock = implode("\n", $uses);

        return <<<PHP
<?php

namespace {$namespace};

{$useBlock}

/**
 * {$docComment}
 *
 * Registered by convention: Laravel discovers listeners in app/Listeners and
 * binds them to the event named in the handle() signature. No provider entry
 * is required.
 */
class {$class}{$implements}
{
{$body}    /**
     * Handle the event.
     */
    public function handle({$event} \$event): void
    {
        \${$variable} = \$event->{$variable};

        // TODO: implement the side effect for this transition.
    }
{$failed}}

PHP;
    }

    // ── Subscriber ──────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $actions
     */
    protected function generateSubscriber(ModelMetadata $meta, array $actions, GenerationOptions $options): array
    {
        $namespace = $this->listenerNamespace($options);
        $class = $meta->model.'EventSubscriber';

        return $this->write(
            $namespace,
            $class,
            $options,
            fn (): string => $this->buildSubscriber($meta, $actions, $namespace, $class, $options),
        );
    }

    /**
     * @param  list<string>  $actions
     */
    protected function buildSubscriber(
        ModelMetadata $meta,
        array $actions,
        string $namespace,
        string $class,
        GenerationOptions $options,
    ): string {
        $eventNamespace = $this->eventNamespace($options);
        $variable = lcfirst($meta->model);

        $uses = [];
        $handlers = [];
        $map = [];

        foreach ($actions as $action) {
            $event = $meta->model.$action;
            $uses[] = "use {$eventNamespace}\\{$event};";

            $handlers[] = <<<PHP
    /**
     * Handle the {$event} event.
     */
    public function handle{$action}({$event} \$event): void
    {
        \${$variable} = \$event->{$variable};

        // TODO: implement the side effect for this transition.
    }
PHP;

            $map[] = "            {$event}::class => 'handle{$action}',";
        }

        $uses[] = 'use Illuminate\Events\Dispatcher;';

        $useBlock = implode("\n", $uses);
        $handlerBlock = implode("\n\n", $handlers);
        $mapBlock = implode("\n", $map);

        return <<<PHP
<?php

namespace {$namespace};

{$useBlock}

/**
 * Subscribes to every {$meta->model} lifecycle event.
 *
 * Subscribers are not auto-discovered. Register this one in a service provider:
 *
 *     use Illuminate\Support\Facades\Event;
 *
 *     Event::subscribe({$class}::class);
 */
class {$class}
{
{$handlerBlock}

    /**
     * Map the events this subscriber handles to its methods.
     *
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher \$events): array
    {
        return [
{$mapBlock}
        ];
    }
}

PHP;
    }

    // ── Shared file handling ────────────────────────────────────────────────

    /**
     * @param  callable(): string  $content
     * @return array<string, string>
     */
    protected function write(string $namespace, string $class, GenerationOptions $options, callable $content): array
    {
        $path = $this->pathFor($namespace, $class);

        if (file_exists($path) && ! $options->force) {
            return [
                'type' => $this->getName(),
                'name' => $class,
                'path' => $path,
                'status' => 'skipped',
                'reason' => 'already exists',
            ];
        }

        if (! $options->dryRun) {
            $dir = dirname($path);

            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($path, $content());
        }

        return [
            'type' => $this->getName(),
            'name' => $class,
            'path' => $path,
            'status' => 'success',
        ];
    }

    /**
     * Map a PSR-4 namespace under App\ back onto the application path.
     */
    protected function pathFor(string $namespace, string $class): string
    {
        $relative = ltrim($namespace, '\\');

        // Match the App segment exactly — a regex like /^App\\?/ would also
        // chew the front off namespaces such as "Application\Listeners".
        if ($relative === 'App') {
            $relative = '';
        } elseif (str_starts_with($relative, 'App\\')) {
            $relative = substr($relative, 4);
        }

        $relative = trim(str_replace('\\', '/', $relative), '/');

        return app_path(($relative === '' ? '' : $relative.'/').$class.'.php');
    }

    // ── Option resolution ───────────────────────────────────────────────────

    /**
     * Read from the options DTO when it carries the field, otherwise fall back
     * to config — which anvil:generate populates at runtime from its flags.
     * Once GenerationOptions declares $listeners / $listenerStyle /
     * $queuedListeners, the config fallbacks become dead weight and can go.
     */
    protected function enabled(GenerationOptions $options): bool
    {
        return (bool) ($options->listeners ?? config('anvil.events.listeners', false));
    }

    protected function style(GenerationOptions $options): string
    {
        $style = $options->listenerStyle ?? config('anvil.events.listener_style', self::STYLE_PER_EVENT);

        return strtolower((string) $style) === self::STYLE_SUBSCRIBER
            ? self::STYLE_SUBSCRIBER
            : self::STYLE_PER_EVENT;
    }

    protected function queued(GenerationOptions $options): bool
    {
        return (bool) ($options->queuedListeners ?? config('anvil.events.queued_listeners', false));
    }

    protected function listenerNamespace(GenerationOptions $options): string
    {
        return trim((string) ($options->listenerNamespace ?? config('anvil.events.listener_namespace', 'App\\Listeners')), '\\');
    }

    protected function eventNamespace(GenerationOptions $options): string
    {
        return trim((string) ($options->eventNamespace ?? config('anvil.events.namespace', 'App\\Events')), '\\');
    }
}
