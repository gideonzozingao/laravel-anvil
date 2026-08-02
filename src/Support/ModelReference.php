<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

/**
 * An immutable pointer to one *already generated* Eloquent model.
 *
 * This object is deliberately dumb: it never derives a namespace, a schema
 * segment or a class name from a table name. It only carries values that were
 * produced by the model-generation phase (or read back off disk), because the
 * moment two places in the pipeline both *compute* a model's FQCN they are free
 * to disagree — which is exactly the bug this type exists to make impossible.
 */
final readonly class ModelReference implements \Stringable
{
    /**
     * @param  string  $fqcn  Fully-qualified class name, no leading backslash (e.g. "App\Models\Core\User").
     * @param  string  $table  Bare table name as it appears in the catalog (e.g. "users").
     * @param  string|null  $schema  Source schema, or null for the connection's default schema.
     * @param  string|null  $qualifiedTable  Value written to `protected $table` (e.g. "core.users"); defaults to $table.
     */
    public function __construct(
        private string $fqcn,
        private string $table,
        private ?string $schema = null,
        private ?string $qualifiedTable = null,
    ) {}

    public function fqcn(): string
    {
        return ltrim($this->fqcn, '\\');
    }

    /**
     * Class name without its namespace, e.g. "User".
     */
    public function shortName(): string
    {
        $fqcn = $this->fqcn();
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    /**
     * Namespace without the class name, e.g. "App\Models\Core". Empty for a global class.
     */
    public function namespace(): string
    {
        $fqcn = $this->fqcn();
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? '' : substr($fqcn, 0, $pos);
    }

    public function table(): string
    {
        return $this->table;
    }

    public function schema(): ?string
    {
        return $this->schema;
    }

    public function qualifiedTable(): string
    {
        return $this->qualifiedTable ?? $this->table;
    }

    /**
     * Whether this model came from a non-default schema.
     */
    public function isSchemaQualified(): bool
    {
        return $this->schema !== null && $this->schema !== '';
    }

    /**
     * Single-instance variable name, e.g. "user" (for `$user`).
     */
    public function variable(): string
    {
        return lcfirst($this->shortName());
    }

    /**
     * Relative path of the class file under the models root, e.g. "Core/User.php".
     * Only meaningful when $rootNamespace is a prefix of this model's namespace.
     */
    public function relativePath(string $rootNamespace): ?string
    {
        $root = trim($rootNamespace, '\\');
        $fqcn = $this->fqcn();

        if ($root !== '' && ! str_starts_with($fqcn.'\\', $root.'\\')) {
            return null;
        }

        $tail = $root === '' ? $fqcn : ltrim(substr($fqcn, strlen($root)), '\\');

        return str_replace('\\', DIRECTORY_SEPARATOR, $tail).'.php';
    }

    /**
     * @return array{fqcn: string, table: string, schema: string|null, qualified_table: string}
     */
    public function toArray(): array
    {
        return [
            'fqcn' => $this->fqcn(),
            'table' => $this->table,
            'schema' => $this->schema,
            'qualified_table' => $this->qualifiedTable(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        throw_if(! isset($data['fqcn'], $data['table']) || ! is_string($data['fqcn']) || ! is_string($data['table']), \InvalidArgumentException::class, 'A model reference requires at least a string "fqcn" and "table".');

        $schema = $data['schema'] ?? null;
        $qualified = $data['qualified_table'] ?? null;

        return new self(
            $data['fqcn'],
            $data['table'],
            is_string($schema) && $schema !== '' ? $schema : null,
            is_string($qualified) && $qualified !== '' ? $qualified : null,
        );
    }

    public function __toString(): string
    {
        return $this->fqcn();
    }
}
