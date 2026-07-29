<?php

namespace Zuqongtech\LaravelAnvil\DocsSync\Php;

/**
 * Minimal, dependency-free PHP source inspection built on token_get_all().
 *
 * Why tokenise rather than execute: `toArray()` needs a hydrated model, a
 * container and a Request to run. Instantiating all three during a docs command
 * would mean booting the app to the point where a bad resource crashes the
 * generator. Why tokenise rather than depend on nikic/php-parser: this package
 * already ships with no parser dependency and the sync only needs the top level
 * of one array literal -- a full AST is a large dependency for a small job.
 *
 * The limits of that trade-off are explicit. This class understands the SHAPE of
 * a returned array literal (its keys, and the raw source of each value). It does
 * not evaluate anything. Callers that cannot interpret a value expression must
 * degrade to PropertyShape::unresolved() rather than guess.
 */
final readonly class SourceTokens
{
    /** Constructs that hide properties from a purely lexical read. */
    private const OPAQUE = [
        'mergeWhen',
        'mergeUnless',
        'merge',
        'array_merge',
        'array_merge_recursive',
        'parent::toArray',
        'parent::rules',
        'get_object_vars',
        'iterator_to_array',
    ];

    private function __construct(
        private string $source,
        /** @var list<array{0:int,1:string}|string> */
        private array $tokens,
    ) {}

    public static function fromSource(string $source): self
    {
        return new self($source, token_get_all($source));
    }

    public static function fromFile(string $path): ?self
    {
        $source = @file_get_contents($path);

        return $source === false ? null : self::fromSource($source);
    }

    /**
     * The fully-qualified name of the first class declared in the file.
     *
     * Derived from the source, not from the file path, because PSR-4 roots are
     * configurable and a path-based guess breaks for anyone whose `app/` directory
     * is mapped to something other than `App\`.
     */
    public function fullyQualifiedClassName(): ?string
    {
        $namespace = '';
        $count = count($this->tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($this->isToken($i, T_NAMESPACE)) {
                $namespace = '';

                for ($j = $i + 1; $j < $count; $j++) {
                    $text = $this->text($j);

                    if ($text === ';' || $text === '{') {
                        break;
                    }

                    if ($this->isToken($j, T_STRING) || $this->isToken($j, T_NAME_QUALIFIED) || $text === '\\') {
                        $namespace .= $text;
                    }
                }

                continue;
            }

            if (! $this->isToken($i, T_CLASS)) {
                continue;
            }

            // Skip `::class` and anonymous classes.
            $previous = $this->previousMeaningful($i - 1);

            if ($previous !== null && ($this->text($previous) === '::' || $this->isToken($previous, T_NEW))) {
                continue;
            }

            $nameIndex = $this->nextMeaningful($i + 1);

            if ($nameIndex === null || ! $this->isToken($nameIndex, T_STRING)) {
                continue;
            }

            $class = $this->text($nameIndex);

            return $namespace === '' ? $class : $namespace.'\\'.$class;
        }

        return null;
    }

    /**
     * Source of the given method's body, braces excluded. Null when the method is
     * absent, abstract, or interface-declared.
     *
     * Only same-name methods at any nesting level are considered; the first match
     * wins, which is correct for a class file and harmless for a file with one
     * class in it (the shape Anvil generates).
     */
    public function methodBody(string $method): ?string
    {
        $count = count($this->tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! $this->isToken($i, T_FUNCTION)) {
                continue;
            }

            $nameIndex = $this->nextMeaningful($i + 1);

            if ($nameIndex === null || ! $this->isToken($nameIndex, T_STRING)) {
                continue;
            }

            if (strcasecmp($this->text($nameIndex), $method) !== 0) {
                continue;
            }

            $brace = $this->findOpeningBrace($nameIndex);

            return $brace === null ? null : $this->braceContents($brace);
        }

        return null;
    }

    /**
     * The source of the array literal returned by the given method, inner content
     * only (no surrounding brackets). Null when the method does not return an
     * array literal directly -- e.g. `return $this->payload();`, which is a real
     * pattern the caller must treat as unreadable rather than empty.
     */
    public function returnedArrayLiteral(string $method): ?string
    {
        $body = $this->methodBody($method);

        if ($body === null) {
            return null;
        }

        return self::fromSource('<?php '.$body)->firstReturnedArray();
    }

    /**
     * True when the method body contains a construct that can inject properties
     * this class cannot see. Drives CodeShape::$partial, which forbids pruning.
     *
     * @return list<string> the opaque constructs found, for the drift report
     */
    public function opaqueConstructs(string $method): array
    {
        $body = $this->methodBody($method);

        if ($body === null) {
            return [];
        }

        $found = [];

        foreach (self::OPAQUE as $needle) {
            if (stripos($body, $needle.'(') !== false || stripos($body, $needle.' (') !== false) {
                $found[] = $needle;
            }
        }

        // A spread inside an array literal is equally opaque. Detected by token,
        // not by regex: a spread may sit anywhere (`[...$a]`, `, ...$a`, or alone
        // on its own line). T_ELLIPSIS cannot mean anything else inside a method
        // body, since variadic declarations live in the signature we already cut.
        foreach (token_get_all('<?php '.$body) as $token) {
            if (is_array($token) && $token[0] === T_ELLIPSIS) {
                $found[] = '...spread';

                break;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Split an array literal's inner source into key => raw-value-source pairs.
     *
     * Non-literal keys (`self::FOO => ...`) and positional entries are returned
     * under a synthetic `#n` key so the caller can count them and mark the read
     * partial without pretending to know their name.
     *
     * @return array<string, string>
     */
    public static function splitArrayLiteral(string $inner): array
    {
        $pairs = [];
        $position = 0;

        foreach (self::splitTopLevel($inner, ',') as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            $halves = self::splitTopLevel($entry, '=>', limit: 2);

            if (count($halves) < 2) {
                $pairs['#'.$position++] = $entry;

                continue;
            }

            $key = self::literalKey(trim($halves[0]));

            if ($key === null) {
                $pairs['#'.$position++] = trim($halves[1]);

                continue;
            }

            $pairs[$key] = trim($halves[1]);
        }

        return $pairs;
    }

    /**
     * Split on a delimiter appearing at bracket depth zero and outside strings.
     * Used for both `,` between entries and `=>` within one entry, so it has to
     * be robust against nested arrays, calls, closures, and `?:` in values.
     *
     * @return list<string>
     */
    public static function splitTopLevel(string $source, string $delimiter, int $limit = PHP_INT_MAX): array
    {
        $tokens = @token_get_all('<?php ['.$source.'];');

        if ($tokens === false) {
            return [$source];
        }

        $parts = [];
        $buffer = '';
        $depth = 0;
        $started = false;

        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;

            // Skip the synthetic wrapper we added to make the fragment parseable.
            if (! $started) {
                if ($text === '[') {
                    $started = true;
                }

                continue;
            }

            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    break; // the wrapper's closing bracket
                }

                $depth--;
            }

            // Compare on token TEXT, not on token type or single-character-ness.
            // PHP lexes `=>` as T_DOUBLE_ARROW and `??` as T_COALESCE, so a check
            // that only accepted string tokens would silently never split on them.
            // Quoted strings are single tokens whose text still carries the quotes,
            // so a delimiter inside a string literal cannot match here.
            $isDelimiter = $depth === 0
                && count($parts) + 1 < $limit
                && $text === $delimiter;

            if ($isDelimiter) {
                $parts[] = $buffer;
                $buffer = '';

                continue;
            }

            $buffer .= $text;
        }

        $parts[] = $buffer;

        return $parts;
    }

    /**
     * Unwrap a quoted string key. Returns null for anything non-literal, because
     * a guessed property name is worse than an acknowledged unknown.
     */
    public static function literalKey(string $key): ?string
    {
        if (preg_match("/^'((?:[^'\\\\]|\\\\.)*)'$/", $key, $m) === 1) {
            return stripcslashes($m[1]);
        }

        if (preg_match('/^"([^"$\\\\]*)"$/', $key, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function firstReturnedArray(): ?string
    {
        $count = count($this->tokens);
        $depth = 0;

        for ($i = 0; $i < $count; $i++) {
            $text = $this->text($i);

            if (in_array($text, ['{'], true)) {
                $depth++;
            } elseif (in_array($text, ['}'], true)) {
                $depth--;
            }

            if ($depth !== 0 || ! $this->isToken($i, T_RETURN)) {
                continue;
            }

            $next = $this->nextMeaningful($i + 1);

            if ($next === null) {
                return null;
            }

            if ($this->text($next) === '[') {
                return $this->bracketContents($next, '[', ']');
            }

            if ($this->isToken($next, T_ARRAY)) {
                $paren = $this->nextMeaningful($next + 1);

                if ($paren !== null && $this->text($paren) === '(') {
                    return $this->bracketContents($paren, '(', ')');
                }
            }

            return null; // returns something that is not an array literal
        }

        return null;
    }

    private function findOpeningBrace(int $from): ?int
    {
        $count = count($this->tokens);

        for ($i = $from; $i < $count; $i++) {
            $text = $this->text($i);

            if ($text === '{') {
                return $i;
            }

            if ($text === ';') {
                return null; // abstract or interface method
            }
        }

        return null;
    }

    private function braceContents(int $open): string
    {
        return $this->bracketContents($open, '{', '}');
    }

    private function bracketContents(int $open, string $openChar, string $closeChar): string
    {
        $count = count($this->tokens);
        $depth = 0;
        $buffer = '';

        for ($i = $open; $i < $count; $i++) {
            $text = $this->text($i);

            if ($text === $openChar) {
                $depth++;

                if ($depth === 1) {
                    continue;
                }
            } elseif ($text === $closeChar) {
                $depth--;

                if ($depth === 0) {
                    return $buffer;
                }
            }

            // Curly-brace bodies contain nested [] and () that must not affect depth,
            // and vice versa -- tracked implicitly because we only count our own pair.
            $buffer .= $text;
        }

        return $buffer;
    }

    private function nextMeaningful(int $from): ?int
    {
        $count = count($this->tokens);

        for ($i = $from; $i < $count; $i++) {
            if ($this->isToken($i, T_WHITESPACE) || $this->isToken($i, T_COMMENT) || $this->isToken($i, T_DOC_COMMENT)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    private function previousMeaningful(int $from): ?int
    {
        for ($i = $from; $i >= 0; $i--) {
            if ($this->isToken($i, T_WHITESPACE) || $this->isToken($i, T_COMMENT) || $this->isToken($i, T_DOC_COMMENT)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    private function isToken(int $index, int $type): bool
    {
        $token = $this->tokens[$index] ?? null;

        return is_array($token) && $token[0] === $type;
    }

    private function text(int $index): string
    {
        $token = $this->tokens[$index] ?? '';

        return is_array($token) ? $token[1] : $token;
    }
}
