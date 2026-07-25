<p align="center">
  <img src="art/anvil-logo.svg" alt="Laravel Anvil" width="140" height="140">
</p>

<h1 align="center">Laravel Anvil</h1>

<p align="center">
  <strong>Forge a complete Laravel application from your database.</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/zuqongtech/laravel-anvil"><img alt="Packagist version" src="https://img.shields.io/packagist/v/zuqongtech/laravel-anvil?style=flat-square"></a>
  <a href="https://packagist.org/packages/zuqongtech/laravel-anvil"><img alt="PHP version" src="https://img.shields.io/packagist/dependency-v/zuqongtech/laravel-anvil/php?style=flat-square"></a>
  <a href="https://packagist.org/packages/zuqongtech/laravel-anvil"><img alt="Downloads" src="https://img.shields.io/packagist/dt/zuqongtech/laravel-anvil?style=flat-square"></a>
  <a href="LICENSE.md"><img alt="License" src="https://img.shields.io/packagist/l/zuqongtech/laravel-anvil?style=flat-square"></a>
</p>

**Laravel Anvil** introspects a live database and hammers your raw schema into a
full, idiomatic Laravel scaffold — models, controllers, form requests, API
resources, services, repositories, policies, gates, observers, events, listeners,
factories, seeders, migrations and tests — plus **versioned JSON APIs** (each
version with its own key casing, pagination and hidden fields), an **OpenAPI 3.1**
specification with Swagger UI, a **web CRUD front end** in pure Blade + Tailwind
or Blade + Livewire, and a **Livewire authentication scaffold** with RBAC.

Point it at a database, run one command, and get working code you own.

## Table of contents

