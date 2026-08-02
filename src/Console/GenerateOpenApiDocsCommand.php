<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Zuqongtech\LaravelAnvil\Support\OpenApiLocator;

/**
 * Generates the API documentation for one or more API versions, then reports
 * where each version lives.
 *
 *   php artisan anvil:forge-apidocs                        # default version
 *   php artisan anvil:forge-apidocs --api-version=2        # just v2
 *   php artisan anvil:forge-apidocs --api-version=2 --force --ui
 *   php artisan anvil:forge-apidocs --all-versions --force  # refresh every version on disk
 *   php artisan anvil:forge-apidocs --check                 # report only, no writes
 *   php artisan anvil:forge-apidocs --check --strict         # CI gate: non-zero if a spec is missing
 *   php artisan anvil:forge-apidocs --json                  # implies --check
 *   php artisan anvil:forge-apidocs --open
 *
 * Generation itself is delegated to anvil:forge-api --spec-only, so there is
 * exactly one implementation of the spec pipeline; this command owns version
 * targeting and reporting.
 */

class GenerateOpenApiDocsCommand extends Command
{


    protected $description = 'Generate and report the API documentation (OpenAPI spec + Swagger UI) per API version';
    protected $signature = 'anvil:forge-apidocs
                            {--api-version=   : Target a single version (1, v1); default: the configured version}
                            {--all-versions   : Target every version already present on disk}
                            {--check          : Report only — generate nothing}
                            {--strict         : Exit non-zero if any targeted version has no spec}
                            {--force          : Overwrite an existing spec}
                            {--ui             : Publish the static Swagger UI for the version}
                            {--format=        : Spec format: yaml or json (default: config)}
                            {--single-file    : Merge schemas and paths into one document}
                            {--connection=    : Database connection to introspect}
                            {--schema=        : Schema(s) to introspect: name, csv list, or "all"}
                            {--tables=*       : Limit generation to specific tables}
                            {--ignore=*       : Exclude specific tables}
                            {--dry-run        : Preview without writing files}
                            {--json           : Output machine-readable JSON (implies --check)}
                            {--open           : Attempt to open the docs URL in the default browser}';
    /**
     * The command this one delegates spec generation to.
     *
     * Careful with find-and-replace across the package: this value used to be
     * a strict PREFIX of this command's own name ('anvil:generate-api' vs.
     * 'anvil:generate-apidocs'), and a rename that touched one but not the
     * other silently broke delegation — resolveApiCommand() reported "the
     * anvil:generate-api command is not registered" even though the spec
     * generator was very much registered, just under 'anvil:forge-api'. Both
     * command names now share the 'anvil:forge-' prefix instead, and
     * resolveApiCommand() still guards against this class of bug regardless
     * of what either name is renamed to next.
     */


    private const API_COMMAND = 'anvil:forge-api';

    /** @var list<string> */
    private const FORMATS = ['yaml', 'json'];

    public function handle(): int
    {
        if (($flagError = $this->validateFlags()) !== null) {
            $this->error($flagError);

            return self::FAILURE;
        }

        // Captured before delegating: anvil:forge-api overwrites
        // anvil.openapi.api_version at runtime, which would otherwise move the
        // "(default)" marker to whichever version was generated last.
        $default = OpenApiLocator::configuredVersion();
        $targets = $this->targets($default);

        $checkOnly = $this->option('check') || $this->option('json');
        $dryRun = (bool) $this->option('dry-run');

        if (! $checkOnly) {
            $target = $this->resolveApiCommand();

            if (is_string($target)) {
                $this->error($target);

                return self::FAILURE;
            }

            if (($status = $this->generate($targets, $target)) !== self::SUCCESS) {
                return $status;
            }
        }

        $preferred = $this->resolvedFormat();
        $enabled = (bool) config('anvil.openapi.docs.enabled', false);

        $report = array_map(
            fn(string $version): array => $this->describe($version, $preferred, $default),
            $targets,
        );

        $missing = count(array_filter($report, static fn(array $e): bool => ! $e['spec_exists']));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'enabled' => $enabled,
                'route' => trim((string) config('anvil.openapi.docs.route', 'docs'), '/'),
                'versioned_output' => OpenApiLocator::versioned(),
                'format' => $preferred,
                'default_version' => $default,
                'check_only' => $checkOnly,
                'dry_run' => $dryRun,
                'missing' => $missing,
                'versions' => $report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->exitCode($missing, $dryRun);
        }

        $this->render($report, $enabled, $checkOnly, $dryRun);

        if ($this->option('open')) {
            $this->handleOpen($report, $enabled);
        }

