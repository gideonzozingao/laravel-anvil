<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

/**
 * Lets hand-written code survive regeneration.
 *
 * A generator normally has two options for an existing file: skip it (and stay
 * stale forever) or overwrite it (and destroy whatever someone added). Neither is
 * acceptable for a tool you are meant to run repeatedly, which is why most people
 * run a generator exactly once and then maintain the output by hand.
 *
 * Two mechanisms, usable independently:
 *
 * 1. KEEP REGIONS. Anything between markers is lifted out of the existing file and
 *    spliced into the newly generated one:
 *
 *        // anvil:keep:start relations
 *        public function activeSubscription(): HasOne { … }
 *        // anvil:keep:end
 *
 *    Markers are matched by name, so reordering the template does not lose them.
 *
 * 2. CONTENT HASH. A header records the hash of the body Anvil produced. On the
 *    next run the file's actual hash is compared: unchanged means safe to
 *    overwrite, changed means a human edited it and --force alone should not
 *    silently discard the work.
 *
 * Marker syntax is comment-style agnostic, so the same mechanism works in PHP,
 * Blade, YAML, JS and CSS output.
 */
final class PreserveRegions
{
    /** Matches `anvil:keep:start <name>` in //, #, /* … *\/, {{-- --}} or <!-- --> form. */
    private const START = '/(?:\/\/|#|\/\*|\{\{--|<!--)\s*anvil:keep:start\s+([\w.-]+)\s*(?:\*\/|--\}\}|-->)?[ \t]*\R?/i';

    private const END = '/(?:\/\/|#|\/\*|\{\{--|<!--)\s*anvil:keep:end\s*(?:\*\/|--\}\}|-->)?[ \t]*\R?/i';

    private const HASH_PATTERN = '/@anvil-hash\s+([a-f0-9]{64})/i';