- [Why Anvil](#why-anvil)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Commands](#commands)
  - [`anvil:generate`](#anvilgenerate)
  - [`anvil:generate-api`](#anvilgenerate-api)
  - [`anvil:generate-apidocs`](#anvilgenerate-apidocs)
  - [`anvil:generate-web`](#anvilgenerate-web)
  - [`anvil:generate-auth`](#anvilgenerate-auth)
- [Versioned API scaffold](#versioned-api-scaffold)
- [Per-version shape profiles](#per-version-shape-profiles)
- [OpenAPI & Swagger UI](#openapi--swagger-ui)
- [Events & listeners](#events--listeners)
- [Relation naming](#relation-naming)
- [Web scaffold](#web-scaffold)
- [Auth scaffold](#auth-scaffold)
- [Configuration](#configuration)
- [Architecture](#architecture)
- [Extending Anvil](#extending-anvil)
- [Working with legacy schemas](#working-with-legacy-schemas)
- [Troubleshooting](#troubleshooting)
- [Upgrading](#upgrading)
- [Security](#security)
- [Contributing](#contributing)
- [License](#license)

## Why Anvil

Most generators work from migrations or hand-written model definitions. Anvil
works from the **database itself** — reading columns, types, primary keys, foreign
keys, unique constraints, indexes and soft-delete markers directly from the live
connection. That makes it equally at home on a greenfield Laravel app and on a
pre-existing or framework-foreign database: non-`id` primary keys, tables without
timestamps, multiple schemas, composite keys, two foreign keys from one table to
the same parent.

The output is plain Laravel code with **no runtime dependency on Anvil**. Generate
it, commit it, edit it freely — Anvil is a build-time tool, not a framework.

## Requirements

| Requirement    | Version                                    |
| -------------- | ------------------------------------------ |
| PHP            | `^8.3`                                     |
| Laravel        | `^11.0 \|\| ^12.0`                         |
| `symfony/yaml` | `^6.0 \|\| ^7.0` (pulled in automatically) |

Optional, depending on what you generate:

- **Livewire 3** (`livewire/livewire`) — for `anvil:generate-web --stack=livewire`
  and for `anvil:generate-auth`.
- A supported driver: **PostgreSQL**, **MySQL/MariaDB**, or **SQLite**.

The generated web scaffold styles itself with **Tailwind CSS via CDN**, so no
front-end build step is needed to see it working.

## Installation

```bash
composer require zuqongtech/laravel-anvil --dev
php artisan vendor:publish --tag=anvil-config
```

Anvil is a development dependency — it generates code; it does not run in
production.

> **Re-publish after upgrading.** `mergeConfigFrom()` is **shallow**: a published
> `config/anvil.php` containing an `api` or `openapi` key _replaces_ that whole
> subtree, so new keys do not fall back to the package defaults. After an upgrade
> run `php artisan vendor:publish --tag=anvil-config --force` (or add the missing
> keys by hand), then `php artisan config:clear`.

For the Livewire stack:

```bash
composer require livewire/livewire
```

## Quick start

```bash
# Models for every table in the default connection
php artisan anvil:generate

# The kitchen sink for two tables
php artisan anvil:generate --all --tables=posts --tables=comments

# A versioned JSON API (v1) plus its OpenAPI spec and Swagger UI
php artisan anvil:generate-api --api-version=1 --ui

# A second version with a different wire format
php artisan anvil:generate-api --api-version=2 --case=camel --pagination=25

# Where are the docs?
php artisan anvil:generate-apidocs --check

# A web CRUD front end
php artisan anvil:generate-web --tables=posts
php artisan anvil:generate-web --stack=livewire --tables=posts

# Login, register, 2FA, lockout and RBAC as Livewire components
php artisan anvil:generate-auth
```

Preview anything without writing files by adding `--dry-run`.

## Commands

Anvil ships five Artisan commands. Each owns one slice of the output; all of them
run the same generation pipeline through the `RunsGenerationPipeline` trait, so
behaviour never diverges between them.

| Command                  | Produces                                                            |
| ------------------------ | ------------------------------------------------------------------- |
| `anvil:generate`         | Models and the core per-model artifacts                             |
| `anvil:generate-api`     | Versioned JSON API + OpenAPI spec (alias: `anvil:generate-openapi`) |
| `anvil:generate-apidocs` | Generates and reports the docs for one or all versions              |
| `anvil:generate-web`     | Web CRUD front end (Blade or Livewire)                              |
| `anvil:generate-auth`    | Livewire auth + RBAC from the users table                           |

---

### `anvil:generate`

The core scaffold. Models are always generated (unless skipped); every other
artifact is opt-in via a flag, or all at once with `--all`.

```bash
php artisan anvil:generate [options]
```

**Artifact flags**

| Flag              | Generates                                                                                                                   |
| ----------------- | --------------------------------------------------------------------------------------------------------------------------- |
| `--all`           | Every artifact type below                                                                                                   |
| `--models`        | Eloquent models (on by default)                                                                                             |
| `--controllers`   | Resource controllers in `App\Http\Controllers`                                                                              |
| `--resources`     | Unversioned API resources in `App\Http\Resources`                                                                           |
| `--observers`     | Model observers                                                                                                             |
| `--policies`      | Authorization policies                                                                                                      |
| `--form-requests` | Unversioned `StoreXxx` / `UpdateXxx` form requests                                                                          |
| `--services`      | Service classes with lifecycle hooks — shared by every front end                                                            |
| `--repositories`  | Repository interface + Eloquent implementation (auto-registers its provider)                                                |
| `--gates`         | Gate definitions appended to your auth provider                                                                             |
| `--api-routes`    | Plain `apiResource` routes appended to `routes/api.php` (unversioned — see `anvil:generate-api` for the versioned scaffold) |
| `--factories`     | Model factories with Faker-inferred definitions                                                                             |
| `--seeders`       | Database seeders                                                                                                            |
| `--migrations`    | Reverse-engineered `Schema::create()` migrations                                                                            |
| `--events`        | `Created` / `Updated` / `Deleted` (+ `Restored`) event classes                                                              |
| `--listeners`     | Handlers for those events — **implies `--events`**                                                                          |
| `--tests`         | Feature tests for the CRUD endpoints                                                                                        |

**Listener flags**

| Flag                 | Default     | Description                                                           |
| -------------------- | ----------- | --------------------------------------------------------------------- |
| `--listener-style=`  | `per-event` | `per-event` → one class per event; `subscriber` → one class per model |
| `--queued-listeners` | off         | Listeners implement `ShouldQueue` (per-event style only)              |

**Targeting**

| Flag            | Default            | Description                             |
| --------------- | ------------------ | --------------------------------------- |
| `--tables=*`    | all                | Limit to specific tables (repeatable)   |
| `--only=*`      | —                  | Alias for `--tables`                    |
| `--ignore=*`    | config             | Exclude specific tables (repeatable)    |
| `--connection=` | `database.default` | Connection to introspect                |
| `--schema=`     | connection default | Schema(s): a name, a CSV list, or `all` |
| `--namespace=`  | `App\Models`       | Namespace for generated models          |
| `--path=`       | `app`              | Base path for generated models          |

**Write behaviour**

| Flag        | Description                                                   |
| ----------- | ------------------------------------------------------------- |
| `--force`   | Overwrite existing files without prompting                    |
| `--backup`  | Copy existing files to `*.bak.{timestamp}` before overwriting |
| `--dry-run` | Report what would be written; touch nothing                   |

**Model detail**

| Flag                     | Description                                            |
| ------------------------ | ------------------------------------------------------ |
| `--with-phpdoc`          | `@property` / `@method` blocks on models               |
| `--with-inverse`         | Generate `hasMany` / `hasOne` inverse relations        |
| `--with-constraints`     | Embed constraint metadata as model comments            |
| `--validate-fk`          | Validate every foreign-key reference before generating |
| `--analyze-constraints`  | Print a constraint summary first                       |
| `--show-recommendations` | Print schema optimisation suggestions                  |

**Deprecated** — these forward to `anvil:generate-api` with a warning and will be
removed in the next major: `--api`, `--api-version`, `--openapi`,
`--openapi-format`, `--openapi-single-file`, `--openapi-ui`.

> Files that already exist are skipped unless you pass `--force`. When iterating
> on a schema, `--force --backup` is usually what you want.

---

### `anvil:generate-api`

Everything a versioned JSON API needs, plus its OpenAPI specification. Aliased as
`anvil:generate-openapi`, which reads better with `--spec-only`.

```bash
php artisan anvil:generate-api [options]
```

**Version & routing**

| Flag             | Default       | Description                                       |
| ---------------- | ------------- | ------------------------------------------------- |
| `--api-version=` | `1`           | Version to generate; accepts `1`, `v1`, `V1`      |
| `--prefix=`      | `api`         | Route prefix, giving `/api/v1/...`                |
| `--auth=`        | `sanctum`     | `sanctum`, `passport`, `jwt`, `token`, `none`     |
| `--guard=`       | from `--auth` | Explicit guard name                               |
| `--middleware=*` | —             | Extra middleware for the route group (repeatable) |
| `--throttle=`    | `60,1`        | Rate limiter, or `none`                           |

`--auth` is the single value that decides **both** the route middleware and the
spec's `securityScheme`, so the running API and its documentation cannot disagree.

**Payload shape** (see [per-version profiles](#per-version-shape-profiles))

| Flag                   | Default           | Description                                                             |
| ---------------------- | ----------------- | ----------------------------------------------------------------------- |
| `--case=`              | `snake`           | Key casing both directions: `snake`, `camel`, `studly`, `kebab`, `none` |
| `--request-case=`      | `--case`          | Inbound casing only                                                     |
| `--response-case=`     | `--case`          | Outbound casing only                                                    |
| `--pagination=`        | `15`              | Default page size                                                       |
| `--pagination-max=`    | `100`             | Maximum a client may request                                            |
| `--pagination-param=`  | `per_page`, cased | Page-size query parameter                                               |
| `--hidden=*`           | config            | Columns omitted from every response (repeatable)                        |
| `--flat-requests`      | off               | Don't group request classes in per-model subdirectories                 |
| `--versioned-services` | off               | Emit a per-version service **subclass** instead of sharing one          |

**What to generate**

| Flag              | Description                                        |
| ----------------- | -------------------------------------------------- |
| `--no-force-json` | Skip the `ForceJsonResponse` middleware + provider |
| `--no-resources`  | Skip API resource classes                          |
| `--no-tests`      | Skip feature tests                                 |
| `--no-spec`       | Scaffold only, no OpenAPI document                 |
| `--spec-only`     | Spec only, no scaffold (models must already exist) |

**Specification**

| Flag             | Default       | Description                                              |
| ---------------- | ------------- | -------------------------------------------------------- |
| `--format=`      | `yaml`        | `yaml` or `json`                                         |
| `--single-file`  | off           | Inline schemas and paths into one document               |
| `--output=`      | `openapi`     | Root output directory                                    |
| `--flat`         | off           | Write to the output root instead of `openapi/v{n}/`      |
| `--security=`    | from `--auth` | `sanctum`, `passport`, `bearer`, `apikey`, `none`        |
| `--server=*`     | derived       | Explicit server URL for the `servers` block (repeatable) |
| `--title=`       | `app.name`    | Specification title                                      |
| `--description=` | generated     | Specification description                                |
| `--ui`           | off           | Publish a static Swagger UI for this version             |
| `--ui-version=`  | `5.17.14`     | `swagger-ui-dist` version to load from the CDN           |
| `--route=`       | `docs`        | Route the interactive docs are served from               |

Plus the same targeting and write-behaviour flags as `anvil:generate`
(`--tables`, `--only`, `--ignore`, `--connection`, `--schema`, `--namespace`,
`--path`, `--force`, `--backup`, `--dry-run`).

The summary table printed before generation shows the resolved profile — casing,
pagination, hidden fields, namespaces — so a mistake is visible before 32 tables
are processed.

---

### `anvil:generate-apidocs`

Generates the documentation for one or every version, then reports where each
lives. Generation is delegated to `anvil:generate-api --spec-only`, so there is
exactly one implementation of the spec pipeline.

```bash
php artisan anvil:generate-apidocs [options]
```

| Flag                                                     | Description                                               |
| -------------------------------------------------------- | --------------------------------------------------------- |
| `--api-version=`                                         | Target a single version; default is the configured one    |
| `--all-versions`                                         | Target every version already present on disk              |
| `--check`                                                | Report only — generate nothing                            |
| `--strict`                                               | Exit non-zero if a targeted version has no spec (CI gate) |
| `--force`                                                | Overwrite an existing spec                                |
| `--ui`                                                   | Publish the static Swagger UI too                         |
| `--format=`                                              | `yaml` or `json`                                          |
| `--single-file`                                          | Merge schemas and paths into one document                 |
| `--connection=`, `--schema=`, `--tables=*`, `--ignore=*` | Passed through to the generator                           |
| `--dry-run`                                              | Preview without writing                                   |
| `--json`                                                 | Machine-readable output (implies `--check`)               |
| `--open`                                                 | Open the docs URL in the default browser                  |

```bash
php artisan anvil:generate-apidocs --check              # what exists?
php artisan anvil:generate-apidocs --all-versions --force
php artisan anvil:generate-apidocs --check --strict      # fail a build on a missing spec
```

`--check --strict` is the useful CI invocation: it asserts that the committed spec
matches what the current schema would produce.

---

### `anvil:generate-web`

A complete web CRUD front end — resource controllers, web routes and views.

```bash
php artisan anvil:generate-web [options]
```

| Flag                                   | Default             | Description                                                   |
| -------------------------------------- | ------------------- | ------------------------------------------------------------- |
| `--stack=`                             | `blade`             | `blade` (Blade + Tailwind) or `livewire` (Blade + Livewire 3) |
| `--layout=`                            | config              | Blade layout the views `@extends`                             |
| `--skip-models`                        | off                 | Assume models already exist                                   |
| `--no-inverse`                         | off                 | Skip inverse-relationship detection when models are generated |
| `--tables=*`, `--only=*`, `--ignore=*` |                     | Targeting                                                     |
| `--connection=`, `--schema=`           |                     | Source                                                        |
| `--namespace=`, `--path=`              | `App\Models`, `app` | Model resolution                                              |
| `--force`, `--backup`, `--dry-run`     |                     | Write behaviour                                               |

The web scaffold reuses the same **services** and **form requests** as the rest of
the app, so those are generated alongside it.

---

### `anvil:generate-auth`

Scaffolds authentication and authorization as Livewire 3 components, driven by
introspection of the users table and its role/permission relationships.

```bash
php artisan anvil:generate-auth [options]
```

| Flag                               | Default                   | Description                               |
| ---------------------------------- | ------------------------- | ----------------------------------------- |
| `--users-table=`                   | `users`                   | The authenticatable table                 |
| `--guard=`                         | `web`                     | Guard the components authenticate against |
| `--namespace=`                     | `App\Livewire\Auth`       | Namespace for the generated components    |
| `--layout=`                        | generates `layouts.guest` | Guest layout to extend                    |
| `--default-role=`                  | —                         | Role assigned to newly registered users   |
| `--roles-table=`                   | `roles`                   | RBAC roles table                          |
| `--permissions-table=`             | `permissions`             | RBAC permissions table                    |
| `--no-2fa`                         | off                       | Skip two-factor authentication            |
| `--no-lockout`                     | off                       | Skip account lockout + login throttling   |
| `--no-verification`                | off                       | Skip the email verification flow          |
| `--connection=`, `--schema=`       |                           | Source                                    |
| `--force`, `--backup`, `--dry-run` |                           | Write behaviour                           |

Generates login, register, logout, forgot/reset password, email verification,
two-factor authentication, account lockout with throttling, RBAC middleware and
gates backed by your own roles/permissions tables, a `User` authorization trait, a
guest layout, and the auth routes. It reports whether full RBAC was detected or
only a `role` column, and prints post-install notes.

---

## Versioned API scaffold

`anvil:generate-api --api-version=2` produces a complete, self-contained version:

```
app/Http/
  Controllers/Api/V2/
    ApiController.php              # base: envelope helpers, never overwritten
    UserController.php
  Requests/Api/V2/
    ApiFormRequest.php             # base: key mapping, error mapping, perPage()
    User/IndexRequest.php          # validated sort/direction/page size
    User/StoreRequest.php
    User/UpdateRequest.php
  Resources/Api/V2/
    ApiResource.php                # base: output casing, hidden-field removal
    UserResource.php
    UserCollection.php             # pagination envelope
routes/api/v2.php
openapi/v2/openapi.yaml + schemas/ + paths/
```

Also generated once: `App\Http\Middleware\ForceJsonResponse` and
`App\Providers\ForceJsonApiServiceProvider`, which lock the group's requests and
exception responses to JSON and load the versioned route file. The provider is
registered in `bootstrap/providers.php` automatically.

**Services are shared, not versioned.** `App\Services\{Model}Service` is used by
every version and by the web scaffold, because business logic belongs in one
place — a per-version copy drifts, and a fix applied to one is silently missing
from the other. What legitimately differs between versions is the _shape_ of input
and output, which is what the versioned requests and resources are for. When
behaviour genuinely differs, `--versioned-services` emits
`App\Services\Api\V2\{Model}Service extends App\Services\{Model}Service` — a thin
subclass with the override points documented, never a copy, and never overwritten
once created.

## Per-version shape profiles

Each version resolves its settings from `anvil.api.defaults` deep-merged with
`anvil.api.versions.{vN}`:

```php
'api' => [
    'defaults' => [
        'pagination' => ['default' => 15, 'max' => 100, 'param' => null, 'page_param' => 'page'],
        'case'       => ['request' => 'snake', 'response' => 'snake'],
        'hidden'     => ['password', 'remember_token', 'two_factor_secret'],
        'read_only'  => ['id', 'created_at', 'updated_at', 'deleted_at'],
        'group_by_model'     => true,
        'versioned_services' => false,
        'namespaces' => [
            'requests'    => 'App\\Http\\Requests\\Api',
            'resources'   => 'App\\Http\\Resources\\Api',
            'controllers' => 'App\\Http\\Controllers\\Api',
            'services'    => 'App\\Services\\Api',
        ],
    ],

    'versions' => [
        'v1' => [],                                   // pure defaults
        'v2' => [
            'case'       => ['request' => 'camel', 'response' => 'camel'],
            'pagination' => ['default' => 25, 'max' => 200],
            'hidden'     => ['password', 'remember_token', 'internal_notes'],
        ],
    ],
],
```

A version states only what it changes. **List values replace rather than append**,
so a version can also hide _less_ than the default.

One object — `ApiVersionProfile` — is the authority, and the form requests, the
resources, the controllers **and the OpenAPI schemas** all read from it. That is
the reason the spec cannot describe an API that doesn't exist.

### Key casing

Set `case.request` / `case.response` and the whole surface follows:

```bash
curl '…/api/v1/users?per_page=2'   # {"data":[{"email_verified_at": …}]}
curl '…/api/v2/users?perPage=2'    # {"data":[{"emailVerifiedAt":  …}]}
```

Internally everything stays in column names. Requests translate inbound keys
before validation, so `rules()` is keyed by column; errors are translated _back_,
so a camelCase client that posts `assignedAgentId` gets an error keyed
`assignedAgentId` rather than `assigned_agent_id`.

The translation uses an **explicit map generated from the real column list**, not a
runtime `Str::snake()`. That matters: `address_line_1` camelises to `addressLine1`,
which snakes back to `address_line1` — a different column. A runtime round-trip
drops the field silently, with no error anywhere. Columns whose names are lossy
that way are reported as warnings in the run summary.

### Pagination

`--pagination` / `--pagination-max` become constants on the version's base
request, and `perPage()` clamps whatever the client asks for. `?per_page=999999`
returns 100 rows, not a table scan. The parameter name defaults to `per_page`
cased for the version, so a camelCase v2 reads `?perPage=`.

### Hidden fields

Hidden columns are enforced twice: excluded from the generated resource _and_
filtered at runtime by the base class. Adding a secret column to the table cannot
leak through a resource generated months ago, and a hand-edit that re-adds it is
still caught. `password` remains in the _request_ schema, since it is writable but
never returned.

## OpenAPI & Swagger UI

The spec is written per version:

```
openapi/
  v1/openapi.yaml   +  schemas/  paths/
  v2/openapi.yaml   +  schemas/  paths/
```

Set `openapi.versioned_output => false` (or pass `--flat`) for the un-versioned
layout. Per model you get `{Model}Resource`, `{Model}Request` and
`{Model}Collection` schemas; the raw entity schema is opt-in via
`openapi.include_entity_schema` since no path references it.

**Split-file mode** (default) writes one file per schema and path, stitched
together by a root `openapi.yaml` that `$ref`s them. **Single-file mode**
(`--single-file`) inlines everything.

### Serving the docs

Two independent mechanisms:

| URL                          | Served by            | Notes                                      |
| ---------------------------- | -------------------- | ------------------------------------------ |
| `/docs`                      | `DocsController`     | Swagger UI, default version                |
| `/docs/v1`                   | `DocsController`     | Swagger UI for v1, with a version switcher |
| `/docs/v1/openapi.yaml`      | `DocsController`     | The root spec, **bundled**                 |
| `/docs/v1/schemas/User.yaml` | `DocsController`     | A raw split file                           |
| `/api-docs/v1/index.html`    | static file (`--ui`) | No PHP involved                            |

The dynamic route bundles the split `$ref` files into one self-contained document
on the fly. That is necessary, not decorative: operations inside a path file use
pointers like `#/components/schemas/User`, which only resolve against the document
they live in — and a path file has no `components` section, so Swagger UI would
throw _"JSON Pointer evaluation failed"_.

`docs.public_path` (default `api-docs`) **must differ from** `docs.route`.
Publishing the static bundle to `public/docs` makes that directory exist on disk,
and both `php artisan serve` and an nginx `try_files` block then hand `/docs` to
the static handler instead of PHP — the route silently stops working.

Set `openapi.docs.remote_base` to serve a spec published elsewhere (a CDN, a docs
bucket). Leave it null to read from local disk. Do **not** point it at this
application's own URL.

## Events & listeners

```bash
php artisan anvil:generate --events --listeners
php artisan anvil:generate --listeners --queued-listeners
php artisan anvil:generate --listeners --listener-style=subscriber
```

Events: `{Model}Created`, `{Model}Updated`, `{Model}Deleted`, plus
`{Model}Restored` for soft-deleting models.

Listeners, `per-event` style: `App\Listeners\{Model}\CreatedListener` and
siblings. Laravel 11+ discovers listeners under `app/Listeners` by convention —
the `handle()` parameter type _is_ the registration, so no provider mapping is
needed. `--queued-listeners` adds `ShouldQueue`, `InteractsWithQueue`,
`$tries`/`$backoff`/`$queue` and a `failed()` hook.

`subscriber` style gives one `{Model}EventSubscriber` per model with a
`subscribe()` map. Subscribers are **not** auto-discovered; the generated class
documents the one-line `Event::subscribe(...)` call. `--queued-listeners` does not
apply to this style and the command says so rather than ignoring it.

`--listeners` implies `--events`: a listener whose `handle()` type-hints a missing
event class breaks listener discovery for the entire directory.

## Relation naming

When a child table references the same parent more than once, the plural of the
child model is ambiguous. Anvil qualifies from the foreign key column:

```
users ← vehicle_bookings.customer_id              →  customerVehicleBookings()
users ← vehicle_bookings.assigned_agent_id        →  assignedAgentVehicleBookings()
users ← vehicle_price_adjustment_logs.adjusted_by →  adjustedVehiclePriceAdjustmentLogs()
users ← page_visits.user_id                       →  pageVisits()   (single key)
```

Set `relationships.inverse_naming => 'suffix'` for
`vehicleBookingsCustomer()` instead.

Behind the heuristic sits a claim registry: every emitted method name is recorded,
Eloquent's own API and all column-derived accessors are pre-claimed, and a name
that is already taken gets a deterministic fallback. A relation can therefore never
shadow a column, override `save()`, or — the case this exists for — produce two
methods with the same name, which is a fatal redeclaration that takes down
`route:list` and every request touching the model.

Names are decided **once**, on `ModelMetadata`, and read from there by the model
generator (methods _and_ PHPDoc), the API resources and the OpenAPI schemas. Any
generator computing its own would eventually disagree with the others.

## Web scaffold

Both stacks share a generated base layout
(`resources/views/layouts/anvil.blade.php`) with a collapsible sidebar whose links
are **discovered at runtime** from your registered web routes — resources appear
automatically and the nav stays correct as you add or remove tables, with no
regeneration. The sidebar collapses on desktop (remembered via `localStorage`) and
slides in as an overlay on mobile.

### `--stack=blade` (default)

- `App\Http\Controllers\Web\{Model}Controller` — full resource controller
  (`index`/`create`/`store`/`show`/`edit`/`update`/`destroy`, plus
  `restore`/`forceDelete` for soft-delete models), returning Blade views and
  redirecting with flash messages.
- `Route::resource(...)` in `routes/web.php`, inside a managed middleware group.
- `resources/views/{slug}/` — `index`, `create`, `edit`, `show` and a shared
  `_form` partial, with inputs inferred from column types.

### `--stack=livewire`

Requests flow **controller → Blade view → Livewire component**:

- The controller handles only the GET endpoints, each rendering a thin wrapper
  that mounts a component; routes are restricted with `->only([...])`.
- `App\Livewire\{Plural}\{Index, Form, Show}` handle listing, create/update
  (validation + persistence via the service) and detail. All writes happen in the
  components.

Both stacks delegate persistence to the generated services, so business logic
lives in one place regardless of front end.

## Auth scaffold

`anvil:generate-auth` reads the users table and its relationships, then generates
Livewire components for the full authentication surface plus RBAC wiring. It
detects whether you have proper roles/permissions tables or just a `role` column
and adapts, and it validates that the target table exists before writing anything.

## Configuration

`config/anvil.php`. The keys most worth knowing:

```php
return [
    'namespace'     => 'App\\Models',
    'target_path'   => 'app',
    'connection'    => null,                  // null = database.default
    'ignore_tables' => ['migrations', 'sessions', 'cache', 'jobs', …],
    'ignore_table_patterns' => ['/^temp_/', '/^backup_/'],

    // Versioned API — see "Per-version shape profiles"
    'api' => [
        'version'    => 'v1',
        'prefix'     => 'api',
        'auth'       => 'sanctum',
        'throttle'   => '60,1',
        'force_json' => true,
        'defaults'   => [ /* pagination, case, hidden, namespaces */ ],
        'versions'   => [ 'v1' => [], 'v2' => [ /* overrides */ ] ],
    ],

    'relationships' => [
        'inverse_naming' => 'prefix',         // prefix | suffix
        'validate_foreign_keys' => false,
    ],

    'events' => [
        'namespace'          => 'App\\Events',
        'listeners'          => false,
        'listener_namespace' => 'App\\Listeners',
        'listener_style'     => 'per-event',  // per-event | subscriber
        'queued_listeners'   => false,
    ],

    'openapi' => [
        'title'            => null,           // null → config('app.name')
        'output_path'      => 'openapi',
        'versioned_output' => true,           // openapi/v1/, openapi/v2/
        'format'           => 'yaml',         // yaml | json
        'split_files'      => true,
        'spec_version'     => '3.1.0',        // the OpenAPI version itself
        'api_version'      => 'v1',           // which API version is written
        'security'         => 'sanctum',
        'servers'          => [],
        'include_entity_schema' => false,

        'docs' => [
            'enabled'     => env('ANVIL_DOCS_ENABLED', env('APP_ENV') === 'local'),
            'route'       => 'docs',          // dynamic, DocsController
            'public_path' => 'api-docs',      // static bundle — MUST differ from route
            'middleware'  => ['web'],         // production: ['web', 'auth']
            'ui_version'  => '5.17.14',
            'remote_base' => null,
        ],
    ],

    'web' => [
        'controller_namespace' => 'App\\Http\\Controllers\\Web',
        'route_file'      => 'routes/web.php',
        'middleware'      => ['web', 'auth'],
        'layout'          => 'layouts.anvil',
        'generate_layout' => true,
        'generate_nav'    => true,
        'livewire'        => ['namespace' => 'App\\Livewire'],
    ],

    'validation' => [
        'confirm_threshold' => 50,            // prompt above this many tables
    ],

    'custom_generators' => [],
];
```

`generators.*` holds per-generator namespaces and options (controllers, resources,
form requests, services, repositories, gates, factories, seeders, migrations,
events, listeners, observers, policies, tests, web).

## Architecture

- **`Contracts\Generator`** — every generator implements `supports()`,
  `getName()` and `generate(ModelMetadata, GenerationOptions)`. Generators needing
  a once-per-run step (writing the OpenAPI root spec, a version's base classes)
  also expose `finalize()`.
- **`GenerationOptions`** — immutable DTO carrying every flag through the pipeline,
  built from a command, an array, or config defaults.
- **`ApiVersionProfile`** — resolves one version's shape settings: casing,
  pagination, hidden fields, namespaces, paths.
- **`ModelMetadata`** — normalised schema for one table, and the authority on
  relation method names (`relationNames()`, `belongsToName()`, `inverseName()`).
- **`RelationNamer`** — qualified naming plus the collision registry.
- **`OpenApiLocator`** — every spec path and docs URL, per version.
- **`KeyCase`** — casing conversion and the explicit map building.
- **`GenerationOrchestrator`** — runs the per-model pass, then finalization.
- **`RunsGenerationPipeline`** — trait shared by every generating command, so they
  cannot diverge.
- **`DatabaseInspector`** — reads columns, keys, indexes and constraints.
- **`LaravelAnvilServiceProvider`** — registers commands, generators and the docs
  routes.

## Extending Anvil

```php
namespace App\Anvil;

use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

final class SidecarGenerator implements Generator
{
    public function supports(GenerationOptions $options): bool
    {
        return $options->services;   // or your own config flag
    }

    public function getName(): string
    {
        return 'Sidecar';
    }

    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        // Use $meta->belongsToName()/inverseName() for relation names so your
        // output agrees with the generated models.
        return [[
            'type' => $this->getName(),
            'name' => $meta->model,
            'status' => 'success',
        ]];
    }
}
```

Register it in `config/anvil.php`:

```php
'custom_generators' => [
    App\Anvil\SidecarGenerator::class,
],
```

Custom generators run after the built-ins and are resolved **through the
container**, so constructor dependencies autowire.

## Working with legacy schemas

- **Non-`id` primary keys** — views, route bindings and spec path parameters
  resolve the real key column.
- **Composite primary keys** — `$primaryKey` array plus `$incrementing = false`.
- **Tables without timestamps** — repositories order by an existing column instead
  of assuming `created_at`.
- **Multiple schemas** — `--schema=name`, a CSV list, or `all`; models get a
  schema-qualified `$table` and a namespace segment. Reserved words are suffixed,
  so a `public` schema becomes `App\Models\PublicSchema\…` rather than the
  unparseable `App\Models\Public\…`.
- **Repeated foreign keys to one parent** — see
  [relation naming](#relation-naming).
- **Postgres default expressions** — `'monthly'::character varying` is unwrapped
  for the spec; `nextval(...)` and `CURRENT_TIMESTAMP` are omitted rather than
  documented as literal string defaults.

If you hit a schema shape that isn't handled well, open an issue with the DDL —
those cases are exactly what hardens the tool.

## Troubleshooting

**`/docs` returns the web server's own 404 page** (plain, not Laravel's). A
`public/docs` directory exists and is shadowing the route. `rm -rf public/docs`;
the static bundle belongs in `docs.public_path` (`api-docs`), which must differ
from `docs.route`.

**Swagger UI reports a CORS error fetching the spec.** The static bundle resolves
its spec URL against `window.location.origin`, so this means an older bundle is
still on disk. Delete it and re-run with `--ui`. Also set `APP_URL` correctly —
the spec's `servers` block derives from it, so "Try it out" fires at whatever it
says.

**The docs route doesn't exist.** `php artisan route:list --path=docs`. If it's
empty: `docs.enabled` is false, or routes are cached (`route:cache` bypasses
provider-registered routes entirely — run `route:clear`), or the published config
predates the key.

**A config key I added is being ignored.** `mergeConfigFrom()` is shallow — a
published `openapi` or `api` key replaces the whole subtree. Re-publish with
`--force` and `config:clear`.

**"Cannot redeclare `App\Models\X::y()`".** Two foreign keys from one table to the
same parent, generated before the naming fix. Regenerate that model with `--force`.

**A stale schema keeps appearing in the spec.** The root spec globs the schemas
directory, and `--force` overwrites but never deletes. `rm -rf openapi/v1` and
regenerate.

**Generation reports success but writes nothing.** Run
`anvil:generate-apidocs --check` to see what's actually on disk. The commands warn
explicitly when the options DTO didn't accept a flag they depend on.

## Upgrading

The API and documentation commands were split out of `anvil:generate`, and the
output moved to per-version directories.

### Command renames

| Before                                 | Now                                  |
| -------------------------------------- | ------------------------------------ |
| `anvil:generate --api`                 | `anvil:generate-api`                 |
| `anvil:generate --api --api-version=2` | `anvil:generate-api --api-version=2` |
| `anvil:generate --openapi`             | `anvil:generate-api --spec-only`     |
| `anvil:generate --openapi-format=json` | `anvil:generate-api --format=json`   |
| `anvil:generate --openapi-single-file` | `anvil:generate-api --single-file`   |
| `anvil:generate --openapi-ui`          | `anvil:generate-api --ui`            |
| `anvil:docs`                           | `anvil:generate-apidocs`             |

The old flags still work on `anvil:generate`: they print a deprecation warning and
forward to `anvil:generate-api`. They will be removed in the next major.

### Moved output

| Before                                     | Now                                          |
| ------------------------------------------ | -------------------------------------------- |
| `openapi/openapi.yaml`                     | `openapi/v1/openapi.yaml`                    |
| `openapi/schemas/`, `openapi/paths/`       | `openapi/v1/schemas/`, `openapi/v1/paths/`   |
| `public/docs/index.html` (static UI)       | `public/api-docs/v1/index.html`              |
| `App\Http\Requests\StoreUserRequest` (API) | `App\Http\Requests\Api\V1\User\StoreRequest` |
| `App\Http\Resources\UserResource` (API)    | `App\Http\Resources\Api\V1\UserResource`     |

The unversioned request and resource classes are still produced by
`anvil:generate --form-requests --resources`; only the API scaffold moved.

```bash
# Move an existing flat spec into the v1 directory
mkdir -p openapi/v1
git mv openapi/openapi.yaml openapi/schemas openapi/paths openapi/v1/

# Or keep the flat layout
# config/anvil.php: 'openapi' => ['versioned_output' => false]
```

### Required steps

```bash
# 1. The static UI must not live under the docs route any more — it shadows it
rm -rf public/docs

# 2. Re-publish the config: mergeConfigFrom is shallow, so a published
#    api/openapi key hides every new sub-key
php artisan vendor:publish --tag=anvil-config --force

# 3. Clear caches; route:cache in particular bypasses the docs routes entirely
php artisan config:clear && php artisan route:clear

# 4. Regenerate. Delete the old spec first: the root document globs the schemas
#    directory, and --force overwrites but never deletes
rm -rf openapi/v1
php artisan anvil:generate-api --api-version=1 --force --ui
```

Set `APP_URL` to the address you actually serve on. The spec's `servers` block
derives from it, so Swagger UI's "Try it out" fires requests at whatever it says.

### Regenerate models

Two model-level fixes need a regeneration to take effect:

- **Duplicate relation methods.** A child table with two foreign keys to the same
  parent used to emit two identically named `hasMany` methods — a fatal
  redeclaration. Names are now qualified from the foreign key.
- **Reserved-word schema namespaces.** A `public` schema produced
  `App\Models\Public\…`, which is not a legal namespace. It is now
  `App\Models\PublicSchema\…`; regenerate every model together, since the FQCN
  changes on both sides of each relation.

```bash
php artisan anvil:generate --all --force --backup
```

## Contributing

Issues and pull requests welcome. Please include the database DDL (or a minimal
reproduction) for any generation bug, and run the test suite plus static analysis
before submitting. Pathological schemas are especially valuable: two FKs to one
parent, composite keys, reserved words as identifiers, 200-column tables.

## License

Laravel Anvil is open-source software released under the **MIT License**.
