<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Acquires the swagger-ui-dist static assets.
 *
 * WHY NOT JUST `npm install`
 *
 * swagger-ui-dist is three files that never execute at build time. Reaching for
 * npm to obtain them costs a full dependency resolution, a lockfile write, and —
 * without --no-save — an entry in the host application's package.json for a
 * package the application does not depend on. On a slow link the resolution alone
 * outlives any reasonable timeout, which is exactly the failure this class exists
 * to stop being the default experience.
 *
 * So three strategies, cheapest first:
 *
 *   local — node_modules/swagger-ui-dist is already present: copy it. Free.
 *   http  — fetch the individual files from a CDN. ~1.5 MB, seconds, touches
 *           nothing but the target directory.
 *   npm   — last resort, into a TEMPORARY prefix so the application's
 *           package.json and lockfile are never modified.
 *
 * Every strategy ends by writing a manifest, so a second run is a no-op rather
 * than a repeat download.
 */
final class SwaggerUiInstaller
{
    /** Without these the docs page cannot render. */
    public const REQUIRED_FILES = [
        'swagger-ui.css',
        'swagger-ui-bundle.js',
        'swagger-ui-standalone-preset.js',
    ];

    /** Nice to have; a miss is not a failure. */
    public const OPTIONAL_FILES = [
        'favicon-16x16.png',
        'favicon-32x32.png',
        'swagger-ui.css.map',
    ];

    /** Recorded next to the assets so a repeat run can skip the work. */
    public const MANIFEST = '.anvil-swagger-ui.json';

    /**
     * Tried in order. Two mirrors because one CDN having a bad day should not
     * fail an install.
     *
     * @var list<string>
     */
    private const MIRRORS = [
        'https://cdn.jsdelivr.net/npm/swagger-ui-dist@%s/%s',
        'https://unpkg.com/swagger-ui-dist@%s/%s',
    ];

    /** @var list<array{strategy: string, status: string, detail: string}> */
    private array $log = [];

    /** @var \Closure(string): void|null */
    private ?\Closure $output = null;

    public function __construct(
        private readonly string $version,
        private readonly string $targetDir,
        private readonly bool $dryRun = false,
        private readonly int $httpTimeout = 120,
        private readonly int $npmTimeout = 900,
    ) {}

    /** Stream progress to the console. */
    public function onOutput(callable $callback): self
    {
        $this->output = \Closure::fromCallable($callback);

        return $this;
    }

    /** @return list<array{strategy: string, status: string, detail: string}> */
    public function log(): array
    {
        return $this->log;
    }

    // -----------------------------------------------------------------------
    // Entry point
    // -----------------------------------------------------------------------

    /**
     * @param  list<string>  $strategies  Subset of local|http|npm, in preference order
     * @return array{ok: bool, strategy: ?string, files: list<string>, bytes: int}
     */
    public function install(array $strategies = ['local', 'http', 'npm'], bool $force = false): array
    {
        if (! $force && $this->alreadyInstalled()) {
            $this->record('cache', 'skipped', "version {$this->version} already present in {$this->relative($this->targetDir)}");

            return ['ok' => true, 'strategy' => 'cache', 'files' => self::REQUIRED_FILES, 'bytes' => 0];
        }

        foreach ($strategies as $strategy) {
            $result = match ($strategy) {
                'local' => $this->fromNodeModules(),
                'http' => $this->fromHttp(),
                'npm' => $this->fromNpm(),
                default => null,
            };

            if ($result !== null && $result['ok']) {
                $this->writeManifest($strategy, $result['files']);

                return $result;
            }
        }

        return ['ok' => false, 'strategy' => null, 'files' => [], 'bytes' => 0];
    }

