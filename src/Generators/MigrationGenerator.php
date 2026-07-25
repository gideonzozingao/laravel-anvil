<?php

namespace Zuqongtech\LaravelAnvil\Generators;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Reverse-engineers a live database table into a Schema::create() migration.
 *
 * This is particularly useful when:
 *  - You have an existing database without version-controlled migrations
 *  - You want to replicate the schema in another environment
 *  - You need a baseline migration as the starting point for further changes
 *
 * Column mapping (DB → Blueprint method):
 *   integer / int      → integer / unsignedBigInteger (PK)
 *   bigint             → bigInteger / id() (PK)
 *   smallint           → smallInteger
 *   tinyint(1)         → boolean
 *   decimal(p,s)       → decimal($col, $p, $s)
 *   float              → float
 *   varchar(n)         → string($col, n)
 *   char(n)            → char($col, n)
 *   text               → text
 *   longtext           → longText
 *   json / jsonb       → json
 *   uuid               → uuid / primary()
 *   boolean            → boolean
 *   date               → date
 *   datetime           → dateTime
 *   timestamp          → timestamp
 *   enum               → enum($col, [...])
 *
 * Special columns handled automatically:
 *   id, created_at, updated_at, deleted_at
 */
final class MigrationGenerator implements Generator
{
    #[\Override]
    public function supports(GenerationOptions $options): bool
    {
        return $options->migrations ?? false;
    }

    #[\Override]
    public function getName(): string
    {
        return 'Migration';
    }

    #[\Override]
    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $timestamp = date('Y_m_d_His');
        $filename = "{$timestamp}_create_{$meta->table}_table.php";
        $path = database_path("migrations/{$filename}");

        // Check if a migration for this table already exists
        $existing = glob(database_path("migrations/*_create_{$meta->table}_table.php"));
        if (! empty($existing) && ! $options->force) {
            return [
                'type' => $this->getName(),
                'name' => $filename,
                'path' => $existing[0],
                'status' => 'skipped',
                'reason' => 'migration already exists',
            ];
        }

        $content = $this->buildMigration($meta);

        if (! $options->dryRun) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, $content);
        }

        return [
            'type' => $this->getName(),
            'name' => $filename,
            'path' => $path,
            'status' => 'success',
        ];
    }

    protected function buildMigration(ModelMetadata $meta): string
    {
        $table = $meta->table;
        $columnLines = $this->buildColumnLines($meta);
        $indexLines = $this->buildIndexLines($meta);
        $fkLines = $this->buildForeignKeyLines($meta);

        $schemaBody = implode("\n", array_filter([
            $columnLines,
            $indexLines,
            $fkLines,
        ]));

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$table}', function (Blueprint \$table) {
{$schemaBody}
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$table}');
    }
};

