<?php

namespace Zuqongtech\LaravelAnvil\Generators;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates domain event classes for each model lifecycle transition.
 *
 * Generated events:
 *   - {Model}Created   — fired after a new record is persisted
 *   - {Model}Updated   — fired after an existing record changes
 *   - {Model}Deleted   — fired after a record is deleted (soft or hard)
 *   - {Model}Restored  — fired after a soft-deleted record is restored (when softDeletes)
 *
 * Each event class:
 *   - Implements ShouldBroadcast (commented out by default — opt in)
 *   - Carries the model instance as a public readonly property
 *   - Implements SerializesModels for queue-safe serialization
 *
 * Usage in the generated service layer:
 *   event(new PostCreated($post));
 */
final class EventGenerator implements Generator
{
    public function supports(GenerationOptions $options): bool
    {
        return $options->events ?? false;
    }

    public function getName(): string
    {
        return 'Event';
    }

    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $events = ['Created', 'Updated', 'Deleted'];

        if ($meta->softDeletes) {
            $events[] = 'Restored';
        }

        $results = [];

        foreach ($events as $action) {
            $results[] = $this->generateEvent($meta, $action, $options);
        }

        return $results;
    }

    protected function generateEvent(ModelMetadata $meta, string $action, GenerationOptions $options): array
    {
        $eventName = $meta->model.$action;
        $path = app_path("Events/{$eventName}.php");

        if (file_exists($path) && ! $options->force) {
            return [
                'type' => $this->getName(),
                'name' => $eventName,
                'path' => $path,
                'status' => 'skipped',
                'reason' => 'already exists',
            ];
        }

        $namespace = $options->getNamespace();
        $content = $this->buildEvent($meta, $action, $namespace, $eventName);

        if (! $options->dryRun) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, $content);
        }

        return [
            'type' => $this->getName(),
            'name' => $eventName,
            'path' => $path,
            'status' => 'success',
        ];
    }

    protected function buildEvent(ModelMetadata $meta, string $action, string $namespace, string $eventName): string
    {
        $model = $meta->model;
        $variable = lcfirst($model);
        $fullModel = trim($namespace, '\\').'\\'.$model;

        $docComment = match ($action) {
            'Created' => "Fired after a new {$model} record is persisted.",
            'Updated' => "Fired after an existing {$model} record is updated.",
            'Deleted' => "Fired after a {$model} record is deleted.",
            'Restored' => "Fired after a soft-deleted {$model} record is restored.",
            default => "Fired on {$model} {$action}.",
        };

        return <<<PHP
<?php

namespace App\Events;

use {$fullModel};
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
// use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/**
 * {$docComment}
 *
 * To broadcast this event over WebSockets, implement ShouldBroadcast
 * and configure the broadcastOn() method.
 */
class {$eventName}
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly {$model} \${$variable},
    ) {}

    // /**
    //  * Get the channels the event should broadcast on.
    //  *
    //  * @return array<int, \Illuminate\Broadcasting\Channel>
    //  */
    // public function broadcastOn(): array
    // {
    //     return [
    //         new PrivateChannel('{$meta->table}.' . \$this->{$variable}->getKey()),
    //     ];
    // }
}

PHP;
    }
}