        return $this->exitCode($missing, $dryRun);
    }

    /**
     * A dry run legitimately leaves nothing on disk, so it never fails --strict.
     */
    protected function exitCode(int $missing, bool $dryRun): int
    {
        if ($missing > 0 && $this->option('strict') && ! $dryRun) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function validateFlags(): ?string
    {
        $requested = (string) $this->option('api-version');

        if ($this->option('all-versions') && $requested !== '') {
            return '--all-versions and --api-version are mutually exclusive; pick one.';
        }

        $format = strtolower((string) $this->option('format'));

        if ($format !== '' && ! in_array($format, self::FORMATS, true)) {
            return sprintf('Unknown --format "%s". Expected one of: %s.', $format, implode(', ', self::FORMATS));
        }

        return null;
    }

    /**
     * Which versions this run covers.
     *
     * @return list<string>
     */
    protected function targets(string $default): array
    {
        if ($this->option('all-versions')) {
            $available = OpenApiLocator::availableVersions();

            if ($available === []) {
                $this->line("  <fg=gray>No versions found on disk; falling back to the configured default ({$default}).</>");

                return [$default];
            }

            return $available;
        }

        $requested = (string) $this->option('api-version');

        if ($requested !== '') {
            return [OpenApiLocator::normaliseVersion($requested)];
        }

        return [$default];
    }

    /**
     * Resolve the command that actually generates specs, or return a string
     * explaining why it cannot be used.
     *
     * Four distinct failures, each with its own message. A naive check would
     * only verify `has()`, which cannot catch the worst case: API_COMMAND
     * pointing back at this command, which would then pass --spec-only to
     * itself and surface as Symfony's opaque "the --spec-only option does not
     * exist". That exact failure mode is why this method also checks identity,
     * not just registration.
     */
    protected function resolveApiCommand(): SymfonyCommand|string
    {
        $application = $this->getApplication();

        if ($application === null) {
            return 'No console application is bound, so the spec cannot be generated. Pass --check to report only.';
        }

        if (! $application->has(self::API_COMMAND)) {
            return sprintf(
                'The %s command is not registered. Register GenerateOpenApiCommand in the service provider, or pass '
                    . '--check to report only.',
                self::API_COMMAND,
            );
        }

        $command = $application->get(self::API_COMMAND);

        if ($command === $this || $command->getName() === $this->getName()) {
            return sprintf(
                'API_COMMAND resolves to this command (%s), which would recurse. It must name the spec generator. '
                    . 'Note that "%s" and "%s" share a prefix — a careless rename or find-and-replace can point one '
                    . 'back at the other.',
                $this->getName(),
                self::API_COMMAND,
                (string) $this->getName(),
            );
        }

        if (! $command->getDefinition()->hasOption('spec-only')) {
            return sprintf(
                '%s does not declare --spec-only, so it is not the spec generator this command expects.',
                (string) $command->getName(),
            );
        }

        return $command;
    }

    /**
     * Delegate to the API command once per target version.
     *
     * @param  list<string>  $versions
     */
    protected function generate(array $versions, SymfonyCommand $target): int
    {
        $name = (string) $target->getName();

        foreach ($versions as $version) {
            $this->line(sprintf(
                '  <options=bold>Generating %s</> <fg=gray>via %s --spec-only</>',
                $version,
                $name,
            ));

            $status = $this->call($name, $this->delegatedArguments($version));

            if ($status !== self::SUCCESS) {
                $this->error("  Spec generation failed for {$version}; stopping.");

                return $status;
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    protected function delegatedArguments(string $version): array
    {
        $arguments = [
            '--spec-only' => true,
            '--api-version' => ltrim($version, 'v'),
        ];

        foreach (['force', 'ui', 'single-file', 'dry-run'] as $flag) {
            if ($this->option($flag)) {
                $arguments["--{$flag}"] = true;
            }
        }

        foreach (['format', 'connection', 'schema'] as $option) {
            if ($value = $this->option($option)) {
                $arguments["--{$option}"] = (string) $value;
            }
        }

        foreach (['tables', 'ignore'] as $option) {
            if ($values = $this->option($option)) {
                $arguments["--{$option}"] = array_map(strval(...), $values);
            }
        }

        return $arguments;
    }

    protected function resolvedFormat(): string
    {
        $format = strtolower((string) $this->option('format'));

        return in_array($format, self::FORMATS, true) ? $format : OpenApiLocator::format();
    }

    /**
     * Report on a version, preferring the requested format but recognising a
     * spec written in the other one — otherwise switching --format makes an
     * existing spec look missing.
     *
     * @return array<string, mixed>
     */
    protected function describe(string $version, string $preferred, string $default): array
    {
        $format = $this->formatOnDisk($version, $preferred);
        $counts = OpenApiLocator::fileCounts($version, $format);

        return [
            'version' => $version,
            'is_default' => $version === $default,
            'format' => $format,
            'docs_url' => OpenApiLocator::docsUrl($version),
            'spec_url' => OpenApiLocator::specUrl($version, $format),
            'spec_path' => OpenApiLocator::specFile($version, $format),
            'spec_exists' => OpenApiLocator::specExists($version, $format),
            'api_base_path' => OpenApiLocator::apiBasePath($version),
            'schema_files' => $counts['schemas'],
            'path_files' => $counts['paths'],
        ];
    }

    protected function formatOnDisk(string $version, string $preferred): string
    {
        if (OpenApiLocator::specExists($version, $preferred)) {
            return $preferred;
        }

        $other = $preferred === 'yaml' ? 'json' : 'yaml';

        return OpenApiLocator::specExists($version, $other) ? $other : $preferred;
    }

    /**
     * @param  list<array<string, mixed>>  $report
     */
    protected function render(array $report, bool $enabled, bool $checkOnly, bool $dryRun): void
    {
        $this->newLine();
        $this->line('  <fg=cyan;options=bold>⚒  Anvil — API Documentation</>');

        if (! $enabled) {
            $this->newLine();
            $this->line('  <fg=yellow>Docs routes are disabled</> — set anvil.openapi.docs.enabled = true to serve them.');
        }

        if (! OpenApiLocator::versioned()) {
            $this->newLine();
            $this->line('  <fg=gray>Flat output mode (anvil.openapi.versioned_output = false) — one spec, no version segment.</>');
        }

        if ($dryRun && ! $checkOnly) {
            $this->newLine();
            $this->line('  <fg=gray>Dry run — the pipeline ran but wrote nothing, so specs below will read as missing.</>');
        }

        $missing = 0;

        foreach ($report as $entry) {
            $this->newLine();

            $heading = '  <options=bold>' . $entry['version'] . '</>';

            if ($entry['is_default']) {
                $heading .= ' <fg=gray>(default)</>';
            }

            $this->line($heading);

            $this->twoColumn('Docs UI', $enabled ? $entry['docs_url'] : '<fg=yellow>disabled</>');
            $this->twoColumn('OpenAPI spec', $entry['spec_url']);
            $this->twoColumn('Spec file', $entry['spec_path']);
            $this->twoColumn('API base', $entry['api_base_path']);

            if ($entry['spec_exists']) {
                $this->twoColumn('Spec status', sprintf(
                    '<fg=green>✓ generated</> <fg=gray>(%d schema, %d path files)</>',
                    $entry['schema_files'],
                    $entry['path_files'],
                ));

                continue;
            }

            $missing++;
            $this->twoColumn('Spec status', '<fg=red>✗ not found</> — ' . $this->missingHint($entry, $checkOnly, $dryRun));
        }

        $this->newLine();

        if ($missing > 0 && $checkOnly) {
            $this->warn(sprintf(
                '  %d of %d version(s) have no spec on disk; those docs pages will 404 until you generate them.',
                $missing,
                count($report),
            ));
            $this->newLine();
        }
    }

    /**
     * The three reasons a spec can be absent are not interchangeable, and
     * telling someone to re-run the command they just ran is the least useful of
     * the three.
     *
     * @param  array<string, mixed>  $entry
     */
    protected function missingHint(array $entry, bool $checkOnly, bool $dryRun): string
    {
        if ($dryRun) {
            return 'dry run — nothing was written, by design';
        }

        if ($checkOnly) {
            return sprintf(
                'run: php artisan %s --api-version=%s',
                (string) $this->getName(),
                ltrim((string) $entry['version'], 'v'),
            );
        }

        return 'generation reported success but wrote nothing — check that the OpenAPI generators resolve their '
            . 'output through OpenApiLocator::specDir()';
    }

    /**
     * Open the default version when it exists, otherwise the newest one that
     * does — opening a URL that is guaranteed to 404 is not helpful.
     *
     * @param  list<array<string, mixed>>  $report
     */
    protected function handleOpen(array $report, bool $enabled): void
    {
        if (! $enabled) {
            $this->warn('  Docs are disabled in config; not opening the browser.');

            return;
        }

        $generated = array_values(array_filter($report, static fn(array $e): bool => (bool) $e['spec_exists']));

        if ($generated === []) {
            $this->warn('  No spec has been generated yet; not opening the browser.');

            return;
        }

        $target = null;

        foreach ($generated as $entry) {
            if ($entry['is_default']) {
                $target = $entry;

                break;
            }
        }

        $target ??= end($generated);

        $this->openInBrowser((string) $target['docs_url']);
        $this->newLine();
    }

    protected function twoColumn(string $label, string $value): void
    {
        $this->line(sprintf('    <options=bold>%-13s</> %s', $label, $value));
    }

    /**
     * Best-effort cross-platform browser open. Silently no-ops if unsupported.
     */
    protected function openInBrowser(string $url): void
    {
        if (! function_exists('exec')) {
            $this->line("  <fg=gray>exec() is disabled; open {$url} manually.</>");

            return;
        }

        // "start" is a cmd builtin, not an executable, so it needs a shell.
        $command = match (PHP_OS_FAMILY) {
            'Darwin' => 'open ' . escapeshellarg($url),
            'Windows' => 'cmd /c start "" ' . escapeshellarg($url),
            default => 'xdg-open ' . escapeshellarg($url),
        };

        $redirect = PHP_OS_FAMILY === 'Windows' ? ' > NUL 2>&1' : ' > /dev/null 2>&1 &';

        @exec($command . $redirect);

        $this->info("🌐 Opening {$url} …");
    }
}