    /**
     * Whether the target already holds this exact version.
     *
     * Checked against the manifest AND the files themselves, because a manifest
     * whose assets were deleted is worse than no manifest — it would report a
     * successful install of files that are not there.
     */
    public function alreadyInstalled(): bool
    {
        $manifestPath = $this->targetDir.'/'.self::MANIFEST;

        if (! is_file($manifestPath)) {
            return false;
        }

        $manifest = json_decode((string) @file_get_contents($manifestPath), true);

        if (! is_array($manifest) || ($manifest['version'] ?? null) !== $this->version) {
            return false;
        }

        foreach (self::REQUIRED_FILES as $file) {
            if (! is_file($this->targetDir.'/'.$file) || filesize($this->targetDir.'/'.$file) === 0) {
                return false;
            }
        }

        return true;
    }

    // -----------------------------------------------------------------------
    // Strategy: already in node_modules
    // -----------------------------------------------------------------------

    /**
     * @return array{ok: bool, strategy: string, files: list<string>, bytes: int}|null
     */
    private function fromNodeModules(): ?array
    {
        $source = base_path('node_modules/swagger-ui-dist');

        if (! is_dir($source)) {
            $this->record('local', 'skipped', 'node_modules/swagger-ui-dist not present');

            return null;
        }

        // A version mismatch is worth saying out loud rather than silently
        // shipping whatever happens to be on disk.
        $installed = $this->nodeModulesVersion($source);

        if ($installed !== null && $installed !== $this->version) {
            $this->record('local', 'skipped', "node_modules holds {$installed}, not {$this->version}");

            return null;
        }

        $this->emit("Copying from node_modules/swagger-ui-dist ({$installed})");

        return $this->copyFrom($source, 'local');
    }

    private function nodeModulesVersion(string $dir): ?string
    {
        $package = json_decode((string) @file_get_contents($dir.'/package.json'), true);

        return is_array($package) && isset($package['version']) && is_string($package['version'])
            ? $package['version']
            : null;
    }

    // -----------------------------------------------------------------------
    // Strategy: HTTP
    // -----------------------------------------------------------------------

    /**
     * Fetch the individual files.
     *
     * This is the path that should almost always win: three requests, no
     * dependency resolution, no mutation of the host project.
     *
     * @return array{ok: bool, strategy: string, files: list<string>, bytes: int}|null
     */
    private function fromHttp(): ?array
    {
        if ($this->dryRun) {
            $this->record('http', 'dry-run', 'would download '.count(self::REQUIRED_FILES).' files');

            return ['ok' => true, 'strategy' => 'http', 'files' => self::REQUIRED_FILES, 'bytes' => 0];
        }

        if (! $this->ensureDir($this->targetDir)) {
            return null;
        }

        $written = [];
        $bytes = 0;

        foreach ([...self::REQUIRED_FILES, ...self::OPTIONAL_FILES] as $file) {
            $required = in_array($file, self::REQUIRED_FILES, true);
            $size = $this->download($file);

            if ($size === null) {
                if ($required) {
                    $this->record('http', 'failed', "could not fetch {$file} from any mirror");

                    return null;
                }

                continue;
            }

            $written[] = $file;
            $bytes += $size;
        }

        $this->record('http', 'success', sprintf('%d files, %s', count($written), $this->humanBytes($bytes)));

        return ['ok' => true, 'strategy' => 'http', 'files' => $written, 'bytes' => $bytes];
    }

    /** @return int|null Bytes written, or null on failure from every mirror. */
    private function download(string $file): ?int
    {
        $destination = $this->targetDir.'/'.$file;

        foreach (self::MIRRORS as $template) {
            $url = sprintf($template, $this->version, $file);
            $temp = $destination.'.part';

            try {
                $response = Http::timeout($this->httpTimeout)
                    ->withoutRedirecting()
                    ->withHeaders(['Accept' => '*/*'])
                    ->sink($temp)
                    ->get($url);

                // A CDN 404 commonly returns an HTML error page with a 200-ish
                // status through a redirect chain. Saving that as
                // swagger-ui-bundle.js produces a docs page that fails silently
                // in the browser, which is far harder to diagnose than a clean
                // install failure — so the payload is checked, not just the code.
                if (! $response->successful() || ! $this->looksValid($temp, $file)) {
                    @unlink($temp);
                    $this->emit("  miss: {$url}");

                    continue;
                }

                @rename($temp, $destination);
                $size = (int) @filesize($destination);
                $this->emit(sprintf('  ✓ %-34s %s', $file, $this->humanBytes($size)));

                return $size;
            } catch (\Throwable $e) {
                @unlink($temp);
                $this->emit('  '.$this->shortError($e, $url));
            }
        }

        return null;
    }

