<?php

namespace Zuqongtech\LaravelAnvil\Support;

class ConfigValidator
{
    protected array $errors = [];

    protected array $warnings = [];

    /**
     * Validate all configuration options
     */
    public function validate(): bool
    {
        $this->errors = [];
        $this->warnings = [];

        $this->validateNamespace();
        $this->validateTargetPath();
        $this->validateConnection();
        $this->validateIgnoreTables();
        $this->validateRelationships();
        $this->validateNaming();
        $this->validateCodeStyle();
        $this->validatePerformance();
        $this->validateAdvanced();
        $this->validateValidation();
        $this->validateOutput();
        $this->validateTypeMappings();
        $this->validateHooks();

        return $this->errors === [];
    }

    /**
     * Validate namespace configuration
     */
    protected function validateNamespace(): void
    {
        $namespace = config('anvil.namespace');

        if (empty($namespace)) {
            $this->addError('namespace', 'Namespace cannot be empty');

            return;
        }

        if (! is_string($namespace)) {
            $this->addError('namespace', 'Namespace must be a string');

            return;
        }

        // Validate namespace format
        if (! preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff\\\\]*$/', $namespace)) {
            $this->addError('namespace', 'Invalid namespace format: '.$namespace);
        }

        // Check for leading/trailing backslashes
        if (str_starts_with($namespace, '\\') || str_ends_with($namespace, '\\')) {
            $this->addWarning('namespace', 'Namespace should not have leading or trailing backslashes');
        }
    }

    /**
     * Validate target path configuration
     */
    protected function validateTargetPath(): void
    {
        $path = config('anvil.target_path');

        if (empty($path)) {
            $this->addError('target_path', 'Target path cannot be empty');

            return;
        }

        if (! is_string($path)) {
            $this->addError('target_path', 'Target path must be a string');

            return;
        }

        // Check if path is writable
        $fullPath = base_path($path);
        if (! is_dir($fullPath) && ! @mkdir($fullPath, 0755, true)) {
            $this->addWarning('target_path', 'Target path is not writable: '.$path);
        }
    }

    /**
     * Validate database connection
     */
    protected function validateConnection(): void
    {
        $connection = config('anvil.connection');

        if ($connection !== null) {
            $availableConnections = array_keys(config('database.connections', []));

            if (! in_array($connection, $availableConnections)) {
                $this->addError('connection', sprintf("Database connection '%s' is not configured", $connection));
            }
        }
    }

    /**
     * Validate ignore tables configuration
     */
    protected function validateIgnoreTables(): void
    {
        $ignoreTables = config('anvil.ignore_tables', []);

        if (! is_array($ignoreTables)) {
            $this->addError('ignore_tables', 'Ignore tables must be an array');

            return;
        }

        foreach ($ignoreTables as $table) {
            if (! is_string($table) || ($table === '' || $table === '0')) {
                $this->addWarning('ignore_tables', 'Invalid table name in ignore list');
            }
        }

        // Validate ignore patterns
        $patterns = config('anvil.ignore_table_patterns', []);

        if (! is_array($patterns)) {
            $this->addError('ignore_table_patterns', 'Ignore table patterns must be an array');

            return;
        }

        foreach ($patterns as $pattern) {
            if (! is_string($pattern)) {
                $this->addWarning('ignore_table_patterns', 'Invalid pattern in ignore list');

                continue;
            }

            // Test if pattern is valid regex
            if (@preg_match($pattern, '') === false) {
                $this->addError('ignore_table_patterns', 'Invalid regex pattern: '.$pattern);
            }
        }
    }

    /**
     * Validate relationships configuration
     */
    protected function validateRelationships(): void
    {
        $relationships = config('anvil.relationships', []);

        if (! is_array($relationships)) {
            $this->addError('relationships', 'Relationships configuration must be an array');

            return;
        }

        $booleanOptions = [
            'detect_belongs_to',
            'detect_has_many',
            'detect_many_to_many',
            'detect_polymorphic',
            'validate_foreign_keys',
            'smart_inverse_detection',
            'typed_relationships',
        ];

        foreach ($booleanOptions as $option) {
            $value = $relationships[$option] ?? null;
            if ($value !== null && ! is_bool($value)) {
                $this->addError('relationships.'.$option, 'Must be a boolean value');
            }
        }

        // Validate max depth
        $maxDepth = $relationships['max_relationship_depth'] ?? 3;
        if (! is_int($maxDepth) || $maxDepth < 1 || $maxDepth > 10) {
            $this->addError('relationships.max_relationship_depth', 'Must be an integer between 1 and 10');
        }
    }

    /**
     * Validate naming conventions
     */
    protected function validateNaming(): void
    {
        $naming = config('anvil.naming', []);

        if (! is_array($naming)) {
            $this->addError('naming', 'Naming configuration must be an array');

            return;
        }

        $customNames = $naming['custom_model_names'] ?? [];

        if (! is_array($customNames)) {
            $this->addError('naming.custom_model_names', 'Custom model names must be an array');

            return;
        }

        foreach ($customNames as $table => $model) {
            if (! is_string($table) || ! is_string($model)) {
                $this->addWarning('naming.custom_model_names', 'Invalid custom model name mapping');

                continue;
            }

            if (! Helpers::isValidClassName($model)) {
                $this->addError('naming.custom_model_names', 'Invalid model class name: '.$model);
            }
        }
    }

    /**
     * Validate code style configuration
     */
    protected function validateCodeStyle(): void
    {
        $codeStyle = config('anvil.code_style', []);

        if (! is_array($codeStyle)) {
            $this->addError('code_style', 'Code style configuration must be an array');

            return;
        }

        // Validate indent spaces
        $indentSpaces = $codeStyle['indent_spaces'] ?? 4;
        if (! is_int($indentSpaces) || $indentSpaces < 2 || $indentSpaces > 8) {
            $this->addError('code_style.indent_spaces', 'Indent spaces must be between 2 and 8');
        }

        // Validate line length
        $lineLength = $codeStyle['line_length'] ?? 120;
        if (! is_int($lineLength) || ($lineLength > 0 && $lineLength < 80)) {
            $this->addWarning('code_style.line_length', 'Line length should be at least 80 or 0 for no limit');
        }

        // Validate visibility order
        $visibilityOrder = $codeStyle['property_visibility_order'] ?? [];
        if (! is_array($visibilityOrder)) {
            $this->addError('code_style.property_visibility_order', 'Visibility order must be an array');
        } else {
            $validVisibilities = ['public', 'protected', 'private'];
            foreach ($visibilityOrder as $visibility) {
                if (! in_array($visibility, $validVisibilities)) {
                    $this->addError('code_style.property_visibility_order', 'Invalid visibility: '.$visibility);
                }
            }
        }
    }

    /**
     * Validate performance configuration
     */
    protected function validatePerformance(): void
    {
        $performance = config('anvil.performance', []);

        if (! is_array($performance)) {
            $this->addError('performance', 'Performance configuration must be an array');

            return;
        }

        // Validate cache schema
        $cacheSchema = $performance['cache_schema'] ?? 0;
        if (! is_int($cacheSchema) || $cacheSchema < 0) {
            $this->addError('performance.cache_schema', 'Cache schema must be a non-negative integer');
        }

        // Validate parallel processing
        $parallel = $performance['parallel_processing'] ?? false;
        if ($parallel && ! extension_loaded('pcntl')) {
            $this->addWarning('performance.parallel_processing', 'Parallel processing requires pcntl extension');
        }

        // Validate parallel workers
        $workers = $performance['parallel_workers'] ?? null;
        if ($workers !== null && (! is_int($workers) || $workers < 1)) {
            $this->addError('performance.parallel_workers', 'Parallel workers must be a positive integer or null');
        }

        // Validate batch size
        $batchSize = $performance['batch_size'] ?? 50;
        if (! is_int($batchSize) || $batchSize < 1) {
            $this->addError('performance.batch_size', 'Batch size must be a positive integer');
        }

        // Validate memory limit
        $memoryLimit = $performance['memory_limit'] ?? '512M';
        if (! preg_match('/^\d+[KMG]?$/i', (string) $memoryLimit)) {
            $this->addError('performance.memory_limit', 'Invalid memory limit format (e.g., 512M, 1G)');
        }

        // Validate query timeout
        $timeout = $performance['query_timeout'] ?? 30;
        if (! is_int($timeout) || $timeout < 1) {
            $this->addError('performance.query_timeout', 'Query timeout must be a positive integer');
        }
    }

    /**
     * Validate advanced features
     */
    protected function validateAdvanced(): void
    {
        $advanced = config('anvil.advanced', []);

        if (! is_array($advanced)) {
            $this->addError('advanced', 'Advanced configuration must be an array');

            return;
        }

        // Check PHP version for attributes
        if (($advanced['use_attributes'] ?? false) && PHP_VERSION_ID < 80000) {
            $this->addError('advanced.use_attributes', 'Attributes require PHP 8.0 or higher');
        }

        // Check PHP version for enums
        if (($advanced['generate_enums'] ?? false) && PHP_VERSION_ID < 80100) {
            $this->addError('advanced.generate_enums', 'Enums require PHP 8.1 or higher');
        }
    }

    /**
     * Validate validation settings
     */
    protected function validateValidation(): void
    {
        $validation = config('anvil.validation', []);

        if (! is_array($validation)) {
            $this->addError('validation', 'Validation configuration must be an array');

            return;
        }

        // Validate confirm threshold
        $threshold = $validation['confirm_threshold'] ?? 50;
        if (! is_int($threshold) || $threshold < 1) {
            $this->addError('validation.confirm_threshold', 'Confirm threshold must be a positive integer');
        }

        // Validate max table name length
        $maxLength = $validation['max_table_name_length'] ?? 64;
        if (! is_int($maxLength) || $maxLength < 1) {
            $this->addError('validation.max_table_name_length', 'Max table name length must be a positive integer');
        }
    }

    /**
     * Validate output configuration
     */
    protected function validateOutput(): void
    {
        $output = config('anvil.output', []);

        if (! is_array($output)) {
            $this->addError('output', 'Output configuration must be an array');

            return;
        }

        // Validate verbosity
        $verbosity = $output['verbosity'] ?? 'normal';
        $validLevels = ['quiet', 'normal', 'verbose', 'debug'];

        if (! in_array($verbosity, $validLevels)) {
            $this->addError('output.verbosity', 'Invalid verbosity level. Must be one of: '.implode(', ', $validLevels));
        }

        // Validate log file
        if ($output['log_to_file'] ?? false) {
            $logFile = $output['log_file'] ?? 'db-introspection.log';
            $logPath = storage_path('logs/'.$logFile);
            $logDir = dirname($logPath);

            if (! is_dir($logDir) && ! @mkdir($logDir, 0755, true)) {
                $this->addWarning('output.log_file', 'Log directory is not writable: '.$logDir);
            }
        }
    }

    /**
     * Validate type mappings
     */
    protected function validateTypeMappings(): void
    {
        $typeMappings = config('anvil.type_mappings', []);

        if (! is_array($typeMappings)) {
            $this->addError('type_mappings', 'Type mappings must be an array');

            return;
        }

        $validPhpTypes = ['int', 'float', 'string', 'bool', 'array', 'mixed'];

        foreach ($typeMappings as $dbType => $phpType) {
            if (! is_string($dbType) || ! is_string($phpType)) {
                $this->addWarning('type_mappings', 'Invalid type mapping');

                continue;
            }

            if (! in_array($phpType, $validPhpTypes, true)) {
                $this->addWarning('type_mappings', 'Unusual PHP type: '.$phpType);
            }
        }

        // Validate cast mappings
        $castMappings = config('anvil.cast_mappings', []);

        if (! is_array($castMappings)) {
            $this->addError('cast_mappings', 'Cast mappings must be an array');
        }
    }

    /**
     * Validate hooks configuration
     */
    protected function validateHooks(): void
    {
        $hooks = config('anvil.hooks', []);

        if (! is_array($hooks)) {
            $this->addError('hooks', 'Hooks configuration must be an array');

            return;
        }

        $hookNames = [
            'before_generation',
            'after_model_generated',
            'after_generation',
            'content_transformer',
            'filename_resolver',
        ];

        foreach ($hookNames as $hookName) {
            $hook = $hooks[$hookName] ?? null;

            if ($hook !== null) {
                if (! is_string($hook)) {
                    $this->addError('hooks.'.$hookName, 'Hook must be a string (class name) or null');

                    continue;
                }

                if (! class_exists($hook)) {
                    $this->addError('hooks.'.$hookName, 'Hook class does not exist: '.$hook);
                }
            }
        }
    }

    /**
     * Add an error
     */
    protected function addError(string $key, string $message): void
    {
        $this->errors[$key] = $message;
    }

    /**
     * Add a warning
     */
    protected function addWarning(string $key, string $message): void
    {
        $this->warnings[$key] = $message;
    }

    /**
     * Get all errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get all warnings
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Check if there are any errors
     */
    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Check if there are any warnings
     */
    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    /**
     * Get formatted error messages
     */
    public function getFormattedErrors(): array
    {
        return array_map(
            fn (string $key, $message): string => sprintf('[%s] %s', $key, $message),
            array_keys($this->errors),
            $this->errors
        );
    }

    /**
     * Get formatted warning messages
     */
    public function getFormattedWarnings(): array
    {
        return array_map(
            fn (string $key, $message): string => sprintf('[%s] %s', $key, $message),
            array_keys($this->warnings),
            $this->warnings
        );
    }

    /**
     * Validate and throw exception if invalid
     */
    public function validateOrFail(): void
    {
        if (! $this->validate()) {
            $messages = implode("\n", $this->getFormattedErrors());
            throw new \InvalidArgumentException('Configuration validation failed:
'.$messages);
        }
    }

    /**
     * Get validation summary
     */
    public function getSummary(): array
    {
        return [
            'valid' => $this->errors === [],
            'errors' => count($this->errors),
            'warnings' => count($this->warnings),
            'error_messages' => $this->getFormattedErrors(),
            'warning_messages' => $this->getFormattedWarnings(),
        ];
    }
}
