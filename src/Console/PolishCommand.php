<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Console;

use Illuminate\Console\Command;
use Zuqongtech\LaravelAnvil\Support\DatabaseInspector;
use Zuqongtech\LaravelAnvil\Support\ModelAuditor;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;
use Zuqongtech\LaravelAnvil\Support\QualityRunner;
use Zuqongtech\LaravelAnvil\Support\SchemaManifest;

/**
 * Formats, modernises and audits generated code.
 *
 *   php artisan anvil:polish                  # fix everything installed
 *   php artisan anvil:polish --test           # report only — the CI mode
 *   php artisan anvil:polish --audit          # only the schema/model audit
 *   php artisan anvil:polish --all-paths      # the whole app, not just generated files
 *   php artisan anvil:polish --publish-config # write rector.php / pint.json
 *
 * Four passes, each optional and each skipped cleanly when its tool is absent:
 *
 *   pint     formatting
 *   rector   PHP 8.4 + Laravel 12 modernisation, dead code, type coverage
 *   phpstan  static analysis
 *   audit    model ↔ schema drift, which the other three cannot see
 *
 * By default only files Anvil generated are touched, read from the manifest. That
 * keeps the run fast and stops a formatting pass turning into an unrelated diff
 * across the whole application.
 */

class PolishCommand extends Command
{
    protected $description = 'Format, modernise and audit the code Anvil generated';
    protected $signature = 'anvil:polish
                            {--pint            : Run Pint}
                            {--rector          : Run Rector}
                            {--stan            : Run PHPStan/Larastan}
                            {--audit           : Run the model/schema audit}
                            {--test            : Report only; change nothing}
                            {--all-paths       : Check the whole app, not just generated files}
                            {--paths=*         : Explicit paths to check (repeatable)}
                            {--connection=     : Database connection for the audit}
                            {--strict          : Exit non-zero when anything is reported}
                            {--publish-config  : Write rector.php and pint.json tuned for generated code}
                            {--json            : Machine-readable output}';
    public function handle(): int
    {
        if ($this->option('publish-config')) {
            return $this->publishConfig();
        }

        $runner = new QualityRunner((int) config('anvil.quality.timeout', 300));
        $fix = ! $this->option('test');

        // No tool flags means "everything available".
        $explicit = $this->option('pint') || $this->option('rector') || $this->option('stan') || $this->option('audit');

        $wants = [
            QualityRunner::PINT => ! $explicit || $this->option('pint'),
            QualityRunner::RECTOR => ! $explicit || $this->option('rector'),
            QualityRunner::PHPSTAN => ! $explicit || $this->option('stan'),
        ];

        $auditWanted = ! $explicit || $this->option('audit');
        $paths = $this->paths();

        if ($paths === [] && ! $auditWanted) {
            $this->components->warn('Nothing to check. Generate something first, or pass --all-paths.');

            return self::SUCCESS;
        }

        $this->heading($runner, $paths, $fix);

        $results = [];
        $touched = [];

        foreach ($wants as $tool => $wanted) {
            if (! $wanted) {
                continue;
            }

            $result = $runner->run($tool, $paths, $fix && $tool !== QualityRunner::PHPSTAN);
            $results[$tool] = $result;
            $touched = array_merge($touched, $result['changed']);

            $this->reportTool($result);
        }

        // Formatters rewrite files, which invalidates the provenance hash. Without
        // re-stamping, every reformatted file reads as hand-edited and the next
        // --force refuses to regenerate it.
        if ($fix && $touched !== []) {
            $restamped = $runner->restamp(array_unique($touched), $this->version());

            if ($restamped !== []) {
                $this->line(sprintf(
                    '    <fg=gray>re-stamped %d generated file(s) so --force still recognises them</>',
                    count($restamped),
                ));
            }
        }

        $audit = $auditWanted ? $this->audit() : ['findings' => [], 'models' => 0];

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'fix' => $fix,
                'paths' => count($paths),
                'tools' => $results,
                'audit' => $audit,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return $this->exitCode($results, $audit['findings']);
    }