    /**
     * Pull every keep region out of a file.
     *
     * @return array<string, string> name => body (without the marker lines)
     */
    public static function extract(string $contents): array
    {
        if (! preg_match_all(self::START, $contents, $starts, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $regions = [];

        foreach ($starts[0] as $index => [$startMatch, $startOffset]) {
            $name = trim($starts[1][$index][0]);
            $bodyStart = $startOffset + strlen($startMatch);

            if (! preg_match(self::END, $contents, $endMatch, PREG_OFFSET_CAPTURE, $bodyStart)) {
                // An unterminated region is a syntax error waiting to happen;
                // dropping it is safer than guessing where it ends.
                continue;
            }

            $regions[$name] = rtrim(substr($contents, $bodyStart, $endMatch[0][1] - $bodyStart), "\r\n");
        }

        return $regions;
    }

    /**
     * Splice the existing file's keep regions into freshly generated content.
     *
     * @return array{content: string, restored: list<string>, orphaned: array<string, string>}
     *                                                                                         orphaned = regions with no matching marker in the new template; the
     *                                                                                         caller decides whether to warn, refuse, or append them.
     */
    public static function merge(string $generated, string $existing): array
    {
        $regions = self::extract($existing);

        if ($regions === []) {
            return ['content' => $generated, 'restored' => [], 'orphaned' => []];
        }

        $restored = [];
        $slots = self::extract($generated);

        foreach ($regions as $name => $body) {
            if (! array_key_exists($name, $slots)) {
                continue;
            }

            $generated = self::replaceRegion($generated, $name, $body);
            $restored[] = $name;
        }

        $orphaned = array_diff_key($regions, $slots);

        // A region whose body is only whitespace was never used; do not report it.
        $orphaned = array_filter($orphaned, static fn (string $body): bool => trim($body) !== '');

        return ['content' => $generated, 'restored' => $restored, 'orphaned' => $orphaned];
    }

    /**
     * Replace the body of one named region, keeping the caller's marker lines.
     */
    private static function replaceRegion(string $contents, string $name, string $body): string
    {
        $quoted = preg_quote($name, '/');

        $pattern = '/((?:\/\/|#|\/\*|\{\{--|<!--)\s*anvil:keep:start\s+'.$quoted.'\s*(?:\*\/|--\}\}|-->)?[ \t]*\R)'
            .'(.*?)'
            .'((?:\/\/|#|\/\*|\{\{--|<!--)\s*anvil:keep:end)/is';

        return (string) preg_replace_callback(
            $pattern,
            static fn (array $m): string => $m[1].($body === '' ? '' : $body."\n").$m[3],
            $contents,
            1,
        );
    }

    // -----------------------------------------------------------------------
    // Modification detection
    // -----------------------------------------------------------------------

    /**
     * Prepend the provenance header carrying the body hash.
     *
     * The comment style follows the file extension so the header is valid in the
     * language it lands in.
     */
    public static function stamp(string $contents, string $path, string $version = 'dev'): string
    {
        $hash = self::hash($contents);
        $date = date('Y-m-d');

        $lines = [
            "@generated by zuqongtech/laravel-anvil {$version} on {$date}",
            "@anvil-hash {$hash}",
            'Regenerate with --force. Anvil refuses to overwrite this file once the',
            'hash stops matching, so hand edits are safe; wrap them in',
            'anvil:keep:start / anvil:keep:end to carry them across regenerations.',
        ];

        return match (self::commentStyle($path)) {
            'blade' => '{{--'."\n    ".implode("\n    ", $lines)."\n--}}\n".$contents,
            'hash' => '# '.implode("\n# ", $lines)."\n".$contents,
            'html' => '<!--'."\n    ".implode("\n    ", $lines)."\n-->\n".$contents,
            // PHP: the header goes after the opening tag, not before it.
            default => self::stampPhp($contents, $lines),
        };
    }

    /**
     * @param  list<string>  $lines
     */
    private static function stampPhp(string $contents, array $lines): string
    {
        $block = "/**\n * ".implode("\n * ", $lines)."\n */\n";

        // After `<?php` and any `declare(strict_types=1);`, before `namespace`.
        if (preg_match('/^(<\?php\s*\R+(?:declare\s*\([^)]*\)\s*;\s*\R+)?)/', $contents, $match) === 1) {
            return $match[1].$block.substr($contents, strlen($match[1]));
        }

        return $block.$contents;
    }

    /**
     * Has this file been edited since Anvil wrote it?
     *
     * Unstamped files return true: without a recorded hash there is no way to know
     * the content is Anvil's, and assuming it is risks eating hand-written code.
     */
    public static function isModified(string $contents): bool
    {
        if (preg_match(self::HASH_PATTERN, $contents, $match) !== 1) {
            return true;
        }

        return ! hash_equals($match[1], self::hash(self::withoutHeader($contents)));
    }

    public static function isStamped(string $contents): bool
    {
        return preg_match(self::HASH_PATTERN, $contents) === 1;
    }

    /**
     * The hash covers the body only, and ignores line-ending and trailing-newline
     * differences — a checkout on Windows should not read as "edited".
     */
    public static function hash(string $contents): string
    {
        return hash('sha256', rtrim(str_replace("\r\n", "\n", $contents))."\n");
    }

    /**
     * Strip the provenance header so the remaining body can be hashed.
     */
    public static function withoutHeader(string $contents): string
    {
        $patterns = [
            '/\/\*\*\s*\R(?:\s*\*.*\R)*?\s*\*\s*@anvil-hash[^\R]*\R(?:\s*\*.*\R)*?\s*\*\/\s*\R/',
            '/\{\{--(?:(?!--\}\}).)*?@anvil-hash(?:(?!--\}\}).)*?--\}\}\s*\R/s',
            '/<!--(?:(?!-->).)*?@anvil-hash(?:(?!-->).)*?-->\s*\R/s',
            '/(?:^|\R)(?:#[^\R]*@anvil-hash[^\R]*\R)(?:#[^\R]*\R)*/',
        ];

        foreach ($patterns as $pattern) {
            $stripped = preg_replace($pattern, '', $contents, 1);

            if ($stripped !== null && $stripped !== $contents) {
                return $stripped;
            }
        }

        return $contents;
    }

    private static function commentStyle(string $path): string
    {
        $name = strtolower(basename($path));

        return match (true) {
            str_ends_with($name, '.blade.php') => 'blade',
            str_ends_with($name, '.yaml'), str_ends_with($name, '.yml') => 'hash',
            str_ends_with($name, '.html'), str_ends_with($name, '.htm') => 'html',
            default => 'php',
        };
    }

    /**
     * Everything a generator needs to decide what to do with one file.
     *
     * @return array{
     *     exists: bool, modified: bool, stamped: bool,
     *     content: string, restored: list<string>, orphaned: array<string, string>
     * }
     */
    public static function reconcile(string $path, string $generated, string $version = 'dev'): array
    {
        if (! is_file($path)) {
            return [
                'exists' => false,
                'modified' => false,
                'stamped' => false,
                'content' => self::stamp($generated, $path, $version),
                'restored' => [],
                'orphaned' => [],
            ];
        }

        $existing = (string) file_get_contents($path);
        $merged = self::merge($generated, $existing);

        return [
            'exists' => true,
            'modified' => self::isModified($existing),
            'stamped' => self::isStamped($existing),
            'content' => self::stamp($merged['content'], $path, $version),
            'restored' => $merged['restored'],
            'orphaned' => $merged['orphaned'],
        ];
    }
}
