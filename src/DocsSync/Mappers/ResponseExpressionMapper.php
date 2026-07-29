<?php

namespace Zuqongtech\LaravelAnvil\DocsSync\Mappers;

use Zuqongtech\LaravelAnvil\DocsSync\Php\SourceTokens;
use Zuqongtech\LaravelAnvil\DocsSync\PropertyShape;

/**
 * Maps one `toArray()` value expression to an OpenAPI schema fragment.
 *
 * The contract that keeps this honest: every branch either recognises a pattern
 * and returns a typed fragment, or returns PropertyShape::unresolved(). It never
 * guesses. An unresolved property carries no structural keys, so the merger
 * leaves whatever the spec says in place -- meaning a type a human annotated by
 * hand survives every future sync. Guessing would instead overwrite that
 * annotation with something wrong on every run, which is far worse than an
 * honest "I could not read this".
 */
final class ResponseExpressionMapper
{
    /** Casts that fully determine the type regardless of the operand. */
    private const CASTS = [
        'string' => ['type' => 'string'],
        'int' => ['type' => 'integer'],
        'integer' => ['type' => 'integer'],
        'float' => ['type' => 'number', 'format' => 'float'],
        'double' => ['type' => 'number', 'format' => 'double'],
        'bool' => ['type' => 'boolean'],
        'boolean' => ['type' => 'boolean'],
        'array' => ['type' => 'array', 'items' => []],
        'object' => ['type' => 'object'],
    ];

    /** Method suffixes whose return type is known regardless of receiver. */
    private const TERMINAL_CALLS = [
        'count' => ['type' => 'integer'],
        'sum' => ['type' => 'number'],
        'avg' => ['type' => 'number'],
        'average' => ['type' => 'number'],
        'exists' => ['type' => 'boolean'],
        'isEmpty' => ['type' => 'boolean'],
        'isNotEmpty' => ['type' => 'boolean'],
        'toIso8601String' => ['type' => 'string', 'format' => 'date-time'],
        'toIso8601ZuluString' => ['type' => 'string', 'format' => 'date-time'],
        'toISOString' => ['type' => 'string', 'format' => 'date-time'],
        'toAtomString' => ['type' => 'string', 'format' => 'date-time'],
        'toRfc3339String' => ['type' => 'string', 'format' => 'date-time'],
        'toDateTimeString' => ['type' => 'string', 'format' => 'date-time'],
        'toDateString' => ['type' => 'string', 'format' => 'date'],
        'toTimeString' => ['type' => 'string'],
        'diffForHumans' => ['type' => 'string'],
        'format' => ['type' => 'string'],
        'timestamp' => ['type' => 'integer'],
        'getTimestamp' => ['type' => 'integer'],
        'toArray' => ['type' => 'array', 'items' => []],
        'all' => ['type' => 'array', 'items' => []],
        'values' => ['type' => 'array', 'items' => []],
        'pluck' => ['type' => 'array', 'items' => []],
        'keys' => ['type' => 'array', 'items' => ['type' => 'string']],
        'toJson' => ['type' => 'string'],
        'value' => ['type' => 'string'],
        'label' => ['type' => 'string'],
        'name' => ['type' => 'string'],
    ];

    /** Global helpers returning URLs. */
    private const URL_HELPERS = ['url', 'route', 'asset', 'secure_url', 'action'];

    public function __construct(
        private readonly ColumnSchemas $columns,
        private readonly string $model,
        /** @var callable(string): string  resource class -> component name */
        private $componentNamer,
    ) {}

    /**
     * @param  string  $name  property key as it appears in the payload
     */
    public function map(string $name, string $expression): PropertyShape
    {
        $expression = trim($expression);

        if ($expression === '') {
            return PropertyShape::unresolved($name, '(empty)');
        }

        $shape = $this->resolve($name, $expression, depth: 0);

        return $shape ?? PropertyShape::unresolved($name, $expression);
    }

    /**
     * Recursive resolution. Returns null (not an unresolved shape) so callers can
     * try an alternative branch -- `??` and `?:` both depend on that.
     */
    private function resolve(string $name, string $expression, int $depth): ?PropertyShape
    {
        if ($depth > 6) {
            return null;
        }

        $expression = $this->stripRedundantParens(trim($expression));

        return $this->fromNullCoalesce($name, $expression, $depth)
            ?? $this->fromTernary($name, $expression, $depth)
            ?? $this->fromCast($name, $expression, $depth)
            ?? $this->fromLiteral($name, $expression)
            ?? $this->fromNestedResource($name, $expression)
            ?? $this->fromConditional($name, $expression, $depth)
            ?? $this->fromUrlHelper($name, $expression)
            ?? $this->fromTerminalCall($name, $expression)
            ?? $this->fromColumn($name, $expression);
    }

