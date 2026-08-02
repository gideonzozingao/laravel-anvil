#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Verify — and optionally repair — that every PHP file under a source root declares
 * what its path promises.
 *
 * A file saved to the wrong path is invisible to `php -l`: the syntax is perfect,
 * so linting passes, and the failure only appears when Composer's autoloader loads
 * a file that declares something else entirely.
 *
 *   Cannot redeclare trait ...\Concerns\RunsGenerationPipeline
 *     at src/Console/GenerateModelsFromDatabase.php:43
 *
 *   Cannot redeclare class ...\Console\GenerateModelsFromDatabase
 *     at src/Console/GenerateAuthCommand.php:50
 *
 * Both of those are one mistake: content landed under the wrong filename. PSR-4
 * requires the namespace to mirror the directory and the declared type name to
 * match the filename, so the correct path is always computable from a file's own
 * contents — which is what --fix does.
 *
 * This script deliberately depends on nothing but PHP itself. It runs when
 * `php artisan` cannot boot, which is exactly when it is needed.
 *
 * Usage:
 *   php bin/verify-file-placement.php [srcRoot] [baseNamespace] [--fix]
 *   php bin/verify-file-placement.php src "Zuqongtech\LaravelAnvil"
 *   php bin/verify-file-placement.php src "Zuqongtech\LaravelAnvil" --fix
 *
 * Exit 0 when everything lines up, 1 otherwise.
 */
$positional = array_values(array_filter(
    array_slice($argv, 1),
    static fn (string $a): bool => ! str_starts_with($a, '--'),
));

$fix = in_array('--fix', $argv, true);
$root = rtrim($positional[0] ?? 'src', DIRECTORY_SEPARATOR);
$baseNamespace = trim($positional[1] ?? 'Zuqongtech\\LaravelAnvil', '\\');

if (! is_dir($root)) {
    fwrite(STDERR, "Not a directory: {$root}\n");
    exit(1);
}

/**
 * Extract the namespace and every top-level type declaration from a file.
 *
 * Token-based rather than reflection: the point is to inspect files that may not
 * be loadable, and loading them would execute code.
 *
 * @return array{namespace: string, types: list<array{kind: string, name: string, line: int}>}
 */
function inspect(string $path): array
{
    $tokens = @token_get_all((string) file_get_contents($path));
    $namespace = '';
    $types = [];
    $depth = 0;
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (! is_array($token)) {
            if ($token === '{') {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
            }

            continue;
        }

        [$id, $text, $line] = [$token[0], $token[1], $token[2]];

        if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;

            continue;
        }

        if ($id === T_NAMESPACE && $namespace === '') {
            $name = '';

            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j] === ';' || $tokens[$j] === '{') {
                    break;
                }

                if (is_array($tokens[$j]) && ! in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $name .= $tokens[$j][1];
                }
            }

            $namespace = trim($name, '\\');

            continue;
        }

        if (! in_array($id, [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true)) {
            continue;
        }

        // Only top-level declarations count. Nested/anonymous classes and
        // `Foo::class` constants are not type declarations for PSR-4 purposes.
        if ($depth > 0) {
            continue;
        }

        $prev = null;
        for ($j = $i - 1; $j >= 0; $j--) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $prev = $tokens[$j];
            break;
        }

        if (is_array($prev) && in_array($prev[0], [T_DOUBLE_COLON, T_NEW], true)) {
            continue;
        }

        $next = null;
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $next = $tokens[$j];
            break;
        }

        if (! is_array($next) || $next[0] !== T_STRING) {
            continue;
        }

        $types[] = ['kind' => strtolower($text), 'name' => $next[1], 'line' => $line];
    }

    return ['namespace' => $namespace, 'types' => $types];
}

/**
 * @return list<string>
 */
function phpFiles(string $root): array
{
    $found = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY,
    );

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && strtolower($file->getExtension()) === 'php') {
            $found[] = $file->getPathname();
        }
    }

    sort($found);

    return $found;
}

function relative(string $path, string $root): string
{
    return ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);
}

