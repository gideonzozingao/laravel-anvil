<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

use Symfony\Component\Process\Process;

/**
 * Runs the quality tools over generated code.
 *
 * Anvil knows exactly which files it wrote — that is what the manifest records —
 * so the tools can be scoped to those files instead of the whole application. On a
 * large codebase that is the difference between a two-second check after each
 * generation and a two-minute one nobody runs.
 *
 * Nothing here is a dependency: each tool is used if it is installed and skipped
 * with an explanation if it is not.
 *
 * ONE IMPORTANT INTERACTION. Pint and Rector rewrite files, which changes the body
 * hash PreserveRegions stamps into the header. Left alone, every generated file
 * would then read as hand-edited and the next --force would refuse to touch it.
 * Callers must re-stamp what these tools modify; PolishCommand does.
 */
final class QualityRunner
{
    public const PINT = 'pint';

    public const RECTOR = 'rector';

    public const PHPSTAN = 'phpstan';

    /** @var array<string, string|null> */
    private array $resolved = [];

    /**
     * @param  int  $timeout  seconds; Rector on a large codebase is not fast
     */
    public function __construct(
        private readonly int $timeout = 300,
    ) {}

    /**
     * The binary for a tool, or null when it is not installed.
     */
    public function binary(string $tool): ?string
    {
        if (array_key_exists($tool, $this->resolved)) {
            return $this->resolved[$tool];
        }

        $candidates = [
            base_path("vendor/bin/{$tool}"),
            base_path("vendor/bin/{$tool}.bat"),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $this->resolved[$tool] = $candidate;
            }
        }

        // Fall back to PATH — some teams install these globally.
        $which = new Process([PHP_OS_FAMILY === 'Windows' ? 'where' : 'which', $tool]);
        $which->run();

        $path = trim($which->getOutput());

        return $this->resolved[$tool] = ($which->isSuccessful() && $path !== '') ? strtok($path, "\n") : null;
    }

    public function available(string $tool): bool
    {
        return $this->binary($tool) !== null;
    }

    /**
     * @return array<string, bool>
     */
    public function availability(): array
    {
        return [
            self::PINT => $this->available(self::PINT),
            self::RECTOR => $this->available(self::RECTOR),
            self::PHPSTAN => $this->available(self::PHPSTAN),
        ];
    }

    public static function installHint(string $tool): string
    {
        return match ($tool) {
            self::PINT => 'composer require laravel/pint --dev',
            self::RECTOR => 'composer require rector/rector driftingly/rector-laravel --dev',
            self::PHPSTAN => 'composer require larastan/larastan --dev',
            default => "composer require {$tool} --dev",
        };
    }

    // -----------------------------------------------------------------------
    // Running
    // -----------------------------------------------------------------------

    /**
     * @param  list<string>  $paths  absolute paths (files or directories)
     * @param  bool  $fix  false = report only
     * @return array{tool: string, ran: bool, ok: bool, exit: int, output: string, changed: list<string>, reason?: string}
     */
    public function run(string $tool, array $paths, bool $fix = true, ?callable $onProgress = null): array
    {
        $binary = $this->binary($tool);

        if ($binary === null) {
            return [
                'tool' => $tool,
                'ran' => false,
                'ok' => true,
                'exit' => 0,
                'output' => '',
                'changed' => [],
                'reason' => 'not installed — '.self::installHint($tool),
            ];
        }

        $paths = $this->existing($paths);

        if ($paths === []) {
            return [
                'tool' => $tool,
                'ran' => false,
                'ok' => true,
                'exit' => 0,
                'output' => '',
                'changed' => [],
                'reason' => 'nothing to check',
            ];
        }

        $output = '';
        $exit = 0;

        // Long path lists blow past the command-line limit; chunk them.
        foreach (array_chunk($paths, 200) as $chunk) {
            $process = new Process(
                $this->command($tool, $binary, $chunk, $fix),
                base_path(),
                // Tool output is far more readable in colour, and we are already
                // rendering it into a console.
                ['COLUMNS' => '120'],
                null,
                $this->timeout,
            );

            $process->run(function (string $type, string $buffer) use ($onProgress): void {
                if ($onProgress !== null) {
                    $onProgress($buffer);
                }
            });

            $output .= $process->getOutput().$process->getErrorOutput();
            $exit = max($exit, $process->getExitCode() ?? 1);
        }

        return [
            'tool' => $tool,
            'ran' => true,
            'ok' => $exit === 0,
            'exit' => $exit,
            'output' => trim($output),
            'changed' => $this->parseChanged($tool, $output),
        ];
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function command(string $tool, string $binary, array $paths, bool $fix): array
    {
        return match ($tool) {
            // Pint fixes by default; --test only reports.
            self::PINT => array_merge(
                [$binary, '--no-interaction'],
                $fix ? [] : ['--test'],
                $paths,
            ),

            // Rector needs paths after `process`; --dry-run reports only.
            self::RECTOR => array_merge(
                [$binary, 'process'],
                $paths,
                ['--no-progress-bar', '--no-diffs'],
                $fix ? [] : ['--dry-run'],
            ),

            // PHPStan never writes; the fix flag is meaningless for it.
            self::PHPSTAN => array_merge(
                [$binary, 'analyse', '--no-progress', '--no-interaction'],
                $paths,
            ),

            default => array_merge([$binary], $paths),
        };
    }

    /**
     * Which files a tool reported as changed. Best effort — the formats are not
     * stable across versions, so this drives reporting, not correctness.
     *
     * @return list<string>
     */
    private function parseChanged(string $tool, string $output): array
    {
        $changed = [];

        if ($tool === self::PINT) {
            // "  FIXED   app/Models/User.php"
            preg_match_all('/^\s*(?:FIXED|✓|⨯)?\s*([\w\/\.\-]+\.php)/mi', $output, $matches);
            $changed = $matches[1] ?? [];
        }

        if ($tool === self::RECTOR) {
            // "1) app/Models/User.php:12"
            preg_match_all('/^\s*\d+\)\s+([\w\/\.\-]+\.php)/mi', $output, $matches);
            $changed = $matches[1] ?? [];
        }

        $changed = array_values(array_unique(array_map(
            static fn (string $path): string => str_starts_with($path, '/') ? $path : base_path($path),
            $changed,
        )));

        return array_values(array_filter($changed, is_file(...)));
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function existing(array $paths): array
    {
        return array_values(array_unique(array_filter(
            $paths,
            file_exists(...),
        )));
    }

    // -----------------------------------------------------------------------
    // Re-stamping
    // -----------------------------------------------------------------------

    /**
     * Restore the provenance hash on files a formatter rewrote.
     *
     * Without this, Pint reindenting a generated model makes it read as
     * hand-edited, and the next `--force` skips it with "edited since
     * generation" — the tool silently stops maintaining its own output.
     *
     * @param  list<string>  $paths
     * @return list<string> the files re-stamped
     */
    public function restamp(array $paths, string $version = 'dev'): array
    {
        $restamped = [];

        foreach ($paths as $path) {
            if (! is_file($path)) {
                continue;
            }

            $contents = (string) file_get_contents($path);

            // Only files Anvil stamped in the first place; a hand-written file
            // that Pint touched is none of our business.
            if (! PreserveRegions::isStamped($contents)) {
                continue;
            }

            $body = PreserveRegions::withoutHeader($contents);

            if (file_put_contents($path, PreserveRegions::stamp($body, $path, $version)) !== false) {
                $restamped[] = $path;
            }
        }

        return $restamped;
    }
}