    private function fromNullCoalesce(string $name, string $expression, int $depth): ?PropertyShape
    {
        $parts = SourceTokens::splitTopLevel($expression, '??', limit: 2);

        if (count($parts) < 2) {
            return null;
        }

        $left = $this->resolve($name, $parts[0], $depth + 1);
        $right = $this->resolve($name, $parts[1], $depth + 1);

        // Prefer whichever side we could type; the coalesce itself only tells us
        // the property is defaulted, not what it is.
        $chosen = $left ?? $right;

        if ($chosen === null) {
            return null;
        }

        return $chosen->renamed($name);
    }

    /**
     * Ternary and elvis. The type is whichever branch we can read; a literal
     * `null` branch additionally makes the property nullable.
     */
    private function fromTernary(string $name, string $expression, int $depth): ?PropertyShape
    {
        $branches = $this->ternaryBranches($expression);

        if ($branches === null) {
            return null;
        }

        [$condition, $then, $else] = $branches;

        // Elvis (`$a ?: $b`) has no `then` branch -- the condition IS the value.
        $primary = trim($then) === '' ? $condition : $then;

        $primaryShape = strtolower(trim($primary)) === 'null'
            ? null
            : $this->resolve($name, $primary, $depth + 1);

        $elseShape = strtolower(trim($else)) === 'null'
            ? null
            : $this->resolve($name, $else, $depth + 1);

        $chosen = $primaryShape ?? $elseShape;

        if ($chosen === null) {
            return null;
        }

        $nullable = strtolower(trim($primary)) === 'null' || strtolower(trim($else)) === 'null';
        $schema = $chosen->schema;

        if ($nullable && $schema !== []) {
            $schema = self::asNullable($schema);
        }

        return new PropertyShape($name, $schema, false, 'ternary', $chosen->unresolved, $chosen->expression);
    }

    /**
     * Split a ternary into [condition, then, else] at bracket depth zero.
     *
     * `?->` and `??` arrive from the lexer as single tokens, so neither can be
     * mistaken for the ternary `?`; likewise `::` cannot be mistaken for `:`.
     * Nested ternaries are matched by counting pending `?` before consuming a `:`.
     *
     * @return array{0:string,1:string,2:string}|null
     */
    private function ternaryBranches(string $expression): ?array
    {
        $texts = [];

        foreach (token_get_all('<?php '.$expression) as $token) {
            if (is_array($token) && $token[0] === T_OPEN_TAG) {
                continue;
            }

            $texts[] = is_array($token) ? $token[1] : $token;
        }

        $depth = 0;
        $questionAt = null;
        $pending = 0;
        $colonAt = null;

        foreach ($texts as $index => $text) {
            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;

                continue;
            }

            if (in_array($text, [')', ']', '}'], true)) {
                $depth--;

                continue;
            }

            if ($depth !== 0) {
                continue;
            }

            if ($text === '?') {
                if ($questionAt === null) {
                    $questionAt = $index;
                } else {
                    $pending++;
                }

                continue;
            }

            if ($text === ':' && $questionAt !== null) {
                if ($pending > 0) {
                    $pending--;

                    continue;
                }

                $colonAt = $index;

                break;
            }
        }

        if ($questionAt === null || $colonAt === null) {
            return null;
        }

