# Laravel Anvil

**Full Laravel scaffold generation via live database introspection.**

[![PHP](https://img.shields.io/badge/PHP-%5E8.2-8892BF.svg)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-11.x%20%7C%2012.x-FF2D20.svg)](https://laravel.com)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE.md)
[![Packagist](https://img.shields.io/packagist/v/zuqongtech/laravel-anvil.svg)](https://packagist.org/packages/zuqongtech/laravel-anvil)

---

## Table of Contents

1. [Introduction](#1-introduction)
2. [Requirements](#2-requirements)
3. [Installation](#3-installation)
4. [Configuration](#4-configuration)
5. [Quick Start](#5-quick-start)
6. [Command Reference](#6-command-reference)
   - 6.1 [Core Flags](#61-core-flags)
   - 6.2 [Artifact Flags](#62-artifact-flags)
   - 6.3 [Versioned API Flag](#63-versioned-api-flag)
   - 6.4 [Behaviour Flags](#64-behaviour-flags)
   - 6.5 [Table Selection Flags](#65-table-selection-flags)
7. [Generated Artifacts](#7-generated-artifacts)
   - 7.1 [Eloquent Models](#71-eloquent-models)
   - 7.2 [Controllers](#72-controllers)
   - 7.3 [API Resources](#73-api-resources)
   - 7.4 [Form Requests](#74-form-requests)
   - 7.5 [Services](#75-services)
   - 7.6 [Repositories](#76-repositories)
   - 7.7 [Policies](#77-policies)
   - 7.8 [Gates](#78-gates)
   - 7.9 [Observers](#79-observers)
   - 7.10 [Events](#710-events)
   - 7.11 [Factories](#711-factories)
   - 7.12 [Seeders](#712-seeders)
   - 7.13 [Migrations](#713-migrations)
   - 7.14 [Feature Tests](#714-feature-tests)
8. [Versioned JSON API Scaffold](#8-versioned-json-api-scaffold)
   - 8.1 [Overview](#81-overview)
   - 8.2 [Directory Structure](#82-directory-structure)
   - 8.3 [ForceJsonResponse Middleware](#83-forcejsonresponse-middleware)
   - 8.4 [ForceJsonApiServiceProvider](#84-forcejsonapiserviceprovider)
   - 8.5 [Versioned Route Files](#85-versioned-route-files)
   - 8.6 [Exception Handling](#86-exception-handling)
   - 8.7 [Multi-version Workflow](#87-multi-version-workflow)
9. [Architecture](#9-architecture)
   - 9.1 [Package Structure](#91-package-structure)
   - 9.2 [Generator Contract](#92-generator-contract)
   - 9.3 [GenerationOrchestrator](#93-generationorchestrator)
   - 9.4 [ModelMetadata DTO](#94-modelmetadata-dto)
   - 9.5 [GenerationOptions DTO](#95-generationoptions-dto)
   - 9.6 [DatabaseInspector](#96-databaseinspector)
   - 9.7 [RelationshipDetector](#97-relationshipdetector)
   - 9.8 [ConstraintAnalyzer](#98-constraintanalyzer)
10. [Database Driver Support](#10-database-driver-support)
11. [Extending Anvil](#11-extending-anvil)
    - 11.1 [Writing a Custom Generator](#111-writing-a-custom-generator)
    - 11.2 [Registering a Custom Generator](#112-registering-a-custom-generator)
    - 11.3 [Lifecycle Hooks](#113-lifecycle-hooks)
    - 11.4 [Custom Model Templates](#114-custom-model-templates)
12. [Schema Analysis Tools](#12-schema-analysis-tools)
13. [Naming Conventions](#13-naming-conventions)
14. [Safety and Idempotency](#14-safety-and-idempotency)
15. [Performance](#15-performance)
16. [Troubleshooting](#16-troubleshooting)
17. [Contributing](#17-contributing)
18. [Changelog](#18-changelog)
19. [License](#19-license)

---

## 1. Introduction

**Laravel Anvil** is a developer-productivity package that introspects a live database and generates a complete, idiomatic Laravel application scaffold from the schema it finds. Instead of hand-authoring boilerplate for every table, Anvil reads your columns, foreign keys, indexes, and constraints once and emits production-ready code across the full application stack.

### What Anvil generates

| Artifact | Flag | Description |
|---|---|---|
| Eloquent Model | _(always)_ | PHPDoc, fillable, hidden, casts, relationships |
| Resource Controller | `--controllers` | Full CRUD, service-injected |
| API Resource | `--resources` | `JsonResource` with relationship loading |
| Form Requests | `--form-requests` | Store + Update with schema-inferred rules |
| Service class | `--services` | Lifecycle hooks, event dispatch |
| Repository | `--repositories` | Interface + Eloquent implementation + provider |
| Policy | `--policies` | All abilities, ownership detection, auto-register |
| Gates | `--gates` | Appended to `AuthServiceProvider` |
| Observer | `--observers` | All lifecycle events, soft-delete hooks |
| Domain Events | `--events` | Created / Updated / Deleted / Restored |
| Factory | `--factories` | Faker-powered, type-inferred definitions |
| Seeder | `--seeders` | Environment-aware counts, FK ordering |
| Migration | `--migrations` | Reverse-engineered `Schema::create()` |
| Feature Tests | `--tests` | Full CRUD test suite |
| **Versioned JSON API** | **`--api`** | Controllers, routes, ForceJson enforcement |

### Design principles

- **Zero opinions on your domain** — Anvil emits stubs your team fills in. No hidden logic is injected.
- **Idempotent by default** — Running the command twice produces the same output. Existing files are never silently overwritten unless `--force` is passed.
- **Driver-agnostic** — MySQL, PostgreSQL, SQLite, and SQL Server are all supported with full introspection parity.
- **Layered extensibility** — Every generator implements a two-method contract. Drop in your own generators via config without touching package source.
- **PSR-12 compliant** — All generated code adheres to PSR-12 and is immediately usable with Laravel Pint.

---

## 2. Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.2` |
| Laravel | `^11.0` or `^12.0` |
| `illuminate/support` | `^11.0 \| ^12.0` |
| `illuminate/database` | `^11.0 \| ^12.0` |
| `illuminate/console` | `^11.0 \| ^12.0` |
| `illuminate/filesystem` | `^11.0 \| ^12.0` |

> **Note:** Laravel Anvil requires PHP 8.2+ because it uses readonly constructor properties, named arguments, first-class callables, and `match` expressions extensively throughout its codebase.

---

## 3. Installation

### Via Composer

```bash
composer require zuqongtech/laravel-anvil --dev
```

> Installing as a `require-dev` dependency is recommended because Anvil is a scaffolding tool that should not be present in production builds.

### Publish the configuration file

```bash
php artisan vendor:publish --provider="Zuqongtech\LaravelAnvil\LaravelAnvilServiceProvider" --tag="config"
```

This creates `config/anvil.php` in your application. You may also publish the stub templates if you want to customise the generated code shapes:

```bash
php artisan vendor:publish --provider="Zuqongtech\LaravelAnvil\LaravelAnvilServiceProvider" --tag="stubs"
```

Stubs are placed in `stubs/anvil/` and take precedence over the package-bundled templates.

### Service Provider

In Laravel 11+ with the minimal bootstrap (`bootstrap/app.php`), the service provider is auto-discovered via the `extra.laravel.providers` key in `composer.json` and requires no manual registration.

For Laravel applications using the classic `config/app.php` providers array, add:

```php
// config/app.php
'providers' => [
    // ...
    Zuqongtech\LaravelAnvil\LaravelAnvilServiceProvider::class,
],
```

---

## 4. Configuration

All configuration lives in `config/anvil.php`. Every key can be overridden via environment variables, making the package CI-friendly without file edits.

### Core settings

```php
// config/anvil.php (excerpt)

// PHP namespace for generated models
'namespace' => env('DB_INTROSPECTION_NAMESPACE', 'App\\Models'),

// Filesystem path relative to base_path() where models are written
'target_path' => env('DB_INTROSPECTION_TARGET_PATH', 'app'),

// Database connection name (null = use application default)
'connection' => env('DB_INTROSPECTION_CONNECTION', null),
```

### Ignored tables

Anvil ships with a default ignore list that covers Laravel's own infrastructure tables. Add any application-specific tables you want excluded from generation:

```php
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
    // Telescope, Pulse tables ...
],
```

Pattern-based ignoring is also supported:

```php
'ignore_table_patterns' => [
    '/^temp_/',     // Ignore all tables prefixed with "temp_"
    '/^backup_/',   // Ignore all tables prefixed with "backup_"
    '/^_.*/',       // Ignore tables prefixed with an underscore
],
```

### Relationship detection

```php
'relationships' => [
    'detect_belongs_to'      => true,   // Infer belongsTo from FK columns
    'detect_has_many'        => true,   // Generate inverse hasMany
    'detect_many_to_many'    => true,   // Detect pivot tables
    'detect_polymorphic'     => true,   // Detect *_type / *_id pairs
    'validate_foreign_keys'  => false,  // Fail on broken FK references
    'smart_inverse_detection'=> true,
    'typed_relationships'    => true,
    'max_relationship_depth' => 3,
],
```

### Naming conventions

```php
'naming' => [
    'singular_models'      => true,   // "users" table → "User" model
    'studly_case_models'   => true,
    'camel_case_relationships' => true,
    'pivot_model_suffix'   => 'Pivot',
    'custom_model_names'   => [
        // Override the default table-to-model conversion for specific tables
        // 'user_data' => 'User',
    ],
],
```

### Fillable strategy

```php
'fillable' => [
    // "auto"   = compute from columns (exclude PK, timestamps, hidden)
    // "all"    = mass-assign everything
    // "none"   = emit an empty $fillable array
    'strategy'               => env('DB_INTROSPECTION_FILLABLE_STRATEGY', 'auto'),
    'exclude'                => ['id', 'created_at', 'updated_at', 'deleted_at'],
    'use_guarded_when_efficient' => true,
    'guarded_threshold'      => 0.8,  // Switch to $guarded when >80% fillable
],
```

### Versioned API settings

```php
'api_version'    => env('DB_INTROSPECTION_API_VERSION', 'v1'),
'api_middleware' => ['auth:sanctum'],
```

The `api_middleware` array controls the middleware stack applied to every versioned route group generated by `--api`. Add or remove entries to match your authentication layer.

### Per-generator configuration

Each generator has its own configuration section under `generators`:

```php
'generators' => [

    'controllers' => [
        'namespace'              => 'App\\Http\\Controllers',
        'base_controller'        => 'App\\Http\\Controllers\\Controller',
        'use_resource_controllers' => true,
        'include_validation'     => true,
    ],

    'resources' => [
        'namespace'              => 'App\\Http\\Resources',
        'exclude_fields'         => ['password', 'remember_token', 'api_token', 'secret'],
        'include_relationships'  => true,
    ],

    'form_requests' => [
        'namespace'              => 'App\\Http\\Requests',
        'authorize_returns'      => true,
    ],

    'services' => [
        'namespace'              => 'App\\Services',
        'dispatch_events'        => true,
    ],

    'repositories' => [
        'namespace'              => 'App\\Repositories',
        'interface_namespace'    => 'App\\Repositories\\Contracts',
        'register_provider'      => true,
    ],

    'policies' => [
        'namespace'              => 'App\\Policies',
        'auto_register'          => false,
        'detect_ownership'       => true,
        'ownership_column'       => 'user_id',
    ],

    'observers' => [
        'namespace'              => 'App\\Observers',
        'auto_register'          => false,
        'include_soft_delete_events' => true,
    ],

    'factories' => [
        'namespace'              => 'Database\\Factories',
        'nullable_probability'   => 0.8,
    ],

    'seeders' => [
        'namespace'              => 'Database\\Seeders',
        'dev_count'              => 50,
        'staging_count'          => 10,
        'production_count'       => 0,
        'register_in_db_seeder'  => true,
    ],

    'tests' => [
        'namespace'              => 'Tests\\Feature',
        'use_pest'               => false,
        'auth_guard'             => 'sanctum',
    ],
],
```

### Advanced features

```php
'advanced' => [
    'generate_events'      => false,
    'generate_observers'   => false,
    'generate_collections' => false,
    'generate_resources'   => false,
    'generate_requests'    => false,
    'generate_scopes'      => false,
    'generate_traits'      => false,
    'use_attributes'       => false,  // Requires PHP 8.0+
    'generate_enums'       => false,  // Requires PHP 8.1+
],
```

### Custom type and cast mappings

Override how database column types are mapped to PHP types and Laravel cast strings:

```php
'type_mappings' => [
    // 'geometry' => 'string',
    // 'point'    => 'array',
],

'cast_mappings' => [
    // 'geometry' => 'string',
],
```

---

## 5. Quick Start

### Generate models only

```bash
php artisan anvil:generate
```

Introspects the default database connection, skips the tables in `ignore_tables`, and writes one Eloquent model per table to `app/Models/`.

### Generate the full application scaffold

```bash
php artisan anvil:generate --all
```

Generates every artifact type for every table in one pass.

### Generate a versioned JSON API

```bash
php artisan anvil:generate --api --api-version=1
```

Produces versioned controllers under `App\Http\Controllers\Api\V1\`, a dedicated route file at `routes/api/v1.php`, and the `ForceJsonApiServiceProvider` that locks all requests and exceptions in that route group to JSON.

### Target specific tables

```bash
php artisan anvil:generate --tables=posts --tables=comments --api --api-version=1
```

### Preview without writing files

```bash
php artisan anvil:generate --all --dry-run
```

### Using a non-default database connection

```bash
php artisan anvil:generate --connection=reporting --all
```

---

## 6. Command Reference

```
php artisan anvil:generate [options]
```

### 6.1 Core Flags

| Flag | Description |
|---|---|
| `--all` | Activate every artifact generator for every table. Equivalent to passing all individual artifact flags simultaneously. |
| `--models` | Models are always generated. This flag is accepted for explicitness but has no effect. |

### 6.2 Artifact Flags

| Flag | Generated files |
|---|---|
| `--controllers` | `app/Http/Controllers/{Model}Controller.php` |
| `--resources` | `app/Http/Resources/{Model}Resource.php` |
| `--form-requests` | `app/Http/Requests/Store{Model}Request.php`, `Update{Model}Request.php` |
| `--services` | `app/Services/{Model}Service.php` |
| `--repositories` | `app/Repositories/{Model}Repository.php`, `app/Repositories/Contracts/{Model}RepositoryInterface.php`, `app/Providers/RepositoryServiceProvider.php` |
| `--policies` | `app/Policies/{Model}Policy.php` |
| `--gates` | Appended to `app/Providers/AuthServiceProvider.php` |
| `--observers` | `app/Observers/{Model}Observer.php` |
| `--events` | `app/Events/{Model}Created.php`, `{Model}Updated.php`, `{Model}Deleted.php` (+ `{Model}Restored.php` for soft-delete models) |
| `--factories` | `database/factories/{Model}Factory.php` |
| `--seeders` | `database/seeders/{Model}Seeder.php` |
| `--migrations` | `database/migrations/{timestamp}_create_{table}_table.php` |
| `--api-routes` | Appended to `routes/api.php` (legacy mode) |
| `--tests` | `tests/Feature/{Model}Test.php` |

### 6.3 Versioned API Flag

| Flag | Default | Description |
|---|---|---|
| `--api` | — | Activate the versioned JSON API scaffold. Supersedes `--controllers` and `--api-routes` when set. Automatically implies `--form-requests`, `--services`, `--api-routes`, and `--tests`. |
| `--api-version=N` | `1` | The version number for the scaffold. Accepts bare integers (`1`, `2`) or `v`-prefixed strings (`v1`, `v2`). Controls the `V{N}` directory segment and `v{n}` route prefix. |

> When `--api` is active, standard `--controllers` output is suppressed — versioned API controllers are generated instead. All other non-controller flags continue to operate independently.

### 6.4 Behaviour Flags

| Flag | Default | Description |
|---|---|---|
| `--force` | off | Overwrite existing files without prompting. |
| `--backup` | off | Before overwriting, copy the existing file to `{filename}.backup.{YmdHis}`. |
| `--dry-run` | off | Print all planned actions to the console without writing any files. |
| `--with-phpdoc` | on | Emit `@property` and `@method` PHPDoc blocks on model classes. |
| `--with-inverse` | on | Detect tables that reference the current table and generate `hasMany`/`hasOne` methods. |
| `--with-constraints` | off | Embed a structured constraint comment block inside every model. |
| `--validate-fk` | off | After inspecting the schema, verify that every FK column references an existing table and column. Emits warnings rather than aborting. |
| `--analyze-constraints` | off | Print a constraint summary (PK counts, FK counts, indexes, unique constraints) before generation begins. |
| `--show-recommendations` | off | After generation, print schema optimisation suggestions (missing indexes on FK columns, redundant indexes, nullable FKs, etc.). |

### 6.5 Table Selection Flags

| Flag | Description |
|---|---|
| `--tables=TABLE` | Process only the named tables. Repeatable: `--tables=posts --tables=comments`. |
| `--only=TABLE` | Alias for `--tables`. |
| `--ignore=TABLE` | Exclude specific tables in addition to those in `config/anvil.php`. Repeatable. |
| `--namespace=NS` | Override the model namespace for this run. Default: `App\Models`. |
| `--path=PATH` | Override the target filesystem path for this run. Default: `app`. |
| `--connection=NAME` | Use a named database connection defined in `config/database.php`. |

---

## 7. Generated Artifacts

### 7.1 Eloquent Models

Every table that is not in the ignore list produces one Eloquent model.

**What is detected automatically:**

| Schema feature | Generated code |
|---|---|
| Non-standard primary key name | `protected $primaryKey = 'custom_id';` |
| Composite primary key | `protected $primaryKey = ['key1', 'key2'];` + `$incrementing = false` |
| UUID primary key | `$incrementing = false` + `$keyType = 'string'` |
| Missing `created_at` / `updated_at` | `public $timestamps = false;` |
| `deleted_at` column | `use SoftDeletes;` trait |
| `password`, `remember_token` columns | Added to `$hidden` |
| Boolean, integer, decimal, date, datetime, json columns | Added to `$casts` |
| Foreign key columns | `belongsTo()` relationship method |
| Tables referencing this table | `hasMany()` relationship method (with `--with-inverse`) |
| Pivot tables (two FKs, minimal extra columns) | `belongsToMany()` (detected by `RelationshipDetector`) |
| `*_type` / `*_id` column pairs | Polymorphic relationship stubs |

**Example output:**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $title
 * @property string|null $body
 * @property int $user_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class Post extends Model
{
    use SoftDeletes;

    protected $table = 'posts';

    protected $fillable = [
        'title',
        'body',
        'user_id',
    ];

    protected $casts = [
        'user_id' => 'integer',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id', 'id');
    }

    public function comments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Comment::class, 'post_id');
    }
}
```

### 7.2 Controllers

Generated with `--controllers` (standard) or `--api` (versioned). See [Section 8](#8-versioned-json-api-scaffold) for the versioned API variant.

Standard controllers extend `App\Http\Controllers\Controller`, inject the model's Service class, use FormRequests for validation, and return API Resource responses.

**Actions generated:** `index` (paginated), `show`, `store`, `update`, `destroy`, plus `restore` and `forceDelete` for soft-delete models.

### 7.3 API Resources

Generated with `--resources`.

**What is included:**

- All non-sensitive scalar columns in schema order
- Sensitive fields excluded: `password`, `remember_token`, `api_token`, `secret`, `two_factor_secret`, `two_factor_recovery_codes`, plus any entries in `config('anvil.generators.resources.exclude_fields')`
- BelongsTo relationships: `new {Related}Resource($this->whenLoaded('{method}'))`
- HasMany inverse relationships: `{Related}Resource::collection($this->whenLoaded('{method}'))`
- Timestamps rendered as ISO-8601 strings: `$this->created_at?->toIso8601String()`
- Automatic `use` imports for all related resource classes (self-referential imports are excluded)

### 7.4 Form Requests

Generated with `--form-requests` (or implicitly with `--api`). Two classes are produced per model: `Store{Model}Request` and `Update{Model}Request`.

**Validation rules are inferred from the schema:**

| Schema information | Emitted rule |
|---|---|
| `nullable` column | `nullable` |
| Required column | `required` |
| Update request | `sometimes` prepended to all rules |
| `varchar(255)` | `string\|max:255` |
| Integer types | `integer` |
| Decimal / float | `numeric` |
| Boolean | `boolean` |
| Date | `date` |
| Datetime / timestamp | `date_format:Y-m-d H:i:s` |
| JSON / JSONB | `array` |
| UUID | `uuid` |
| `enum('a','b','c')` | `in:a,b,c` |
| Foreign key column | `exists:{table},{column}` |
| Single-column unique constraint | `unique:{table},{column}` (Store) / `unique:{table},{column},{$id}` (Update) |
| Column name contains `email` | `email:rfc,dns` appended |
| Column name contains `url` or `website` | `url` appended |

### 7.5 Services

Generated with `--services` (or implicitly with `--api`). The service class sits between the controller and the repository.

**Methods:** `paginate`, `findOrFail`, `create`, `update`, `delete`, plus `restore` and `forceDelete` for soft-delete models.

**Lifecycle hooks** are generated as protected stub methods that subclasses can override without touching the base implementation:

```php
protected function beforeCreate(array &$data): void {}
protected function afterCreate(Post $post): void {}
protected function beforeUpdate(Post $post, array &$data): void {}
protected function afterUpdate(Post $post): void {}
protected function beforeDelete(Post $post): void {}
```

Each `create` and `update` call automatically dispatches the corresponding domain event (`PostCreated`, `PostUpdated`) if the events were also generated.

### 7.6 Repositories

Generated with `--repositories`. Three files are produced:

1. **`{Model}RepositoryInterface`** — defines `paginate`, `findOrFail`, `create`, `update`, `delete`, plus trashed variants for soft-delete models.
2. **`{Model}Repository`** — Eloquent implementation. The `paginate` method includes a filter loop ready for extension.
3. **`RepositoryServiceProvider`** — created once, then appended on each subsequent run. Binds each interface to its implementation with an anonymous factory:

```php
$this->app->bind(PostRepositoryInterface::class, fn () => new PostRepository(new \App\Models\Post()));
```

> **Note:** `RepositoryServiceProvider` must be registered in your application's bootstrap or providers list after generation.

### 7.7 Policies

Generated with `--policies`.

**Abilities generated:** `viewAny`, `view`, `create`, `update`, `delete`, plus `restore` and `forceDelete` for soft-delete models.

**Ownership detection:** If the table contains a column matching `config('anvil.generators.policies.ownership_column')` (default `user_id`), the write abilities (`update`, `delete`, `restore`, `forceDelete`) emit an ownership check:

```php
public function update(User $user, Post $post): bool
{
    return $user->id === $post->user_id;
}
```

If no ownership column is found, the abilities return `true` as a permissive default, which you should tighten to match your domain rules.

**`before()` hook** is always generated with a documented super-admin bypass pattern.

**Auto-registration:** When `config('anvil.generators.policies.auto_register')` is `true`, Anvil appends the `{Model} => {Model}Policy` mapping to `AuthServiceProvider::$policies`. If `AuthServiceProvider` does not exist, a minimal one is created.

### 7.8 Gates

Generated with `--gates`. Anvil appends Gate definitions directly inside `AuthServiceProvider::boot()` rather than creating per-model files, keeping authorization centralised.

**Gates generated per model:**

```
viewAny-{model-slug}
view-{model-slug}
create-{model-slug}
update-{model-slug}
delete-{model-slug}
restore-{model-slug}      (soft-delete models only)
forceDelete-{model-slug}  (soft-delete models only)
```

Ownership logic follows the same detection strategy as Policies.

### 7.9 Observers

Generated with `--observers`.

**Hooks generated:** `creating`, `created`, `updating`, `updated`, `saving`, `saved`, `deleting`, `deleted`. For soft-delete models: `restoring`, `restored`, `forceDeleting`, `forceDeleted`.

Each method body is an empty stub with a descriptive docblock.

**Auto-registration:** When `config('anvil.generators.observers.auto_register')` is `true`, Anvil appends `{Model}::observe({Model}Observer::class)` inside `AppServiceProvider::boot()` (or `EventServiceProvider` if `AppServiceProvider` is absent).

### 7.10 Events

Generated with `--events`. One class per lifecycle transition:

- `{Model}Created`
- `{Model}Updated`
- `{Model}Deleted`
- `{Model}Restored` _(soft-delete models only)_

Every event class uses the `Dispatchable`, `InteractsWithSockets`, and `SerializesModels` traits, carries the model instance as a `public readonly` property, and includes a commented-out `broadcastOn()` stub for WebSocket support.

### 7.11 Factories

Generated with `--factories`. Faker expressions are inferred using a two-pass strategy:

**Pass 1 — Name pattern matching** (longest match wins):

| Column name pattern | Faker expression |
|---|---|
| `email` | `fake()->safeEmail()` |
| `first_name` | `fake()->firstName()` |
| `last_name` | `fake()->lastName()` |
| `name` | `fake()->name()` |
| `phone` / `mobile` | `fake()->phoneNumber()` |
| `address` / `street` | `fake()->address()` / `fake()->streetAddress()` |
| `city` | `fake()->city()` |
| `country` | `fake()->country()` |
| `url` / `website` | `fake()->url()` |
| `uuid` | `fake()->uuid()` |
| `slug` | `fake()->slug()` |
| `title` | `fake()->sentence(6)` |
| `body` / `content` | `fake()->paragraphs(n, true)` |
| `price` / `amount` | `fake()->randomFloat(2, ...)` |
| `latitude` / `lng` | `fake()->latitude()` / `fake()->longitude()` |
| `password` | `bcrypt(fake()->password())` |
| `token` | `Str::random(64)` |
| `color` / `colour` | `fake()->hexColor()` |
| `image` / `avatar` | `fake()->imageUrl(...)` |
| _(and 20+ more)_ | |

**Pass 2 — Database type fallback:**

| DB type | Faker expression |
|---|---|
| `int` / `bigint` | `fake()->numberBetween(1, 1000)` |
| `tinyint(1)` | `fake()->boolean()` |
| `decimal` / `float` | `fake()->randomFloat(2, 0, 10000)` |
| `date` | `fake()->date()` |
| `datetime` / `timestamp` | `fake()->dateTime()->format('Y-m-d H:i:s')` |
| `json` / `jsonb` | `json_encode([])` |
| `uuid` | `fake()->uuid()` |
| `text` / `longtext` | `fake()->paragraph()` |
| `enum(...)` | `fake()->randomElement([...])` with extracted values |

**FK columns** always resolve to `RelatedModel::factory()` rather than a random integer, ensuring referential integrity in test data.

**Nullable columns** are wrapped in `fake()->optional(0.8)?->...` (80% chance of a non-null value, configurable via `generators.factories.nullable_probability`).

### 7.12 Seeders

Generated with `--seeders`. Each seeder uses the model's factory and provides environment-aware record counts:

```php
$count = match (true) {
    app()->environment('production') => 0,
    app()->environment('staging')    => 10,
    default                          => 50,
};
```

Foreign key dependencies are detected and the parent seeder is called first. When `config('anvil.generators.seeders.register_in_db_seeder')` is `true`, the seeder class is appended to `DatabaseSeeder::run()` automatically.

### 7.13 Migrations

Generated with `--migrations`. Each migration reverse-engineers the live table into a `Schema::create()` call.

**Column type mapping:**

| Database type | Blueprint method |
|---|---|
| `bigint` PK | `$table->id()` |
| `varchar(255)` | `$table->string('col', 255)` |
| `text` / `longtext` | `$table->text()` / `$table->longText()` |
| `tinyint(1)` | `$table->boolean('col')` |
| `decimal(10,2)` | `$table->decimal('col', 10, 2)` |
| `json` / `jsonb` | `$table->json('col')` |
| `uuid` | `$table->uuid('col')` |
| `enum('a','b')` | `$table->enum('col', ['a', 'b'])` |
| `created_at` + `updated_at` | `$table->timestamps()` |
| `deleted_at` | `$table->softDeletes()` |

Indexes, unique constraints, and foreign key constraints are all included. Default values are preserved. The `down()` method calls `Schema::dropIfExists()`.

### 7.14 Feature Tests

Generated with `--tests` (or implicitly with `--api`). A full `PHPUnit` test class is generated per model, covering the complete CRUD lifecycle against the generated API endpoints.

**Test cases included:**

- `test_index_returns_paginated_list`
- `test_store_creates_record`
- `test_store_validates_required_fields`
- `test_show_returns_single_record`
- `test_show_returns_404_for_missing_record`
- `test_update_modifies_record`
- `test_destroy_deletes_record`
- `test_destroy_returns_404_for_missing_record`
- `test_restore_revives_soft_deleted_record` _(soft-delete models)_
- `test_force_delete_permanently_removes_record` _(soft-delete models)_

Tests use `RefreshDatabase`, create a `User` via factory, and authenticate via `actingAs`. The base URL is derived from the configured API version and model slug.

---

## 8. Versioned JSON API Scaffold

### 8.1 Overview

The `--api` flag activates a self-contained versioned API scaffold that goes beyond simply placing controllers in a subdirectory. It generates the complete infrastructure required to run a JSON-only API:

```bash
php artisan anvil:generate --api --api-version=1 --tables=posts --tables=comments
```

The flag implies `--form-requests`, `--services`, `--api-routes`, and `--tests` automatically. All other flags (`--repositories`, `--factories`, etc.) operate independently and can be combined freely.

### 8.2 Directory Structure

After running `--api --api-version=1`, the following structure is created:

```
app/
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       └── V1/
│   │           ├── ApiController.php          ← Abstract base (generated once)
│   │           ├── PostController.php
│   │           └── CommentController.php
│   ├── Middleware/
│   │   └── ForceJsonResponse.php              ← JSON enforcement middleware
│   └── Resources/
│       ├── PostResource.php
│       └── CommentResource.php
├── Providers/
│   └── ForceJsonApiServiceProvider.php        ← Route loading + exception handling
│
routes/
└── api/
    └── v1.php                                 ← All V1 routes collected here
```

### 8.3 ForceJsonResponse Middleware

```php
// app/Http/Middleware/ForceJsonResponse.php
```

This middleware performs two jobs:

1. **Incoming:** Sets `Accept: application/json` on the request before it reaches the router. This is what triggers Laravel's exception handler to render JSON instead of HTML for any error that occurs within the API route group.

2. **Outgoing:** Sets `Content-Type: application/json` on all responses (the 204 No Content special case is excluded to avoid sending a content header with no body).

The middleware is applied globally to all versioned route groups by `ForceJsonApiServiceProvider` and is never applied to non-API routes.

### 8.4 ForceJsonApiServiceProvider

This is the centrepiece of the JSON API infrastructure:

```php
// app/Providers/ForceJsonApiServiceProvider.php
```

**Responsibilities:**

**Route loading** — The provider maintains a `$versions` map of version slug → route file path:

```php
protected array $versions = [
    'v1' => 'routes/api/v1.php',
    'v2' => 'routes/api/v2.php',  // Added automatically by --api-version=2
];
```

Each version's routes are wrapped in the correct middleware stack:

```php
Route::middleware(['api', ForceJsonResponse::class, 'auth:sanctum'])
    ->prefix('v1')
    ->name('api.v1.')
    ->group(base_path('routes/api/v1.php'));
```

**Exception handling** — The provider registers a `renderable()` callback on Laravel's exception handler that intercepts all unhandled `Throwable` instances on API paths and converts them to a structured JSON envelope:

```json
{
    "success": false,
    "message": "Resource not found.",
    "errors": {}
}
```

In debug mode (`APP_DEBUG=true`), a `debug` key is appended:

```json
{
    "success": false,
    "message": "Resource not found.",
    "debug": {
        "exception": "Illuminate\\Database\\Eloquent\\ModelNotFoundException",
        "file": "/var/www/app/Http/Controllers/Api/V1/PostController.php",
        "line": 42,
        "trace": [...]
    }
}
```

**Registration** — Anvil adds the provider and registers the `ForceJsonResponse` middleware in `bootstrap/app.php` (Laravel 11+) or `config/app.php` (Laravel ≤10) automatically.

### 8.5 Versioned Route Files

Each version gets its own route file at `routes/api/v{n}.php`. The file is created with a header that documents the middleware stack, then each model's routes are appended idempotently:

```php
// routes/api/v1.php

use Illuminate\Support\Facades\Route;

// Post resource routes
Route::apiResource('posts', \App\Http\Controllers\Api\V1\PostController::class);

// Soft-delete lifecycle routes for Post
Route::patch('posts/{id}/restore', [\App\Http\Controllers\Api\V1\PostController::class, 'restore'])
    ->name('posts.restore');
Route::delete('posts/{id}/force', [\App\Http\Controllers\Api\V1\PostController::class, 'forceDelete'])
    ->name('posts.forceDelete');
```

The route file does **not** contain its own `Route::middleware()` or `Route::prefix()` wrapper — those are applied by `ForceJsonApiServiceProvider` when the file is loaded, keeping the route file clean and portable.

### 8.6 Exception Handling

All exceptions thrown inside a versioned API route group are classified and serialised to JSON automatically. The full classification map:

| Exception class | HTTP status | Message |
|---|---|---|
| `AuthenticationException` | 401 | "Unauthenticated." |
| `AuthorizationException` | 403 | "This action is unauthorized." |
| `ModelNotFoundException` | 404 | "Resource not found." |
| `NotFoundHttpException` | 404 | "The requested URL was not found." |
| `MethodNotAllowedHttpException` | 405 | "Method not allowed." |
| `ValidationException` | 422 | "The given data was invalid." + errors bag |
| `HttpException` (generic) | `$e->getStatusCode()` | `$e->getMessage()` |
| `QueryException` | 500 | "A database error occurred." |
| Any other `Throwable` | 500 | "An unexpected error occurred." |

### 8.7 Multi-version Workflow

To release a `v2` API alongside an existing `v1`:

```bash
php artisan anvil:generate --api --api-version=2 --tables=posts
```

Anvil will:

1. Create `app/Http/Controllers/Api/V2/ApiController.php` (base) and `PostController.php`
2. Create `routes/api/v2.php`
3. Append `'v2' => 'routes/api/v2.php'` to `ForceJsonApiServiceProvider::$versions`
4. Leave all `v1` files completely untouched

Both versions are served simultaneously under their respective prefixes (`/v1/posts`, `/v2/posts`), sharing the same JSON enforcement infrastructure.

---

## 9. Architecture

### 9.1 Package Structure

```
src/
├── Console/
│   └── GenerateModelsFromDatabase.php    ← artisan anvil:generate
├── Contracts/
│   └── Generator.php                     ← Generator interface
├── Generators/
│   ├── ApiControllerGenerator.php
│   ├── ApiRouteGenerator.php
│   ├── ControllerGenerator.php
│   ├── EventGenerator.php
│   ├── FactoryGenerator.php
│   ├── FormRequestGenerator.php
│   ├── ForceJsonServiceProviderGenerator.php
│   ├── GateGenerator.php
│   ├── MigrationGenerator.php
│   ├── ObserverGenerator.php
│   ├── PolicyGenerator.php
│   ├── RepositoryGenerator.php
│   ├── ResourceGenerator.php
│   ├── SeederGenerator.php
│   ├── ServiceGenerator.php
│   └── TestGenerator.php
├── Support/
│   ├── ConfigValidator.php
│   ├── ConstraintAnalyzer.php
│   ├── DatabaseInspector.php
│   ├── FileWriter.php
│   ├── GenerationOptions.php
│   ├── GenerationOrchestrator.php
│   ├── Helpers.php
│   ├── ModelBuilder.php
│   ├── ModelMetadata.php
│   ├── RelationshipDetector.php
│   └── StubGenerator.php
└── LaravelAnvilServiceProvider.php
```

### 9.2 Generator Contract

Every generator implements a three-method interface:

```php
namespace Zuqongtech\LaravelAnvil\Contracts;

interface Generator
{
    /**
     * Execute generation for a single model's metadata.
     * Returns an array (or array-of-arrays for multi-file generators).
     */
    public function generate(ModelMetadata $meta, GenerationOptions $options): array;

    /**
     * Return true if this generator should run given the current options.
     * Called by the orchestrator before invoking generate().
     */
    public function supports(GenerationOptions $options): bool;

    /**
     * Human-readable name used in console output and result arrays.
     */
    public function getName(): string;
}
```

The `generate()` method returns a result array that **must** contain at minimum a `status` key (`'success'`, `'skipped'`, `'updated'`, `'dry-run'`). The orchestrator stamps the `type` key from `getName()` after the call returns.

### 9.3 GenerationOrchestrator

`GenerationOrchestrator` is the pipeline that connects the command to the generators. It holds an ordered list of `Generator` instances and calls each one for every `ModelMetadata` object it receives:

```php
foreach ($metadata as $meta) {
    foreach ($this->generators as $generator) {
        if ($generator->supports($options)) {
            $result = $generator->generate($meta, $options);
            $result['type'] = $generator->getName();
            $modelResults['artifacts'][] = $result;
        }
    }
}
```

Generator order matters for infrastructure generators like `ForceJsonServiceProviderGenerator` (which must run before `ApiControllerGenerator` references files it creates). The order in `LaravelAnvilServiceProvider` is authoritative.

### 9.4 ModelMetadata DTO

`ModelMetadata` is a plain data-transfer object populated by `DatabaseInspector`. It is the sole source of truth passed to every generator:

```php
final class ModelMetadata
{
    public string  $table;
    public string  $model;                  // StudlyCase model name
    public array   $columns;               // Raw column metadata
    public array   $foreignKeys;           // FK definitions
    public array   $indexes;               // All indexes including unique
    public array   $uniqueConstraints;     // Unique indexes only
    public ?string $primaryKey;            // Single PK column name
    public array   $compositePrimaryKey;   // Ordered PK column names
    public bool    $softDeletes;           // Has deleted_at column
    public bool    $timestamps;            // Has created_at + updated_at
    public ?array  $constraintAnalysis;    // Populated by ConstraintAnalyzer
    public array   $inverseRelationships;  // Populated by RelationshipDetector
}
```

`ModelMetadata::fromTable(string $table, DatabaseInspector $inspector): self` is the canonical factory method.

### 9.5 GenerationOptions DTO

`GenerationOptions` is a `final` readonly-constructor DTO that encapsulates every flag passed to `anvil:generate`. It has three factory methods:

| Factory | Source |
|---|---|
| `GenerationOptions::fromCommand(Command $command)` | Reads all `$command->option()` values |
| `GenerationOptions::withDefaults()` | Reads from `config/anvil.php` |
| `GenerationOptions::fromArray(array $options)` | Programmatic construction |

Key derived helpers:

```php
$options->getApiVersionString();       // "V1"
$options->getApiVersionSlug();         // "v1"
$options->getApiControllerNamespace(); // "App\Http\Controllers\Api\V1"
$options->getEnabledGenerators();      // ["Models", "ApiScaffold", "Resources", ...]
$options->getAllIgnoredTables();        // Config ignore + CLI ignore merged
```

### 9.6 DatabaseInspector

`DatabaseInspector` abstracts all raw SQL introspection behind a unified API. It supports MySQL, PostgreSQL, SQLite, and SQL Server with driver-specific queries for each operation.

**Public API:**

```php
$inspector->getAllTables(): array
$inspector->getColumns(string $table): array
$inspector->getPrimaryKey(string $table): ?string
$inspector->getCompositePrimaryKey(string $table): array
$inspector->getForeignKeys(string $table): array
$inspector->getIndexes(string $table): array
$inspector->getUniqueConstraints(string $table): array
$inspector->getCheckConstraints(string $table): array
$inspector->getTableMetadata(string $table): array   // Aggregates all above
$inspector->getTableComment(string $table): ?string
$inspector->tableExists(string $table): bool
$inspector->getDatabaseName(): string
$inspector->getDriver(): string
```

Column arrays always contain at minimum: `name`, `type`, `nullable`, `default`, `extra`, `comment`, `key`.

### 9.7 RelationshipDetector

`RelationshipDetector` builds a complete foreign key map across all tables in a single pass, then answers relationship queries without repeated database calls:

```php
// Build the map once
$detector->buildForeignKeyMap(array $tables): void

// Query the map
$detector->getInverseRelationships(string $table): array
$detector->detectManyToMany(string $table): array
$detector->detectPolymorphic(string $table): array
$detector->isPivotTable(string $table): bool
$detector->getPivotTables(): array
$detector->validateForeignKeys(): array
```

**Pivot table detection heuristic:** A table is considered a pivot table if it has exactly two foreign key columns and at most two additional non-timestamp columns. This correctly identifies `post_tag`, `role_user`, etc., while excluding tables like `order_items` that carry business data.

### 9.8 ConstraintAnalyzer

`ConstraintAnalyzer` provides deeper structural analysis of table constraints, caches results per table, and generates human-readable recommendations:

```php
$analyzer->analyzeTable(string $table): array
$analyzer->getConstraintSummary(array $tables): array
$analyzer->validateConstraintIntegrity(array $tables): array
```

**Recommendations generated:**

| Condition | Recommendation type |
|---|---|
| Missing primary key | `warning` |
| FK column without an index | `performance` |
| Redundant / prefix-duplicate indexes | `optimization` |
| Nullable FK column | `info` |

---

## 10. Database Driver Support

| Feature | MySQL | PostgreSQL | SQLite | SQL Server |
|---|---|---|---|---|
| Table listing | ✅ | ✅ | ✅ | ✅ |
| Column metadata | ✅ | ✅ | ✅ | ✅ |
| Primary key detection | ✅ | ✅ | ✅ | ✅ |
| Composite PK detection | ✅ | ✅ | ✅ | ✅ |
| Foreign key detection | ✅ | ✅ | ✅ | ✅ |
| Index detection | ✅ | ✅ | ✅ | ✅ |
| Unique constraint detection | ✅ | ✅ | ✅ | ✅ |
| Check constraint detection | ✅ (8.0.16+) | ✅ | ❌ | ✅ |
| Table comments | ✅ | ✅ | ❌ | ❌ |
| Column comments | ✅ | ✅ | ❌ | ✅ |
| UUID PK detection | ✅ | ✅ | ✅ | ✅ |
| Auto-increment detection | ✅ | ✅ (nextval) | ✅ | ✅ (identity) |

SQLite's limited introspection means check constraints and table/column comments are unavailable on that driver. All other features are fully supported.

---

## 11. Extending Anvil

### 11.1 Writing a Custom Generator

Implement the `Generator` contract:

```php
<?php

namespace App\Generators;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

class OpenApiSpecGenerator implements Generator
{
    public function supports(GenerationOptions $options): bool
    {
        // Activate via your own custom flag or always run
        return $options->toArray()['generate_openapi'] ?? false;
    }

    public function getName(): string
    {
        return 'OpenApiSpec';
    }

    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $path = base_path("openapi/{$meta->table}.yaml");

        // ... build your spec ...

        if (! $options->dryRun) {
            file_put_contents($path, $spec);
        }

        return [
            'type'   => $this->getName(),
            'name'   => $meta->model,
            'path'   => $path,
            'status' => 'success',
        ];
    }
}
```

### 11.2 Registering a Custom Generator

Add the fully qualified class name to `config/anvil.php`:

```php
'custom_generators' => [
    App\Generators\OpenApiSpecGenerator::class,
],
```

Anvil's `LaravelAnvilServiceProvider` resolves these classes through the service container and appends them to the orchestrator's generator list after all built-in generators.

To override a built-in generator entirely, rebind it in your own service provider **before** `LaravelAnvilServiceProvider` boots:

```php
// app/Providers/AppServiceProvider.php
public function register(): void
{
    $this->app->singleton(
        \Zuqongtech\LaravelAnvil\Generators\ControllerGenerator::class,
        \App\Generators\MyControllerGenerator::class,
    );
}
```

Because the orchestrator resolves generators via the container, your binding takes precedence.

### 11.3 Lifecycle Hooks

For lightweight customisation without writing a full generator, register hook classes in `config/anvil.php`:

```php
'hooks' => [
    'before_generation'     => App\Hooks\BeforeGenerationHook::class,
    'after_model_generated' => App\Hooks\AfterModelHook::class,
    'after_generation'      => null,
    'content_transformer'   => App\Hooks\ModelContentTransformer::class,
    'filename_resolver'     => null,
],
```

Hook classes must implement the corresponding interface from `Zuqongtech\LaravelAnvil\Contracts\`.

### 11.4 Custom Model Templates

Publish the stubs:

```bash
php artisan vendor:publish --tag="stubs"
```

Edit `stubs/anvil/model.stub`. Available placeholders mirror the keys in `StubGenerator::$replacements`:

| Placeholder | Content |
|---|---|
| `{namespace}` | Model namespace |
| `{uses}` | `use` import statements |
| `{docblock}` | Class-level PHPDoc |
| `{class_name}` | Model class name |
| `{table}` | Table name string |
| `{primary_key}` | `$primaryKey` property (if non-default) |
| `{timestamps}` | `$timestamps = false` (if applicable) |
| `{fillable}` | `$fillable` array |
| `{hidden}` | `$hidden` array |
| `{casts}` | `$casts` array |
| `{constraint_comments}` | Constraint block comment |
| `{relationships}` | Relationship method stubs |

---

## 12. Schema Analysis Tools

Anvil includes two analysis modes that do not generate files but provide useful intelligence about your schema.

### Constraint analysis (`--analyze-constraints`)

Prints a summary before generation begins:

```
  Tables            : 12
  With primary keys : 12
  Without PKs       : 0
  Foreign keys      : 18
  Indexes           : 31
  Unique constraints: 7
```

### Optimisation recommendations (`--show-recommendations`)

After generation, prints table-level suggestions:

```
Table: orders

  ⚡ [index] Foreign key column 'customer_id' lacks an index
     → Add an index on 'customer_id' for better query performance

  🔧 [redundant_index] Index 'orders_status_created_at_index' may be redundant with 'orders_created_at_index'
     → Consider removing the shorter index if the longer one serves both purposes

Table: users

  ✅ No recommendations — schema looks great!
```

### FK validation (`--validate-fk`)

Cross-references every FK column against the referenced table and column. Issues are reported as warnings; generation continues unless the referenced table is genuinely absent from the schema:

```
⚠️  Found 1 FK issue(s):
   - legacy_orders.old_user_id: Referenced table does not exist
```

---

## 13. Naming Conventions

Anvil follows Laravel conventions by default. The table-to-model conversion pipeline is:

```
table name  →  singular  →  StudlyCase  →  model name
"blog_posts" → "blog_post" → "BlogPost"
"user_data"  → "user_datum" → "UserDatum"  ← can be overridden
```

Override specific tables in `config/anvil.php`:

```php
'naming' => [
    'custom_model_names' => [
        'user_data' => 'UserProfile',
    ],
],
```

**Relationship method names** follow camelCase:

```
FK column       →  method name
"user_id"       →  user()
"author_user_id"→  authorUser()
"category_id"   →  category()
```

**Inverse relationship names** follow camelCase plural:

```
Model name  →  inverse method
"Comment"   →  comments()
"OrderItem" →  orderItems()
```

---

## 14. Safety and Idempotency

Anvil is designed to be run repeatedly as a schema evolves. All safety guarantees:

**File existence checks** — Before writing any file, Anvil checks whether it already exists. Without `--force`, existing files are skipped and a `skipped` status is reported.

**Backup mode** — With `--backup`, a timestamped copy (`Model.php.backup.20240115143022`) is created before any overwrite.

**Dry run** — `--dry-run` causes all generators to compute their output and report planned actions without touching the filesystem. All status values become `dry-run`.

**Append idempotency** — Generators that append to existing files (`ApiRouteGenerator`, `GateGenerator`, `RepositoryGenerator`, `ObserverGenerator`, `PolicyGenerator`, `ForceJsonServiceProviderGenerator`) all perform a `str_contains` check for a unique marker string before inserting. Running the command a second time will report `skipped` rather than duplicating entries.

**Large table confirmation** — When the table count exceeds `config('anvil.validation.confirm_threshold')` (default: 50), Anvil prompts for confirmation before proceeding unless `--force` is passed.

**Configuration validation** — Before any introspection begins, `ConfigValidator` checks namespace format, path writability, connection availability, regex pattern validity, PHP version compatibility, and more. Generation is aborted if validation fails.

---

## 15. Performance

For databases with many tables, Anvil provides several performance controls:

### Schema caching

```php
// config/anvil.php
'performance' => [
    'cache_schema' => env('DB_INTROSPECTION_CACHE', 0),  // TTL in seconds; 0 = disabled
],
```

When enabled, `DatabaseInspector` results are cached for the configured TTL. Useful for repeated runs during a generation session.

### Parallel processing

```php
'performance' => [
    'parallel_processing' => env('DB_INTROSPECTION_PARALLEL', false),
    'parallel_workers'    => env('DB_INTROSPECTION_WORKERS', null),  // null = CPU count
],
```

Requires the `pcntl` extension. When enabled, tables are distributed across worker processes. `ConfigValidator` emits a warning if this is enabled without `pcntl`.

### Memory and timeout

```php
'performance' => [
    'memory_limit'   => env('DB_INTROSPECTION_MEMORY', '512M'),
    'query_timeout'  => 30,   // seconds per introspection query
    'batch_size'     => 50,   // tables processed per progress bar tick
],
```

---

## 16. Troubleshooting

### Generation fails: "Configuration validation failed"

Run with `-v` for detailed output, then check `config/anvil.php` for the reported key. Common causes:

- `namespace` contains leading or trailing backslashes
- `target_path` directory is not writable
- A named `connection` does not exist in `config/database.php`
- A regex in `ignore_table_patterns` is malformed

### Models are generated but relationships are missing

Confirm that foreign keys are defined at the database level (not just in application code). Anvil reads structural FK metadata — application-level conventions without DB constraints will not be detected.

For databases without enforced FKs, use `--validate-fk` to see what Anvil finds, and consider using `custom_model_names` and manually adjusting the generated relationship stubs.

### `--api` generates controllers but routes return 404

Confirm `ForceJsonApiServiceProvider` is registered in your bootstrap. For Laravel 11+, check that `bootstrap/app.php` contains `->withProviders([ForceJsonApiServiceProvider::class])`. For earlier versions, check `config/app.php` providers array.

Also confirm that `routes/api/v{n}.php` exists and was populated (check with `--dry-run` first).

### Pivot tables are being generated as models

Anvil detects pivot tables by checking whether a table has exactly two FK columns and at most two additional non-timestamp columns. If your pivot table carries extra business data (making it a "rich pivot"), it will correctly be treated as a regular model. Add it to `ignore_tables` if you want to exclude it.

### Generated factories cause foreign key constraint violations

Ensure dependent models are seeded before their dependants. The `SeederGenerator` detects FK relationships and calls parent seeders first. If you are calling factories directly in tests, use `{Model}::factory()->for(User::factory())->create()` to resolve the dependency chain.

### `ForceJsonResponse` middleware is breaking non-API routes

The middleware is applied exclusively to routes registered by `ForceJsonApiServiceProvider` (inside the versioned prefix/middleware group). It is never applied globally. If you see interference, verify that your non-API routes are not accidentally matching the `v*/` path pattern checked inside `registerJsonExceptionHandler`.

---

## 17. Contributing

Contributions are welcome. Please follow these guidelines:

### Development setup

```bash
git clone https://github.com/zuqongtech/laravel-anvil.git
cd laravel-anvil
composer install
```

### Running tests

```bash
composer test
```

### Code style

The project uses [Laravel Pint](https://laravel.com/docs/pint) with the `laravel` preset:

```bash
composer lint        # Fix style issues
composer lint-check  # Check without fixing (used in CI)
```

### Pull request guidelines

1. Fork the repository and create a feature branch from `main`.
2. Add or update tests for any changed behaviour.
3. Ensure `composer test` and `composer lint-check` both pass.
4. Follow the existing docblock style — every `public` and `protected` method should have a PHPDoc comment.
5. Update `CHANGELOG.md` under the `[Unreleased]` section.
6. Submit your pull request with a clear description of the change and its motivation.

### Reporting issues

Please open a GitHub issue with:

- Laravel and PHP versions
- Database driver and version
- The full `anvil:generate` command you ran
- The full console output (use `--dry-run` if the issue occurs at generation time)
- A minimal table schema that reproduces the problem (a `CREATE TABLE` statement is ideal)

---

## 18. Changelog

### [1.0.0] — Initial Release

#### Added

- `anvil:generate` Artisan command with full flag set
- Eloquent model generation with PHPDoc, casts, timestamps, soft deletes, fillable, hidden, and relationship detection (belongsTo, hasMany, belongsToMany, polymorphic)
- `ControllerGenerator` — standard resource controllers with service injection
- `ResourceGenerator` — `JsonResource` with column mapping, relationship loading, and ISO-8601 timestamps
- `FormRequestGenerator` — `Store` and `Update` form requests with schema-inferred validation rules
- `ServiceGenerator` — service layer with lifecycle hooks and event dispatch
- `RepositoryGenerator` — interface + Eloquent implementation + `RepositoryServiceProvider`
- `PolicyGenerator` — full ability set with ownership detection and `AuthServiceProvider` auto-registration
- `GateGenerator` — Gate definitions appended to `AuthServiceProvider`
- `ObserverGenerator` — all lifecycle hooks with soft-delete event support and auto-registration
- `EventGenerator` — domain events per lifecycle transition
- `FactoryGenerator` — Faker-powered definitions with name-pattern and type-fallback inference
- `SeederGenerator` — environment-aware counts with FK dependency ordering
- `MigrationGenerator` — reverse-engineered `Schema::create()` migrations with indexes and FKs
- `TestGenerator` — full CRUD PHPUnit test suites
- `ApiControllerGenerator` — versioned API controllers under `Api\V{n}\`
- `ApiRouteGenerator` — dual-mode (legacy + versioned) route registration
- `ForceJsonServiceProviderGenerator` — `ForceJsonResponse` middleware, `ForceJsonApiServiceProvider`, and bootstrap registration
- `--api` / `--api-version=N` flags for versioned JSON API scaffold
- `DatabaseInspector` with full support for MySQL, PostgreSQL, SQLite, SQL Server
- `RelationshipDetector` with pivot table, polymorphic, and inverse relationship detection
- `ConstraintAnalyzer` with recommendations engine
- `ConfigValidator` with pre-generation validation and warnings
- `GenerationOptions` DTO with `fromCommand`, `withDefaults`, and `fromArray` factories
- `ModelMetadata` DTO populated by `DatabaseInspector`
- `GenerationOrchestrator` pipeline
- Custom generator support via `config('anvil.custom_generators')`
- Lifecycle hook system via `config('anvil.hooks')`
- Dry run, backup, force overwrite, and idempotent append modes
- Schema analysis tools: `--analyze-constraints`, `--show-recommendations`, `--validate-fk`
- Progress bar, verbosity control, and colourised console output
- PSR-12 compliant generated code

---

## 19. License

Laravel Anvil is open-source software licensed under the [MIT License](LICENSE.md).
