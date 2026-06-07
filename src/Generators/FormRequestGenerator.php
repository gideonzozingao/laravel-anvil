<?php

namespace Zuqongtech\LaravelAnvil\Generators;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates StoreXxxRequest and UpdateXxxRequest form request classes.
 *
 * Validation rules are inferred from the live schema:
 *   - Column type  → 'string', 'integer', 'numeric', 'boolean', 'date', 'array' (json)
 *   - Nullable     → 'nullable' vs 'required'
 *   - max_length   → 'max:N' for varchar/char
 *   - Foreign keys → 'exists:table,column'
 *   - Unique       → 'unique:table,column' (Store) / 'unique:table,column,{id}' (Update)
 *   - enum columns → 'in:a,b,c' when detected from type string
 */
final class FormRequestGenerator implements Generator
{
    public function supports(GenerationOptions $options): bool
    {
        return $options->formRequests ?? false;
    }

    public function getName(): string
    {
        return 'FormRequest';
    }

    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $results = [];

        foreach (['Store', 'Update'] as $verb) {
            $className = $verb.$meta->model.'Request';
            $path = app_path("Http/Requests/{$className}.php");

            if (file_exists($path) && ! $options->force) {
                $results[] = [
                    'type' => $this->getName(),
                    'name' => $className,
                    'path' => $path,
                    'status' => 'skipped',
                    'reason' => 'already exists',
                ];

                continue;
            }

            $content = $verb === 'Store'
                ? $this->buildStoreRequest($meta, $className)
                : $this->buildUpdateRequest($meta, $className);

            if (! $options->dryRun) {
                $dir = dirname($path);
                if (! is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($path, $content);
            }

            $results[] = [
                'type' => $this->getName(),
                'name' => $className,
                'path' => $path,
                'status' => 'success',
                'action' => file_exists($path) ? 'overwritten' : 'created',
            ];
        }

        return $results;
    }

    // -----------------------------------------------------------------------
    // Builders
    // -----------------------------------------------------------------------

    protected function buildStoreRequest(ModelMetadata $meta, string $className): string
    {
        $rules = $this->buildRules($meta, isUpdate: false);
        $rulesPhp = $this->formatRulesArray($rules, indent: 3);

        return <<<PHP
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class {$className} extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return {$rulesPhp};
    }
}

PHP;
    }

    protected function buildUpdateRequest(ModelMetadata $meta, string $className): string
    {
        $rules = $this->buildRules($meta, isUpdate: true);
        $rulesPhp = $this->formatRulesArray($rules, indent: 3);
        $pk = $meta->primaryKey ?? 'id';

        return <<<PHP
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class {$className} extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        \${$pk} = \$this->route('{$pk}');

        return {$rulesPhp};
    }
}

PHP;
    }

    // -----------------------------------------------------------------------
    // Rule inference
    // -----------------------------------------------------------------------

    protected function buildRules(ModelMetadata $meta, bool $isUpdate): array
    {
        $rules = [];

        $skipColumns = array_merge(
            $meta->compositePrimaryKey,
            [$meta->primaryKey, 'created_at', 'updated_at', 'deleted_at', 'remember_token'],
        );

        $fkMap = array_column($meta->foreignKeys, 'referenced_table', 'column');
        $fkRefCol = array_column($meta->foreignKeys, 'referenced_column', 'column');

        $uniqueColumns = [];
        foreach ($meta->uniqueConstraints as $constraint) {
            if (count($constraint['columns']) === 1) {
                $uniqueColumns[] = $constraint['columns'][0]['name'];
            }
        }

        foreach ($meta->columns as $col) {
            $name = $col['name'];

            if (in_array($name, $skipColumns, true)) {
                continue;
            }

            $colRules = [];

            // required / nullable / sometimes
            if ($isUpdate) {
                $colRules[] = 'sometimes';
                $colRules[] = $col['nullable'] ? 'nullable' : 'required';
            } else {
                $colRules[] = $col['nullable'] ? 'nullable' : 'required';
            }

            // type rule
            $typeRule = $this->inferTypeRule($col['type'] ?? 'string');
            if ($typeRule) {
                $colRules[] = $typeRule;
            }

            // max length
            if (! empty($col['max_length']) && $col['max_length'] > 0) {
                $colRules[] = "max:{$col['max_length']}";
            }

            // foreign key existence
            if (isset($fkMap[$name])) {
                $refTable = $fkMap[$name];
                $refCol = $fkRefCol[$name] ?? 'id';
                $colRules[] = "exists:{$refTable},{$refCol}";
            }

            // unique constraint
            if (in_array($name, $uniqueColumns, true)) {
                $pk = $meta->primaryKey ?? 'id';
                if ($isUpdate) {
                    $colRules[] = "unique:{$meta->table},{$name},\${$pk}";
                } else {
                    $colRules[] = "unique:{$meta->table},{$name}";
                }
            }

            // email heuristic
            if (str_contains(strtolower($name), 'email')) {
                $colRules[] = 'email:rfc,dns';
            }

            // url heuristic
            if (str_contains(strtolower($name), 'url') || str_contains(strtolower($name), 'website')) {
                $colRules[] = 'url';
            }

            $rules[$name] = $colRules;
        }

        return $rules;
    }

    protected function inferTypeRule(string $dbType): ?string
    {
        $type = strtolower(preg_replace('/\(.*\)/', '', $dbType));

        return match (true) {
            in_array($type, ['int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint', 'serial']) => 'integer',
            in_array($type, ['decimal', 'numeric', 'float', 'double', 'real']) => 'numeric',
            in_array($type, ['boolean', 'bool']) => 'boolean',
            in_array($type, ['date']) => 'date',
            in_array($type, ['datetime', 'timestamp']) => 'date_format:Y-m-d H:i:s',
            in_array($type, ['json', 'jsonb']) => 'array',
            in_array($type, ['uuid']) => 'uuid',
            str_starts_with($type, 'enum') => $this->buildEnumRule($dbType),
            default => 'string',
        };
    }

    protected function buildEnumRule(string $dbType): string
    {
        // Extract values from e.g. "enum('active','inactive','pending')"
        if (preg_match("/enum\('(.+?)'\)/i", $dbType, $m)) {
            $values = array_map('trim', explode("','", $m[1]));

            return 'in:'.implode(',', $values);
        }

        return 'string';
    }

    // -----------------------------------------------------------------------
    // Formatting helpers
    // -----------------------------------------------------------------------

    protected function formatRulesArray(array $rules, int $indent): string
    {
        if (empty($rules)) {
            return '[]';
        }

        $pad = str_repeat('    ', $indent);
        $innerPad = str_repeat('    ', $indent + 1);

        $lines = ["[\n"];
        foreach ($rules as $col => $colRules) {
            $ruleStr = implode('|', $colRules);
            $lines[] = "{$innerPad}'{$col}' => '{$ruleStr}',\n";
        }
        $lines[] = "{$pad}]";

        return implode('', $lines);
    }
}