PHP;
    }

    protected function buildColumnLines(ModelMetadata $meta): string
    {
        $lines = [];
        $columnNames = array_column($meta->columns, 'name');

        $hasTimestamps = in_array('created_at', $columnNames) && in_array('updated_at', $columnNames);
        $hasSoftDeletes = in_array('deleted_at', $columnNames);
        $autoSkip = ['created_at', 'updated_at', 'deleted_at'];

        // Handle primary key
        $pkCols = $meta->compositePrimaryKey;

        foreach ($meta->columns as $col) {
            $name = $col['name'];

            if (in_array($name, $autoSkip, true)) {
                continue;
            }

            // Standard id() shorthand
            if (in_array($name, $pkCols, true) && count($pkCols) === 1) {
                $line = $this->buildPrimaryKeyLine($name, $col);
            } else {
                $line = $this->buildColumnLine($name, $col, $pkCols);
            }

            if ($line) {
                $lines[] = "            {$line}";
            }
        }

        if ($hasTimestamps) {
            $lines[] = '            $table->timestamps();';
        }
        if ($hasSoftDeletes) {
            $lines[] = '            $table->softDeletes();';
        }

        return implode("\n", $lines);
    }

    protected function buildPrimaryKeyLine(string $name, array $col): ?string
    {
        $type = strtolower(preg_replace('/\(.*\)/', '', $col['type'] ?? 'bigint'));

        if ($name === 'id' && in_array($type, ['bigint', 'integer', 'int', 'serial'])) {
            return '$table->id();';
        }

        if (in_array($type, ['uuid', 'char', 'varchar'])) {
            return "\$table->uuid('{$name}')->primary();";
        }

        if ($name === 'id') {
            return '$table->id();';
        }

        return "\$table->unsignedBigInteger('{$name}')->primary();";
    }

    protected function buildColumnLine(string $name, array $col, array $pkCols): ?string
    {
        $raw = $col['type'] ?? 'varchar(255)';
        $type = strtolower(preg_replace('/\(.*\)/', '', $raw));

        $method = $this->typeToBlueprint($name, $raw, $type);
        $nullable = ($col['nullable'] ?? false) ? '->nullable()' : '';
        $default = $this->defaultExpression($col);

        // FK columns get unsignedBigInteger unless they're UUID
        if (
            str_ends_with($name, '_id') &&
            in_array($type, ['int', 'integer', 'bigint', 'mediumint', 'smallint'])
        ) {
            return "\$table->unsignedBigInteger('{$name}'){$nullable}{$default};";
        }

        return "\$table->{$method}{$nullable}{$default};";
    }

    protected function typeToBlueprint(string $name, string $raw, string $type): string
    {
        // Length extraction
        preg_match('/\((\d+)(?:,(\d+))?\)/', $raw, $m);
        $len = $m[1] ?? null;
        $scale = $m[2] ?? null;

        return match (true) {
            str_starts_with($raw, 'enum') => $this->enumBlueprint($name, $raw),
            in_array($type, ['varchar', 'character varying']) => $len ? "string('{$name}', {$len})" : "string('{$name}')",
            in_array($type, ['char', 'character']) => $len ? "char('{$name}', {$len})" : "char('{$name}')",
            in_array($type, ['tinyint']) && $len == 1 => "boolean('{$name}')",
            in_array($type, ['tinyint']) => "tinyInteger('{$name}')",
            in_array($type, ['smallint', 'int2']) => "smallInteger('{$name}')",
            in_array($type, ['mediumint']) => "mediumInteger('{$name}')",
            in_array($type, ['int', 'integer', 'int4']) => "integer('{$name}')",
            in_array($type, ['bigint', 'int8']) => "bigInteger('{$name}')",
            in_array($type, ['decimal', 'numeric']) && $len && $scale => "decimal('{$name}', {$len}, {$scale})",
            in_array($type, ['decimal', 'numeric']) && $len => "decimal('{$name}', {$len})",
            in_array($type, ['decimal', 'numeric']) => "decimal('{$name}')",
            in_array($type, ['float', 'real', 'float4']) => "float('{$name}')",
            in_array($type, ['double', 'double precision', 'float8']) => "double('{$name}')",
            in_array($type, ['boolean', 'bool']) => "boolean('{$name}')",
            in_array($type, ['date']) => "date('{$name}')",
            in_array($type, ['datetime']) => "dateTime('{$name}')",
            in_array($type, ['timestamp', 'timestamptz']) => "timestamp('{$name}')",
            in_array($type, ['time', 'timetz']) => "time('{$name}')",
            in_array($type, ['year']) => "year('{$name}')",
            in_array($type, ['text']) => "text('{$name}')",
            in_array($type, ['tinytext']) => "tinyText('{$name}')",
            in_array($type, ['mediumtext']) => "mediumText('{$name}')",
            in_array($type, ['longtext']) => "longText('{$name}')",
            in_array($type, ['json', 'jsonb']) => "json('{$name}')",
            in_array($type, ['uuid']) => "uuid('{$name}')",
            in_array($type, ['binary', 'varbinary', 'blob']) => "binary('{$name}')",
            in_array($type, ['inet']) => "string('{$name}', 45)",
            default => "string('{$name}')",
        };
    }

    protected function enumBlueprint(string $name, string $raw): string
    {
        if (preg_match("/enum\('(.+?)'\)/i", $raw, $m)) {
            $values = array_map(
                fn ($v): string => "'".trim((string) $v)."'",
                explode("','", $m[1])
            );

            return "enum('{$name}', [".implode(', ', $values).'])';
        }

        return "string('{$name}')";
    }

    protected function defaultExpression(array $col): string
    {
        $default = $col['default'] ?? null;

        if ($default === null || $default === '' || str_contains((string) $default, 'nextval')) {
            return '';
        }

        $raw = str_replace("'", "\\'", $default);

        return "->default('{$raw}')";
    }

    protected function buildIndexLines(ModelMetadata $meta): string
    {
        $lines = [];

        foreach ($meta->indexes as $index) {
            if ($index['primary']) {
                continue;
            }

            $cols = array_map(fn ($c): string => "'{$c['name']}'", $index['columns']);
            $colStr = count($cols) === 1 ? $cols[0] : '['.implode(', ', $cols).']';

            if ($index['unique']) {
                $lines[] = "            \$table->unique({$colStr}, '{$index['name']}');";
            } else {
                $lines[] = "            \$table->index({$colStr}, '{$index['name']}');";
            }
        }

        return implode("\n", $lines);
    }

    protected function buildForeignKeyLines(ModelMetadata $meta): string
    {
        $lines = [];

        foreach ($meta->foreignKeys as $fk) {
            $col = $fk['column'];
            $refTable = $fk['referenced_table'];
            $refCol = $fk['referenced_column'] ?? 'id';
            $constraintName = $fk['constraint_name'] ?? "fk_{$meta->table}_{$col}";

            $lines[] = "            \$table->foreign('{$col}', '{$constraintName}')"
                ."->references('{$refCol}')->on('{$refTable}')->onDelete('cascade');";
        }

        return implode("\n", $lines);
    }
}