    // -----------------------------------------------------------------------
    // Paths
    // -----------------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function paths(): array
    {
        if ($explicit = $this->option('paths')) {
            return array_map(
                static fn(string $path): string => str_starts_with($path, '/') ? $path : base_path($path),
                array_map(strval(...), $explicit),
            );
        }

        if ($this->option('all-paths')) {
            return array_values(array_filter([
                app_path(),
                base_path('routes'),
                base_path('database'),
                resource_path('views'),
                base_path('tests'),
            ], is_dir(...)));
        }

        // The manifest records exactly what Anvil wrote — that is the whole point
        // of scoping: a formatting pass over generated files, not the codebase.
        $manifest = SchemaManifest::load();
        $paths = [];

        foreach ($manifest->tables() as $entry) {
            foreach ($entry['artifacts'] ?? [] as $artifact) {
                $absolute = base_path((string) $artifact);

                if (is_file($absolute) && str_ends_with($absolute, '.php')) {
                    $paths[] = $absolute;
                }
            }
        }

        if ($paths === []) {
            // No manifest yet: fall back to the directories Anvil owns.
            $paths = array_values(array_filter([
                app_path('Models'),
                app_path('Enums'),
                app_path('Http/Controllers/Api'),
                app_path('Http/Requests'),
                app_path('Http/Resources'),
                app_path('Services'),
                app_path('Repositories'),
                app_path('Livewire'),
            ], is_dir(...)));
        }

        return array_values(array_unique($paths));
    }

    // -----------------------------------------------------------------------
    // Reporting
    // -----------------------------------------------------------------------

