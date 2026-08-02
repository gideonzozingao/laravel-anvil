<?php

namespace Zuqongtech\LaravelAnvil\Generators;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\Helpers;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates a database seeder for each model.
 *
 * The generated seeder:
 *   - Uses the model's factory to create configurable record counts
 *   - Respects FK ordering (the seeder calls the parent seeder first
 *     when a user_id or other FK exists)
 *   - Provides separate development and production seed amounts
 *   - Optionally calls DatabaseSeeder::class to register itself
 *
 * Example generated file:
 *
 *   class PostSeeder extends Seeder
 *   {
 *       public function run(): void
 *       {
 *           $count = app()->environment('production') ? 0 : 50;
 *           Post::factory()->count($count)->create();
 *       }
 *   }
 */
final class SeederGenerator implements Generator
{
    #[\Override]
    public function supports(GenerationOptions $options): bool
    {
        return $options->seeders ?? false;
    }

    #[\Override]
    public function getName(): string
    {
        return 'Seeder';
    }

    #[\Override]
    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $seederName = $meta->model.'Seeder';
        $path = database_path("seeders/{$seederName}.php");

        if (file_exists($path) && ! $options->force) {
            return [
                'type' => $this->getName(),
                'name' => $seederName,
                'path' => $path,
                'status' => 'skipped',
                'reason' => 'already exists',
            ];
        }

        $namespace = $options->getNamespace();
        $content = $this->buildSeeder($meta, $namespace);

        if (! $options->dryRun) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, $content);

            // Register in DatabaseSeeder
            $this->registerInDatabaseSeeder($seederName);
        }

        return [
            'type' => $this->getName(),
            'name' => $seederName,
            'path' => $path,
            'status' => 'success',
        ];
    }

    protected function buildSeeder(ModelMetadata $meta, string $namespace): string
    {
        $model = $meta->model;
        $seeder = $model.'Seeder';
        $fullModel = trim($namespace, '\\').'\\'.$model;

        // Determine FK dependencies — list them as parent seeders to call
        $parentSeeders = [];
        foreach ($meta->foreignKeys as $fk) {
            $refModel = Helpers::tableToModelName($fk['referenced_table']);
            $parentSeeders[] = "        \$this->call({$refModel}Seeder::class);";
        }

        $parentCallsBlock = '';
        if (! empty($parentSeeders)) {
            $calls = implode("\n", array_unique($parentSeeders));
            $parentCallsBlock = <<<PHP

        // Seed parent/related tables first
{$calls}

PHP;
        }

        return <<<PHP
                <?php

                namespace Database\Seeders;

                use {$fullModel};
                use Illuminate\Database\Seeder;

                class {$seeder} extends Seeder
                {
                    /**
                     * Run the database seeds.
                     *
                     * Override the count values to control how many records are created.
                     * Production environments receive 0 records by default to prevent
                     * accidental data injection.
                     */
                    public function run(): void
                    {
                {$parentCallsBlock}        \$count = match (true) {
                            app()->environment('production') => 0,
                            app()->environment('staging')    => 10,
                            default                          => 50,
                        };

                        {$model}::factory()->count(\$count)->create();
                    }
                }

        PHP;
    }

    protected function registerInDatabaseSeeder(string $seederName): void
    {
        $dbSeederPath = database_path('seeders/DatabaseSeeder.php');

        if (! file_exists($dbSeederPath)) {
            return;
        }

        $content = file_get_contents($dbSeederPath);

        if (str_contains($content, $seederName.'::class')) {
            return;
        }

        // Look for an existing call() array or a single call()
        $callLine = "        \$this->call({$seederName}::class);";

        // Append before last closing brace of run()
        $updated = preg_replace(
            '/(\s*\}\s*\}\s*$)/s',
            "\n{$callLine}\n    }\n}",
            $content,
            1,
        );

        if ($updated !== null) {
            file_put_contents($dbSeederPath, $updated);
        }
    }
}
