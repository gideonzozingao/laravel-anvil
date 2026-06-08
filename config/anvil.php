<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Namespace for Generated Models
    |--------------------------------------------------------------------------
    */
    'namespace' => env('DB_INTROSPECTION_NAMESPACE', 'App\\Models'),

    /*
    |--------------------------------------------------------------------------
    | Target Path for Generated Models
    |--------------------------------------------------------------------------
    */
    'target_path' => env('DB_INTROSPECTION_TARGET_PATH', 'app'),

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    */
    'connection' => env('DB_INTROSPECTION_CONNECTION', null),

    /*
    |--------------------------------------------------------------------------
    | Tables to Ignore by Default
    |--------------------------------------------------------------------------
    */
    'ignore_tables' => [
        'migrations',
        'password_resets',
        'password_reset_tokens',
        'failed_jobs',
        'personal_access_tokens',
        'jobs',
        'job_batches',
        'cache',
        'cache_locks',
        'sessions',
        'telescope_entries',
        'telescope_entries_tags',
        'telescope_monitoring',
        'pulse_entries',
        'pulse_aggregates',
        'pulse_values',
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Patterns to Ignore
    |--------------------------------------------------------------------------
    */
    'ignore_table_patterns' => [
        '/^temp_/',
        '/^backup_/',
        '/^_.*/',
    ],

    /*
    |--------------------------------------------------------------------------
    | API Configuration
    |--------------------------------------------------------------------------
    |
    | Controls the prefix and middleware applied to routes written by the
    | ApiRouteGenerator. Override per-environment with .env values.
    |
    */
    'api_version' => env('DB_INTROSPECTION_API_VERSION', 'v1'),

    'api_middleware' => ['auth:sanctum'],

    /*
    |--------------------------------------------------------------------------
    | PHPDoc Generation
    |--------------------------------------------------------------------------
    */
    'with_phpdoc' => env('DB_INTROSPECTION_WITH_PHPDOC', true),

    /*
    |--------------------------------------------------------------------------
    | Inverse Relationships
    |--------------------------------------------------------------------------
    */
    'with_inverse' => env('DB_INTROSPECTION_WITH_INVERSE', true),

    /*
    |--------------------------------------------------------------------------
    | Backup Existing Models
    |--------------------------------------------------------------------------
    */
    'backup_existing' => env('DB_INTROSPECTION_BACKUP', false),

    /*
    |--------------------------------------------------------------------------
    | Force Overwrite Without Prompt
    |--------------------------------------------------------------------------
    */
    'force_overwrite' => env('DB_INTROSPECTION_FORCE', false),

    /*
    |--------------------------------------------------------------------------
    | Dry Run Mode
    |--------------------------------------------------------------------------
    */
    'dry_run' => env('DB_INTROSPECTION_DRY_RUN', false),

    /*
    |--------------------------------------------------------------------------
    | Hidden Fields Detection
    |--------------------------------------------------------------------------
    */
    'hidden_field_patterns' => [
        'password',
        'secret',
        'token',
        'api_key',
        'api_secret',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ],

    /*
    |--------------------------------------------------------------------------
    | Soft Deletes Detection
    |--------------------------------------------------------------------------
    */
    'detect_soft_deletes' => env('DB_INTROSPECTION_SOFT_DELETES', true),

    /*
    |--------------------------------------------------------------------------
    | Timestamp Detection
    |--------------------------------------------------------------------------
    */
    'detect_timestamps' => env('DB_INTROSPECTION_TIMESTAMPS', true),

    /*
    |--------------------------------------------------------------------------
    | Cast Inference
    |--------------------------------------------------------------------------
    */
    'infer_casts' => env('DB_INTROSPECTION_INFER_CASTS', true),

    /*
    |--------------------------------------------------------------------------
    | Date Format
    |--------------------------------------------------------------------------
    */
    'date_format' => env('DB_INTROSPECTION_DATE_FORMAT', null),

    /*
    |--------------------------------------------------------------------------
    | Model Template Path
    |--------------------------------------------------------------------------
    */
    'template_path' => env('DB_INTROSPECTION_TEMPLATE', null),

    /*
    |--------------------------------------------------------------------------
    | Model Base Class
    |--------------------------------------------------------------------------
    */
    'base_model_class' => env('DB_INTROSPECTION_BASE_MODEL', 'Illuminate\\Database\\Eloquent\\Model'),

    /*
    |--------------------------------------------------------------------------
    | Relationship Detection Settings
    |--------------------------------------------------------------------------
    */
    'relationships' => [
        'detect_belongs_to' => env('DB_INTROSPECTION_BELONGS_TO', true),
        'detect_has_many' => env('DB_INTROSPECTION_HAS_MANY', true),
        'detect_many_to_many' => env('DB_INTROSPECTION_MANY_TO_MANY', true),
        'detect_polymorphic' => env('DB_INTROSPECTION_POLYMORPHIC', true),
        'validate_foreign_keys' => env('DB_INTROSPECTION_VALIDATE_FK', false),
        'smart_inverse_detection' => env('DB_INTROSPECTION_SMART_INVERSE', true),
        'typed_relationships' => env('DB_INTROSPECTION_TYPED_RELATIONS', true),
        'max_relationship_depth' => env('DB_INTROSPECTION_MAX_DEPTH', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Naming Conventions
    |--------------------------------------------------------------------------
    */
    'naming' => [
        'singular_models' => true,
        'studly_case_models' => true,
        'camel_case_relationships' => true,
        'camel_case_accessors' => true,
        'pivot_model_prefix' => '',
        'pivot_model_suffix' => 'Pivot',
        'custom_model_names' => [
            // 'user_data' => 'User',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fillable and Guarded Settings
    |--------------------------------------------------------------------------
    */
    'fillable' => [
        'strategy' => env('DB_INTROSPECTION_FILLABLE_STRATEGY', 'auto'),
        'exclude' => ['id', 'created_at', 'updated_at', 'deleted_at'],
        'use_guarded_when_efficient' => true,
        'guarded_threshold' => 0.8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Code Style and Formatting
    |--------------------------------------------------------------------------
    */
    'code_style' => [
        'indent_spaces' => 4,
        'short_array_syntax' => true,
        'trailing_commas' => true,
        'line_length' => 120,
        'blank_line_between_methods' => true,
        'blank_line_before_return' => false,
        'sort_properties' => false,
        'sort_methods' => false,
        'property_visibility_order' => ['protected', 'public', 'private'],
        'strict_types' => env('DB_INTROSPECTION_STRICT_TYPES', false),
        'psr12_compliance' => env('DB_INTROSPECTION_PSR12', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Optimization
    |--------------------------------------------------------------------------
    */
    'performance' => [
        'cache_schema' => env('DB_INTROSPECTION_CACHE', 0),
        'parallel_processing' => env('DB_INTROSPECTION_PARALLEL', false),
        'parallel_workers' => env('DB_INTROSPECTION_WORKERS', null),
        'batch_size' => 50,
        'memory_limit' => env('DB_INTROSPECTION_MEMORY', '512M'),
        'query_timeout' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Advanced Features
    |--------------------------------------------------------------------------
    |
    | These flags are distinct from the CLI generator flags: they control
    | features WITHIN the generated code rather than which files are generated.
    |
    */
    'advanced' => [
        'generate_events' => false, // Adds event dispatch to generated service hooks
        'generate_observers' => false,
        'generate_collections' => false,
        'generate_resources' => false,
        'generate_requests' => false,
        'generate_scopes' => false,
        'generate_traits' => false,
        'use_attributes' => false,
        'generate_enums' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation and Safety
    |--------------------------------------------------------------------------
    */
    'validation' => [
        'validate_class_names' => true,
        'check_existing_files' => true,
        'verify_connection' => true,
        'warn_no_primary_key' => true,
        'warn_no_foreign_keys' => false,
        'confirm_threshold' => 50,
        'skip_invalid_tables' => true,
        'max_table_name_length' => 64,
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging and Output
    |--------------------------------------------------------------------------
    */
    'output' => [
        'verbosity' => env('DB_INTROSPECTION_VERBOSITY', 'normal'),
        'show_progress' => true,
        'show_summary' => true,
        'show_details' => false,
        'log_to_file' => env('DB_INTROSPECTION_LOG', false),
        'log_file' => 'db-introspection.log',
        'show_timestamp' => true,
        'colorize_output' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Type Mappings
    |--------------------------------------------------------------------------
    */
    'type_mappings' => [
        // 'geometry' => 'string',
        // 'point'    => 'array',
        // 'inet'     => 'string',
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Cast Mappings
    |--------------------------------------------------------------------------
    */
    'cast_mappings' => [
        // 'geometry' => 'string',
    ],

    /*
    |--------------------------------------------------------------------------
    | Lifecycle Hooks
    |--------------------------------------------------------------------------
    |
    | Fully qualified class names implementing the respective interfaces.
    | Null = disabled.
    |
    */
    'hooks' => [
        'before_generation' => null,
        'after_model_generated' => null,
        'after_generation' => null,
        'content_transformer' => null,
        'filename_resolver' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Generators
    |--------------------------------------------------------------------------
    |
    | Add your own Generator implementations here. They will be appended to
    | the orchestrator's generator list after all built-in generators.
    |
    | Example:
    |   App\Generators\OpenApiSpecGenerator::class,
    |
    */
    'custom_generators' => [
        // App\Generators\OpenApiSpecGenerator::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-Generator Configuration
    |--------------------------------------------------------------------------
    */
    'generators' => [

        'controllers' => [
            'namespace' => 'App\\Http\\Controllers',
            'base_controller' => 'App\\Http\\Controllers\\Controller',
            'use_resource_controllers' => true,
            'include_validation' => true,
        ],

        'resources' => [
            'namespace' => 'App\\Http\\Resources',
            'exclude_fields' => ['password', 'remember_token', 'api_token', 'secret'],
            'include_relationships' => true,
        ],

        'form_requests' => [
            'namespace' => 'App\\Http\\Requests',
            'authorize_returns' => true,   // default authorize() return value
        ],

        'services' => [
            'namespace' => 'App\\Services',
            'dispatch_events' => true,   // fire Created/Updated/Deleted events
        ],

        'repositories' => [
            'namespace' => 'App\\Repositories',
            'interface_namespace' => 'App\\Repositories\\Contracts',
            'register_provider' => true,   // auto-update RepositoryServiceProvider
        ],

        'gates' => [
            'provider' => 'AuthServiceProvider',  // or 'GateServiceProvider'
            'default_viewany' => true,
            'ownership_column' => 'user_id',
        ],

        'api_routes' => [
            'version' => env('DB_INTROSPECTION_API_VERSION', 'v1'),
            'middleware' => ['auth:sanctum'],
            'route_file' => 'routes/api.php',
        ],

        'factories' => [
            'namespace' => 'Database\\Factories',
            'nullable_probability' => 0.8,    // optional() probability for nullable columns
        ],

        'seeders' => [
            'namespace' => 'Database\\Seeders',
            'dev_count' => 50,
            'staging_count' => 10,
            'production_count' => 0,
            'register_in_db_seeder' => true,
        ],

        'migrations' => [
            'include_foreign_keys' => true,
            'include_indexes' => true,
        ],

        'events' => [
            'namespace' => 'App\\Events',
            'include_broadcast_stub' => true,   // Adds commented-out broadcastOn()
        ],

        'observers' => [
            'namespace' => 'App\\Observers',
            'auto_register' => false,
            'include_soft_delete_events' => true,
        ],

        'policies' => [
            'namespace' => 'App\\Policies',
            'auto_register' => false,
            'detect_ownership' => true,
            'ownership_column' => 'user_id',
        ],

        'tests' => [
            'namespace' => 'Tests\\Feature',
            'use_pest' => false,   // set true to emit Pest-style tests
            'auth_guard' => 'sanctum',
        ],

        'web' => [
            'controller_namespace' => 'App\\Http\\Controllers\\Web',
            'route_file' => 'routes/web.php',
            'middleware' => ['web', 'auth'],   // routes wrapped in this group
            'layout' => 'layouts.anvil',   // views @extends this
            'generate_layout' => true,              // emit a Tailwind-CDN base layout once
        ],

    ],
    'openapi' => [
        'title' => env('ANVIL_OPENAPI_TITLE', 'Laravel Anvil'),
        'output_path' => 'openapi',
        'format' => 'yaml',        // yaml | json
        'split_files' => true,
        'version' => '3.1.0',
        'api_url' => env('APP_URL', 'http://localhost'),
        'security' => 'sanctum',     // sanctum | passport | none
        'publish_ui' => false,
        'docs' => [
            'enabled' => env('ANVIL_DOCS_ENABLED', true),
            'route' => env('ANVIL_DOCS_ROUTE', 'docs'),   // serves /docs and /docs/{file}
            'middleware' => ['web'],                            // add 'auth' to gate it in prod
            'ui_version' => '5.17.14',                          // swagger-ui-dist CDN version
        ],
    ],
    'web' => [
        'controller_namespace' => 'App\\Http\\Controllers\\Web',
        'route_file' => 'routes/web.php',
        'middleware' => ['web', 'auth'],   // routes wrapped in this group
        'layout' => 'layouts.anvil',   // views @extends this
        'generate_layout' => true,              // emit a Tailwind-CDN base layout once
    ],

];