    /**
     * @param  list<string>  $paths
     */
    private function heading(QualityRunner $runner, array $paths, bool $fix): void
    {
        if ($this->option('json')) {
            return;
        }

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>⚒  Anvil — polish</>');

        $availability = [];

        foreach ($runner->availability() as $tool => $available) {
            $availability[] = $available ? "<fg=green>{$tool}</>" : "<fg=gray>{$tool}</>";
        }

        $this->line('  ' . implode('  ', $availability)
            . '   <fg=gray>' . ($fix ? 'fixing' : 'reporting only') . ', '
            . count($paths) . ' path(s)</>');
        $this->newLine();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function reportTool(array $result): void
    {
        if ($this->option('json')) {
            return;
        }

        $tool = str_pad((string) $result['tool'], 8);

        if (! $result['ran']) {
            $this->line("  <fg=gray>–</> {$tool} <fg=gray>{$result['reason']}</>");

            return;
        }

        $icon = $result['ok'] ? '<fg=green>✔</>' : '<fg=yellow>▲</>';
        $changed = count($result['changed']);
        $summary = $changed > 0 ? "{$changed} file(s) affected" : 'clean';

        $this->line("  {$icon} {$tool} <fg=gray>{$summary}</>");

        // Show the tool's own output when it found something; it explains the
        // finding far better than any summary here could.
        if (! $result['ok'] && $result['output'] !== '') {
            foreach (array_slice(explode("\n", (string) $result['output']), 0, 40) as $line) {
                $this->line('      <fg=gray>' . $line . '</>');
            }
        }
    }

    // -----------------------------------------------------------------------
    // Model audit
    // -----------------------------------------------------------------------

    /**
     * @return array{findings: array<string, list<array<string, string>>>, models: int}
     */
    private function audit(): array
    {
        $connection = (string) ($this->option('connection') ?: config('database.default'));

        try {
            $inspector = new DatabaseInspector($connection);
        } catch (\Throwable $e) {
            if (! $this->option('json')) {
                $this->line('  <fg=gray>–</> audit    <fg=gray>no database connection: ' . $e->getMessage() . '</>');
            }

            return ['findings' => [], 'models' => 0];
        }

        $auditor = new ModelAuditor;
        $namespace = trim((string) config('anvil.namespace', 'App\\Models'), '\\');
        $ignore = (array) config('anvil.ignore_tables', []);

        $findings = [];
        $count = 0;

        foreach ($inspector->getAllSchemaTables(null) as $row) {
            $table = (string) ($row['table'] ?? '');

            if ($table === '' || in_array($table, $ignore, true)) {
                continue;
            }

            try {
                $meta = ModelMetadata::fromTable($table, $inspector, $row['schema'] ?? null);
            } catch (\Throwable) {
                continue;
            }

            $path = app_path(str_replace(
                '\\',
                '/',
                (str_starts_with($namespace, 'App\\') ? substr($namespace, 4) : $namespace),
            ) . '/' . $meta->model . '.php');

            if (! is_file($path)) {
                continue;   // not generated yet; anvil:diff is the tool for that
            }

            $count++;
            $result = $auditor->audit($meta, $path, $connection);

            if ($result !== []) {
                $findings[$meta->model] = $result;
            }
        }

        $this->reportAudit($findings, $count);

        return ['findings' => $findings, 'models' => $count];
    }

    /**
     * @param  array<string, list<array<string, string>>>  $findings
     */
    private function reportAudit(array $findings, int $models): void
    {
        if ($this->option('json')) {
            return;
        }

        if ($findings === []) {
            $this->line("  <fg=green>✔</> audit    <fg=gray>{$models} model(s) match their tables</>");

            return;
        }

        $total = array_sum(array_map(count(...), $findings));
        $this->line("  <fg=yellow>▲</> audit    <fg=gray>{$total} finding(s) across " . count($findings) . ' model(s)</>');
        $this->newLine();

        foreach ($findings as $model => $results) {
            $this->line("    <options=bold>{$model}</>");

            foreach ($results as $finding) {
                [$icon, $colour] = match ($finding['severity']) {
                    ModelAuditor::ERROR => ['✘', 'red'],
                    ModelAuditor::WARNING => ['▲', 'yellow'],
                    default => ['•', 'gray'],
                };

                $this->line("      <fg={$colour}>{$icon}</> {$finding['message']}");
                $this->line("        <fg=gray>{$finding['fix']}</>");
            }

            $this->newLine();
        }
    }

    // -----------------------------------------------------------------------
    // Config publishing
    // -----------------------------------------------------------------------

    private function publishConfig(): int
    {
        $files = [
            base_path('rector.php') => $this->rectorConfig(),
            base_path('pint.json') => $this->pintConfig(),
        ];

        foreach ($files as $path => $contents) {
            $name = basename($path);

            if (is_file($path)) {
                $this->line("  <fg=gray>–</> {$name} already exists — not overwritten");

                continue;
            }

            file_put_contents($path, $contents);
            $this->line("  <fg=green>✔</> {$name}");
        }

        $this->newLine();
        $this->line('  <fg=gray>Install what you are missing:</>');
        $this->line('    composer require --dev laravel/pint rector/rector driftingly/rector-laravel larastan/larastan');
        $this->newLine();

        return self::SUCCESS;
    }

    private function rectorConfig(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelLevelSetList;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/app',
        __DIR__ . '/bootstrap',
        __DIR__ . '/config',
        __DIR__ . '/database',
        __DIR__ . '/routes',
        __DIR__ . '/tests',
    ])
    // public/ holds index.php and built assets — nothing worth rewriting, and a
    // rewrite of the front controller is a bad day.
    ->withSkip([
        __DIR__ . '/public',
        __DIR__ . '/bootstrap/cache',
        // Migrations are a historical record. Modernising them changes files that
        // have already run everywhere, for no runtime benefit.
        __DIR__ . '/database/migrations',
    ])
    ->withPhpSets(php84: true)
    ->withSets([
        LaravelLevelSetList::UP_TO_LARAVEL_120,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_IF_HELPERS,
        LaravelSetList::LARAVEL_ARRAY_STR_FUNCTION_TO_STATIC_CALL,
    ])
    ->withTypeCoverageLevel(12)
    ->withDeadCodeLevel(12)
    ->withCodeQualityLevel(12);

PHP;
    }

    private function pintConfig(): string
    {
        return <<<'JSON'
{
    "preset": "laravel",
    "rules": {
        "declare_strict_types": true,
        "ordered_imports": {
            "sort_algorithm": "alpha"
        },
        "no_unused_imports": true,
        "not_operator_with_successor_space": true,
        "trailing_comma_in_multiline": {
            "elements": ["arrays", "arguments", "parameters"]
        },
        "phpdoc_align": {
            "align": "left"
        }
    },
    "exclude": [
        "bootstrap/cache",
        "storage"
    ]
}

JSON;
    }

    // -----------------------------------------------------------------------
    // Exit
    // -----------------------------------------------------------------------

    /**
     * @param  array<string, array<string, mixed>>  $results
     * @param  array<string, list<array<string, string>>>  $findings
     */
    private function exitCode(array $results, array $findings): int
    {
        $failed = array_filter($results, static fn(array $r): bool => $r['ran'] && ! $r['ok']);

        $errors = 0;

        foreach ($findings as $results2) {
            foreach ($results2 as $finding) {
                if ($finding['severity'] === ModelAuditor::ERROR) {
                    $errors++;
                }
            }
        }

        if (! $this->option('json')) {
            $this->newLine();

            if ($failed === [] && $errors === 0) {
                $this->line('  <fg=green>Everything clean.</>');
            } else {
                $this->line(sprintf(
                    '  %d tool(s) reported issues, %d audit error(s).',
                    count($failed),
                    $errors,
                ));
            }

            $this->newLine();
        }

        return ($failed !== [] || $errors > 0) && $this->option('strict')
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function version(): string
    {
        return (string) config('anvil.version', 'dev');
    }
}
