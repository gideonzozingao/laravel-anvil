<?php

namespace Zuqongtech\LaravelAnvil\Generators;

use Illuminate\Support\Str;
use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates a Feature test class covering the full CRUD lifecycle
 * of the generated controller + API routes.
 *
 * Each generated test class includes:
 *   - test_index_returns_paginated_list
 *   - test_store_creates_record
 *   - test_store_validates_required_fields
 *   - test_show_returns_record
 *   - test_show_returns_404_for_missing_record
 *   - test_update_modifies_record
 *   - test_destroy_deletes_record
 *   - test_destroy_returns_404_for_missing_record
 *   - (restore / forceDelete when softDeletes)
 *
 * Tests use Laravel's built-in HTTP testing helpers (actingAs, getJson, etc.)
 * and Pest-compatible function syntax wrapped in a standard PHPUnit class
 * so they work in both frameworks.
 *
 * Authentication: tests create a User via factory and act as that user.
 * If your app doesn't use Sanctum/session auth, remove the actingAs() calls.
 */
final class TestGenerator implements Generator
{
    #[\Override]
    public function supports(GenerationOptions $options): bool
    {
        return $options->tests ?? false;
    }

    #[\Override]
    public function getName(): string
    {
        return 'Test';
    }

    #[\Override]
    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $testName = $meta->model.'Test';
        $path = base_path("tests/Feature/{$testName}.php");

        if (file_exists($path) && ! $options->force) {
            return [
                'type' => $this->getName(),
                'name' => $testName,
                'path' => $path,
                'status' => 'skipped',
                'reason' => 'already exists',
            ];
        }

        $namespace = $options->getNamespace();
        $content = $this->buildTest($meta, $namespace);

        if (! $options->dryRun) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, $content);
        }

        return [
            'type' => $this->getName(),
            'name' => $testName,
            'path' => $path,
            'status' => 'success',
        ];
    }

    protected function buildTest(ModelMetadata $meta, string $namespace): string
    {
        $model = $meta->model;
        $test = $model.'Test';
        $fullModel = trim($namespace, '\\').'\\'.$model;
        $variable = lcfirst($model);
        $slug = Str::plural(Str::kebab($model));
        $version = config('anvil.api_version', 'v1');
        $baseUrl = "/api/{$version}/{$slug}";

        // Required columns for create payload
        $fillable = collect($meta->columns)
            ->reject(fn ($c) => in_array($c['name'], [
                $meta->primaryKey, 'created_at', 'updated_at', 'deleted_at', 'remember_token',
            ]))
            ->reject(fn ($c) => $c['nullable'])
            ->pluck('name')
            ->take(3) // Keep the test payload concise
            ->toArray();

        $payloadLines = array_map(
            fn ($col) => "            '{$col}' => fake()->sentence(),",
            $fillable
        );
        $payloadStr = implode("\n", $payloadLines);

        $softDeleteTests = '';
        if ($meta->softDeletes) {
            $softDeleteTests = <<<PHP


    public function test_restore_revives_soft_deleted_record(): void
    {
        \${$variable} = {$model}::factory()->create();
        \${$variable}->delete();

        \$response = \$this->actingAs(\$this->user)
            ->patchJson("{$baseUrl}/{\${$variable}->getKey()}/restore");

        \$response->assertOk();
        \$this->assertNotSoftDeleted(\${$variable});
    }

    public function test_force_delete_permanently_removes_record(): void
    {
        \${$variable} = {$model}::factory()->create();
        \${$variable}->delete();

        \$response = \$this->actingAs(\$this->user)
            ->deleteJson("{$baseUrl}/{\${$variable}->getKey()}/force");

        \$response->assertNoContent();
        \$this->assertDatabaseMissing('{$meta->table}', ['{$meta->primaryKey}' => \${$variable}->getKey()]);
    }
PHP;
        }

        return <<<PHP
<?php

namespace Tests\Feature;

use {$fullModel};
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class {$test} extends TestCase
{
    use RefreshDatabase;

    protected User \$user;

    protected function setUp(): void
    {
        parent::setUp();
        \$this->user = User::factory()->create();
    }

    public function test_index_returns_paginated_list(): void
    {
        {$model}::factory()->count(5)->create();

        \$response = \$this->actingAs(\$this->user)
            ->getJson('{$baseUrl}');

        \$response->assertOk()
            ->assertJsonStructure(['data', 'meta', 'links']);
    }

    public function test_store_creates_record(): void
    {
        \$payload = [
{$payloadStr}
        ];

        \$response = \$this->actingAs(\$this->user)
            ->postJson('{$baseUrl}', \$payload);

        \$response->assertCreated();
        \$this->assertDatabaseHas('{$meta->table}', \$payload);
    }

    public function test_store_validates_required_fields(): void
    {
        \$response = \$this->actingAs(\$this->user)
            ->postJson('{$baseUrl}', []);

        \$response->assertUnprocessable();
    }

    public function test_show_returns_single_record(): void
    {
        \${$variable} = {$model}::factory()->create();

        \$response = \$this->actingAs(\$this->user)
            ->getJson("{$baseUrl}/{\${$variable}->getKey()}");

        \$response->assertOk();
    }

    public function test_show_returns_404_for_missing_record(): void
    {
        \$response = \$this->actingAs(\$this->user)
            ->getJson('{$baseUrl}/99999999');

        \$response->assertNotFound();
    }

    public function test_update_modifies_record(): void
    {
        \${$variable} = {$model}::factory()->create();

        \$response = \$this->actingAs(\$this->user)
            ->putJson("{$baseUrl}/{\${$variable}->getKey()}", \${$variable}->toArray());

        \$response->assertOk();
    }

    public function test_destroy_deletes_record(): void
    {
        \${$variable} = {$model}::factory()->create();

        \$response = \$this->actingAs(\$this->user)
            ->deleteJson("{$baseUrl}/{\${$variable}->getKey()}");

        \$response->assertNoContent();
        \$this->assertDatabaseMissing('{$meta->table}', ['{$meta->primaryKey}' => \${$variable}->getKey()]);
    }

    public function test_destroy_returns_404_for_missing_record(): void
    {
        \$response = \$this->actingAs(\$this->user)
            ->deleteJson('{$baseUrl}/99999999');

        \$response->assertNotFound();
    }
{$softDeleteTests}
}

PHP;
    }
}
