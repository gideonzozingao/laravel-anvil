# Laravel Anvil

> ⚒ Forge a complete Laravel application from your database.

**Laravel Anvil** introspects a live database and hammers your raw schema into a
full, idiomatic Laravel scaffold — models, controllers, API resources, form
requests, services, repositories, policies, gates, observers, events,
factories, seeders, migrations and tests — plus an optional versioned JSON API,
an OpenAPI 3.1 specification with Swagger UI, and a complete web CRUD front end
in either **pure Blade + Tailwind** or **Blade + Livewire**.

Point it at a database, run one command, and get working code you own.

## Table of contents

- [Why Anvil](#why-anvil)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Commands](#commands)
  - [`anvil:generate`](#anvilgenerate)
  - [`anvil:generate-web`](#anvilgenerate-web)
  - [`anvil:docs`](#anvildocs)
- [The web scaffold](#the-web-scaffold)
- [The versioned API scaffold](#the-versioned-api-scaffold)
- [OpenAPI & Swagger UI](#openapi--swagger-ui)
- [Configuration](#configuration)
- [Architecture](#architecture)
- [Extending Anvil](#extending-anvil)
- [Working with legacy schemas](#working-with-legacy-schemas)
- [Security](#security)
- [Contributing](#contributing)
- [License](#license)

## Why Anvil

Most generators work from migrations or hand-written model definitions. Anvil
works from the **database itself** — reading columns, types, primary keys,
foreign keys, unique constraints, indexes and soft-delete markers directly from
the live connection. That makes it equally at home on a greenfield Laravel app
and on a pre-existing or framework-foreign database (it has been exercised
against schemas that don't follow Laravel conventions — non-`id` primary keys,
tables without timestamps, and so on).

The output is plain Laravel code with no runtime dependency on Anvil. Generate
it, commit it, edit it freely — Anvil is a build-time tool, not a framework.

## Requirements

| Requirement    | Version                                    |
| -------------- | ------------------------------------------ |
| PHP            | `^8.3`                                     |
| Laravel        | `^11.0 \|\| ^12.0`                         |
| `symfony/yaml` | `^6.0 \|\| ^7.0` (pulled in automatically) |

Optional, depending on what you generate:

- **Livewire 3** (`livewire/livewire`) — only for `anvil:generate-web --stack=livewire`.
- A supported database driver: **PostgreSQL**, **MySQL/MariaDB**, or **SQLite**.

The generated web scaffold styles itself with **Tailwind CSS via CDN** out of
the box, so no front-end build step is required to see it working.

## Installation

```bash
composer require zuqongtech/laravel-anvil --dev
```

Anvil is intended as a development dependency — it generates code; it does not
run in production.

Publish the configuration:

```bash
php artisan vendor:publish --tag=anvil-config
```

This writes `config/anvil.php`. For the Livewire stack, install Livewire too:

```bash
composer require livewire/livewire
```

## Quick start

```bash
# Generate models for every table in the default connection
php artisan anvil:generate

# Generate the full kitchen sink for two tables
php artisan anvil:generate --all --tables=posts --tables=comments

# A versioned JSON API (v1) with an OpenAPI spec + Swagger UI
php artisan anvil:generate --api --openapi --tables=posts
php artisan anvil:docs            # prints the docs URL

# A web CRUD front end (Blade + Tailwind)
php artisan anvil:generate-web --tables=posts

# The same, with Livewire components
php artisan anvil:generate-web --stack=livewire --tables=posts
```

Preview anything without writing files by adding `--dry-run`.

## Commands

Anvil ships three Artisan commands.

### `anvil:generate`

Generates the application scaffold (everything except the web front end, which
has its own command). Models are always generated; every other artifact is opt
-in via a flag, or all at once with `--all`.

```bash
php artisan anvil:generate [options]
```

**Artifact flags**

| Flag              | Generates                                         |
| ----------------- | ------------------------------------------------- |
| `--all`           | Every artifact type below                         |
| `--models`        | Eloquent models (always on)                       |
| `--controllers`   | Resource controllers                              |
| `--resources`     | API resource classes                              |
| `--observers`     | Model observers                                   |
| `--policies`      | Authorization policies                            |
| `--form-requests` | `StoreXxx` / `UpdateXxx` form requests            |
| `--services`      | Service classes with lifecycle hooks              |
| `--repositories`  | Repository interface + Eloquent implementation    |
| `--gates`         | Gate definitions appended to your auth provider   |
| `--api-routes`    | `apiResource` routes appended to `routes/api.php` |
| `--factories`     | Model factories with Faker-inferred definitions   |
| `--seeders`       | Database seeders                                  |
| `--migrations`    | Reverse-engineered `Schema::create()` migrations  |
| `--events`        | `Created` / `Updated` / `Deleted` event classes   |
| `--tests`         | Feature tests for the CRUD endpoints              |

**API & docs flags**

| Flag                    | Description                                                  |
| ----------------------- | ------------------------------------------------------------ |
| `--api`                 | Generate a versioned JSON API scaffold with JSON enforcement |
| `--api-version=1`       | Version for `--api` (accepts `1`, `v1`, `V1`)                |
| `--openapi`             | Generate an OpenAPI 3.1 specification                        |
| `--openapi-format=yaml` | `yaml` (default) or `json`                                   |
| `--openapi-single-file` | Merge schemas + paths into one file                          |
| `--openapi-ui`          | Publish a static Swagger UI to `public/docs/`                |

**Targeting & behaviour flags**

| Flag                     | Description                                  |
| ------------------------ | -------------------------------------------- |
| `--namespace=App\Models` | Namespace for generated models               |
| `--connection=`          | Database connection to introspect            |
| `--tables=*`             | Limit to specific tables (repeatable)        |
| `--ignore=*`             | Exclude specific tables (repeatable)         |
| `--only=*`               | Alias for `--tables`                         |
| `--path=app`             | Base path for generated models               |
| `--force`                | Overwrite existing files without prompting   |
| `--backup`               | Back up existing files before overwriting    |
| `--dry-run`              | Preview without writing files                |
| `--with-phpdoc`          | Add PHPDoc blocks to models                  |
| `--with-inverse`         | Generate inverse relationships               |
| `--with-constraints`     | Embed constraint metadata in model comments  |
| `--validate-fk`          | Validate all foreign-key references          |
| `--analyze-constraints`  | Print a constraint summary before generating |
| `--show-recommendations` | Print schema optimisation suggestions        |

> **Note** — files that already exist are skipped unless you pass `--force`.
> When iterating on a schema, `--force` (optionally with `--backup`) is what you
> want.

### `anvil:generate-web`

Generates a complete web CRUD front end — resource controllers, web routes, and
views — for the chosen tables. The web scaffold reuses the same **services** and
**form requests** as the rest of the app, so those are generated alongside it.

```bash
php artisan anvil:generate-web [options]
```

| Flag                     | Description                                                                  |
| ------------------------ | ---------------------------------------------------------------------------- |
| `--stack=blade`          | Front-end stack: `blade` (Blade + Tailwind) or `livewire` (Blade + Livewire) |
| `--tables=*`             | Limit to specific tables                                                     |
| `--only=*`               | Alias for `--tables`                                                         |
| `--ignore=*`             | Exclude specific tables                                                      |
| `--connection=`          | Database connection to introspect                                            |
| `--namespace=App\Models` | Namespace of the models the scaffold references                              |
| `--path=app`             | Base path for generated models                                               |
| `--layout=`              | Blade layout the views should extend (overrides config)                      |
| `--skip-models`          | Don't (re)generate models — assume they already exist                        |
| `--no-inverse`           | Skip inverse-relationship detection when generating models                   |
| `--force`                | Overwrite existing files                                                     |
| `--backup`               | Back up existing files before overwriting                                    |
| `--dry-run`              | Preview without writing files                                                |

### `anvil:docs`

Prints the location of the generated API documentation and reports whether the
spec has been generated yet.

```bash
php artisan anvil:docs            # human-readable summary
php artisan anvil:docs --json     # machine-readable
php artisan anvil:docs --open     # open the docs URL in your browser
```

## The web scaffold

`anvil:generate-web` produces a full create/read/update/delete UI per resource.
Both stacks share the same shell: a generated base layout
(`resources/views/layouts/anvil.blade.php`) with a **professional collapsible
sidebar** whose links are **discovered at runtime** from your registered web
routes — every resource appears automatically and the nav stays correct as you
add or remove tables, with no regeneration. The sidebar collapses on desktop
(remembered via `localStorage`) and slides in as an overlay on mobile.

### `--stack=blade` (default)

Pure Blade + Tailwind. Per resource:

- `App\Http\Controllers\Web\{Model}Controller` — full resource controller
  (`index`/`create`/`store`/`show`/`edit`/`update`/`destroy`, plus
  `restore`/`forceDelete` for soft-delete models), returning Blade views and
  redirecting with flash messages.
- `Route::resource(...)` in `routes/web.php`, inside a managed middleware group.
- `resources/views/{slug}/` — `index`, `create`, `edit`, `show`, and a shared
  `_form` partial. Inputs are inferred from column types.

### `--stack=livewire`

Blade + Livewire 3. Requests still flow **controller → Blade view → Livewire
component**:

- The controller handles only the GET endpoints (`index`/`create`/`show`/`edit`),
  each rendering a thin Blade wrapper that mounts a component.
- Routes are restricted to those GET actions
  (`Route::resource(...)->only([...])`).
- `App\Livewire\{Plural}\{Index, Form, Show}` components (with views under
  `resources/views/livewire/{slug}/`) handle the listing, the create/update form
  (validation + persistence via the service), and the detail view. All writes
  happen in the components.

Both stacks delegate persistence to the generated `Service` classes, so business
logic lives in one place regardless of front end.

## The versioned API scaffold

`--api` generates a versioned, JSON-only API:

- Controllers under `App\Http\Controllers\Api\V{n}\`.
- A `ForceJsonResponse` middleware + `ForceJsonApiServiceProvider` that lock the
  API's requests and exception responses to JSON.
- Versioned route files under `routes/api/v{n}.php`.
- Form requests and services (implied by `--api`).

```bash
php artisan anvil:generate --api --api-version=2 --tables=posts
```

## OpenAPI & Swagger UI

`--openapi` produces an **OpenAPI 3.1** specification under `openapi/`:

- **Split-file mode** (default) — one file per schema and path under
  `openapi/schemas/` and `openapi/paths/`, stitched together by a root
  `openapi.yaml` that references them.
- **Single-file mode** (`--openapi-single-file`) — everything inlined into one
  document.

Interactive docs are served at `/docs` by Anvil's `DocsController`, which bundles
the split files into one self-contained document on the fly so Swagger UI
resolves every reference. Enable the route in config and visit:

```
http://your-app.test/docs
```

```bash
php artisan anvil:generate --openapi --force
php artisan anvil:docs
```

> Regenerate with `--force` after schema changes — existing spec files are
> otherwise left untouched.

## Configuration

`config/anvil.php` controls defaults. The most useful keys:

```php
return [
    // Models
    'namespace'     => 'App\\Models',
    'target_path'   => 'app',
    'connection'    => null,                 // null = default connection
    'ignore_tables' => ['migrations', 'password_reset_tokens', 'sessions'],

    // Web scaffold
    'web' => [
        'controller_namespace' => 'App\\Http\\Controllers\\Web',
        'route_file'           => 'routes/web.php',
        'middleware'           => ['web', 'auth'],
        'layout'               => 'layouts.anvil',
        'generate_layout'      => true,
        'generate_nav'         => true,
        'livewire' => [
            'namespace' => 'App\\Livewire',
        ],
    ],

    // OpenAPI
    'openapi' => [
        'format'      => 'yaml',             // yaml | json
        'split_files' => true,
        'output_path' => 'openapi',
        'title'       => null,               // defaults to app name
        'api_version' => 'v1',
        'security'    => 'sanctum',          // sanctum | passport | none
        'docs' => [
            'enabled'    => true,
            'route'      => 'docs',
            'middleware' => [],
            'ui_version' => '5.17.14',
        ],
    ],

    // Large-schema safety prompt
    'validation' => [
        'confirm_threshold' => 50,
    ],
];
```

## Architecture

Anvil is built around a small, explicit pipeline:

- **`Contracts\Generator`** — every generator implements `supports()`,
  `getName()` and `generate(ModelMetadata, GenerationOptions)`. Generators that
  need a once-per-run step (e.g. writing the OpenAPI root spec) also expose
  `finalize()`.
- **`GenerationOptions`** — an immutable DTO carrying every flag through the
  pipeline, built from the command line, an array, or config defaults.
- **`GenerationOrchestrator`** — resolves the registered generators, runs the
  per-model pass, then the finalization pass.
- **`RunsGenerationPipeline`** — a trait shared by `anvil:generate` and
  `anvil:generate-web` so both commands run the identical pipeline and never
  diverge.
- **`DatabaseInspector` / `ModelMetadata`** — read the schema and normalise it
  into the metadata every generator consumes.
- **`LaravelAnvilServiceProvider`** — registers the commands and the generator
  list.

## Extending Anvil

Add your own artifact by implementing the contract and registering it:

```php
namespace App\Anvil;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

final class SidecarGenerator implements Generator
{
    public function supports(GenerationOptions $options): bool
    {
        return $options->services; // or your own flag
    }

    public function getName(): string
    {
        return 'Sidecar';
    }

    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        // ...write your file, return a result row...
        return [[
            'type'   => $this->getName(),
            'name'   => $meta->model,
            'status' => 'success',
        ]];
    }
}
```

Register it in your own service provider by adding the class to Anvil's
generator list. Resolve generators **through the container** (`app()->make()`)
rather than `new`, so any constructor dependencies autowire correctly.

## Working with legacy schemas

Anvil aims to be robust against databases that don't follow Laravel
conventions:

- **Non-`id` primary keys** — views and route bindings resolve the real key
  column rather than assuming `id`.
- **Tables without timestamps** — repositories order listings by an existing
  column (timestamps if present, otherwise the primary key) instead of blindly
  using `created_at`.
- **Composite / missing keys** — handled gracefully where a single-column key is
  required.

If you hit a schema shape that isn't handled well, please open an issue with the
DDL — these cases are exactly what hardens the tool.

## Security

Anvil generates code from database identifiers and serves documentation, so a
few notes:

- It is a **development tool**; install it with `--dev` and don't ship it to
  production.
- Generated code is plain Laravel that you review and own — treat it as your own
  code for audit purposes.
- The Swagger docs route is configurable; lock it behind middleware (or disable
  it) for any non-local environment via `anvil.openapi.docs.middleware` /
  `anvil.openapi.docs.enabled`.
- Recommended companions for auditing generated output: `composer audit`,
  `roave/security-advisories`, Larastan/PHPStan, and a SAST pass (Psalm taint
  analysis, Semgrep, or progpilot).

## Contributing

Issues and pull requests are welcome. Please include the database DDL (or a
minimal reproduction) for any generation bug, and run the test suite plus static
analysis before submitting.

## License

Laravel Anvil is open-source software released under the **MIT License**.
