<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

/**
 * Rebuilds a ModelRegistry by reading the model classes that are already on disk.
 *
 * This is the fallback that makes `--web` and `--api` safe to run as separate
 * invocations without regenerating models: if the manifest is missing, stale or
 * was never committed, the namespaces are recovered from the source files
 * themselves rather than recomputed from table names.
 *
 * Parsing is done with token_get_all rather than reflection deliberately — the
 * generated classes may not be autoloadable at the point a package command runs
 * (fresh files, no dumped autoloader), and loading them would execute code.
 */
final class ModelDiscovery
{
    /**
     * @param  string  $directory  Models root on disk, e.g. app/Models.
     * @param  string|null  $rootNamespace  e.g. "App\Models"; only used to sanity-check discovered namespaces.
     * @param  array<string, string>  $schemaSegmentMap  Namespace segment => schema name (e.g. ['Core' => 'core',
     *                                                   'MembersDb' => 'members_db']). Supply this from the same
     *                                                   studly helper the generator uses, so a bare `$table` in a
     *                                                   schema sub-namespace still recovers its schema. Segment keys
     *                                                   are matched case-insensitively.
     */
    public static function scan(
        string $directory,
        ?string $rootNamespace = null,
        array $schemaSegmentMap = [],
    ): ModelRegistry {
        $registry = (new ModelRegistry)->setRootNamespace($rootNamespace);

        if (! is_dir($directory)) {
            return $registry;
        }

        $lookup = [];

        foreach ($schemaSegmentMap as $segment => $schema) {
            $lookup[strtolower((string) $segment)] = (string) $schema;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $contents = @file_get_contents($file->getPathname());

            if ($contents === false) {
                continue;
            }

            $parsed = self::parse($contents);

            if ($parsed === null) {
                continue;
            }

            [$namespace, $class, $table, $isAbstract] = $parsed;

            if ($isAbstract || $class === '') {
                continue;
            }

            $fqcn = $namespace === '' ? $class : $namespace.'\\'.$class;

            // A model with no explicit $table cannot be mapped back to a table
            // without re-deriving a plural form, which is exactly the guessing
            // this class exists to avoid. Skip it and let the caller report it.
            if ($table === null) {
                continue;
            }

            $registry->register(new ModelReference(
                $fqcn,
                self::bareTable($table),
                self::schemaFor($table, $namespace, $rootNamespace, $lookup),
                $table,
            ));
        }

        return $registry;
    }

    /**
     * Model classes on disk that declare no `protected $table`, and so cannot be
     * mapped to a table. Useful for a warning line in the command output.
     *
     * @return array<int, string> FQCNs
     */
    public static function unmappable(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $contents = @file_get_contents($file->getPathname());

            if ($contents === false) {
                continue;
            }

            $parsed = self::parse($contents);

            if ($parsed === null) {
                continue;
            }

            [$namespace, $class, $table, $isAbstract] = $parsed;

            if ($isAbstract || $class === '' || $table !== null) {
                continue;
            }

            $found[] = $namespace === '' ? $class : $namespace.'\\'.$class;
        }

        sort($found);

        return $found;
    }

    /**
     * @return array{0: string, 1: string, 2: string|null, 3: bool}|null
     *                                                                   [namespace, class, table, isAbstract]
     */
    private static function parse(string $contents): ?array
    {
        if (! str_contains($contents, '<?php')) {
            return null;
        }

        $tokens = @token_get_all($contents);

        if ($tokens === []) {
            return null;
        }

        $namespace = '';
        $class = '';
        $table = null;
        $isAbstract = false;

        $count = count($tokens);
        $sawAbstract = false;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                continue;
            }

            [$id, $text] = $token;

            if ($id === T_NAMESPACE && $namespace === '') {
                $namespace = self::readName($tokens, $i + 1, $count);

                continue;
            }

            if ($id === T_ABSTRACT) {
                $sawAbstract = true;

                continue;
            }

            if ($id === T_CLASS && $class === '') {
                // Skip `Foo::class` and anonymous classes.
                $prev = self::previousSignificant($tokens, $i);

                if (is_array($prev) && ($prev[0] === T_DOUBLE_COLON || $prev[0] === T_NEW)) {
                    continue;
                }

                $next = self::nextSignificant($tokens, $i);

                if (! is_array($next) || $next[0] !== T_STRING) {
                    continue;
                }

                $class = $next[1];
                $isAbstract = $sawAbstract;

                continue;
            }

            if ($id === T_VARIABLE && $text === '$table' && $table === null) {
                $eq = self::nextSignificant($tokens, $i);

                if ($eq !== '=') {
                    continue;
                }

                $value = self::nextSignificant($tokens, self::nextSignificantIndex($tokens, $i));

                if (is_array($value) && $value[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $table = trim($value[1], "'\"");
                }
            }
        }

        if ($class === '') {
            return null;
        }

        return [$namespace, $class, $table === '' ? null : $table, $isAbstract];
    }

    /**
     * Read a (possibly qualified) name starting at $start.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function readName(array $tokens, int $start, int $count): string
    {
        $name = '';

        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === ';' || $token === '{') {
                break;
            }

            if (! is_array($token)) {
                continue;
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $name .= $token[1];
        }

        return trim($name, '\\');
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private static function nextSignificant(array $tokens, int $index)
    {
        $i = self::nextSignificantIndex($tokens, $index);

        return $i === null ? null : $tokens[$i];
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function nextSignificantIndex(array $tokens, int $index): ?int
    {
        $count = count($tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            if (self::isSignificant($tokens[$i])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private static function previousSignificant(array $tokens, int $index)
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if (self::isSignificant($tokens[$i])) {
                return $tokens[$i];
            }
        }

        return null;
    }

    /**
     * @param  array{0: int, 1: string, 2: int}|string  $token
     */
    private static function isSignificant($token): bool
    {
        if (! is_array($token)) {
            return true;
        }

        return ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_ATTRIBUTE], true);
    }

    private static function bareTable(string $table): string
    {
        $pos = strrpos($table, '.');

        return $pos === false ? $table : substr($table, $pos + 1);
    }

    /**
     * Resolve the owning schema: the qualified `$table` is authoritative; failing
     * that, the namespace segment below the models root is translated through the
     * caller-supplied map. Never un-studlied by guesswork.
     *
     * @param  array<string, string>  $lookup
     */
    private static function schemaFor(
        string $table,
        string $namespace,
        ?string $rootNamespace,
        array $lookup,
    ): ?string {
        $pos = strrpos($table, '.');

        if ($pos !== false && $pos > 0) {
            return substr($table, 0, $pos);
        }

        if ($rootNamespace === null || $rootNamespace === '') {
            return null;
        }

        $root = trim($rootNamespace, '\\');

        if ($namespace === $root || ! str_starts_with($namespace.'\\', $root.'\\')) {
            return null;
        }

        $tail = ltrim(substr($namespace, strlen($root)), '\\');
        $segment = explode('\\', $tail)[0] ?? '';

        return $lookup[strtolower($segment)] ?? null;
    }
}
