<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Auth;

use Illuminate\Support\Str;

/**
 * Every filesystem write the scaffold performs, plus the result log.
 *
 * Pulling this out of the templates class means dry-run, force, backup and
 * failure reporting are decided in exactly one place, and a part cannot
 * accidentally write directly.
 */
final class ScaffoldWriter
{
    /** @var list<array{type: string, name: string, status: string, reason?: string, path?: string}> */
    private array $results = [];

    public function __construct(
        private readonly AuthContext $context,
        private readonly TokenMap $tokens,
    ) {}

    public function tokens(): TokenMap
    {
        return $this->tokens;
    }

    // -----------------------------------------------------------------------
    // Targets
    // -----------------------------------------------------------------------

    /** A Livewire component class plus its Blade view. */
    public function component(string $class, string $php, string $view): void
    {
        $this->file($this->context->componentPath($class), $php, 'Component', $class);

        $slug = Str::kebab($class);
        $this->file(
            resource_path("views/livewire/auth/{$slug}.blade.php"),
            $view,
            'View',
            "auth/{$slug}",
        );
    }

    public function appFile(string $relative, string $content, string $type, string $name): void
    {
        $this->file(app_path($relative), $content, $type, $name);
    }

    public function viewFile(string $relative, string $content, string $name): void
    {
        $this->file(resource_path('views/'.$relative), $content, 'Layout', $name);
    }

    public function baseFile(string $relative, string $content, string $type, string $name): void
    {
        $this->file(base_path($relative), $content, $type, $name);
    }

    /**
     * A migration, skipped when an equivalent one already exists.
     *
     * The old migrationPath() embedded date('Y_m_d_His'), so the exists-check
     * never matched a previous run's file: running the command twice produced two
     * migrations adding the same columns, and the second `migrate` died on
     * "column already exists". Matching on the descriptive suffix instead of the
     * full filename makes the write genuinely idempotent.
     */
    public function migration(string $descriptor, string $content, string $name): void
    {
        $existing = glob(database_path("migrations/*_{$descriptor}.php")) ?: [];

        if ($existing !== [] && ! $this->context->force) {
            $this->results[] = [
                'type' => 'Migration',
                'name' => $name,
                'status' => 'skipped',
                'reason' => 'already exists: '.basename($existing[0]),
                'path' => $existing[0],
            ];

            return;
        }

        // On --force, rewrite the existing file rather than adding a second one
        // with a newer timestamp.
        $path = $existing[0] ?? database_path('migrations/'.date('Y_m_d_His').'_'.$descriptor.'.php');

        $this->file($path, $content, 'Migration', $name);
    }

    // -----------------------------------------------------------------------
    // Write
    // -----------------------------------------------------------------------

    public function file(string $path, string $content, string $type, string $name): void
    {
        $exists = file_exists($path);

        // Checked before the dry-run branch: a dry run over an existing file
        // would otherwise report "would write" where the real run would skip.
        if ($exists && ! $this->context->force) {
            $this->record($type, $name, 'skipped', 'exists', $path);

            return;
        }

        if ($this->context->dryRun) {
            $this->record($type, $name, 'dry-run', $exists ? 'would overwrite' : 'would create', $path);

            return;
        }

        try {
            if ($exists && $this->context->backup) {
                @copy($path, $path.'.'.date('YmdHis').'.bak');
            }

            $dir = dirname($path);

            throw_if(! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir), \RuntimeException::class, "could not create {$dir}");

            throw_if(file_put_contents($path, $content) === false, \RuntimeException::class, 'file_put_contents() returned false');

            $this->record($type, $name, 'success', $exists ? 'overwritten' : 'created', $path);
        } catch (\Throwable $e) {
            $this->record($type, $name, 'failed', $e->getMessage(), $path);
        }
    }

    /** Record something that is not a file — a warning, a deferred step. */
    public function note(string $type, string $name, string $status, string $reason): void
    {
        $this->record($type, $name, $status, $reason);
    }

    /**
     * @return list<array{type: string, name: string, status: string, reason?: string, path?: string}>
     */
    public function results(): array
    {
        return $this->results;
    }

    private function record(string $type, string $name, string $status, ?string $reason, ?string $path = null): void
    {
        $entry = ['type' => $type, 'name' => $name, 'status' => $status];

        if ($reason !== null) {
            $entry['reason'] = $reason;
        }

        if ($path !== null) {
            $entry['path'] = $path;
        }

        $this->results[] = $entry;
    }
}
