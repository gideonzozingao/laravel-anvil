<?php

use App\Models\Category;
use App\Models\PriceHistory;
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
    |
    | NOTE: web scaffold settings (controller namespace, route file, layout,
    | etc.) live in the single top-level 'web' block below, not here — this
    | key list used to duplicate that block verbatim. WebGenerator should
    | resolve against config('anvil.web'), not a generators.web sub-array.
    |
    | The 'resources' and 'form_requests' namespaces below are also read by
    | anvil:docs-sync to work out where to look for hand-edited payloads, so
    | changing one of them moves discovery with it. See openapi.sync.roots.
    |
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
    | anvil:docs-sync reads 'output_path', 'versioned_output', 'format',
    | 'api_version' and 'split_files' to locate the spec it must reconcile, so
    | those five keys describe one spec for both the writer and the reconciler.
    | Changing 'versioned_output' moves both.
    |
    */
    'openapi' => [
        'title' => env('ANVIL_OPENAPI_TITLE', null),   // null → config('app.name')
        'description' => null,
        'output_path' => 'openapi',
        'versioned_output' => env('ANVIL_OPENAPI_VERSIONED', true),
        'format' => env('ANVIL_OPENAPI_FORMAT', 'yaml'),   // yaml | json
        'split_files' => true,

        // Root document name, without extension. Read by docs-sync to find the
        // file it must open; the generators assume the same name.
        'filename' => env('ANVIL_OPENAPI_FILENAME', 'openapi'),

        'spec_version' => '3.1.0',        // the OpenAPI spec version itself
        'api_version' => env('ANVIL_API_VERSION', 'v1'),   // which API version is being written

        'api_url' => env('APP_URL', 'http://localhost'),   // explicit server URLs; empty → derived from api_url + api.prefix + version
        'security' => env('ANVIL_OPENAPI_SECURITY', 'sanctum'),   // sanctum | passport | bearer | apikey | none

        'contact_name' => null,
        'contact_email' => null,

        // Runtime gates, written by anvil:generate-api. Leave false here: the
        // generators OR these against GenerationOptions so a DTO key mismatch
        // cannot silently no-op the whole spec pipeline.
        'enabled' => false,
        'ui' => false,

        'base_path_location' => 'auto',
        'servers' => [
            ['url' => env('APP_URL', 'http://localhost').'/api/v1', 'description' => 'Local'],
        ],

        /*
        |----------------------------------------------------------------------
        | Payload Reconciliation  (anvil:docs-sync)
        |----------------------------------------------------------------------
        |
        | The generators derive schemas from ModelMetadata — the database. But
        | the payload a client actually sees is shaped by two files the pipeline
        | never reads:
        |
        |   {Model}Resource::toArray()            what the API returns
        |   Store/Update{Model}Request::rules()   what the API accepts
        |
        | Edit either and the spec is silently wrong; regenerating does not help,
        | because --openapi re-derives from the database and cannot see the edit.
        | anvil:docs-sync closes that gap by reading the CODE and merging into the
        | spec: structure from code, prose from the spec.
        |
        | Nothing here needs setting to get started. Every key has a working
        | default and the whole block can be deleted.
        |
        |   php artisan anvil:docs-sync                  reconcile
        |   php artisan anvil:docs-sync --check          CI gate, never writes
        |   php artisan anvil:docs-sync --dry-run --diff preview, per property
        |   php artisan anvil:generate --api --openapi --docs-sync
        |
        */
        'sync' => [

            /*
             |------------------------------------------------------------------
             | Roots
             |------------------------------------------------------------------
             |
             | Directories scanned for payload classes, recursively — so the
             | versioned subdirectories the API scaffold writes (V1/, V2/) are
             | picked up without listing them.
             |
             | null derives them from generators.resources.namespace and
             | generators.form_requests.namespace above, which is why this is the
             | default: those namespaces are already the single source of truth
             | for where payload classes live, and duplicating the paths here
             | would leave docs-sync scanning a directory nothing writes to any
             | more the moment either namespace changes.
             |
             | Set an explicit list for a modular layout, or for resources that
             | live outside the configured namespaces:
             |
             |   'roots' => [
             |       ['path' => app_path('Http/Resources'), 'kind' => 'response'],
             |       ['path' => app_path('Http/Requests'),  'kind' => 'request'],
             |       ['path' => base_path('modules'),       'kind' => 'response'],
             |   ],
             |
             | 'kind' is only a hint. A class ending in Request is always read as
             | a request and one ending in Resource or Collection as a response,
             | whichever root it was found under, so a misfiled class is still
             | read with the correct reader.
             */
            'roots' => null,

            /*
             |------------------------------------------------------------------
             | Schema name overrides
             |------------------------------------------------------------------
             |
             | docs-sync works out which component a class documents by trying an
             | ordered list of candidate names and preferring one that ALREADY
             | EXISTS in the spec. That handles the conventions the generators
             | use without configuration, and — more importantly — stops sync
             | inventing a duplicate VehicleStoreRequest beside the real
             | StoreVehicleRequest, which would leave one of them permanently
             | stale.
             |
             | Add an entry when the guess is wrong. Symptoms: a "Several classes
             | claim this component" skip, or a new schema appearing next to the
             | one you expected to be updated. Key by FQCN or short class name;
             | the value is the exact key in components.schemas.
             */
            'schema_names' => [
                // 'App\Http\Requests\V1\StoreVehicleRequest' => 'VehicleCreatePayload',
                // 'VehicleResource' => 'Vehicle',
            ],

            /*
             |------------------------------------------------------------------
             | Enum namespaces
             |------------------------------------------------------------------
             |
             | Searched when resolving a bare `new Enum(FuelKind::class)` in a
             | form request. Because enums.validation below is 'rule', generated
             | requests reference enum classes by their imported short name.
             |
             | This only matters in the fallback path. docs-sync EXECUTES rules()
             | when it can, and an executed rule object resolves its own cases
             | exactly. It falls back to reading the source when rules() throws
             | without a bound request — normal for update requests that touch
             | $this->route() or $this->user() for a unique-ignore — and tokenised
             | source has no import table, so these namespaces are the only way
             | to find the class.
             |
             | null follows enums.namespace below.
             */
            'enum_namespaces' => null,

            /*
             |------------------------------------------------------------------
             | Custom readers
             |------------------------------------------------------------------
             |
             | Class names implementing Zuqongtech\LaravelAnvil\Contracts\ShapeReader,
             | resolved through the container so they may take constructor
             | dependencies. Declared the same way custom_generators are.
             |
             | The built-ins cover {Model}Resource::toArray() and
             | Store/Update{Model}Request::rules(). Add a reader for anything
             | else — a ResourceCollection subclass, a DTO layer, an inline
             | $request->validate([...]) in a controller.
             |
             | Yours are tried FIRST, so a reader whose supports() claims a class
             | takes it over the built-in while the built-ins still handle
             | everything they otherwise would.
             |
             | This is config rather than a container binding on purpose. A bound
             | DocsSynchronizer is a singleton whose spec directory is fixed at
             | construction, so --api-version=v2 would silently reconcile v1's
             | spec. Declaring readers keeps the version a per-run decision.
             |
             | A class that is missing, or does not implement the contract, throws
             | rather than being skipped — a reader that quietly never runs looks
             | exactly like a reader that ran and found nothing.
             */
            'readers' => [
                // App\Docs\DtoShapeReader::class,
            ],

            /*
             |------------------------------------------------------------------
             | Pruning
             |------------------------------------------------------------------
             |
             | Whether a property documented in the spec but absent from the code
             | is removed. True is correct: deleting a field from toArray() should
             | stop the spec promising it.
             |
             | Three things are never pruned regardless of this setting, because
             | pruning them would lose documentation no one can recover:
             |
             |   - anything read from a partial source (a mergeWhen(), a spread, a
             |     parent::toArray(), a computed key). Absence of evidence is not
             |     evidence of absence.
             |   - any property marked x-anvil: {managed: false}
             |   - anything at all under --check or --dry-run
             |
             | Set false, or pass --no-prune, if you document fields that no
             | resource produces and would rather not mark each one individually.
             */
            'prune' => true,

            /*
             |------------------------------------------------------------------
             | Auto-sync in local development
             |------------------------------------------------------------------
             |
             | Off by default, and worth leaving off. An implicit file write
             | during a web request is surprising, and a spec that changes
             | without a command having been run is hard to reason about in a
             | team — the diff appears with no author.
             |
             | The reliable guards are a pre-commit hook and a CI gate:
             |
             |   php artisan anvil:docs-sync --install-hook
             |   php artisan anvil:docs-sync --check          # in CI
             |
             | If you do enable this, note that filemtime() on a DIRECTORY only
             | changes when entries are added or removed, so editing an existing
             | resource in place will not trigger an mtime-based check. It has to
             | hash the files to be correct.
             */
            'auto' => false,

        ],

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
            'ui_version' => '5.17.14',   // swagger-ui-dist CDN version
            'remote_base' => env('ANVIL_DOCS_REMOTE_BASE', null),
            'remote_timeout' => env('ANVIL_DOCS_REMOTE_TIMEOUT', 5),

            // The Blade view that renders the docs shell. Point this at your own
            // view (e.g. 'anvil::docs.redoc', or a fully custom
            // 'vendor.acme.api-docs') to swap renderers without touching package
            // source. Default resolves to resources/views/docs/show.blade.php in
            // this package, or the published override under
            // resources/views/vendor/anvil/docs/show.blade.php once
            // `php artisan vendor:publish --tag=anvil-views` has run.
            'view' => env('ANVIL_DOCS_VIEW', 'anvil::docs.show'),

            // Local path (relative to the app URL, e.g. '/api-docs/v1/assets') where
            // `php artisan anvil:install:swagger-ui` placed the vendored assets.
            // Leave null to load swagger-ui.css/js from the CDN instead — this key
            // previously existed but was never actually read by DocsController;
            // it now is.
            'asset_base' => env('ANVIL_DOCS_ASSET_BASE'),

            // Route middleware for the docs page and spec endpoints. Add 'auth',
            // a Gate-checking middleware, etc. to gate the docs — this is the
            // reason the page moved off a static public/ file: static files under
            // public/ cannot be gated by Laravel middleware at all.
            //
            // NOTE: this key was previously declared twice in this block. PHP keeps
            // the last literal, so the earlier one was dead — and the production
            // guidance that sat on it was silently doing nothing.
            'middleware' => ['web'],   // production: ['web', 'auth']
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
        'frontend' => [
            'mode' => 'cdn',              // cdn | vite | none — how layouts load Tailwind
            'tailwind_version' => 4,      // used only when installing from scratch
            'livewire_constraint' => '^3.0',
            'css_entrypoint' => 'resources/css/app.css',
            'composer_binary' => 'composer',
            'npm_binary' => 'npm',
            'process_timeout' => 600,
            'backup_before_patch' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Enums
    |--------------------------------------------------------------------------
    |
    | 'validation' decides how a generated form request constrains an enum column:
    |
    |   rule → new Enum(FuelKind::class)   type-safe, refactor-safe
    |   in   → 'in:petrol,diesel,ev'       plain string rule
    |
    | Both are readable by anvil:docs-sync, but by different routes. 'in' is read
    | straight from the rule string. 'rule' is resolved by executing rules() and
    | reflecting the enum — and when rules() cannot be executed, by looking the
    | short class name up in openapi.sync.enum_namespaces, which defaults to the
    | namespace below.
    |
    */
    'enums' => [
        'enabled' => true,
        'namespace' => 'App\\Enums',
        'naming' => 'model_column',
        'validation' => 'rule',   // rule | in
    ],

    'graphql' => [
        'output' => 'graphql',
        'guard' => env('ANVIL_GRAPHQL_GUARD', 'sanctum'),   // '' = public, 'default' = @guard
        'policies' => true,       // emit @can bound to the generated policies
        'mutations' => true,
    ],

    'cache' => [
        'enabled' => env('ANVIL_CACHE', true),
        'store' => null,                     // null = default store
        'prefix' => 'anvil',
        'scope' => 'auth',                   // none | auth | tenant
        'jitter' => 0.1,
        'stale_while_revalidate' => 30,      // 0 = lock-and-recompute instead
        'lock_seconds' => 5,
        'allow_bypass' => env('ANVIL_CACHE_BYPASS', false),
        'ttl' => ['single' => 300, 'list' => 60, 'aggregate' => 30, 'reference' => 3600],
        'profiles' => [
            'reference' => ['ttl' => ['single' => 3600, 'list' => 3600]],
        ],
        'models' => [
            Category::class => ['profile' => 'reference'],
            PriceHistory::class => ['enabled' => false],   // too volatile
        ],
    ],
];