    /**
     * Cheap sanity check on a downloaded asset.
     *
     * Guards against the classic CDN failure: an HTML error page saved under a
     * .js name, which the browser then refuses to execute with a message that
     * mentions neither the CDN nor the version.
     */
    private function looksValid(string $path, string $file): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $size = (int) filesize($path);

        // The bundle is ~1 MB; the CSS ~150 KB. Anything tiny is an error page.
        $floor = str_ends_with($file, '.png') ? 100 : 1024;

        if ($size < $floor) {
            return false;
        }

        $head = (string) @file_get_contents($path, false, null, 0, 512);

        if ($head === '') {
            return false;
        }

        // HTML where JS or CSS was expected.
        if (! str_ends_with($file, '.png') && preg_match('/^\s*<(!doctype|html)/i', $head) === 1) {
            return false;
        }

        return true;
    }

    // -----------------------------------------------------------------------
    // Strategy: npm
    // -----------------------------------------------------------------------

    /**
     * Last resort.
     *
     * Two changes from the original invocation. The install goes into a temporary
     * --prefix, so the application's package.json and package-lock.json are never
     * touched — installing swagger-ui-dist as a dependency of the host app was
     * always a side effect nobody asked for. And output is streamed, so a slow
     * install looks slow rather than looking hung.
     *
     * @return array{ok: bool, strategy: string, files: list<string>, bytes: int}|null
     */
    private function fromNpm(): ?array
    {
        $binary = (string) config('anvil.openapi.docs.npm_binary', 'npm');
        $spec = 'swagger-ui-dist@'.$this->version;

        if ($this->dryRun) {
            $this->record('npm', 'dry-run', "{$binary} install {$spec} --no-save --prefix <temp>");

            return ['ok' => true, 'strategy' => 'npm', 'files' => self::REQUIRED_FILES, 'bytes' => 0];
        }

        $prefix = $this->temporaryPrefix();

        if ($prefix === null) {
            $this->record('npm', 'failed', 'could not create a temporary install directory');

            return null;
        }

        $this->emit("Running: {$binary} install {$spec} --no-save --prefix {$prefix}");
        $this->emit("  (up to {$this->npmTimeout}s; output follows)");

        try {
            $result = Process::path(base_path())
                ->timeout($this->npmTimeout)
                ->run(
                    [$binary, 'install', $spec, '--no-save', '--no-audit', '--no-fund', '--prefix', $prefix],
                    function (string $type, string $buffer): void {
                        foreach (preg_split('/\R/', rtrim($buffer)) ?: [] as $line) {
                            if (trim($line) !== '') {
                                $this->emit('  '.$line);
                            }
                        }
                    },
                );
        } catch (\Throwable $e) {
            // The whole point of this class: a timeout is a diagnosable condition,
            // not an uncaught ProcessTimedOutException with a vendor stack trace.
            $this->record('npm', 'failed', $this->npmFailureHint($e));
            $this->cleanup($prefix);

            return null;
        }

        if ($result->failed()) {
            $this->record('npm', 'failed', sprintf(
                'exit %d: %s',
                $result->exitCode(),
                $this->firstLine($result->errorOutput() ?: $result->output()),
            ));
            $this->cleanup($prefix);

            return null;
        }

        $source = $prefix.'/node_modules/swagger-ui-dist';

        if (! is_dir($source)) {
            $this->record('npm', 'failed', "install reported success but {$source} does not exist");
            $this->cleanup($prefix);

            return null;
        }

        $copied = $this->copyFrom($source, 'npm');
        $this->cleanup($prefix);

        return $copied;
    }

    /**
     * A timeout here almost always means a slow link or a cold npm cache, and
     * both have concrete remedies worth naming.
     */
    private function npmFailureHint(\Throwable $e): string
    {
        $message = $this->firstLine($e->getMessage());

        if (! str_contains(strtolower($message), 'timed out') && ! str_contains(strtolower($message), 'timeout')) {
            return $message;
        }

        return sprintf(
            'timed out after %ds. Raise it with --timeout=1800, or avoid npm entirely with --source=http.',
            $this->npmTimeout,
        );
    }

    private function temporaryPrefix(): ?string
    {
        $base = sys_get_temp_dir().'/anvil-swagger-ui-'.bin2hex(random_bytes(4));

        return @mkdir($base, 0o755, true) || is_dir($base) ? $base : null;
    }

    private function cleanup(string $dir): void
    {
        if (! is_dir($dir) || ! str_contains($dir, 'anvil-swagger-ui-')) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }

    // -----------------------------------------------------------------------
    // Shared
    // -----------------------------------------------------------------------

    /**
     * @return array{ok: bool, strategy: string, files: list<string>, bytes: int}|null
     */
    private function copyFrom(string $source, string $strategy): ?array
    {
        if ($this->dryRun) {
            $this->record($strategy, 'dry-run', "would copy from {$source}");

            return ['ok' => true, 'strategy' => $strategy, 'files' => self::REQUIRED_FILES, 'bytes' => 0];
        }

        if (! $this->ensureDir($this->targetDir)) {
            return null;
        }

        $written = [];
        $bytes = 0;

        foreach ([...self::REQUIRED_FILES, ...self::OPTIONAL_FILES] as $file) {
            $from = $source.'/'.$file;
            $required = in_array($file, self::REQUIRED_FILES, true);

            if (! is_file($from)) {
                if ($required) {
                    $this->record($strategy, 'failed', "{$file} missing from {$source}");

                    return null;
                }

                continue;
            }

            if (! @copy($from, $this->targetDir.'/'.$file)) {
                if ($required) {
                    $this->record($strategy, 'failed', "could not copy {$file} into {$this->relative($this->targetDir)}");

                    return null;
                }

                continue;
            }

            $written[] = $file;
            $bytes += (int) @filesize($from);
        }

        $this->record($strategy, 'success', sprintf('%d files, %s', count($written), $this->humanBytes($bytes)));

        return ['ok' => true, 'strategy' => $strategy, 'files' => $written, 'bytes' => $bytes];
    }

    /**
     * @param  list<string>  $files
     */
    private function writeManifest(string $strategy, array $files): void
    {
        if ($this->dryRun) {
            return;
        }

        @file_put_contents($this->targetDir.'/'.self::MANIFEST, json_encode([
            'version' => $this->version,
            'strategy' => $strategy,
            'installed_at' => date('c'),
            'files' => array_values($files),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    private function ensureDir(string $dir): bool
    {
        if (is_dir($dir)) {
            return true;
        }

        if (@mkdir($dir, 0o755, true) || is_dir($dir)) {
            return true;
        }

        $this->record('filesystem', 'failed', "could not create {$this->relative($dir)}");

        return false;
    }

    private function record(string $strategy, string $status, string $detail): void
    {
        $this->log[] = ['strategy' => $strategy, 'status' => $status, 'detail' => $detail];
    }

    private function emit(string $line): void
    {
        if ($this->output !== null) {
            ($this->output)($line);
        }
    }

    private function firstLine(string $text): string
    {
        $line = trim((string) (preg_split('/\R/', trim($text))[0] ?? ''));

        return $line === '' ? 'no output' : $line;
    }

    private function shortError(\Throwable $e, string $url): string
    {
        return sprintf('miss: %s (%s)', $url, $this->firstLine($e->getMessage()));
    }

    private function humanBytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => round($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024).' KB',
            default => $bytes.' B',
        };
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), '/');
    }
}
