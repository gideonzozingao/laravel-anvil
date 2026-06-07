<?php

namespace Zuqongtech\LaravelAnvil\Support;

class StubGenerator
{
    public function __construct(protected array $replacements = []) {}

    /**
     * Add a replacement
     */
    public function addReplacement(string $key, string $value): self
    {
        $this->replacements[$key] = $value;

        return $this;
    }

    /**
     * Add multiple replacements
     */
    public function addReplacements(array $replacements): self
    {
        $this->replacements = array_merge($this->replacements, $replacements);

        return $this;
    }

    /**
     * Generate model stub
     */
    public function generate(): string
    {
        $stub = $this->getModelStub();

        foreach ($this->replacements as $key => $value) {
            $stub = str_replace(sprintf('{%s}', $key), $value, $stub);
        }

        return $stub;
    }

    /**
     * Get base model stub template
     */
    protected function getModelStub(): string
    {
        return <<<'STUB'
<?php

namespace {namespace};

use Illuminate\Database\Eloquent\Model;
{uses}

{docblock}
class {class_name} extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = '{table}';
{primary_key}
{timestamps}
{constraint_comments}
{fillable}
{hidden}
{casts}
{dates}
{relationships}
}

STUB;
    }

    /**
     * Generate relationship method stub
     */
    public static function relationshipStub(
        string $type,
        string $methodName,
        string $relatedModel,
        ?string $foreignKey = null,
        ?string $localKey = null,
        bool $withDocBlock = true
    ): string {
        $docBlock = '';
        if ($withDocBlock) {
            $relationType = ucfirst($type);
            $docBlock = "    /**\n";
            $docBlock .= "     * Get the {$methodName} relationship.\n";
            $docBlock .= "     *\n";
            $docBlock .= sprintf('     * @return \Illuminate\Database\Eloquent\Relations\%s%s', $relationType, PHP_EOL);
            $docBlock .= "     */\n";
        }

        $params = sprintf("'%s'", $relatedModel);

        if ($foreignKey) {
            $params .= sprintf(", '%s'", $foreignKey);
        }

        if ($localKey) {
            $params .= sprintf(", '%s'", $localKey);
        }

        return $docBlock."    public function {$methodName}()\n".
               "    {\n".
               "        return \$this->{$type}({$params});\n".
               '    }';
    }

    /**
     * Generate fillable array stub
     */
    public static function fillableStub(array $columns, int $indent = 1): string
    {
        if ($columns === []) {
            return '';
        }

        $indentation = str_repeat('    ', $indent);
        $innerIndent = str_repeat('    ', $indent + 1);

        $stub = "\n{$indentation}/**\n";
        $stub .= $indentation.' * The attributes that are mass assignable.
';
        $stub .= $indentation.' *
';
        $stub .= $indentation.' * @var array<int, string>
';
        $stub .= $indentation.' */
';
        $stub .= $indentation.'protected $fillable = [
';

        foreach ($columns as $column) {
            $stub .= "{$innerIndent}'{$column}',\n";
        }

        return $stub.($indentation.'];');
    }

    /**
     * Generate hidden array stub
     */
    public static function hiddenStub(array $columns, int $indent = 1): string
    {
        if ($columns === []) {
            return '';
        }

        $indentation = str_repeat('    ', $indent);
        $innerIndent = str_repeat('    ', $indent + 1);

        $stub = "\n{$indentation}/**\n";
        $stub .= $indentation.' * The attributes that should be hidden for serialization.
';
        $stub .= $indentation.' *
';
        $stub .= $indentation.' * @var array<int, string>
';
        $stub .= $indentation.' */
';
        $stub .= $indentation.'protected $hidden = [
';

        foreach ($columns as $column) {
            $stub .= "{$innerIndent}'{$column}',\n";
        }

        return $stub.($indentation.'];');
    }

    /**
     * Generate casts array stub
     */
    public static function castsStub(array $casts, int $indent = 1): string
    {
        if ($casts === []) {
            return '';
        }

        $indentation = str_repeat('    ', $indent);
        $innerIndent = str_repeat('    ', $indent + 1);

        $stub = "\n{$indentation}/**\n";
        $stub .= $indentation.' * The attributes that should be cast.
';
        $stub .= $indentation.' *
';
        $stub .= $indentation.' * @var array<string, string>
';
        $stub .= $indentation.' */
';
        $stub .= $indentation.'protected $casts = [
';

        foreach ($casts as $column => $cast) {
            $stub .= "{$innerIndent}'{$column}' => '{$cast}',\n";
        }

        return $stub.($indentation.'];');
    }

    /**
     * Generate dates array stub
     */
    public static function datesStub(array $dates, int $indent = 1): string
    {
        if ($dates === []) {
            return '';
        }

        $indentation = str_repeat('    ', $indent);
        $innerIndent = str_repeat('    ', $indent + 1);

        $stub = "\n{$indentation}/**\n";
        $stub .= $indentation.' * The attributes that should be mutated to dates.
';
        $stub .= $indentation.' *
';
        $stub .= $indentation.' * @var array<int, string>
';
        $stub .= $indentation.' */
';
        $stub .= $indentation.'protected $dates = [
';

        foreach ($dates as $date) {
            $stub .= "{$innerIndent}'{$date}',\n";
        }

        return $stub.($indentation.'];');
    }

    /**
     * Generate class-level PHPDoc
     */
    public static function classDocBlock(array $properties, array $methods): string
    {
        $lines = [];

        foreach ($properties as $property) {
            $type = $property['type'] ?? 'mixed';
            $name = $property['name'];
            $comment = $property['comment'] ?? null;

            // Skip empty property definitions (used for table comments)
            if (empty($type) && empty($name)) {
                if ($comment) {
                    $lines[] = $comment;
                }

                continue;
            }

            $lines[] = $comment ? sprintf('@property %s $%s %s', $type, $name, $comment) : sprintf('@property %s $%s', $type, $name);
        }

        if ($properties !== [] && $methods !== []) {
            $lines[] = '';
        }

        foreach ($methods as $method) {
            $return = $method['return'] ?? 'mixed';
            $name = $method['name'];
            $comment = $method['comment'] ?? null;

            $lines[] = $comment ? sprintf('@method %s %s() %s', $return, $name, $comment) : sprintf('@method %s %s()', $return, $name);
        }

        if ($lines === []) {
            return '';
        }

        return Helpers::formatDocBlock($lines, 0);
    }

    /**
     * Generate primary key property stub
     */
    public static function primaryKeyStub(?string $primaryKey, int $indent = 1): string
    {
        // If null or 'id', don't add the property (use Laravel's default)
        if ($primaryKey === null || $primaryKey === 'id') {
            return '';
        }

        $indentation = str_repeat('    ', $indent);

        return "\n{$indentation}/**\n".
               ($indentation.' * The primary key for the model.
').
               ($indentation.' *
').
               ($indentation.' * @var string
').
               ($indentation.' */
').
               sprintf("%sprotected \$primaryKey = '%s';", $indentation, $primaryKey);
    }

    /**
     * Generate timestamps property stub
     */
    public static function timestampsStub(bool $timestamps, int $indent = 1): string
    {
        if ($timestamps) {
            return '';
        }

        $indentation = str_repeat('    ', $indent);

        return "\n{$indentation}/**\n".
               ($indentation.' * Indicates if the model should be timestamped.
').
               ($indentation.' *
').
               ($indentation.' * @var bool
').
               ($indentation.' */
').
               ($indentation.'public $timestamps = false;');
    }

    /**
     * Generate uses statements
     */
    public static function usesStub(array $uses): string
    {
        if ($uses === []) {
            return '';
        }

        $statements = [];
        foreach ($uses as $use) {
            $statements[] = sprintf('use %s;', $use);
        }

        return implode("\n", $statements);
    }
}
