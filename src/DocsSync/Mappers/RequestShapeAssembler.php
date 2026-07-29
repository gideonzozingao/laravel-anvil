<?php

namespace Zuqongtech\LaravelAnvil\DocsSync\Mappers;

use Zuqongtech\LaravelAnvil\DocsSync\PropertyShape;

/**
 * Reassembles Laravel's flat, dotted rule keys into the nested structure the
 * payload actually has.
 *
 * `rules()` returns a flat map, but the JSON body is a tree:
 *
 *   'profile.timezone' => 'required|string'   ->  profile: { timezone: string }
 *   'items.*.sku'      => 'required|string'   ->  items: [ { sku: string } ]
 *   'tags.*'           => 'string'            ->  tags: [ string ]
 *
 * Documenting the flat keys verbatim -- which is what a naive implementation does
 * -- produces a spec describing a body with a literal `"profile.timezone"` key.
 * Every client generated from it would be wrong.
 *
 * A container's own rules still apply: `'items' => 'required|array|min:1'` sets
 * `minItems: 1` and marks it required, while its children come from `items.*`.
 */
final readonly class RequestShapeAssembler
{
    public function __construct(private ValidationRuleMapper $mapper) {}

    /**
     * @param  array<string, list<string>>  $rules  field (possibly dotted) => rule tokens
     * @return array<string, PropertyShape>
     */
    public function assemble(array $rules): array
    {
        $tree = [];

        foreach ($rules as $field => $tokens) {
            $this->insert($tree, explode('.', (string) $field), $tokens, (string) $field);
        }

        return $this->renderLevel($tree);
    }

    /**
     * @param  array<string, mixed>  $tree
     * @param  list<string>  $segments
     * @param  list<string>  $tokens
     */
    private function insert(array &$tree, array $segments, array $tokens, string $field): void
    {
        $head = array_shift($segments);

        if ($head === null) {
            return;
        }

        $tree[$head] ??= ['rules' => null, 'field' => $field, 'children' => []];

        if ($segments === []) {
            $tree[$head]['rules'] = $tokens;
            $tree[$head]['field'] = $field;

            return;
        }

        $this->insert($tree[$head]['children'], $segments, $tokens, $field);
    }

    /**
     * @param  array<string, mixed>  $level
     * @return array<string, PropertyShape>
     */
    private function renderLevel(array $level): array
    {
        $properties = [];

        foreach ($level as $segment => $node) {
            $properties[(string) $segment] = $this->renderNode((string) $segment, $node);

            // `confirmed` implies a sibling the rules never name.
            if (is_array($node['rules'] ?? null) && ValidationRuleMapper::hasRule($node['rules'], 'confirmed')) {
                $sibling = ValidationRuleMapper::confirmationSibling((string) $segment, $properties[(string) $segment]);
                $properties[$sibling->name] = $sibling;
            }
        }

        return $properties;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function renderNode(string $segment, array $node): PropertyShape
    {
        /** @var array<string, mixed> $children */
        $children = $node['children'] ?? [];
        /** @var list<string>|null $ownRules */
        $ownRules = $node['rules'] ?? null;
        $field = (string) ($node['field'] ?? $segment);

        // Leaf: no nested keys below it.
        if ($children === []) {
            return $ownRules === null
                ? PropertyShape::unresolved($segment, '(container with no rules)')
                : $this->mapper->map($field, $ownRules)->renamed($segment);
        }

        $container = $ownRules === null ? null : $this->mapper->map($field, $ownRules)->renamed($segment);
        $required = $container?->required ?? false;

        // Wildcard child means this is an array.
        if (isset($children['*'])) {
            $items = $this->renderNode('*', $children['*']);
            $schema = ['type' => 'array', 'items' => $items->schema === [] ? [] : $items->schema];

            foreach (['minItems', 'maxItems'] as $key) {
                if (isset($container->schema[$key])) {
                    $schema[$key] = $container->schema[$key];
                }
            }

            return new PropertyShape($segment, $schema, $required, 'rules:array');
        }

        // Otherwise a nested object.
        $nested = $this->renderLevel($children);
        $properties = [];
        $requiredChildren = [];

        foreach ($nested as $name => $shape) {
            $properties[$name] = $shape->toSpecFragment();

            if ($shape->required) {
                $requiredChildren[] = $name;
            }
        }

        sort($requiredChildren);

        $schema = ['type' => 'object', 'properties' => $properties];

        if ($requiredChildren !== []) {
            $schema['required'] = $requiredChildren;
        }

        if (($container->schema['type'] ?? null) !== null && self::mentionsNull($container->schema['type'])) {
            $schema = ResponseExpressionMapper::asNullable($schema);
        }

        return new PropertyShape($segment, $schema, $required, 'rules:object');
    }

    private static function mentionsNull(mixed $type): bool
    {
        return is_array($type) && in_array('null', $type, true);
    }
}
