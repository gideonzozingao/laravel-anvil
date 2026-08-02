#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Resolve every `$this->method()` call against the methods that actually exist.
 *
 * This is the gap between the other two checks. A misspelled self-call is valid
 * PHP, so `php -l` passes; the file is in the right place, so the placement check
 * passes; and it only fails when the line executes:
 *
 *     BadMethodCallException
 *     Method ...\GenerateWebCommand::runPipelin does not exist.
 *       src/Console/GenerateWebCommand.php:153
 *
 * One character, found by a browser instead of by CI.
 *
 * Resolution walks the class's own methods, every trait it uses (recursively,
 * through the file's `use` imports), and its parents. Parents outside the scanned
 * set are read by reflection when an autoloader is available, and fall back to a
 * built-in list for Illuminate\Console\Command otherwise.
 *
 * ON FALSE POSITIVES
 *
 * Illuminate\Console\Command is Macroable, so a name that resolves to nothing may
 * legitimately be a runtime macro. Reporting every unresolved call would therefore
 * cry wolf. Instead:
 *
 *   • an unresolved call that is within edit distance 2 of a real method is an
 *     ERROR — that is a typo, and it is what this tool is for
 *   • any other unresolved call is a NOTICE, listed but not failing the build
 *
 * `runPipelin` is distance 1 from `runPipeline`, so it lands in the first bucket.
 *
 * Usage:
 *   php bin/verify-self-calls.php [srcRoot] [baseNamespace]
 *   php bin/verify-self-calls.php src "Zuqongtech\LaravelAnvil"
 *   php bin/verify-self-calls.php src "Zuqongtech\LaravelAnvil" --strict   # notices fail too
 *
 * Exit 0 when no typo is found, 1 otherwise.
 */
$positional = array_values(array_filter(
    array_slice($argv, 1),
    static fn (string $a): bool => ! str_starts_with($a, '--'),
));

$strict = in_array('--strict', $argv, true);
$root = rtrim($positional[0] ?? 'src', DIRECTORY_SEPARATOR);
$baseNamespace = trim($positional[1] ?? 'Zuqongtech\\LaravelAnvil', '\\');

if (! is_dir($root)) {
    fwrite(STDERR, "Not a directory: {$root}\n");
    exit(1);
}

