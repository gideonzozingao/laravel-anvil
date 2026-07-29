<?php

namespace Zuqongtech\LaravelAnvil\DocsSync\Readers;

use Zuqongtech\LaravelAnvil\Contracts\ShapeReader;
use Zuqongtech\LaravelAnvil\DocsSync\CodeShape;
use Zuqongtech\LaravelAnvil\DocsSync\ComponentNaming;
use Zuqongtech\LaravelAnvil\DocsSync\Mappers\ColumnSchemas;
use Zuqongtech\LaravelAnvil\DocsSync\Mappers\ResponseExpressionMapper;
use Zuqongtech\LaravelAnvil\DocsSync\Php\SourceTokens;
use Zuqongtech\LaravelAnvil\DocsSync\PropertyShape;

/**
 * Reads the response payload an API resource produces, from `toArray()`.
 *
 * Tokenised, never executed. Executing `toArray()` would need a hydrated model, a
 * bound container and a Request instance; a docs command that boots that much is a
 * docs command that crashes whenever a resource has a bug, which is exactly when
 * you most want the drift report.
 */
final readonly class ResourceShapeReader implements ShapeReader
{
    public function __construct(
        private ColumnSchemas $columns,
        private ComponentNaming $naming,
    ) {}

    public function supports(string $class): bool
    {
        $short = ComponentNaming::shortName($class);

        return str_ends_with($short, 'Resource') || str_ends_with($short, 'Collection');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function read(string $class, array $context = []): ?CodeShape
    {
        $file = (string) ($context['file'] ?? '');
        $tokens = $file !== '' ? SourceTokens::fromFile($file) : null;

        if ($tokens === null) {
            return null;
        }

        $literal = $tokens->returnedArrayLiteral('toArray');

        if ($literal === null) {
            // `toArray()` is missing or does not return an array literal directly
            // (e.g. `return $this->payload($request);`). Reporting this as an empty
            // shape would prune the entire component, so refuse to read instead.
            return null;
        }

        $model = (string) ($context['model'] ?? ComponentNaming::modelFor($class));
        $mapper = new ResponseExpressionMapper(
            $this->columns,
            $model,
            fn (string $resource): string => $this->naming->referenceFor($resource),
        );

        $notes = [];
        $opaque = $tokens->opaqueConstructs('toArray');

        if ($opaque !== []) {
            $notes[] = 'Partial read: '.implode(', ', $opaque).' can add properties this reader cannot see, so nothing was pruned.';
        }

        $properties = [];
        $positional = 0;

        foreach (SourceTokens::splitArrayLiteral($literal) as $key => $expression) {
            if (str_starts_with((string) $key, '#')) {
                $positional++;

                continue;
            }

            $shape = $this->mapValue($mapper, (string) $key, $expression, $model);

            // A key that appears literally in the returned array is always in the
            // payload; only a `when()`/`whenLoaded()` wrapper can remove it. This
            // is why responses can report "became conditional" at all.
            $properties[(string) $key] = $shape->asRequired(! $shape->isConditional());
        }

        if ($positional > 0) {
            $notes[] = "Partial read: {$positional} entry(ies) had no literal key (spread, conditional merge, or computed key).";
        }

        if ($properties === [] && $positional === 0) {
            return null;
        }

        return new CodeShape(
            component: '',
            kind: CodeShape::RESPONSE,
            properties: $properties,
            source: $class,
            partial: $opaque !== [] || $positional > 0,
            notes: $notes,
            fingerprint: sha1((string) $tokens->methodBody('toArray')),
        );
    }

    /**
     * Inline array literals become nested object schemas, recursively. Anvil's
     * generated resources use them for grouped payloads (`'meta' => [...]`), and
     * flattening them would document a shape the API never returns.
     */
    private function mapValue(ResponseExpressionMapper $mapper, string $key, string $expression, string $model, int $depth = 0): PropertyShape
    {
        $trimmed = trim($expression);

        if ($depth <= 4 && str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']')) {
            $inner = substr($trimmed, 1, -1);
            $entries = SourceTokens::splitArrayLiteral($inner);

            $nested = [];
            $hasPositional = false;

            foreach ($entries as $nestedKey => $nestedExpression) {
                if (str_starts_with((string) $nestedKey, '#')) {
                    $hasPositional = true;

                    continue;
                }

                $nested[(string) $nestedKey] = $this->mapValue($mapper, (string) $nestedKey, $nestedExpression, $model, $depth + 1);
            }

            if ($nested === []) {
                // A list, not a keyed object -- we know it is an array, no more.
                return new PropertyShape($key, ['type' => 'array', 'items' => []], false, 'inline-list');
            }

            $properties = [];

            foreach ($nested as $name => $shape) {
                $properties[$name] = $shape->toSpecFragment();
            }

            return new PropertyShape(
                $key,
                ['type' => 'object', 'properties' => $properties],
                false,
                $hasPositional ? 'inline-object:partial' : 'inline-object',
            );
        }

        return $mapper->map($key, $expression);
    }
}
