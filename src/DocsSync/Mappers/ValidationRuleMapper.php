<?php

namespace Zuqongtech\LaravelAnvil\DocsSync\Mappers;

use Zuqongtech\LaravelAnvil\DocsSync\PropertyShape;

/**
 * Maps one field's validation rules to an OpenAPI schema fragment.
 *
 * Rules arrive as a flat list of tokens, each either a plain rule (`max:255`) or
 * the raw source of a rule object (`Rule::in([...])`, `new Enum(Status::class)`).
 * Both forms occur: the reader executes `rules()` when it can and tokenises the
 * source when it cannot, and this mapper must not care which happened.
 *
 * Bound semantics are type-dependent, which is the detail most naive
 * implementations get wrong: `max:255` on a string is `maxLength`, on a number it
 * is `maximum`, and on an array it is `maxItems`. Emitting `maximum: 255` for a
 * string field produces a spec that validators reject.
 */
final readonly class ValidationRuleMapper
{
    /** Rules that fix the base type outright. */
    private const TYPE_RULES = [
        'integer' => 'integer',
        'int' => 'integer',
        'numeric' => 'number',
        'decimal' => 'number',
        'string' => 'string',
        'boolean' => 'boolean',
        'bool' => 'boolean',
        'accepted' => 'boolean',
        'declined' => 'boolean',
        'array' => 'array',
        'list' => 'array',
        'json' => 'string',
        'file' => 'string',
        'image' => 'string',
    ];

    /** Rules that imply a string format. */
    private const FORMAT_RULES = [
        'email' => 'email',
        'url' => 'uri',
        'active_url' => 'uri',
        'uuid' => 'uuid',
        'ulid' => 'ulid',
        'ip' => 'ipv4',
        'ipv4' => 'ipv4',
        'ipv6' => 'ipv6',
        'mac_address' => 'mac',
        'hex_color' => 'hex-color',
        'date' => 'date-time',
        'file' => 'binary',
        'image' => 'binary',
    ];

    /** Rules meaning "may be absent", so never `required`. */
    private const CONDITIONAL_PRESENCE = [
        'sometimes',
        'nullable',
        'present_if',
        'present_unless',
        'present_with',
        'required_if',
        'required_if_accepted',
        'required_if_declined',
        'required_unless',
        'required_with',
        'required_with_all',
        'required_without',
        'required_without_all',
        'required_array_keys',
        'missing',
        'missing_if',
        'missing_unless',
        'missing_with',
        'missing_with_all',
        'prohibited',
        'prohibited_if',
        'prohibited_unless',
        'prohibits',
        'exclude',
        'exclude_if',
        'exclude_unless',
        'exclude_with',
        'exclude_without',
    ];

    /**
     * @param  list<string>  $enumNamespaces  searched when resolving a bare enum class
     */
    public function __construct(
        private ColumnSchemas $columns,
        private string $model,
        private array $enumNamespaces = [],
    ) {}

    /**
     * Split a pipe-delimited rule string into tokens.
     *
     * `regex:` and `not_regex:` are special-cased because a pattern legitimately
     * contains `|`, and splitting inside one produces two nonsense rules. Laravel
     * itself tells you to use array syntax for regex; this at least degrades to
     * "keep the rest of the string intact" instead of corrupting it.
     *
     * @return list<string>
     */
    public static function splitRuleString(string $rules): array
    {
        $tokens = [];
        $buffer = '';
        $length = strlen($rules);
        $verbatim = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $rules[$i];

            if (! $verbatim && $char === '|') {
                $tokens[] = $buffer;
                $buffer = '';

                continue;
            }

            $buffer .= $char;

            if (! $verbatim && preg_match('/(?:^|\|)(?:not_)?regex:$/i', $buffer) === 1) {
                $verbatim = true;
            }
        }

        $tokens[] = $buffer;

        return array_values(array_filter(array_map(trim(...), $tokens), static fn (string $t): bool => $t !== ''));
    }

    /**
     * @param  list<string>  $tokens
     */
    public function map(string $field, array $tokens): PropertyShape
    {
        $name = self::leafName($field);
        $type = null;
        $format = null;
        $enum = null;
        $nullable = false;
        $required = false;
        $conditional = false;
        $bounds = [];
        $pattern = null;
        $referenced = null;
        $sawRule = false;
        $unreadable = [];

        foreach ($tokens as $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            $sawRule = true;
            [$rule, $argument] = self::divide($token);
            $lower = strtolower($rule);

            if ($lower === 'required') {
                $required = true;

                continue;
            }

            if ($lower === 'nullable') {
                $nullable = true;

                continue;
            }

            if (in_array($lower, self::CONDITIONAL_PRESENCE, true)) {
                $conditional = true;

                continue;
            }

            if (isset(self::TYPE_RULES[$lower])) {
                $type ??= self::TYPE_RULES[$lower];
            }

            if (isset(self::FORMAT_RULES[$lower])) {
                $format ??= self::FORMAT_RULES[$lower];
                $type ??= 'string';
            }

            switch ($lower) {
                case 'in':
                    $enum = self::parseList($argument);

                    break;

                case 'date_format':
                    $type ??= 'string';
                    $format = self::formatForDatePattern($argument);

                    break;

                case 'regex':
                    $type ??= 'string';
                    $pattern = self::unwrapRegex($argument);

                    break;

                case 'min':
                case 'max':
                case 'size':
                case 'between':
                case 'digits':
                case 'digits_between':
                case 'gt':
                case 'gte':
                case 'lt':
                case 'lte':
                    $bounds[] = [$lower, $argument];

                    break;

                case 'exists':
                case 'unique':
                    $referenced ??= self::parseReference($argument, $name);

                    break;

                case 'confirmed':
                case 'different':
                case 'same':
                case 'filled':
                    break;

                default:
                    if ($enumValues = $this->enumRuleValues($token)) {
                        $enum = $enumValues;
                        $type ??= is_int($enumValues[0]) ? 'integer' : 'string';

                        break;
                    }

                    if ($listValues = self::inRuleObjectValues($token)) {
                        $enum = $listValues;
                        $type ??= 'string';

                        break;
                    }

                    if (self::looksLikeExpression($token)) {
                        $unreadable[] = $token;
                    }
            }
        }

        if (! $sawRule) {
            return PropertyShape::unresolved($name, '(no rules)');
        }

        // A referenced column tells us the type when nothing else did -- this is
        // why `exists:businesses,id` becomes an integer rather than a guessed string.
        if ($type === null && $referenced !== null) {
            $schema = $this->resolveReference($referenced);

            if ($schema !== null) {
                $type = is_array($schema['type'] ?? null)
                    ? (string) ($schema['type'][0] ?? 'string')
                    : (string) ($schema['type'] ?? 'string');
                $format ??= isset($schema['format']) ? (string) $schema['format'] : null;
            }
        }

        // Nothing at all determined a type: honest unresolved beats a guessed string,
        // so a hand-annotated type in the spec survives.
        if ($type === null && $enum === null && $format === null) {
            return $unreadable === []
                ? PropertyShape::unresolved($name, implode('|', $tokens), $required && ! $conditional)
                : PropertyShape::unresolved($name, $unreadable[0], $required && ! $conditional);
        }

        $type ??= 'string';
        $schema = ['type' => $type];

        // `format` is not string-only: a resolved foreign key legitimately carries
        // `int64`. But a string-only format (`email`) must never land on a number,
        // which can happen when an `exists:` lookup sets the type after a format
        // rule set the format, so each side is filtered against the other.
        if ($format !== null) {
            $numericFormats = ['int32', 'int64', 'float', 'double'];
            $isNumeric = in_array($type, ['integer', 'number'], true);

            if ($type === 'string' && ! in_array($format, $numericFormats, true)) {
                $schema['format'] = $format;
            } elseif ($isNumeric && in_array($format, $numericFormats, true)) {
                $schema['format'] = $format;
            }
        }

        if ($enum !== null) {
            $schema['enum'] = $enum;
        }

        if ($pattern !== null) {
            $schema['pattern'] = $pattern;
        }

        foreach ($bounds as [$rule, $argument]) {
            $schema = self::applyBound($schema, $type, $rule, $argument);
        }

        if ($type === 'array' && ! isset($schema['items'])) {
            $schema['items'] = [];
        }

        if ($nullable) {
            $schema = ResponseExpressionMapper::asNullable($schema);
        }

        return new PropertyShape(
            name: $name,
            schema: $schema,
            required: $required && ! $conditional,
            origin: 'rules',
        );
    }

    /**
     * `confirmed` requires a `{field}_confirmation` sibling that the rules never
     * name. Documenting the endpoint without it means every client that reads the
     * spec gets a 422 -- so sync synthesises it.
     */
    public static function confirmationSibling(string $field, PropertyShape $base): PropertyShape
    {
        return new PropertyShape(
            name: self::leafName($field).'_confirmation',
            schema: $base->schema,
            required: $base->required,
            origin: 'rules:confirmed',
        );
    }

    /** @param list<string> $tokens */
    public static function hasRule(array $tokens, string $rule): bool
    {
        foreach ($tokens as $token) {
            if (strcasecmp(self::divide(trim($token))[0], $rule) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function divide(string $token): array
    {
        $colon = strpos($token, ':');

        return $colon === false
            ? [$token, '']
            : [substr($token, 0, $colon), substr($token, $colon + 1)];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function applyBound(array $schema, string $type, string $rule, string $argument): array
    {
        $numbers = array_values(array_filter(
            array_map(trim(...), explode(',', $argument)),
            is_numeric(...),
        ));

        if ($numbers === []) {
            return $schema;
        }

        $first = $numbers[0] + 0;
        $second = isset($numbers[1]) ? $numbers[1] + 0 : null;

        [$minKey, $maxKey] = match ($type) {
            'string' => ['minLength', 'maxLength'],
            'array' => ['minItems', 'maxItems'],
            default => ['minimum', 'maximum'],
        };

        switch ($rule) {
            case 'min':
            case 'gte':
                $schema[$minKey] = $first;

                break;

            case 'gt':
                $schema[$minKey] = $type === 'string' || $type === 'array' ? $first + 1 : $first;

                if ($type !== 'string' && $type !== 'array') {
                    $schema['exclusiveMinimum'] = $first;
                    unset($schema[$minKey]);
                }

                break;

            case 'max':
            case 'lte':
                $schema[$maxKey] = $first;

                break;

            case 'lt':
                if ($type === 'string' || $type === 'array') {
                    $schema[$maxKey] = max(0, $first - 1);
                } else {
                    $schema['exclusiveMaximum'] = $first;
                }

                break;

            case 'size':
                $schema[$minKey] = $first;
                $schema[$maxKey] = $first;

                break;

            case 'between':
            case 'digits_between':
                $schema[$minKey] = $first;

                if ($second !== null) {
                    $schema[$maxKey] = $second;
                }

                break;

            case 'digits':
                $schema['minLength'] = $first;
                $schema['maxLength'] = $first;

                break;
        }

        return $schema;
    }

    /** @return list<string> */
    private static function parseList(string $argument): array
    {
        return array_values(array_map(
            static fn (string $v): string => trim(trim($v), "'\""),
            array_filter(explode(',', $argument), static fn (string $v): bool => trim($v) !== ''),
        ));
    }

    /**
     * `Rule::in(['a', 'b'])` in tokenised source.
     *
     * @return list<string>|null
     */
    private static function inRuleObjectValues(string $token): ?array
    {
        if (preg_match('/Rule\s*::\s*in\s*\(\s*\[(.*?)\]\s*\)/is', $token, $m) !== 1) {
            return null;
        }

        $values = self::parseList($m[1]);

        return $values === [] ? null : $values;
    }

    /**
     * `new Enum(Status::class)` / `Rule::enum(Status::class)`. Values come from
     * reflection when the enum is loadable (the in-app case). In the tokenised
     * fallback the class may not be autoloadable, and returning null there is
     * correct -- an empty enum in a spec is worse than no enum.
     *
     * @return list<string|int>|null
     */
    private function enumRuleValues(string $token): ?array
    {
        if (preg_match('/(?:new\s+Enum|Rule\s*::\s*enum)\s*\(\s*([\w\\\\]+)\s*::\s*class/i', $token, $m) !== 1) {
            return null;
        }

        $class = $m[1];

        // `enums.validation = 'rule'` means generated requests write
        // `new Enum(VehicleStatus::class)` against an imported short name. Tokenised
        // source has no import table, so the configured enum namespaces are the only
        // way to resolve it. Executing rules() sidesteps this entirely.
        $candidates = [$class, '\\'.$class];

        foreach ($this->enumNamespaces as $namespace) {
            $candidates[] = trim($namespace, '\\').'\\'.ltrim($class, '\\');
        }

        foreach ($candidates as $candidate) {
            if (! enum_exists($candidate)) {
                continue;
            }

            $values = [];

            foreach ($candidate::cases() as $case) {
                $values[] = $case->value ?? $case->name;
            }

            return $values === [] ? null : $values;
        }

        return null;
    }

    /**
     * @return array{table:string,column:string}|null
     */
    private static function parseReference(string $argument, string $field): ?array
    {
        $parts = array_map(trim(...), explode(',', $argument));
        $table = trim($parts[0] ?? '', "'\"");

        if ($table === '') {
            return null;
        }

        // `exists:table` without a column means the column matches the field name.
        $column = trim($parts[1] ?? $field, "'\"");

        return ['table' => $table, 'column' => $column === '' ? $field : $column];
    }

    /**
     * @param  array{table:string,column:string}  $reference
     * @return array<string, mixed>|null
     */
    private function resolveReference(array $reference): ?array
    {
        $component = $this->columns->componentForTable($reference['table']);

        if ($component === null) {
            // Unknown table, but an `exists:` on an `*_id`-shaped field is
            // overwhelmingly a foreign key. Integer is the safe read.
            return str_ends_with($reference['column'], 'id') ? ['type' => 'integer'] : null;
        }

        return $this->columns->for($component, $reference['column'])
            ?? $this->columns->for($component, 'id');
    }

    private static function formatForDatePattern(string $pattern): string
    {
        $pattern = trim($pattern, "'\"");

        return preg_match('/[HGhisuv]/', $pattern) === 1 ? 'date-time' : 'date';
    }

    private static function unwrapRegex(string $argument): string
    {
        $argument = trim($argument);

        if (strlen($argument) > 1 && preg_match('/^([\/#~%])(.*)\1[imsxuADSUXJn]*$/s', $argument, $m) === 1) {
            return $m[2];
        }

        return $argument;
    }

    private static function looksLikeExpression(string $token): bool
    {
        return str_contains($token, '(') || str_contains($token, '::') || str_starts_with($token, '$');
    }

    public static function leafName(string $field): string
    {
        $segments = explode('.', $field);

        return end($segments);
    }
}
