<?php

namespace Zuqongtech\LaravelAnvil\Generators;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\Helpers;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates a model factory using Faker-powered definitions inferred
 * from column types and naming conventions.
 *
 * Column heuristics applied (in order):
 *   1. Name pattern  — email, name, first_name, last_name, phone, address,
 *                      city, country, zip, url, uuid, slug, title, body,
 *                      description, summary, content, price, quantity,
 *                      latitude, longitude, ip_address, user_agent, color,
 *                      password, token, code, status, type, locale, currency
 *   2. FK column     — OtherModel::factory()
 *   3. DB type       — integer, decimal, boolean, date, datetime, json/jsonb,
 *                      enum → fake()->randomElement([...])
 *   4. Fallback      — fake()->sentence()
 *
 * Nullable columns wrap their definition in:
 *   fake()->optional(0.8)->...  (80 % chance of a non-null value)
 */
final class FactoryGenerator implements Generator
{
    /** @var array<string, string> Name-pattern → faker expression */
    private const NAME_PATTERNS = [
        'email' => 'fake()->safeEmail()',
        'first_name' => 'fake()->firstName()',
        'last_name' => 'fake()->lastName()',
        'name' => 'fake()->name()',
        'phone' => 'fake()->phoneNumber()',
        'mobile' => 'fake()->phoneNumber()',
        'address' => 'fake()->address()',
        'street' => 'fake()->streetAddress()',
        'city' => 'fake()->city()',
        'state' => 'fake()->state()',
        'country' => 'fake()->country()',
        'zip' => 'fake()->postcode()',
        'postcode' => 'fake()->postcode()',
        'url' => 'fake()->url()',
        'website' => 'fake()->url()',
        'uuid' => 'fake()->uuid()',
        'slug' => 'fake()->slug()',
        'title' => 'fake()->sentence(6)',
        'headline' => 'fake()->sentence(8)',
        'body' => 'fake()->paragraphs(3, true)',
        'description' => 'fake()->paragraph()',
        'summary' => 'fake()->sentence()',
        'content' => 'fake()->paragraphs(2, true)',
        'price' => 'fake()->randomFloat(2, 0.99, 9999.99)',
        'amount' => 'fake()->randomFloat(2, 0, 10000)',
        'quantity' => 'fake()->numberBetween(1, 100)',
        'count' => 'fake()->numberBetween(0, 1000)',
        'latitude' => 'fake()->latitude()',
        'longitude' => 'fake()->longitude()',
        'lat' => 'fake()->latitude()',
        'lng' => 'fake()->longitude()',
        'ip' => 'fake()->ipv4()',
        'ip_address' => 'fake()->ipv4()',
        'user_agent' => 'fake()->userAgent()',
        'color' => 'fake()->hexColor()',
        'colour' => 'fake()->hexColor()',
        'password' => 'bcrypt(fake()->password())',
        'token' => 'Str::random(64)',
        'code' => "strtoupper(fake()->lexify('??????'))",
        'locale' => 'fake()->locale()',
        'currency' => 'fake()->currencyCode()',
        'image' => 'fake()->imageUrl()',
        'avatar' => 'fake()->imageUrl(200, 200)',
        'thumbnail' => 'fake()->imageUrl(150, 150)',
        'rating' => 'fake()->numberBetween(1, 5)',
        'score' => 'fake()->numberBetween(0, 100)',
        'age' => 'fake()->numberBetween(18, 80)',
        'year' => 'fake()->year()',
        'note' => 'fake()->sentence()',
        'comment' => 'fake()->sentence()',
        'message' => 'fake()->paragraph()',
        'subject' => 'fake()->sentence(6)',
    ];

    #[\Override]
    public function supports(GenerationOptions $options): bool
    {
        return $options->factories ?? false;
    }

    #[\Override]
    public function getName(): string
    {
        return 'Factory';
    }

    #[\Override]
    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $factoryName = $meta->model.'Factory';
        $path = database_path("factories/{$factoryName}.php");

        if (file_exists($path) && ! $options->force) {
            return [
                'type' => $this->getName(),
                'name' => $factoryName,
                'path' => $path,
                'status' => 'skipped',
                'reason' => 'already exists',
            ];
        }

        $namespace = $options->getNamespace();
        $content = $this->buildFactory($meta, $namespace);