        return [
            implode('', array_slice($texts, 0, $questionAt)),
            implode('', array_slice($texts, $questionAt + 1, $colonAt - $questionAt - 1)),
            implode('', array_slice($texts, $colonAt + 1)),
        ];
    }

    private function fromCast(string $name, string $expression, int $depth): ?PropertyShape
    {
        if (preg_match('/^\(\s*(string|int|integer|float|double|bool|boolean|array|object)\s*\)\s*(.+)$/is', $expression, $m) !== 1) {
            return null;
        }

        $schema = self::CASTS[strtolower($m[1])];

        // For an array cast, try to type the items from the operand.
        if ($schema['type'] === 'array') {
            $inner = $this->resolve($name, $m[2], $depth + 1);

            if ($inner !== null && ($inner->schema['type'] ?? null) === 'array' && ($inner->schema['items'] ?? []) !== []) {
                $schema['items'] = $inner->schema['items'];
            }
        }

        return new PropertyShape($name, $schema, false, 'cast:'.strtolower($m[1]));
    }

    private function fromLiteral(string $name, string $expression): ?PropertyShape
    {
        $lower = strtolower($expression);

        if ($lower === 'null') {
            // Type-free but explicitly nullable: no structural claim to make.
            return null;
        }

        if ($lower === 'true' || $lower === 'false') {
            return new PropertyShape($name, ['type' => 'boolean'], false, 'literal');
        }

        if (preg_match('/^-?\d+$/', $expression) === 1) {
            return new PropertyShape($name, ['type' => 'integer'], false, 'literal');
        }

        if (preg_match('/^-?\d*\.\d+$/', $expression) === 1) {
            return new PropertyShape($name, ['type' => 'number'], false, 'literal');
        }

        if (SourceTokens::literalKey($expression) !== null) {
            return new PropertyShape($name, ['type' => 'string'], false, 'literal');
        }

        return null;
    }

    /**
     * `new XResource($this->y)`, `XResource::make(...)`, `XResource::collection(...)`.
     * Emits a `$ref`, which is what makes nested payload edits propagate: the
     * referenced component is itself synced from its own resource class.
     */
    private function fromNestedResource(string $name, string $expression): ?PropertyShape
    {
        if (preg_match('/^([A-Za-z_\\\\][\w\\\\]*Resource|[A-Za-z_\\\\][\w\\\\]*Collection)\s*::\s*(collection|make)\s*\(/i', $expression, $m) === 1) {
            $component = ($this->componentNamer)($m[1]);
            $ref = ['$ref' => '#/components/schemas/'.$component];

            return strtolower($m[2]) === 'collection'
                ? new PropertyShape($name, ['type' => 'array', 'items' => $ref], false, 'resource:collection')
                : new PropertyShape($name, $ref, false, 'resource');
        }

        if (preg_match('/^new\s+([A-Za-z_\\\\][\w\\\\]*(?:Resource|Collection))\s*\(/i', $expression, $m) === 1) {
            $component = ($this->componentNamer)($m[1]);

            return new PropertyShape($name, ['$ref' => '#/components/schemas/'.$component], false, 'resource');
        }

        return null;
    }

    /**
     * `$this->when(cond, value)` and `$this->whenLoaded('rel', value)` wrap a value
     * in a conditional. The inner value is the type; the conditionality means the
     * property is not required, which the caller already assumes for responses.
     *
     * `$this->whenLoaded('rel')` with no value argument yields the relation itself,
     * which is not typeable from source -- correctly unresolved.
     */
    private function fromConditional(string $name, string $expression, int $depth): ?PropertyShape
    {
        if (preg_match('/^\$this\s*->\s*(when|whenLoaded|whenNotNull|whenAppended|whenCounted|whenPivotLoaded)\s*\(/i', $expression, $m) !== 1) {
            return null;
        }

        $args = $this->callArguments($expression);

        if ($args === null) {
            return null;
        }

        $valueIndex = strtolower($m[1]) === 'whennotnull' ? 0 : 1;
        $inner = $args[$valueIndex] ?? null;

        if ($inner === null || trim($inner) === '') {
            return null;
        }

        // Unwrap a closure or arrow function used to defer evaluation.
        $inner = preg_replace('/^(?:static\s+)?fn\s*\([^)]*\)\s*=>\s*/i', '', trim($inner)) ?? $inner;

        if (preg_match('/^(?:static\s+)?function\s*\([^)]*\)[^{]*\{\s*return\s+(.+?);?\s*\}$/is', trim($inner), $cm) === 1) {
            $inner = $cm[1];
        }

        $shape = $this->resolve($name, $inner, $depth + 1);

        if ($shape === null) {
            return null;
        }

        // Preserve the fact that the key may be absent -- the caller uses it to
        // decide requiredness, and it is information the generator cannot derive.
        return new PropertyShape(
            $name,
            $shape->schema,
            false,
            PropertyShape::CONDITIONAL.':'.strtolower($m[1]),
            $shape->unresolved,
            $shape->expression,
        );
    }

    private function fromUrlHelper(string $name, string $expression): ?PropertyShape
    {
        $pattern = '/^(?:\\\\)?('.implode('|', self::URL_HELPERS).')\s*\(/i';

        if (preg_match($pattern, $expression) === 1) {
            return new PropertyShape($name, ['type' => 'string', 'format' => 'uri'], false, 'url-helper');
        }

        if (preg_match('/^(?:\\\\)?Storage\s*::\s*(url|temporaryUrl)\s*\(/i', $expression) === 1) {
            return new PropertyShape($name, ['type' => 'string', 'format' => 'uri'], false, 'url-helper');
        }

        return null;
    }

    /**
     * A trailing method call whose return type is known: `->count()`,
     * `?->toIso8601String()`, `->format('Y-m-d')`.
     */
    private function fromTerminalCall(string $name, string $expression): ?PropertyShape
    {
        if (preg_match('/(?:->|\?->)\s*([A-Za-z_]\w*)\s*\([^()]*\)\s*$/', $expression, $m) !== 1) {
            return null;
        }

        $method = $m[1];
        $schema = self::TERMINAL_CALLS[$method] ?? null;

        if ($schema === null) {
            foreach (self::TERMINAL_CALLS as $known => $candidate) {
                if (strcasecmp($known, $method) === 0) {
                    $schema = $candidate;

                    break;
                }
            }
        }

        if ($schema === null) {
            return null;
        }

        // `?->` means the whole expression can be null.
        if (str_contains($expression, '?->')) {
            $schema = self::asNullable($schema);
        }

        return new PropertyShape($name, $schema, false, 'call:'.$method);
    }

    /**
     * `$this->column`, `$this->column->value` (enum cast), `$this->getKey()`.
     * Types come from the entity schema, never re-derived.
     */
    private function fromColumn(string $name, string $expression): ?PropertyShape
    {
        if (preg_match('/^\$this\s*->\s*getKey\s*\(\s*\)$/i', $expression) === 1) {
            $schema = $this->columns->for($this->model, 'id');

            return $schema === null ? null : new PropertyShape($name, $schema, false, 'column:id');
        }

        // Enum-cast access: `$this->status->value` / `$this->status?->value`.
        if (preg_match('/^\$this\s*->\s*([A-Za-z_]\w*)\s*\??->\s*(value|name)$/', $expression, $m) === 1) {
            $schema = $this->columns->for($this->model, $m[1]) ?? ['type' => 'string'];
            $schema = ColumnSchemas::structuralOnly($schema);

            if (str_contains($expression, '?->')) {
                $schema = self::asNullable($schema);
            }

            return new PropertyShape($name, $schema, false, 'column:'.$m[1]);
        }

        if (preg_match('/^\$this\s*->\s*([A-Za-z_]\w*)$/', $expression, $m) !== 1) {
            return null;
        }

        $schema = $this->columns->for($this->model, $m[1]);

        if ($schema === null) {
            // A property on the model that is not a column -- an accessor, an
            // appended attribute, a relation. Not typeable from here.
            return null;
        }

        return new PropertyShape($name, $schema, false, 'column:'.$m[1]);
    }

    /**
     * Arguments of the outermost call in the expression, split at depth zero.
     *
     * @return list<string>|null
     */
    private function callArguments(string $expression): ?array
    {
        $open = strpos($expression, '(');

        if ($open === false) {
            return null;
        }

        $depth = 0;
        $length = strlen($expression);

        for ($i = $open; $i < $length; $i++) {
            if ($expression[$i] === '(') {
                $depth++;
            } elseif ($expression[$i] === ')') {
                $depth--;

                if ($depth === 0) {
                    $inner = substr($expression, $open + 1, $i - $open - 1);

                    return array_map(trim(...), SourceTokens::splitTopLevel($inner, ','));
                }
            }
        }

        return null;
    }

    private function stripRedundantParens(string $expression): string
    {
        while (
            strlen($expression) > 1
            && $expression[0] === '('
            && str_ends_with($expression, ')')
            && $this->parensBalancedAcross($expression)
        ) {
            $expression = trim(substr($expression, 1, -1));
        }

        return $expression;
    }

    private function parensBalancedAcross(string $expression): bool
    {
        $depth = 0;
        $length = strlen($expression);

        for ($i = 0; $i < $length; $i++) {
            if ($expression[$i] === '(') {
                $depth++;
            } elseif ($expression[$i] === ')') {
                $depth--;

                if ($depth === 0 && $i !== $length - 1) {
                    return false;
                }
            }
        }

        return $depth === 0;
    }

    /**
     * OpenAPI 3.1 nullability: a type union, not the 3.0 `nullable` keyword.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function asNullable(array $schema): array
    {
        if (isset($schema['$ref'])) {
            return ['anyOf' => [$schema, ['type' => 'null']]];
        }

        $type = $schema['type'] ?? null;

        if ($type === null) {
            return $schema;
        }

        $types = is_array($type) ? $type : [$type];

        if (! in_array('null', $types, true)) {
            $types[] = 'null';
        }

        $schema['type'] = $types;

        return $schema;
    }
}