// ---------------------------------------------------------------------------
// Inspect every file once
// ---------------------------------------------------------------------------

$files = [];

foreach (phpFiles($root) as $path) {
    ['namespace' => $namespace, 'types' => $types] = inspect($path);

    $relativeDir = trim(str_replace(DIRECTORY_SEPARATOR, '\\', dirname(relative($path, $root))), '.\\');

    $files[$path] = [
        'relative' => relative($path, $root),
        'namespace' => $namespace,
        'types' => $types,
        'expected_namespace' => $relativeDir === '' ? $baseNamespace : $baseNamespace.'\\'.$relativeDir,
        'expected_name' => basename($path, '.php'),
        'hash' => (string) md5_file($path),
    ];
}

echo 'Checked '.count($files)." file(s) under {$root}/ against base namespace {$baseNamespace}\n";

$problems = [];

// ---------------------------------------------------------------------------
// Plan: group by declared FQN, then decide which file is canonical
// ---------------------------------------------------------------------------

/** @var array<string, list<string>> fqn => paths */
$byFqn = [];

foreach ($files as $path => $info) {
    if ($info['namespace'] !== $info['expected_namespace']) {
        $problems[] = [
            'file' => $info['relative'],
            'detail' => "namespace is [{$info['namespace']}], path requires [{$info['expected_namespace']}]",
        ];
    }

    if ($info['types'] === []) {
        continue;
    }

    if (count($info['types']) > 1) {
        $names = implode(', ', array_map(static fn (array $t): string => $t['kind'].' '.$t['name'], $info['types']));
        $problems[] = [
            'file' => $info['relative'],
            'detail' => 'declares '.count($info['types'])." top-level types ({$names}); PSR-4 allows one per file",
        ];

        continue;
    }

    $type = $info['types'][0];

    if ($type['name'] !== $info['expected_name']) {
        $problems[] = [
            'file' => $info['relative'],
            'detail' => "declares {$type['kind']} {$type['name']} on line {$type['line']}, "
                ."but the filename requires {$info['expected_name']}",
        ];
    }

    $inPackage = $info['namespace'] === $baseNamespace
        || str_starts_with($info['namespace'].'\\', $baseNamespace.'\\');

    if (! $inPackage) {
        continue;
    }

    // The correct path is computable from the file's own contents: the namespace
    // gives the directory, the declared type gives the filename.
    $tail = trim(substr($info['namespace'], strlen($baseNamespace)), '\\');
    $dir = $tail === '' ? $root : $root.DIRECTORY_SEPARATOR.str_replace('\\', DIRECTORY_SEPARATOR, $tail);

    $files[$path]['fqn'] = ($info['namespace'] === '' ? '' : $info['namespace'].'\\').$type['name'];
    $files[$path]['target'] = $dir.DIRECTORY_SEPARATOR.$type['name'].'.php';
    $files[$path]['line'] = $type['line'];
    $files[$path]['kind'] = $type['kind'];

    $byFqn[$files[$path]['fqn']][] = $path;
}

$moves = [];
$duplicates = [];

foreach ($byFqn as $fqn => $paths) {
    // Canonical is the file already sitting where the type belongs — NOT simply the
    // first one encountered. Picking by iteration order names the wrong file as the
    // stray, which is worse than not reporting at all.
    $canonical = null;

    foreach ($paths as $candidate) {
        if ($files[$candidate]['target'] === $candidate) {
            $canonical = $candidate;
            break;
        }
    }

    if (count($paths) === 1) {
        $only = $paths[0];

        if ($canonical === null) {
            $moves[$only] = $files[$only]['target'];
        }

        continue;
    }

    // More than one file declares this type: the "Cannot redeclare" fatal.
    $canonical ??= $paths[0];

    foreach ($paths as $stray) {
        if ($stray === $canonical) {
            continue;
        }

        $problems[] = [
            'file' => $files[$stray]['relative'],
            'detail' => "{$files[$stray]['kind']} {$fqn} is also declared in "
                ."{$files[$canonical]['relative']}:{$files[$canonical]['line']}"
                .' — this is the "Cannot redeclare" fatal',
        ];

        // Whatever the stray's own filename should have contained is not on disk
        // anywhere, so moving cannot recover it. Say that rather than pretend.
        $duplicates[] = [
            'file' => $files[$stray]['relative'],
            'duplicate_of' => $files[$canonical]['relative'],
            'identical' => $files[$canonical]['hash'] === $files[$stray]['hash'],
            'missing' => $files[$stray]['expected_name'],
        ];
    }

    if ($files[$canonical]['target'] !== $canonical) {
        $moves[$canonical] = $files[$canonical]['target'];
    }
}

