<?php

namespace Zuqongtech\LaravelAnvil\Support;

use Illuminate\Filesystem\Filesystem;

class FileWriter
{
    protected Filesystem $files;

    protected string $basePath;

    public function __construct(?string $basePath = null, protected bool $dryRun = false)
    {
        $this->files = new Filesystem;
        $this->basePath = $basePath ?? base_path();
    }

    /**
     * Write model to file
     */
    public function writeModel(string $content, string $namespace, string $modelName, string $targetPath): array
    {
        $filePath = $this->getFilePath($namespace, $modelName, $targetPath);
        $directory = dirname($filePath);

        $result = [
            'path' => $filePath,
            'relative_path' => str_replace($this->basePath.'/', '', $filePath),
            'existed' => $this->files->exists($filePath),
            'written' => false,
            'dry_run' => $this->dryRun,
        ];

        if ($this->dryRun) {
            $result['message'] = 'Dry run - file not written';

            return $result;
        }

        // Ensure directory exists
        if (! $this->files->isDirectory($directory)) {
            $this->files->makeDirectory($directory, 0755, true);
        }

        // Write file
        $written = $this->files->put($filePath, $content);
        $result['written'] = $written !== false;
        $result['bytes'] = $written;

        $result['message'] = $result['existed'] ? 'Model overwritten' : 'Model created';

        return $result;
    }

    /**
     * Get file path for model
     */
    protected function getFilePath(string $namespace, string $modelName, string $targetPath): string
    {
        $namespacePath = Helpers::namespaceToPath($namespace, $targetPath);
        $fullPath = $this->basePath.'/'.$targetPath.'/'.$namespacePath;

        return $fullPath.'/'.$modelName.'.php';
    }

    /**
     * Check if model file exists
     */
    public function modelExists(string $namespace, string $modelName, string $targetPath): bool
    {
        $filePath = $this->getFilePath($namespace, $modelName, $targetPath);

        return $this->files->exists($filePath);
    }

    /**
     * Backup existing model
     */
    public function backupModel(string $namespace, string $modelName, string $targetPath): ?string
    {
        $filePath = $this->getFilePath($namespace, $modelName, $targetPath);

        if (! $this->files->exists($filePath)) {
            return null;
        }

        $backupPath = $filePath.'.backup.'.date('YmdHis');
        $this->files->copy($filePath, $backupPath);

        return $backupPath;
    }
}
