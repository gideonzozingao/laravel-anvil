<?php

use Illuminate\Database\Eloquent\Model;

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
    | Versioned API Scaffold  (anvil:generate-api)
    |--------------------------------------------------------------------------
    |
    | Project-level defaults for the versioned JSON API. Every value here is
    | overridden at runtime by the matching flag on anvil:generate-api, so this
    | block is what you get when the flag is omitted.
    |
    | 'auth' is the seam that ties the runtime API to its documentation: it maps
    | to route middleware here AND to the spec's securityScheme below, so the
    | two cannot drift. Valid values: sanctum | passport | jwt | token | none.
    |
    */
    'api' => [
        'version' => env('ANVIL_API_VERSION', 'v1'),
        'prefix' => env('ANVIL_API_PREFIX', 'api'),
        'auth' => env('ANVIL_API_AUTH', 'sanctum'),
        'guard' => env('ANVIL_API_GUARD', null),   // null = the guard implied by 'auth'
        'throttle' => env('ANVIL_API_THROTTLE', '60,1'),   // "60,1", "120", or 'none'
        'pagination' => env('ANVIL_API_PAGINATION', 15),
        'force_json' => true,   // generate ForceJsonResponse middleware + provider

        // Computed by anvil:generate-api from auth/guard/throttle, and read by
        // the route + controller generators. Editing it here sets the baseline.
        'middleware' => ['api', 'auth:sanctum', 'throttle:60,1'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy API keys  (ApiRouteGenerator — unversioned --api-routes)
    |--------------------------------------------------------------------------
    |
    | Kept for the plain apiResource append. The 'api' block above supersedes
    | these for the versioned scaffold; they are read only by ApiRouteGenerator.
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
    | Runtime Flags  (written by the commands — do not rely on editing these)
    |--------------------------------------------------------------------------
    |
    | The generators read these as a fallback when GenerationOptions does not
    | carry the corresponding field. anvil:generate-api sets them from --force /
    | --dry-run at the start of every run. They exist so a DTO key mismatch
    | cannot silently turn a generator off; see ResolvesSpecOptions.
    |
    */
    'runtime' => [
        'dry_run' => false,
        'force' => false,
    ],

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
    'base_model_class' => env('DB_INTROSPECTION_BASE_MODEL', Model::class),

    /*
    |--------------------------------------------------------------------------
    | Relationship Detection Settings
    |--------------------------------------------------------------------------
    |
    | 'inverse_naming' decides how a hasMany is named when the child table points
    | at the same parent more than once — vehicle_bookings.customer_id and
    | vehicle_bookings.assigned_agent_id both referencing users:
    |
    |   prefix → customerVehicleBookings() / assignedAgentVehicleBookings()
    |   suffix → vehicleBookingsCustomer() / vehicleBookingsAssignedAgent()
    |
    | Without a qualifier both would be named vehicleBookings(), which is a fatal
    | redeclaration. A single key to a parent is never qualified.
    |
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
        'inverse_naming' => env('ANVIL_INVERSE_NAMING', 'prefix'),   // prefix | suffix
    ],

    /*
    |--------------------------------------------------------------------------
    | Domain Events & Listeners
    |--------------------------------------------------------------------------
    |
    | Read by EventGenerator and ListenerGenerator. anvil:generate overwrites
    | 'listeners', 'listener_style' and 'queued_listeners' at runtime from
    | --listeners / --listener-style / --queued-listeners.
    |
    | listener_style:
    |   per-event  → App\Listeners\{Model}\CreatedListener, one class per event
    |   subscriber → App\Listeners\{Model}EventSubscriber, one class per model
    |
    | Laravel 11+ auto-discovers listeners under app/Listeners; subscribers must
    | be registered manually (the generated class documents the call).
    |
    */
    'events' => [
        'namespace' => 'App\\Events',
        'listeners' => false,
        'listener_namespace' => 'App\\Listeners',
        'listener_style' => env('ANVIL_LISTENER_STYLE', 'per-event'),   // per-event | subscriber
        'queued_listeners' => false,   // implement ShouldQueue (per-event style only)
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
    | Add your own Generator implementations here. A flat list is appended to
    | the core group; a group-keyed map targets a specific pipeline slice.
    |
    | Example:
    |   App\Generators\SidecarGenerator::class,
    |   'openapi' => [App\Generators\WebhookPathGenerator::class],
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

        'listeners' => [
            'namespace' => 'App\\Listeners',
            'include_failed_hook' => true,   // failed() method on queued listeners
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

    /*
    |--------------------------------------------------------------------------
    | OpenAPI Specification
    |--------------------------------------------------------------------------
    |
    | OUTPUT LAYOUT — 'versioned_output' puts each API version in its own
    | directory so v1 and v2 coexist:
    |
    |   openapi/v1/openapi.yaml + schemas/ + paths/
    |   openapi/v2/openapi.yaml + schemas/ + paths/
    |
    | Set it to false for the flat pre-versioning layout (openapi/openapi.yaml).
    | Existing installs upgrading to versioned output should move their files:
    |
    |   mkdir -p openapi/v1 && git mv openapi/openapi.yaml openapi/schemas \
    |       openapi/paths openapi/v1/
    |
    | 'title' is null so it falls back to config('app.name'). Setting a literal
    | here means every project's spec is titled the same thing.
    |
    */
    'openapi' => [
        'title' => env('ANVIL_OPENAPI_TITLE', null),   // null → config('app.name')
        'description' => null,
        'output_path' => 'openapi',
        'versioned_output' => env('ANVIL_OPENAPI_VERSIONED', true),
        'format' => env('ANVIL_OPENAPI_FORMAT', 'yaml'),   // yaml | json
        'split_files' => true,

        'spec_version' => '3.1.0',        // the OpenAPI spec version itself
        'api_version' => env('ANVIL_API_VERSION', 'v1'),   // which API version is being written

        'api_url' => env('APP_URL', 'http://localhost'),
        'servers' => [],   // explicit server URLs; empty → derived from api_url + api.prefix + version
        'security' => env('ANVIL_OPENAPI_SECURITY', 'sanctum'),   // sanctum | passport | bearer | apikey | none

        'contact_name' => null,
        'contact_email' => null,

        // Runtime gates, written by anvil:generate-api. Leave false here: the
        // generators OR these against GenerationOptions so a DTO key mismatch
        // cannot silently no-op the whole spec pipeline.
        'enabled' => false,
        'ui' => false,

        /*
        |----------------------------------------------------------------------
        | Interactive Docs
        |----------------------------------------------------------------------
        |
        | 'route' is served dynamically by DocsController, which bundles the
        | split $ref files into one document on the fly:
        |
        |   /docs                      Swagger UI, default version
        |   /docs/v1                   Swagger UI for v1
        |   /docs/v1/openapi.yaml      the bundled root spec
        |   /docs/v1/schemas/User.yaml a raw split file
        |
        | 'public_path' is where --ui writes a STATIC bundle, and it MUST NOT
        | equal 'route'. Publishing to public/docs makes that directory exist on
        | disk, and both `php artisan serve` and an nginx try_files block then
        | hand /docs to the static handler instead of PHP — the route silently
        | stops working and you get the web server's own 404.
        |
        | 'remote_base' is for a spec published elsewhere (a CDN, a docs bucket,
        | another service). Null — the default — reads from output_path on local
        | disk. Do NOT point it at this application's own URL: that makes the app
        | HTTP-fetch from itself and breaks whenever app.url is not the address
        | actually being served.
        |
        | SECURITY: 'enabled' defaults to true only in local. Anywhere else, add
        | 'auth' (or a signed/IP middleware) to 'middleware' before enabling it —
        | the docs describe every endpoint you have.
        |
        */
        'docs' => [
            'enabled' => env('ANVIL_DOCS_ENABLED', env('APP_ENV', 'production') === 'local'),
            'route' => env('ANVIL_DOCS_ROUTE', 'docs'),
            'public_path' => env('ANVIL_DOCS_PUBLIC_PATH', 'api-docs'),
            'middleware' => ['web'],   // production: ['web', 'auth']
            'ui_version' => '5.17.14',   // swagger-ui-dist CDN version
            'remote_base' => env('ANVIL_DOCS_REMOTE_BASE', null),
            'remote_timeout' => env('ANVIL_DOCS_REMOTE_TIMEOUT', 5),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Web Scaffold
    |--------------------------------------------------------------------------
    */
    'web' => [
        'controller_namespace' => 'App\\Http\\Controllers\\Web',
        'route_file' => 'routes/web.php',
        'middleware' => ['web', 'auth'],   // routes wrapped in this group
        'layout' => 'layouts.anvil',   // views @extends this
        'generate_layout' => true,              // emit a Tailwind-CDN base layout once
        'generate_nav' => true,
        'livewire' => [
            'namespace' => 'App\\Livewire',
        ],
    ],

];
