<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

/**
 * Lints generated PHP before it is written to disk.
 *
 * `public int $tenant_id = null;` reached a browser as a 500 because nothing
 * checked the generated file. It is not a subtle failure — `php -l` catches it
 * outright, exit code 255:
 *
 *     Default value for property of type int may not be null.
 *     Use the nullable type ?int to allow null default value
 *
 * So the cost of never shipping that class of bug again is one subprocess per
 * generated file. A generator that writes a file which cannot be compiled has
 * failed, and it should say so at generation time, naming the table it was working
 * on — not leave the operator to find it by clicking through routes.
 *
 * This catches compile-time errors: parse failures, property/type mismatches,
 * duplicate declarations within the file, invalid modifier combinations. It cannot
 * catch things resolved at runtime — a missing parent class, an unimplemented
 * abstract method, a bad `use` target. Those need the placement check and an actual
 * boot.
 */
final class PhpSyntaxCheck
{
    private static ?bool $available = null;

    /**
     * Lint a string of PHP. Returns null when it compiles, or the compiler's
     * complaint with the temp path stripped out.
     */
    public static function check(string $code): ?string
    {
        if (! self::available()) {
            return null;
        }

        $temp = tempnam(sys_get_temp_dir(), 'anvil-lint-');

        if ($temp === false) {
            return null;
        }

        try {
            if (@file_put_contents($temp, $code) === false) {
                return null;
            }

            return self::lintFile($temp, $temp);
        } finally {
            @unlink($temp);
        }
    }

    /**
     * Lint a file already on disk.
     */
    public static function checkFile(string $path): ?string
    {
        if (! self::available() || ! is_file($path)) {
            return null;
        }

        return self::lintFile($path, $path);
    }

    /**
     * @param  string  $path  file to lint
     * @param  string  $stripFromMessage  path to remove from the message, so a temp
     *                                    filename does not leak into command output
     */
    private static function lintFile(string $path, string $stripFromMessage): ?string
    {
        // -n skips php.ini: an opcache or extension in the app's ini has no bearing
        // on whether the file compiles, and skipping it keeps the check fast.
        $command = escapeshellarg(PHP_BINARY).' -n -l '.escapeshellarg($path).' 2>&1';

        $output = [];
        $status = 0;
        @exec($command, $output, $status);

        if ($status === 0) {
            return null;
        }

        $message = trim(implode("\n", array_filter(
            $output,
            static fn (string $line): bool => trim($line) !== '' && ! str_starts_with($line, 'Errors parsing'),
        )));

        $message = str_replace([$stripFromMessage, ' in  on line', 'PHP Fatal error:  ', 'Fatal error: ', 'PHP Parse error:  ', 'Parse error: '], ['', '', '', '', '', ''], $message);

        // Collapse the doubled report php -l emits on stderr and stdout.
        $lines = array_values(array_unique(array_filter(array_map(trim(...), explode("\n", $message)))));

        return $lines === [] ? 'the file does not compile' : implode(' ', $lines);
    }

    /**
     * Whether linting can run at all. Resolved once: on a host where exec() is
     * disabled the generator must not fail every file.
     */
    public static function available(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }

        if (! function_exists('exec') || PHP_BINARY === '') {
            return self::$available = false;
        }

        $disabled = array_map(trim(...), explode(',', (string) ini_get('disable_functions')));

        if (in_array('exec', $disabled, true)) {
            return self::$available = false;
        }

        // Prove it end to end rather than assuming: a known-bad snippet must be
        // rejected, or the check is silently passing everything.
        $temp = tempnam(sys_get_temp_dir(), 'anvil-probe-');

        if ($temp === false) {
            return self::$available = false;
        }

        self::$available = true;

        try {
            @file_put_contents($temp, "<?php\nclass AnvilLintProbe { public int \$x = null; }\n");

            return self::$available = self::lintFile($temp, $temp) !== null;
        } finally {
            @unlink($temp);
        }
    }

    /**
     * For tests.
     */
    public static function resetAvailability(): void
    {
        self::$available = null;
    }
}
