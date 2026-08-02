#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Lint generated PHP and report every file that cannot compile.
 *
 * A generated class with a compile-time fault only surfaces when something loads
 * it, which for a Livewire component means opening the route:
 *
 *     Default value for property of type int may not be null.
 *     Use the nullable type ?int to allow null default value
 *       app/Livewire/Vehicles/Form.php:15
 *
 * Thirty-two resources means thirty-two routes to click before you know the scale
 * of it. This finds them all in one pass, and groups by fault so a systematic
 * generator bug reads as one problem rather than sixty-four.
 *
 * Depends on nothing but PHP. Run it against a generated application, not the
 * package.
 *
 * Usage:
 *   php bin/verify-generated-php.php [path ...]
 *   php bin/verify-generated-php.php app/Livewire app/Http
 *   php bin/verify-generated-php.php app          # everything
 *
 * Exit 0 when everything compiles, 1 otherwise.
 */
$paths = array_values(array_filter(
    array_slice($argv, 1),
    static fn (string $a): bool => ! str_starts_with($a, '--'),
));

if ($paths === []) {
    $paths = array_values(array_filter(['app', 'database/factories', 'database/seeders'], 'is_dir'));
}

if ($paths === []) {
    fwrite(STDERR, "Nothing to check. Pass a directory, e.g. app/Livewire\n");
    exit(1);
}

/**
 * @return list<string>
 */
function collect(array $paths): array
{
    $files = [];

    foreach ($paths as $path) {
        if (is_file($path)) {
            $files[] = $path;

            continue;
        }

        if (! is_dir($path)) {
            fwrite(STDERR, "Skipping missing path: {$path}\n");

            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (
                $file instanceof SplFileInfo
                && strtolower($file->getExtension()) === 'php'
                && ! str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)
            ) {
                $files[] = $file->getPathname();
            }
        }
    }

    $files = array_values(array_unique($files));
    sort($files);

    return $files;
}

/**
 * @return array{0: bool, 1: string, 2: ?int} [ok, message, line]
 */
function lint(string $path): array
{
    // -n skips php.ini: whether a file compiles has nothing to do with the app's
    // extensions, and skipping it keeps a large sweep fast.
    $command = escapeshellarg(PHP_BINARY).' -n -l '.escapeshellarg($path).' 2>&1';

    $output = [];
    $status = 0;
    @exec($command, $output, $status);

    if ($status === 0) {
        return [true, '', null];
    }

    $lines = array_values(array_filter(array_map('trim', $output), static fn (string $l): bool => $l !== '' && ! str_starts_with($l, 'Errors parsing')));
    $raw = implode(' ', array_unique($lines));

    $line = null;

    if (preg_match('/ on line (\d+)/', $raw, $m) === 1) {
        $line = (int) $m[1];
    }

    // Normalise so the same fault in sixty files groups into one entry.
    $message = preg_replace('/ in .*? on line \d+/', '', $raw) ?? $raw;
    $message = str_replace(['PHP Fatal error:  ', 'Fatal error: ', 'PHP Parse error:  ', 'Parse error: '], '', $message);

    return [false, trim($message), $line];
}

$files = collect($paths);

if ($files === []) {
    echo 'No PHP files found under: '.implode(', ', $paths)."\n";
    exit(0);
}

echo 'Linting '.count($files).' file(s) under '.implode(', ', $paths)."\n";

$broken = [];

foreach ($files as $path) {
    [$ok, $message, $line] = lint($path);

    if ($ok) {
        continue;
    }

    $broken[$message][] = ['file' => $path, 'line' => $line];
}

if ($broken === []) {
    echo "\n✅ All ".count($files)." file(s) compile.\n";
    exit(0);
}

$total = array_sum(array_map('count', $broken));

echo "\n❌ {$total} file(s) do not compile, ".count($broken)." distinct fault(s):\n";

// Biggest fault first — that is the generator bug worth fixing.
uasort($broken, static fn (array $a, array $b): int => count($b) <=> count($a));

foreach ($broken as $message => $occurrences) {
    printf("\n   %s\n   %s (%d file%s)\n\n", $message, str_repeat('─', min(60, strlen($message))), count($occurrences), count($occurrences) === 1 ? '' : 's');

    foreach (array_slice($occurrences, 0, 10) as $occurrence) {
        printf("     %s%s\n", $occurrence['file'], $occurrence['line'] !== null ? ':'.$occurrence['line'] : '');
    }

    if (count($occurrences) > 10) {
        printf("     … and %d more\n", count($occurrences) - 10);
    }
}

echo "\nA fault repeated across many files is one generator bug, not many broken files.\n";
echo "Fix the generator, delete the affected files, and regenerate.\n";

exit(1);