// An autoloader lets parents and framework traits be read by reflection. Absent
// one, the fallback list below covers the base most commands extend.
foreach ([dirname($root).'/vendor/autoload.php', getcwd().'/vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}

/**
 * Methods available on Illuminate\Console\Command when reflection is unavailable.
 *
 * @var list<string>
 */
const COMMAND_FALLBACK = [
    'ask',
    'anticipate',
    'choice',
    'confirm',
    'secret',
    'components',
    'info',
    'line',
    'comment',
    'question',
    'error',
    'warn',
    'alert',
    'newLine',
    'table',
    'withProgressBar',
    'createProgressBar',
    'output',
    'outputComponents',
    'argument',
    'arguments',
    'hasArgument',
    'option',
    'options',
    'hasOption',
    'call',
    'callSilent',
    'callSilently',
    'getDefinition',
    'getName',
    'setName',
    'getDescription',
    'setDescription',
    'getHelp',
    'setHelp',
    'getApplication',
    'handle',
    'run',
    'execute',
    'configure',
    'isHidden',
    'setHidden',
    'getLaravel',
    'setLaravel',
    'schedule',
    'fail',
    'trap',
    'resolveCommand',
];

/**
 * @return array{
 *     namespace: string,
 *     imports: array<string, string>,
 *     types: list<array{
 *         kind: string, name: string, line: int, parent: ?string,
 *         traits: list<string>, methods: array<string, int>,
 *         calls: list<array{name: string, line: int}>,
 *         dynamic: bool
 *     }>
 * }
 */
function analyse(string $path): array
{
    $source = (string) file_get_contents($path);
    $tokens = @token_get_all($source);
    $count = count($tokens);

    $namespace = '';
    $imports = [];
    $types = [];
    $current = null;
    $depth = 0;
    $typeDepth = null;
    $lastDoc = '';

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (! is_array($token)) {
            if ($token === '{') {
                $depth++;
            } elseif ($token === '}') {
                $depth--;

                if ($typeDepth !== null && $depth < $typeDepth) {
                    $types[] = $current;
                    $current = null;
                    $typeDepth = null;
                }
            }

            continue;
        }

        [$id, $text, $line] = [$token[0], $token[1], $token[2]];

        if ($id === T_DOC_COMMENT) {
            $lastDoc = $text;

            continue;
        }

        if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;

            continue;
        }

        // namespace Foo\Bar;
        if ($id === T_NAMESPACE && $namespace === '' && $current === null) {
            $namespace = readName($tokens, $i, $count);

            continue;
        }

        // use Foo\Bar; / use Foo\Bar as Baz;   (only outside a class body)
        if ($id === T_USE && $current === null) {
            $name = readName($tokens, $i, $count);

            if ($name !== '') {
                $short = str_contains($name, ' as ')
                    ? trim(substr($name, (int) strrpos($name, ' as ') + 4))
                    : substr($name, (int) strrpos($name, '\\') + 1);
                $fqn = str_contains($name, ' as ')
                    ? trim(substr($name, 0, (int) strrpos($name, ' as ')))
                    : $name;
                $imports[$short] = trim($fqn, '\\');
            }

            continue;
        }

        // use SomeTrait;  (inside a class body)
        if ($id === T_USE && $current !== null) {
            foreach (explode(',', readName($tokens, $i, $count)) as $trait) {
                $trait = trim($trait);

                if ($trait !== '') {
                    $current['traits'][] = $trait;
                }
            }

            continue;
        }

        if (in_array($id, [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true) && $current === null) {
            $prev = neighbour($tokens, $i, -1);

            if (is_array($prev) && in_array($prev[0], [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }

            $next = neighbour($tokens, $i, 1);

            if (! is_array($next) || $next[0] !== T_STRING) {
                continue;
            }

            preg_match_all('/@mixin\s+([\\\\\w]+)/', $lastDoc, $mixinMatches);

            $current = [
                'kind' => strtolower($text),
                'name' => $next[1],
                'line' => $line,
                'parent' => null,
                'traits' => [],
                'mixins' => $mixinMatches[1] ?? [],
                'methods' => [],
                'calls' => [],
                'dynamic' => false,
            ];
            $typeDepth = $depth + 1;
            $lastDoc = '';

            continue;
        }

        if ($current === null) {
            continue;
        }

        if ($id === T_EXTENDS) {
            $current['parent'] = readName($tokens, $i, $count);

            continue;
        }

        if ($id === T_FUNCTION) {
            $next = neighbour($tokens, $i, 1);

            if (is_array($next) && $next[0] === T_STRING) {
                $current['methods'][$next[1]] = $line;

                // __call / __get make any name potentially valid.
                if (in_array($next[1], ['__call', '__callStatic', '__get'], true)) {
                    $current['dynamic'] = true;
                }
            }

            continue;
        }

        // $this->method(
        if ($id === T_VARIABLE && $text === '$this') {
            $arrow = neighbour($tokens, $i, 1);

            if (! is_array($arrow) || $arrow[0] !== T_OBJECT_OPERATOR) {
                continue;
            }

            $name = neighbour($tokens, $i, 2);

            if (! is_array($name) || $name[0] !== T_STRING) {
                continue;   // $this->{$dynamic}() or $this->property
            }

            $paren = neighbour($tokens, $i, 3);

            if ($paren === '(') {
                $current['calls'][] = ['name' => $name[1], 'line' => $line];
            }
        }
    }

    if ($current !== null) {
        $types[] = $current;
    }

    return ['namespace' => $namespace, 'imports' => $imports, 'types' => $types];
}

/**
 * Read the name following a keyword, up to `;`, `{` or `(`.
 *
 * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
 */
function readName(array $tokens, int $from, int $count): string
{
    $name = '';

    for ($i = $from + 1; $i < $count; $i++) {
        $token = $tokens[$i];

        if ($token === ';' || $token === '{' || $token === '(') {
            break;
        }

        if (! is_array($token)) {
            if ($token === ',') {
                $name .= ',';
            }

            continue;
        }

        if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        if ($token[0] === T_WHITESPACE) {
            $name .= ' ';

            continue;
        }

        if ($token[0] === T_IMPLEMENTS) {
            break;
        }

        $name .= $token[1];
    }

    return trim(preg_replace('/\s+/', ' ', $name) ?? '');
}

/**
 * The nth significant token either side of $index.
 *
 * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
 * @return array{0: int, 1: string, 2: int}|string|null
 */
function neighbour(array $tokens, int $index, int $offset)
{
    $step = $offset < 0 ? -1 : 1;
    $remaining = abs($offset);
    $i = $index;

    while ($remaining > 0) {
        $i += $step;

        if (! isset($tokens[$i])) {
            return null;
        }

        if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $remaining--;
    }

    return $tokens[$i] ?? null;
}

/**
 * @param  array<string, string>  $imports
 */
function qualify(string $name, string $namespace, array $imports): string
{
    $name = trim($name, '\\');

    if ($name === '') {
        return '';
    }

    $head = explode('\\', $name)[0];

    if (isset($imports[$head])) {
        return count(explode('\\', $name)) === 1
            ? $imports[$head]
            : $imports[$head].'\\'.substr($name, strlen($head) + 1);
    }

    return $namespace === '' ? $name : $namespace.'\\'.$name;
}

// ---------------------------------------------------------------------------
// Index
// ---------------------------------------------------------------------------

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY,
);

/** @var array<string, array<string, mixed>> $index fqcn => info */
$index = [];

foreach ($iterator as $file) {
    if (! $file instanceof SplFileInfo || strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    $analysis = analyse($file->getPathname());

    foreach ($analysis['types'] as $type) {
        $fqcn = ($analysis['namespace'] === '' ? '' : $analysis['namespace'].'\\').$type['name'];

        $index[$fqcn] = $type + [
            'file' => ltrim(substr($file->getPathname(), strlen($root)), DIRECTORY_SEPARATOR),
            'namespace' => $analysis['namespace'],
            'imports' => $analysis['imports'],
        ];
    }
}

/**
 * Every method name reachable on $fqcn, following traits and parents.
 *
 * @return array{names: array<string, true>, dynamic: bool}
 */
function reachable(string $fqcn, array $index, array $seen = []): array
{
    if ($fqcn === '' || isset($seen[$fqcn])) {
        return ['names' => [], 'dynamic' => false];
    }

    $seen[$fqcn] = true;

    // Outside the scanned tree: reflection if we can, fallback list otherwise.
    if (! isset($index[$fqcn])) {
        if (class_exists($fqcn) || trait_exists($fqcn) || interface_exists($fqcn)) {
            $names = [];

            foreach ((new ReflectionClass($fqcn))->getMethods() as $method) {
                $names[$method->getName()] = true;
            }

            return [
                'names' => $names,
                'dynamic' => isset($names['__call']) || isset($names['__get']),
            ];
        }

        if ($fqcn === 'Illuminate\\Console\\Command') {
            return ['names' => array_fill_keys(COMMAND_FALLBACK, true), 'dynamic' => true];
        }

        // Unknown ancestor: treat as dynamic so we do not accuse the file of
        // calling something that may well exist out of sight.
        return ['names' => [], 'dynamic' => true];
    }

    $info = $index[$fqcn];
    $names = array_fill_keys(array_keys($info['methods']), true);
    $dynamic = (bool) $info['dynamic'];

    foreach ($info['traits'] as $trait) {
        $resolved = reachable(qualify($trait, $info['namespace'], $info['imports']), $index, $seen);
        $names += $resolved['names'];
        $dynamic = $dynamic || $resolved['dynamic'];
    }

    // A trait has no parent, so "@mixin Command" is how it declares the API it
    // expects the consuming class to bring. Both pipeline traits document it.
    foreach ($info['mixins'] ?? [] as $mixin) {
        $resolved = reachable(qualify($mixin, $info['namespace'], $info['imports']), $index, $seen);
        $names += $resolved['names'];
        $dynamic = $dynamic || $resolved['dynamic'];
    }

    if (is_string($info['parent']) && $info['parent'] !== '') {
        $resolved = reachable(qualify($info['parent'], $info['namespace'], $info['imports']), $index, $seen);
        $names += $resolved['names'];
        $dynamic = $dynamic || $resolved['dynamic'];
    }

    return ['names' => $names, 'dynamic' => $dynamic];
}

/**
 * Every type that uses $fqcn, directly or through another trait.
 *
 * One level is not enough: ResolvesGeneratedModels is used by
 * RunsGenerationPipeline, which is used by the commands. Stopping at the first
 * level leaves the trait's info()/line() calls looking unresolved, because a trait
 * has no parent to inherit them from.
 *
 * @param  array<string, array<string, mixed>>  $index
 * @return list<string>
 */
function consumersOf(string $fqcn, array $index): array
{
    $found = [];
    $frontier = [$fqcn];

    while ($frontier !== []) {
        $target = array_shift($frontier);

        foreach ($index as $candidate => $info) {
            if (isset($found[$candidate])) {
                continue;
            }

            foreach ($info['traits'] as $trait) {
                if (qualify($trait, $info['namespace'], $info['imports']) !== $target) {
                    continue;
                }

                $found[$candidate] = true;
                $frontier[] = $candidate;
                break;
            }
        }
    }

    return array_keys($found);
}

/**
 * Closest known method name within edit distance 2, or null.
 *
 * @param  array<string, true>  $known
 */
function nearest(string $name, array $known): ?string
{
    $best = null;
    $bestDistance = 3;

    foreach (array_keys($known) as $candidate) {
        if (abs(strlen($candidate) - strlen($name)) > 2) {
            continue;
        }

        $distance = levenshtein($name, $candidate);

        if ($distance > 0 && $distance < $bestDistance) {
            $best = $candidate;
            $bestDistance = $distance;
        }
    }

    return $best;
}

// ---------------------------------------------------------------------------
// Resolve
// ---------------------------------------------------------------------------

$typos = [];
$notices = [];
$checked = 0;

foreach ($index as $fqcn => $info) {
    if ($info['calls'] === []) {
        continue;
    }

    // A trait's $this-> calls are resolved against the classes that use it, not
    // against the trait itself — a trait may legitimately call a method the
    // consuming class provides. Skip traits unless nothing uses them.
    $resolved = reachable($fqcn, $index);
    $known = $resolved['names'];

    if ($info['kind'] === 'trait') {
        foreach (consumersOf($fqcn, $index) as $consumer) {
            $known += reachable($consumer, $index)['names'];
        }
    }

    foreach ($info['calls'] as $call) {
        $checked++;

        if (isset($known[$call['name']])) {
            continue;
        }

        $suggestion = nearest($call['name'], $known);

        $entry = [
            'file' => $info['file'],
            'line' => $call['line'],
            'class' => $info['name'],
            'call' => $call['name'],
            'suggestion' => $suggestion,
        ];

        // A near-miss is a typo whether or not the class is Macroable — a macro
        // one character away from a real method is not a thing anyone writes.
        if ($suggestion !== null) {
            $typos[] = $entry;
        } elseif (! $resolved['dynamic']) {
            $notices[] = $entry;
        }
    }
}

printf("Resolved %d \$this-> call(s) across %d type(s) under %s/\n", $checked, count($index), $root);

if ($typos === [] && $notices === []) {
    echo "\n✅ Every self-call resolves.\n";
    exit(0);
}

$status = 0;

if ($typos !== []) {
    printf("\n❌ %d likely typo(s):\n\n", count($typos));

    foreach ($typos as $typo) {
        printf(
            "   %s:%d\n      %s::%s() does not exist — did you mean %s()?\n\n",
            $typo['file'],
            $typo['line'],
            $typo['class'],
            $typo['call'],
            $typo['suggestion'],
        );
    }

    $status = 1;
}

if ($notices !== []) {
    printf("\n⚠️  %d unresolved call(s) with no close match:\n\n", count($notices));

    foreach ($notices as $notice) {
        printf("   %s:%d  %s::%s()\n", $notice['file'], $notice['line'], $notice['class'], $notice['call']);
    }

    echo "\n   These may be legitimate — a macro, or a method on an ancestor this tool\n";
    echo "   could not load. Run with an autoloader present to narrow them down.\n";

    if ($strict) {
        $status = 1;
    }
}

exit($status);
