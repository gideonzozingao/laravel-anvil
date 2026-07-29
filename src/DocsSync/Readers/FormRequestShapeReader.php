<?php

namespace Zuqongtech\LaravelAnvil\DocsSync\Readers;

use Throwable;
use Zuqongtech\LaravelAnvil\Contracts\ShapeReader;
use Zuqongtech\LaravelAnvil\DocsSync\CodeShape;
use Zuqongtech\LaravelAnvil\DocsSync\ComponentNaming;
use Zuqongtech\LaravelAnvil\DocsSync\Mappers\ColumnSchemas;
use Zuqongtech\LaravelAnvil\DocsSync\Mappers\RequestShapeAssembler;
use Zuqongtech\LaravelAnvil\DocsSync\Mappers\ValidationRuleMapper;
use Zuqongtech\LaravelAnvil\DocsSync\Php\SourceTokens;

/**
 * Reads the request payload a form request accepts, from `rules()`.
 *
 * Unlike a resource, `rules()` is often safe to EXECUTE, and executing it is
 * strictly better: it resolves `Rule::in()`, enum rules, constants and computed
 * arrays exactly, where tokenising can only approximate them. So this reader tries
 * execution first and falls back to tokenising.
 *
 * Execution fails in one very common case -- `rules()` that touches
 * `$this->route()` or `$this->user()`, which is normal for update requests with a
 * unique-ignore. That throws without a bound request, so the fallback is not an
 * edge case and gets the same care as the happy path.
 */
final readonly class FormRequestShapeReader implements ShapeReader
{
    /**
     * @param  list<string>  $enumNamespaces
     */
    public function __construct(
        private ColumnSchemas $columns,
        private bool $allowExecution = true,
        private array $enumNamespaces = [],
    ) {}

    public function supports(string $class): bool
    {
        return str_ends_with(ComponentNaming::shortName($class), 'Request');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function read(string $class, array $context = []): ?CodeShape
    {
        $file = (string) ($context['file'] ?? '');
        $model = (string) ($context['model'] ?? ComponentNaming::modelFor($class));

        $notes = [];
        $rules = $this->allowExecution ? $this->executeRules($class) : null;
        $partial = false;

        if ($rules === null) {
            $tokens = $file !== '' ? SourceTokens::fromFile($file) : null;

            if ($tokens === null) {
                return null;
            }

            $literal = $tokens->returnedArrayLiteral('rules');

            if ($literal === null) {
                return null;
            }

            $rules = $this->tokeniseRules($literal, $notes);
            $opaque = $tokens->opaqueConstructs('rules');

            if ($opaque !== []) {
                $partial = true;
                $notes[] = 'Partial read: '.implode(', ', $opaque).' in rules(), so nothing was pruned.';
            }

            $notes[] = 'Rules were read from source: rules() could not be executed (it likely uses $this->route() or $this->user()).';
        }

        if ($rules === []) {
            return null;
        }

        $assembler = new RequestShapeAssembler(
            new ValidationRuleMapper($this->columns, $model, $this->enumNamespaces),
        );
        $properties = $assembler->assemble($rules);

        return new CodeShape(
            component: '',
            kind: CodeShape::REQUEST,
            properties: $properties,
            source: $class,
            partial: $partial,
            notes: $notes,
            fingerprint: sha1(json_encode($rules, JSON_THROW_ON_ERROR)),
        );
    }

    /**
     * Instantiate the form request and call `rules()`. Returns null on any failure,
     * which is a normal outcome, not an error.
     *
     * @return array<string, list<string>>|null
     */
    private function executeRules(string $class): ?array
    {
        if (! class_exists($class)) {
            return null;
        }

        try {
            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->hasMethod('rules')) {
                return null;
            }

            $constructor = $reflection->getConstructor();

            if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                return null;
            }

            $instance = $reflection->newInstance();
            $raw = $instance->rules();

            return is_array($raw) ? $this->normaliseExecuted($raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<mixed, mixed>  $raw
     * @return array<string, list<string>>
     */
    private function normaliseExecuted(array $raw): array
    {
        $normalised = [];

        foreach ($raw as $field => $rules) {
            $tokens = [];

            foreach (is_array($rules) ? $rules : ValidationRuleMapper::splitRuleString((string) $rules) as $rule) {
                if (is_string($rule)) {
                    $tokens = [...$tokens, ...ValidationRuleMapper::splitRuleString($rule)];

                    continue;
                }

                if (is_object($rule)) {
                    // A rule object. `__toString()` on Laravel's own rule builders
                    // yields the string form ("in:a,b"), which the mapper handles.
                    $tokens[] = method_exists($rule, '__toString')
                        ? (string) $rule
                        : 'new '.$rule::class.'()';

                    continue;
                }

                $tokens[] = (string) $rule;
            }

            $normalised[(string) $field] = $tokens;
        }

        return $normalised;
    }

    /**
     * Turn the source of the returned rules array into per-field token lists.
     *
     * @param  list<string>  $notes
     * @return array<string, list<string>>
     */
    private function tokeniseRules(string $literal, array &$notes): array
    {
        $rules = [];
        $skipped = 0;

        foreach (SourceTokens::splitArrayLiteral($literal) as $field => $expression) {
            if (str_starts_with((string) $field, '#')) {
                $skipped++;

                continue;
            }

            $rules[(string) $field] = $this->tokeniseRuleValue(trim($expression));
        }

        if ($skipped > 0) {
            $notes[] = "Partial read: {$skipped} rule entry(ies) had a non-literal key.";
        }

        return $rules;
    }

    /**
     * @return list<string>
     */
    private function tokeniseRuleValue(string $expression): array
    {
        // A quoted pipe-delimited string.
        if (($literal = SourceTokens::literalKey($expression)) !== null) {
            return ValidationRuleMapper::splitRuleString($literal);
        }

        // An array literal of rules, possibly mixing strings and rule objects.
        if (str_starts_with($expression, '[') && str_ends_with($expression, ']')) {
            $tokens = [];

            foreach (SourceTokens::splitTopLevel(substr($expression, 1, -1), ',') as $element) {
                $element = trim($element);

                if ($element === '') {
                    continue;
                }

                $unquoted = SourceTokens::literalKey($element);

                $tokens = $unquoted !== null
                    ? [...$tokens, ...ValidationRuleMapper::splitRuleString($unquoted)]
                    : [...$tokens, $element];
            }

            return $tokens;
        }

        // Anything else (a method call, a constant, a variable) is kept whole so
        // the mapper can report it as unresolved rather than mis-parse it.
        return [$expression];
    }
}
