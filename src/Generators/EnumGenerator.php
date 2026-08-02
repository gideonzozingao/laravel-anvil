<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Generators;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\EnumColumn;
use Zuqongtech\LaravelAnvil\Support\EnumDetector;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates a backed PHP enum for every column whose values come from a fixed set.
 *
 *   vehicle_bookings.status enum('scheduled','active','cancelled')
 *     → App\Enums\VehicleBookingStatus
 *
 * The same enum is then used in four places, all resolved from this one detection
 * pass: the model cast, the form request rule, the OpenAPI schema and the
 * TypeScript union. A `status varchar` that the database already constrains is
 * otherwise a bare string in each of them, and the allowed values live only in a
 * CHECK constraint nobody reads.
 *
 * MUST run before the model generator: the cast references the enum class, so the
 * file has to exist by the time anyone loads the model.
 */
final class EnumGenerator implements Generator
{
    #[\Override]
    public function supports(GenerationOptions $options): bool
    {
        return (bool) ($options->enums ?? config('anvil.enums.enabled', true));
    }

    #[\Override]
    public function getName(): string
    {
        return 'Enum';
    }

    #[\Override]
    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $enums = EnumDetector::forTable($meta, $options->getConnection());

        if ($enums === []) {
            return [];
        }

        $results = [];

        foreach ($enums as $enum) {
            $results[] = $this->write($enum, $options);
        }

        return $results;
    }

    /**
     * @return array<string, string>
     */
    protected function write(EnumColumn $enum, GenerationOptions $options): array
    {
        $path = $enum->path();
        $exists = file_exists($path);

        $result = [
            'type' => $this->getName(),
            'name' => $enum->enumName,
            'path' => $path,
            'reason' => sprintf('%s.%s (%d values, via %s)', $enum->table, $enum->column, count($enum->cases), $enum->source),
        ];

        if ($exists && ! $options->force) {
            return $result + ['status' => 'skipped'];
        }

        if ($options->dryRun) {
            return $result + ['status' => 'dry-run'];
        }

        $dir = dirname($path);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return $result + ['status' => 'failed', 'reason' => "could not create {$dir}"];
        }

        if (file_put_contents($path, $this->build($enum)) === false) {
            return $result + ['status' => 'failed'];
        }

        return $result + ['status' => 'success', 'action' => $exists ? 'overwritten' : 'created'];
    }

    protected function build(EnumColumn $enum): string
    {
        $namespace = $enum->namespace();
        $name = $enum->enumName;
        $backing = $enum->backing;
        $source = $this->sourceDescription($enum);

        $cases = '';
        $labels = '';

        foreach ($enum->cases as $value => $case) {
            $literal = $backing === 'int' ? $value : "'".str_replace("'", "\\'", $value)."'";

            $cases .= "    case {$case} = {$literal};\n";
            $labels .= sprintf("            self::%s => '%s',\n", $case, addslashes($enum->label((string) $value)));
        }

        $cases = rtrim($cases, "\n");
        $labels = rtrim($labels, "\n");

        $default = $enum->defaultCase();
        $defaultMethod = $default === null
            ? <<<'PHP'

                    /**
                     * The column has no database default, so there is no obvious starting value.
                     */
                    public static function default(): ?self
                    {
                        return null;
                    }
                PHP
            : <<<PHP

                /** Mirrors the column default in the database. */
                public static function default(): self
                {
                    return self::{$default};
                }
            PHP;

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            /**
             * Values permitted in {$enum->table}.{$enum->column}.
             *
             * {$source}
             *
             * Used by the model cast, the form request rules, the OpenAPI schema and the
             * generated TypeScript union — so the allowed values are stated once and cannot
             * drift between the database and the code that reads it.
             */
            enum {$name}: {$backing}
            {
                {$cases}

                /**
                 * The raw values, for an `in:` rule or a select list.
                 *
                 * @return list<{$backing}>
                 */
                public static function values(): array
                {
                    return array_column(self::cases(), 'value');
                }

                /**
                 * Display label. Derived from the stored value; edit freely — this file is
                 * only regenerated with --force.
                 */
                public function label(): string
                {
                    return match (\$this) {
                        {$labels}
                    };
                }

                /**
                 * value => label, ready for a <select> or a filter dropdown.
                 *
                 * @return array<{$backing}, string>
                 */
                public static function options(): array
                {
                    return array_combine(
                        self::values(),
                        array_map(static fn (self \$case): string => \$case->label(), self::cases()),
                    );
                }

                /** `\$booking->status->is(Status::Active, Status::Pending)` */
                public function is(self ...\$cases): bool
                {
                    return in_array(\$this, \$cases, true);
                }

                public function isNot(self ...\$cases): bool
                {
                    return ! \$this->is(...\$cases);
                }
                    {$defaultMethod}
            }

            PHP;
    }

    protected function sourceDescription(EnumColumn $enum): string
    {
        return match ($enum->source) {
            'inline' => 'Detected from the column definition: enum(…).',
            'pg_type' => 'Detected from a Postgres enum type (CREATE TYPE … AS ENUM).',
            'check' => 'Detected from a CHECK constraint on the column.',
            'metadata' => 'Detected from the schema metadata.',
            default => 'Detected from the schema.',
        };
    }
}