// A move is only safe if its destination is free, or is occupied by a file that is
// itself moving away — that second case is the A-holds-B, B-holds-A swap.
$conflicts = [];

foreach ($moves as $from => $to) {
    if (! file_exists($to) || isset($moves[$to])) {
        continue;
    }

    $conflicts[] = [
        'file' => relative($from, $root),
        'target' => relative($to, $root),
    ];

    unset($moves[$from]);
}

if ($problems === []) {
    echo "\n✅ Every file declares what its path promises.\n";
    exit(0);
}

echo "\n❌ ".count($problems)." placement problem(s):\n\n";

foreach ($problems as $problem) {
    echo "   {$problem['file']}\n      {$problem['detail']}\n\n";
}

// ---------------------------------------------------------------------------
// Repair
// ---------------------------------------------------------------------------

if ($moves !== []) {
    echo $fix ? "Repairing:\n\n" : "Repairable by moving — re-run with --fix:\n\n";

    foreach ($moves as $from => $to) {
        printf("   %s\n     → %s\n", relative($from, $root), relative($to, $root));
    }

    echo "\n";
}

if ($fix && $moves !== []) {
    // A swap — A holds B's class while B holds A's — cannot be done one rename at
    // a time without clobbering, so stage every file under a temp name first.
    $staged = [];

    foreach ($moves as $from => $to) {
        $temp = $from.'.placement-tmp-'.bin2hex(random_bytes(4));

        if (! @rename($from, $temp)) {
            fwrite(STDERR, "   could not stage {$from}\n");

            foreach ($staged as $stagedTemp => $original) {
                @rename($stagedTemp, $original);
            }

            fwrite(STDERR, "Nothing was moved.\n");
            exit(1);
        }

        $staged[$temp] = ['from' => $from, 'to' => $to];
    }

    foreach ($staged as $temp => $move) {
        $dir = dirname($move['to']);

        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true)) {
            fwrite(STDERR, "   could not create {$dir}\n");
            exit(1);
        }

        if (file_exists($move['to'])) {
            fwrite(STDERR, '   refusing to overwrite '.relative($move['to'], $root)."\n");
            exit(1);
        }

        if (! @rename($temp, $move['to'])) {
            fwrite(STDERR, '   could not move into place: '.relative($move['to'], $root)."\n");
            exit(1);
        }

        echo '   moved → '.relative($move['to'], $root)."\n";
    }

    echo "\nNow run: composer dump-autoload\n";
    echo "Then re-run this script to confirm.\n";
}

if ($conflicts !== []) {
    echo "\n⚠️  ".count($conflicts)." move(s) blocked by an existing file:\n\n";

    foreach ($conflicts as $conflict) {
        printf("   %s belongs at %s, which already holds a different file.\n", $conflict['file'], $conflict['target']);
    }

    echo "\n";
}

if ($duplicates !== []) {
    echo "\n⚠️  ".count($duplicates)." file(s) cannot be repaired by moving:\n\n";

    foreach ($duplicates as $item) {
        printf(
            "   %s\n      %s %s.\n      Nothing on disk declares what %s.php should contain — re-copy that one file.\n\n",
            $item['file'],
            $item['identical'] ? 'is byte-for-byte a copy of' : 'declares the same type as',
            $item['duplicate_of'],
            $item['missing'],
        );
    }
}

if (! $fix) {
    echo "A misplaced file passes `php -l` cleanly — the syntax is valid, it is simply\n";
    echo "not the file the autoloader expects at that path.\n";
}

exit(1);