        if (! $options->dryRun) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, $content);
        }

        return [
            'type' => $this->getName(),
            'name' => $factoryName,
            'path' => $path,
            'status' => 'success',
        ];
    }

    protected function buildFactory(ModelMetadata $meta, string $namespace): string
    {
        $model = $meta->model;
        $factory = $model.'Factory';
        $fullModel = trim($namespace, '\\').'\\'.$model;

        $fkMap = array_column($meta->foreignKeys, 'referenced_table', 'column');

        $definitions = [];
        $needsStrImport = false;

        $skipCols = array_merge(
            $meta->compositePrimaryKey,
            [$meta->primaryKey, 'created_at', 'updated_at', 'deleted_at', 'remember_token'],
        );

        foreach ($meta->columns as $col) {
            $name = $col['name'];

            if (in_array($name, $skipCols, true)) {
                continue;
            }

            $definition = $this->resolveDefinition($name, $col, $fkMap, $namespace);

            if (str_contains($definition, 'Str::')) {
                $needsStrImport = true;
            }

            if ($col['nullable']) {
                if (str_starts_with($definition, 'fake()->')) {
                    $inner = substr($definition, strlen('fake()->'));
                    $definition = "fake()->optional(0.8)?->{$inner} ?? null";
                } else {
                    $definition = "fake()->boolean(80) ? {$definition} : null";
                }
            }

            $definitions[] = "            '{$name}' => {$definition},";
        }

        $definitionsStr = implode("\n", $definitions);
        $strImport = $needsStrImport ? "\nuse Illuminate\\Support\\Str;" : '';

        return <<<PHP
<?php

namespace Database\Factories;
{$strImport}
use {$fullModel};
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<{$model}>
 */
class {$factory} extends Factory
{
    protected \$model = {$model}::class;

    public function definition(): array
    {
        return [
{$definitionsStr}
        ];
    }
}

PHP;
    }

    protected function resolveDefinition(string $name, array $col, array $fkMap, string $namespace): string
    {
        // 1. FK → related factory
        if (isset($fkMap[$name])) {
            $relatedModel = Helpers::tableToModelName($fkMap[$name]);
            $relatedFull = trim($namespace, '\\').'\\'.$relatedModel;

            return "\\{$relatedFull}::factory()";
        }

        // 2. Name pattern (partial match, longest key wins)
        $lower = strtolower($name);
        $bestKey = null;
        $bestLen = 0;

        foreach (self::NAME_PATTERNS as $pattern => $expr) {
            if (str_contains($lower, $pattern) && strlen($pattern) > $bestLen) {
                $bestKey = $pattern;
                $bestLen = strlen($pattern);
            }
        }

        if ($bestKey !== null) {
            return self::NAME_PATTERNS[$bestKey];
        }

        // 3. DB type fallback
        return $this->typeDefinition($col['type'] ?? 'varchar');
    }

    protected function typeDefinition(string $dbType): string
    {
        $type = strtolower((string) preg_replace('/\(.*\)/', '', $dbType));

        if (str_starts_with($type, 'enum')) {
            if (preg_match("/enum\('(.+?)'\)/i", $dbType, $m)) {
                $values = array_map(
                    fn ($v): string => "'".trim((string) $v)."'",
                    explode("','", $m[1])
                );

                return 'fake()->randomElement(['.implode(', ', $values).'])';
            }
        }

        return match (true) {
            in_array($type, ['int', 'integer', 'smallint', 'mediumint', 'bigint']) => 'fake()->numberBetween(1, 1000)',
            in_array($type, ['tinyint']) => 'fake()->boolean()',
            in_array($type, ['boolean', 'bool']) => 'fake()->boolean()',
            in_array($type, ['decimal', 'numeric', 'float', 'double', 'real']) => 'fake()->randomFloat(2, 0, 10000)',
            in_array($type, ['date']) => 'fake()->date()',
            in_array($type, ['datetime', 'timestamp']) => "fake()->dateTime()->format('Y-m-d H:i:s')",
            in_array($type, ['time']) => 'fake()->time()',
            in_array($type, ['json', 'jsonb']) => 'json_encode([])',
            in_array($type, ['uuid']) => 'fake()->uuid()',
            in_array($type, ['text', 'mediumtext', 'longtext']) => 'fake()->paragraph()',
            default => 'fake()->sentence()',
        };
    }
}
