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

**Point it at a database, run one command, and get working code you own** .

## 1. Table of contents

## Table of contents

- [Why Anvil](#why-anvil)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Commands](#commands)
  - [Generation](#generation)
    - [`anvil:forge:app-scaffold`](#anvilforgeapp-scaffold)
    - [`anvil:forge-api`](#anvilforge-api)
    - [`anvil:forge-apidocs`](#anvilforge-apidocs)
    - [`anvil:forge-webapp`](#anvilforge-webapp)
    - [`anvil:forge-auth`](#anvilforge-auth)
    - [`anvil:forge-graphql`](#anvilforge-graphql)
    - [`anvil:forge-client`](#anvilforge-client)
  - [Inspection](#inspection)
    - [`anvil:doctor`](#anvildoctor)
    - [`anvil:diff`](#anvildiff)
    - [`anvil:docs-sync`](#anvildocs-sync)
  - [Maintenance](#maintenance)
    - [`anvil:polish`](#anvilpolish)
    - [`anvil:frontend`](#anvilfrontend)
    - [`anvil:install:swagger-ui`](#anvilinstallswagger-ui)
- [Versioned API scaffold](#versioned-api-scaffold)
- [Per-version shape profiles](#per-version-shape-profiles)
- [OpenAPI & Swagger UI](#openapi--swagger-ui)
- [Response caching](#response-caching)
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

> The `Response caching` anchor is new — see the note at the end of this file.

---

## 2. Quick start

## Quick start

```bash
# 1. Check the schema before generating anything from it
php artisan anvil:doctor

# 2. Models first — everything else resolves them from the manifest
php artisan anvil:forge:app-scaffold --models --schema=all

# 3. The kitchen sink for two tables
php artisan anvil:forge:app-scaffold --all --tables=posts --tables=comments

# 4. A versioned JSON API (v1) plus its OpenAPI spec and Swagger UI
php artisan anvil:forge-api --api-version=1 --ui

# A second version with a different wire format
php artisan anvil:forge-api --api-version=2 --case=camel --pagination=25

# Where are the docs?
php artisan anvil:forge-apidocs --check

# 5. A web CRUD front end
php artisan anvil:forge-webapp --tables=posts
php artisan anvil:forge-webapp --stack=livewire --install-assets

# Login, register, 2FA, lockout and RBAC as Livewire components
php artisan anvil:forge-auth

# 6. A typed TypeScript client for the frontend
php artisan anvil:forge-client --api-version=1 --hooks

# 7. Format, modernise and audit what was generated
php artisan anvil:polish
```

Preview anything without writing files by adding `--dry-run`.

---

## 3. Commands

## Commands

Anvil ships thirteen Artisan commands. Each owns one slice of the output, and
every generating command runs the identical pipeline through the
`RunsGenerationPipeline` trait, so behaviour never diverges between them.

### Generation

| Command                    | Produces                                                         |
| -------------------------- | ---------------------------------------------------------------- |
| `anvil:forge:app-scaffold` | Models and the core per-model artifacts                          |
| `anvil:forge-api`          | Versioned JSON API + OpenAPI spec (alias: `anvil:forge-openapi`) |
| `anvil:forge-apidocs`      | Generates and reports the docs for one or all versions           |
| `anvil:forge-webapp`       | Web CRUD front end (Blade or Livewire)                           |
| `anvil:forge-auth`         | Livewire authentication + RBAC from the users table              |
| `anvil:forge-graphql`      | Lighthouse GraphQL schema (types, inputs, queries, mutations)    |
| `anvil:forge-client`       | Typed TypeScript client for a versioned API                      |

### Inspection

| Command           | Reports                                                        |
| ----------------- | -------------------------------------------------------------- |
| `anvil:doctor`    | Schema shapes that break code generation, before they break it |
| `anvil:diff`      | What changed in the database since the last generation         |
| `anvil:docs-sync` | Drift between hand-edited payloads and the OpenAPI spec        |

### Maintenance

| Command                    | Does                                                  |
| -------------------------- | ----------------------------------------------------- |
| `anvil:polish`             | Pint, Rector, PHPStan, and a model ↔ schema audit     |
| `anvil:frontend`           | Checks or installs Livewire and Tailwind              |
| `anvil:install:swagger-ui` | Vendors the Swagger UI assets so `/docs` needs no CDN |

### Model generation comes first

Only `anvil:forge:app-scaffold --models` writes models. Every other command
**resolves** them — from `storage/anvil/models.json`, or by scanning the model
path — and imports them from whatever namespace they were actually written to.

```bash
php artisan anvil:forge:app-scaffold --models --schema=all
php artisan anvil:forge-webapp --stack=livewire
```

That split is what stops a schema-namespaced model (`App\Models\Core\User`)
being re-derived as `App\Models\User` by a downstream generator, and it stops a
web or API run silently reverting hand edits to a model. A run that needs a
model with no manifest entry and nothing on disk fails and names the table,
rather than emitting a controller that imports a class which was never written.

If the manifest is stale or missing but the models exist, rebuild it without
generating anything:

```bash
php artisan anvil:forge:app-scaffold --refresh-models
```

---

### `anvil:forge:app-scaffold`

The core scaffold. Models are always generated unless skipped; every other
artifact is opt-in via a flag, or all at once with `--all`.

```bash
php artisan anvil:forge:app-scaffold [schemas...] [options]
```

**Artifact flags**

| Flag              | Generates                                                                                                                |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------ |
| `--all`           | Every artifact type below                                                                                                |
| `--models`        | Eloquent models into schema-namespaced classes — **phase 1**, run before any other artifact                              |
| `--controllers`   | Resource controllers in `App\Http\Controllers`                                                                           |
| `--resources`     | Unversioned API resources in `App\Http\Resources`                                                                        |
| `--observers`     | Model observers                                                                                                          |
| `--policies`      | Authorization policies                                                                                                   |
| `--form-requests` | Unversioned `StoreXxx` / `UpdateXxx` form requests                                                                       |
| `--services`      | Service classes with lifecycle hooks — shared by every front end                                                         |
| `--repositories`  | Repository interface + Eloquent implementation (auto-registers its provider)                                             |
| `--gates`         | Gate definitions appended to your auth provider                                                                          |
| `--api-routes`    | Plain `apiResource` routes appended to `routes/api.php` (unversioned — see `anvil:forge-api` for the versioned scaffold) |
| `--factories`     | Model factories with Faker-inferred definitions                                                                          |
| `--seeders`       | Database seeders                                                                                                         |
| `--migrations`    | Reverse-engineered `Schema::create()` migrations                                                                         |
| `--events`        | `Created` / `Updated` / `Deleted` (+ `Restored`) event classes                                                           |
| `--listeners`     | Handlers for those events — **implies `--events`**                                                                       |
| `--tests`         | Feature tests for the CRUD endpoints                                                                                     |

**Manifest**

| Flag               | Description                                                                                                                                                              |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `--refresh-models` | Rebuild the model manifest from the models already on disk, generating nothing. Short-circuits the pipeline: it touches no database beyond resolving the default schema. |

**Listener flags**

| Flag                 | Default     | Description                                                                                           |
| -------------------- | ----------- | ----------------------------------------------------------------------------------------------------- |
| `--listener-style=`  | `per-event` | `per-event` → one class per event; `subscriber` → one class per model                                 |
| `--queued-listeners` | off         | Listeners implement `ShouldQueue` (per-event style only; the command says so rather than ignoring it) |

**Targeting**

| Flag / argument | Default            | Description                                                                                                     |
| --------------- | ------------------ | --------------------------------------------------------------------------------------------------------------- |
| `--tables=*`    | all                | Limit to specific tables (repeatable)                                                                           |
| `--only=*`      | —                  | Alias for `--tables`                                                                                            |
| `--ignore=*`    | config             | Exclude specific tables (repeatable)                                                                            |
| `--connection=` | `database.default` | Connection to introspect                                                                                        |
| `--schema=`     | connection default | Schema(s): a name, a CSV list, or `all`                                                                         |
| `--namespace=`  | `App\Models`       | Namespace for generated models                                                                                  |
| `--path=`       | `app`              | Base path for generated models                                                                                  |
| `schemas...`    | —                  | Positional recovery slot for an unquoted `--schema` list the shell split on a space. Prefer `--schema="a,b,c"`. |

> **Quote your schema list.** `--schema=core, admin_db` reaches Symfony as
> `--schema=core,` plus a stray argument, which without the catch-all would
> abort with _"No arguments expected"_ — a message that points nowhere near the
> mistake. Fragments shaped like schema names are recovered and reported;
> anything else is refused by name, so a real typo is not swallowed.

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

**Deprecated** — these print a warning and forward to `anvil:forge-api`; they
will be removed in the next major: `--api`, `--api-version`, `--openapi`,
`--openapi-format`, `--openapi-single-file`, `--openapi-ui`.

> Files that already exist are skipped unless you pass `--force`. When iterating
> on a schema, `--force --backup` is usually what you want.

---

### `anvil:forge-api`

Everything a versioned JSON API needs, plus its OpenAPI 3.1 specification.
Aliased as `anvil:forge-openapi`, which reads better with `--spec-only`.

```bash
php artisan anvil:forge-api [options]
```

**Version & routing**

| Flag             | Default       | Description                                       |
| ---------------- | ------------- | ------------------------------------------------- |
| `--api-version=` | `1`           | Version to generate; accepts `1`, `v1`, `V1`      |
| `--prefix=`      | `api`         | Route prefix, giving `/api/v1/...`                |
| `--auth=`        | `sanctum`     | `sanctum`, `passport`, `jwt`, `token`, `none`     |
| `--guard=`       | from `--auth` | Explicit guard name                               |
| `--middleware=*` | —             | Extra middleware for the route group (repeatable) |
| `--throttle=`    | `60,1`        | Rate limiter (`60` or `60,1`), or `none`          |

`--auth` is the single value that decides **both** the route middleware and the
spec's `securityScheme`, so the running API and its documentation cannot
disagree.

**Payload shape** (see [per-version profiles](#per-version-shape-profiles))

| Flag                   | Default           | Description                                                             |
| ---------------------- | ----------------- | ----------------------------------------------------------------------- |
| `--case=`              | `snake`           | Key casing both directions: `snake`, `camel`, `studly`, `kebab`, `none` |
| `--request-case=`      | `--case`          | Inbound casing only                                                     |
| `--response-case=`     | `--case`          | Outbound casing only                                                    |
| `--pagination=`        | `15`              | Default page size                                                       |
| `--pagination-max=`    | `100`             | Maximum a client may request; must be ≥ `--pagination`                  |
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

`--no-spec` and `--spec-only` are mutually exclusive — together there would be
nothing to generate, and the command says so rather than doing nothing quietly.

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

**Response caching**

Opt-in. The generated services wrap their reads in a cache layer whose
invalidation uses generation stamps rather than tags, so it works on every cache
driver — including `file` and `database`, which have no tag support.

| Flag               | Default     | Description                                                                |
| ------------------ | ----------- | -------------------------------------------------------------------------- |
| `--cache`          | off         | Generate services that cache query results                                 |
| `--no-cache`       | —           | Force caching off, overriding `anvil.cache.enabled`                        |
| `--cache-store=`   | app default | Cache store to use                                                         |
| `--cache-ttl=`     | config      | `300` for every profile, or per-profile: `single=300,list=60`              |
| `--cache-stale=`   | config      | Seconds a stale value may be served while it refreshes; `0` disables       |
| `--cache-scope=`   | `auth`      | Result isolation: `auth`, `tenant`, `none`                                 |
| `--cache-profile=` | config      | Default volatility profile applied to every model                          |
| `--cache-jitter=`  | config      | TTL randomisation as a fraction — `0.1` for ±10%, which spreads a stampede |
| `--cache-bypass`   | off         | Let callers request an uncached read. **Never enable this in production**  |
| `--cache-model=*`  | —           | Per-model override: `Category:reference`, `PriceHistory:off` (repeatable)  |
| `--etag`           | off         | Emit `ETag` / `If-None-Match` handling and document `304` in the spec      |

Versioned cached services default `$cacheVariant` to `static::class`, so a v1
and a v2 service reading the same table cannot collide on a cache key even
though they return different shapes.

**Targeting and write behaviour**

The same flags as `anvil:forge:app-scaffold`: `--tables`, `--only`, `--ignore`,
`--connection`, `--schema`, `--namespace`, `--path`, `--force`, `--backup`,
`--dry-run`.

The summary table printed before generation shows the resolved profile — casing,
pagination, hidden fields, namespaces, cache settings — so a mistake is visible
before 32 tables are processed. Free-text options are validated up front, so a
typo in `--auth`, `--format`, `--security`, `--case` or `--throttle` fails
before any file is written.

---

### `anvil:forge-apidocs`

Generates the documentation for one or every version, then reports where each
lives. Generation is delegated to `anvil:forge-api --spec-only`, so there is
exactly one implementation of the spec pipeline; this command owns version
targeting and reporting.

```bash
php artisan anvil:forge-apidocs [options]
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

`--all-versions` and `--api-version` are mutually exclusive.

```bash
php artisan anvil:forge-apidocs --check               # what exists?
php artisan anvil:forge-apidocs --all-versions --force
php artisan anvil:forge-apidocs --check --strict      # fail a build on a missing spec
```

`--check --strict` is the useful CI invocation: it asserts that the committed
spec matches what the current schema would produce. A dry run legitimately
leaves nothing on disk, so `--dry-run` never fails `--strict`.

Reporting recognises a spec written in the format you did _not_ ask for, so
switching `--format` does not make an existing spec read as missing. When a spec
is absent, the reason given distinguishes the three cases — dry run, not
generated yet, or generated-but-nothing-written — because telling someone to
re-run the command they just ran is the least useful of the three.

---

### `anvil:forge-webapp`

A complete web CRUD front end — resource controllers, web routes and views.

```bash
php artisan anvil:forge-webapp [options]
```

**This command does not generate models.** It resolves them from the manifest
(or by scanning the model path) and imports them from wherever they were
actually written.

| Flag          | Default | Description                                                   |
| ------------- | ------- | ------------------------------------------------------------- |
| `--stack=`    | `blade` | `blade` (Blade + Tailwind) or `livewire` (Blade + Livewire 3) |
| `--per-page=` | `15`    | Default rows per page in generated listings (ceiling: 500)    |

A `--per-page` outside the built-in set (10, 15, 25, 50, 100) is inserted into
the generated per-page `<select>`, so the dropdown never opens showing a value
the user never chose.

**Layout & navigation**

| Flag          | Default | Description                                                  |
| ------------- | ------- | ------------------------------------------------------------ |
| `--layout=`   | config  | Blade layout the views extend (overrides `anvil.web.layout`) |
| `--no-layout` | off     | Do not generate a base layout — you already have one         |
| `--no-nav`    | off     | Do not generate the sidebar navigation partial               |

A custom `--layout` that does not exist yet, combined with `--no-layout`,
produces views that extend a missing view. The command warns before writing
them rather than after.

**Frontend assets**

Handled by a preflight that runs **before** the pipeline. That ordering is not
cosmetic: a Composer install cannot take effect in the process that performs it,
because the autoloader is already built and the providers already registered —
so an install run exits and asks you to re-run.

| Flag                   | Default | Description                                                                    |
| ---------------------- | ------- | ------------------------------------------------------------------------------ |
| `--assets-mode=`       | config  | How views load Tailwind: `cdn`, `vite`, `none`                                 |
| `--install-assets`     | off     | Install every frontend dependency the selected stack needs                     |
| `--with-livewire`      | off     | Install Livewire 3 if the project does not already have it                     |
| `--with-tailwind`      | off     | Install and wire Tailwind if the project does not already have it              |
| `--tailwind-version=`  | `4`     | Tailwind major version to install when missing: `3` or `4`                     |
| `--no-package-manager` | off     | Write config files but print the composer/npm commands instead of running them |
| `--skip-asset-check`   | off     | Bypass the frontend preflight entirely                                         |

`--assets-mode=cdn` uses the Tailwind Play CDN, which compiles styles in the
browser and is not for production; the command says so every run.
`--assets-mode=none` means the layout loads no CSS at all.

**Targeting and write behaviour**

| Flag                                   | Description                                                                                                   |
| -------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| `--tables=*`, `--only=*`, `--ignore=*` | Targeting                                                                                                     |
| `--connection=`, `--schema=`           | Source                                                                                                        |
| `--namespace=`, `--path=`              | Where to **locate** the models, not where to write them                                                       |
| `--no-inverse`                         | Skip inverse-relationship detection                                                                           |
| `--force`, `--backup`, `--dry-run`     | Write behaviour                                                                                               |
| `--skip-models`                        | **Deprecated no-op.** This command never generated models; passing it warns rather than accepting it silently |

The web scaffold reuses the same **services** and **form requests** as the rest
of the app, so those are generated alongside it.

---

### `anvil:forge-auth`

Scaffolds authentication and authorization as Livewire 3 components, driven by
introspection of the users table and its role/permission relationships.

```bash
php artisan anvil:forge-auth [options]
```

| Flag                               | Default                   | Description                                                             |
| ---------------------------------- | ------------------------- | ----------------------------------------------------------------------- |
| `--users-table=`                   | `users`                   | The authenticatable table                                               |
| `--guard=`                         | `web`                     | Guard the components authenticate against                               |
| `--namespace=`                     | `App\Livewire\Auth`       | Namespace for the generated components                                  |
| `--layout=`                        | generates `layouts.guest` | Guest layout to extend                                                  |
| `--accent=`                        | `indigo`                  | Tailwind accent: `indigo`, `blue`, `emerald`, `violet`, `rose`, `slate` |
| `--dark`                           | off                       | Include the dark-mode toggle in the guest layout                        |
| `--default-role=`                  | —                         | Role assigned to newly registered users                                 |
| `--roles-table=`                   | `roles`                   | RBAC roles table                                                        |
| `--permissions-table=`             | `permissions`             | RBAC permissions table                                                  |
| `--no-2fa`                         | off                       | Skip two-factor authentication                                          |
| `--no-lockout`                     | off                       | Skip account lockout + login throttling                                 |
| `--no-verification`                | off                       | Skip the email verification flow                                        |
| `--connection=`, `--schema=`       |                           | Source                                                                  |
| `--force`, `--backup`, `--dry-run` |                           | Write behaviour                                                         |

Accent colours are interpolated at generation time so Tailwind's scanner sees
literal class names, not strings assembled at runtime.

**Pre-flight runs before anything is written.** Generating code that cannot
possibly run is worse than refusing, so the command checks that Livewire is
installed, that `--accent` is known, that the guard exists in `config/auth.php`
and resolves to a provider whose model class exists, that the users table is
present on the connection, and that it has `email` and `password` columns. A
missing table offers near-miss candidates rather than just refusing.

Non-fatal gaps are reported as warnings _after_ the configuration table, so the
settings they refer to are still on screen: a missing `name` column, a missing
`email_verified_at` with verification enabled, missing lockout columns, an
absent `pragmarx/google2fa`, and — the one that silently breaks logins — a
`setPasswordAttribute()` mutator on the user model, which would hash the hash
the register form already produced.

A partial scaffold exits non-zero, so CI notices.

Generates login, register, logout, forgot/reset password, email verification,
two-factor authentication, account lockout with throttling, RBAC middleware and
gates backed by your own roles/permissions tables, a `User` authorization trait,
a guest layout, and the auth routes.

---

### `anvil:forge-graphql`

Generates a Lighthouse GraphQL schema from the database.

```bash
php artisan anvil:forge-graphql [options]
```

| Flag                                                     | Default   | Description                                                  |
| -------------------------------------------------------- | --------- | ------------------------------------------------------------ |
| `--output=`                                              | `graphql` | Directory for the schema files                               |
| `--api-version=`                                         | `1`       | Version profile supplying hidden fields and pagination       |
| `--guard=`                                               | —         | Auth guard for `@guard` (empty = none, `default` = `@guard`) |
| `--policies`                                             | off       | Emit `@can` directives bound to the generated policies       |
| `--no-mutations`                                         | off       | Queries only — a read-only graph                             |
| `--single-file`                                          | off       | One `schema.graphql` instead of a file per type              |
| `--connection=`, `--schema=`, `--tables=*`, `--ignore=*` |           | Targeting                                                    |
| `--force`                                                | off       | Overwrite existing type files                                |
| `--dry-run`                                              | off       | Preview without writing                                      |

Output:

```
graphql/schema.graphql          root — imports the rest, never overwritten
graphql/scalars.graphql         scalar declarations
graphql/enums.graphql           one enum per detected enum column
graphql/types/Vehicle.graphql   type + inputs + queries + mutations
```

The root file is written once and then left alone: it is where hand-written
queries, custom mutations and subscriptions go. Everything under `types/` is
regenerated freely.

Tables with a composite primary key are skipped and reported — there is no
single ID for `@find`, and Lighthouse cannot resolve mutations against them.
Expose the relationship through its parents instead.

Requires `nuwave/lighthouse`; the command checks and explains rather than
emitting a schema nothing can serve. Running without `--guard` warns loudly:
a GraphQL endpoint with no guard exposes every type and mutation to anonymous
callers, and unlike REST there is no route list that makes that obvious.

---

### `anvil:forge-client`

Generates a typed TypeScript client for a versioned API.

```bash
php artisan anvil:forge-client [options]
```

| Flag                                                     | Default            | Description                             |
| -------------------------------------------------------- | ------------------ | --------------------------------------- |
| `--api-version=`                                         | `1`                | API version to target (`1`, `v1`, `V1`) |
| `--output=`                                              | `resources/js/api` | Output directory                        |
| `--stack=`                                               | `ts`               | Client flavour (only `ts` today)        |
| `--hooks`                                                | off                | Also emit React Query hooks             |
| `--connection=`, `--schema=`, `--tables=*`, `--ignore=*` |                    | Targeting                               |
| `--force`                                                | off                | Overwrite existing files                |
| `--dry-run`                                              | off                | Preview without writing                 |

Output:

```
resources/js/api/v2/types.ts       interfaces + payload types per model
resources/js/api/v2/client.ts      fetch wrapper, error type, pagination
resources/js/api/v2/vehicles.ts    list/get/create/update/remove per resource
resources/js/api/v2/hooks.ts       React Query hooks (--hooks)
resources/js/api/v2/index.ts       barrel
```

Everything resolves through `ApiVersionProfile` — the same object the PHP
requests and resources read — so a camelCase v2 produces camelCase interfaces
and a `?perPage=` query parameter. The types cannot drift from the API because
both are projections of one schema.

The generated `client.ts` has no dependencies. `--hooks` requires
`@tanstack/react-query` v5.

```ts
import { configure, listUsers, ApiError } from "@/api/v1";

configure({
  baseUrl: import.meta.env.VITE_API_URL,
  getToken: () => localStorage.getItem("token"),
});
```

---

### `anvil:doctor`

Reports the schema shapes that break code generation, before they break it.

```bash
php artisan anvil:doctor [options]
```

| Flag            | Description                                          |
| --------------- | ---------------------------------------------------- |
| `--connection=` | Database connection to inspect                       |
| `--schema=`     | Schema(s) to inspect: name, CSV list, or `all`       |
| `--tables=*`    | Limit the check to specific tables                   |
| `--ignore=*`    | Exclude specific tables                              |
| `--data`        | Also run checks that read row data (password hashes) |
| `--strict`      | Exit non-zero when any **error** is found            |
| `--json`        | Machine-readable output                              |

Findings are `error`, `warning` or `note`, each with a suggested fix:

- **Primary keys** — none at all, or composite
- **Duplicate foreign keys** — two FKs from one table to the same parent, the
  shape that used to produce a fatal redeclaration
- **Unindexed foreign keys** — every eager load on that relation is a sequential scan
- **Dangling foreign keys** — a parent outside the generation set
- **Reserved words** — as a column, a model name, or a schema name
- **Model collisions** — a column that camelises onto an Eloquent method, or two
  columns that camelise to the same name
- **Authenticatable tables** — a `password` column means the model must extend
  `Illuminate\Foundation\Auth\User`, not `Model`, or `SessionGuard` throws a
  `TypeError`. With `--data`, samples up to 50 stored hashes and reports any
  that are not a recognised algorithm — the cause of _"This password does not
  use the Bcrypt algorithm"_ at login.
- **Enum candidates** — a `status`/`type`/`role` column stored as free text
- **Width and timestamps** — 60+ columns, or no `created_at`/`updated_at` pair

`--data` is the only check that touches row data, and it is opt-in.

---

### `anvil:diff`

What changed in the database since Anvil last ran.

```bash
php artisan anvil:diff [options]
```

| Flag            | Description                                    |
| --------------- | ---------------------------------------------- |
| `--connection=` | Database connection to introspect              |
| `--schema=`     | Schema(s) to inspect: name, CSV list, or `all` |
| `--tables=*`    | Limit the comparison to specific tables        |
| `--ignore=*`    | Exclude specific tables                        |
| `--accept`      | Record the current schema as the new baseline  |
| `--strict`      | Exit non-zero if the schema has drifted        |
| `--json`        | Machine-readable output                        |

```bash
php artisan anvil:diff              # human-readable plan
php artisan anvil:diff --strict     # CI gate
php artisan anvil:diff --accept     # record the baseline, generate nothing
```

On a handful of tables this is a convenience. On a few hundred it is the
difference between regenerating everything and regenerating the four tables a
migration actually touched.

`--strict` in CI catches the case that bites teams: someone shipped a migration
and did not regenerate, so the committed models, spec and TypeScript client all
describe a schema that no longer exists.

The report also lists **orphaned artifacts** — files belonging to tables that no
longer exist. Regeneration never removes these: `--force` overwrites, it does
not delete.

---

### `anvil:docs-sync`

Reconciles the OpenAPI spec with hand-edited resources and form requests. A thin
adapter over `DocsSynchronizer`: all merge safety rules live in the synchroniser,
so this command, the `--docs-sync` pipeline flag and the local auto-sync hook
cannot drift apart in behaviour.

```bash
php artisan anvil:docs-sync [model...] [options]
```

| Argument / flag   | Description                                                       |
| ----------------- | ----------------------------------------------------------------- |
| `model...`        | Limit to these models or tables (e.g. `Vehicle users`)            |
| `--api-version=`  | Limit to one API version and read that version's spec (e.g. `v1`) |
| `--only=`         | Limit to `requests` or `responses` (default: both)                |
| `--check`         | Report drift and exit non-zero; never writes. For CI.             |
| `--breaking-only` | With `--check`, only fail on breaking drift                       |
| `--dry-run`       | Show what would change without writing                            |
| `--diff`          | Print per-property drift                                          |
| `--adopt`         | Take ownership of components sync does not manage yet             |
| `--no-prune`      | Never remove properties from the spec                             |
| `--install-hook`  | Install a pre-commit hook that runs `--check`                     |

The safety model: hand-authored components are never touched, a partial read
never prunes, an unresolved property defers to the spec, and `--check` never
writes. Drift severity is direction-dependent — a change to a response schema is
treated more seriously than the same change to a request schema, because clients
break on what they receive.

The synchroniser is built per invocation and never resolved from the container.
A bound singleton would fix its spec directory at construction, so
`--api-version=v2` would silently reconcile v1's spec. Custom readers go in
`anvil.openapi.sync.readers` instead, which keeps the version a per-run decision.

---

### `anvil:polish`

Formats, modernises and audits the code Anvil generated.

```bash
php artisan anvil:polish [options]
```

| Flag               | Description                                                   |
| ------------------ | ------------------------------------------------------------- |
| `--pint`           | Run Pint                                                      |
| `--rector`         | Run Rector                                                    |
| `--stan`           | Run PHPStan/Larastan                                          |
| `--audit`          | Run the model/schema audit                                    |
| `--test`           | Report only; change nothing — the CI mode                     |
| `--all-paths`      | Check the whole app, not just generated files                 |
| `--paths=*`        | Explicit paths to check (repeatable)                          |
| `--connection=`    | Database connection for the audit                             |
| `--strict`         | Exit non-zero when anything is reported                       |
| `--publish-config` | Write a `rector.php` and `pint.json` tuned for generated code |
| `--json`           | Machine-readable output                                       |

Four passes, each optional and each skipped cleanly when its tool is absent:
`pint` (formatting), `rector` (PHP 8.4 + Laravel 12 modernisation, dead code,
type coverage), `phpstan` (static analysis), and `audit` (model ↔ schema drift,
which the other three cannot see). Passing no tool flag runs everything
available.

By default only the files Anvil generated are touched, read from the manifest.
That keeps the run fast and stops a formatting pass turning into an unrelated
diff across the whole application. With no manifest yet, it falls back to the
directories Anvil owns.

**Formatters rewrite files, which invalidates the provenance hash.** Without
re-stamping, every reformatted file would read as hand-edited and the next
`--force` would refuse to regenerate it — so the command re-stamps what the
formatters touched and reports how many.

```bash
php artisan anvil:polish                   # fix everything installed
php artisan anvil:polish --test --strict   # CI gate
php artisan anvil:polish --audit           # only the schema/model audit
php artisan anvil:polish --publish-config  # write rector.php / pint.json
```

Neither published config file is overwritten if it already exists.

---

### `anvil:frontend`

Inspects or installs the frontend dependencies the web scaffold expects.

```bash
php artisan anvil:frontend [options]
```

| Flag                   | Default | Description                                                           |
| ---------------------- | ------- | --------------------------------------------------------------------- |
| `--check`              | off     | Report the current state and exit; non-zero when something is missing |
| `--install`            | off     | Install whatever is missing                                           |
| `--stack=`             | `blade` | Which stack the project targets: `blade` or `livewire`                |
| `--tailwind-version=`  | config  | Tailwind major version to install when missing (3 or 4)               |
| `--no-package-manager` | off     | Write config files but only print the composer/npm commands           |
| `--dry-run`            | off     | Show what would happen without writing or installing                  |

Exists as its own command so installation never has to be entangled with a
generation run. The recommended sequence on a fresh project:

```bash
php artisan anvil:frontend --install --stack=livewire
php artisan anvil:forge-webapp --stack=livewire
```

`--check` is read-only and exits non-zero when something is missing, which makes
it usable as a CI guard. A bare invocation is informational and exits zero.

---

### `anvil:install:swagger-ui`

Vendors the Swagger UI assets into `public/` so the docs page does not depend on
a CDN at request time.

```bash
php artisan anvil:install:swagger-ui [options]
```

| Flag              | Default | Description                                                  |
| ----------------- | ------- | ------------------------------------------------------------ |
| `--ui-version=`   | config  | `swagger-ui-dist` version                                    |
| `--api-version=`  | config  | API version whose docs directory receives the assets         |
| `--source=`       | `auto`  | Where to get the files: `auto`, `local`, `http`, `npm`       |
| `--timeout=`      | `900`   | Seconds allowed for the npm fallback                         |
| `--http-timeout=` | `120`   | Seconds allowed per file download                            |
| `--check`         | off     | Report what would happen and exit                            |
| `--skip-generate` | off     | Do not regenerate the spec first                             |
| `--force`         | off     | Re-download even when the correct version is already present |
| `--dry-run`       | off     | Preview without writing files                                |

The assets are three static files, so `auto` tries an existing `node_modules`
copy, then a direct download, and only then npm — the slowest and most likely to
time out on a constrained link.

Every strategy failing produces an actionable list rather than a
`ProcessTimedOutException` naming a vendor file:

```bash
php artisan anvil:install:swagger-ui --source=http      # skip npm entirely
php artisan anvil:install:swagger-ui --timeout=1800     # slow link
npm install --no-save swagger-ui-dist@5.17.14 \
  && php artisan anvil:install:swagger-ui --source=local
```

After a successful install, point the docs page at the vendored copy:

```php
// config/anvil.php
'openapi' => ['docs' => ['asset_base' => '/api-docs/v1/assets']],
```

Unless `--skip-generate` is passed, the spec is regenerated first via
`anvil:forge-api --spec-only`.

## Notes on what else in the README is now stale

Outside the Commands section, these still use the pre-rename names:

| Location                       | Stale                                                        | Should be                                         |
| ------------------------------ | ------------------------------------------------------------ | ------------------------------------------------- |
| Requirements (optional deps)   | `anvil:generate-web --stack=livewire`, `anvil:generate-auth` | `anvil:forge-webapp`, `anvil:forge-auth`          |
| Versioned API scaffold         | `anvil:generate-api --api-version=2`                         | `anvil:forge-api --api-version=2`                 |
| Events & listeners (3 samples) | `anvil:generate --events --listeners` etc.                   | `anvil:forge:app-scaffold --events --listeners`   |
| Troubleshooting                | `anvil:generate-apidocs --check`                             | `anvil:forge-apidocs --check`                     |
| Upgrading — Command renames    | Maps `anvil:generate --api` → `anvil:generate-api`           | Whole table needs a second column; see below      |
| Upgrading — Required steps     | `anvil:generate-api --api-version=1 --force --ui`            | `anvil:forge-api --api-version=1 --force --ui`    |
| Upgrading — Regenerate models  | `anvil:generate --all --force --backup`                      | `anvil:forge:app-scaffold --all --force --backup` |
| Requirements table             | PHP `^8.3`, Laravel `^11.0 \|\| ^12.0`                       | Confirm — the code uses PHP 8.4 syntax throughout |

Suggested replacement for the Upgrading rename table:

| Originally                             | Then                               | Now                             |
| -------------------------------------- | ---------------------------------- | ------------------------------- |
| `anvil:generate`                       | —                                  | `anvil:forge:app-scaffold`      |
| `anvil:generate --api`                 | `anvil:generate-api`               | `anvil:forge-api`               |
| `anvil:generate --openapi`             | `anvil:generate-api --spec-only`   | `anvil:forge-api --spec-only`   |
| `anvil:generate --openapi-format=json` | `anvil:generate-api --format=json` | `anvil:forge-api --format=json` |
| `anvil:generate --openapi-single-file` | `anvil:generate-api --single-file` | `anvil:forge-api --single-file` |
| `anvil:generate --openapi-ui`          | `anvil:generate-api --ui`          | `anvil:forge-api --ui`          |
| `anvil:docs`                           | `anvil:generate-apidocs`           | `anvil:forge-apidocs`           |
| `anvil:generate-web`                   | —                                  | `anvil:forge-webapp`            |
| `anvil:generate-auth`                  | —                                  | `anvil:forge-auth`              |
| `anvil:generate-graphql`               | —                                  | `anvil:forge-graphql`           |
| `anvil:generate-client`                | —                                  | `anvil:forge-client`            |

The `Response caching` section referenced in the TOC does not exist in the
README yet — the `--cache*` and `--etag` flags are documented in the command
reference above, but the generation-stamp invalidation model, the volatility
profiles and `$cacheVariant` deserve prose alongside "Per-version shape
profiles". Say the word and I'll draft it.

## Local development

Anvil generates code by introspecting a live database, which makes it awkward to
test the way a normal library is tested. A unit test can assert that a stub
renders; only a real schema, a real Laravel application and a real `php artisan`
run will tell you whether the thing Anvil wrote is code that boots.

The working setup is a throwaway Laravel 12 application that symlinks the
package source through a Composer path repository, so an edit in `src/` is live
in the test app on the next command with no `composer update`.

**→ [`documents/local-test.md`](documents/local-test.md) — the full guide.**

It covers:

| Section                                                                                  | What you need it for                                                                                                                                       |
| ---------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| [Linking the package](documents/local-test.md#4-linking-the-package-via-path-repository) | The path repository, and the `minimum-stability` / `prefer-stable` / `*@dev` combination an untagged package needs                                         |
| [Configuring the database](documents/local-test.md#6-configuring-the-database)           | Which driver to reach for — SQLite is fastest but cannot exercise check constraints, column comments or enum detection                                     |
| [Running the generators](documents/local-test.md#9-running-the-generators)               | Command ordering, and why models have to exist before anything downstream resolves them                                                                    |
| [Test scenarios](documents/local-test.md#11-common-test-scenarios)                       | Sixteen scenarios from a bare `--dry-run` up to the full OpenAPI → client round trip                                                                       |
| [Resetting](documents/local-test.md#12-resetting-the-test-application)                   | Getting back to a clean slate between runs                                                                                                                 |
| [Troubleshooting](documents/local-test.md#13-troubleshooting)                            | Failures specific to the local setup — stale autoloaders, mirrored instead of symlinked packages, published-config merges resolving a connection to `null` |

### The short version

```bash
# One-time: a throwaway app next to the package
composer create-project laravel/laravel laravel-anvil-test-local
cd laravel-anvil-test-local
```

Add the path repository to its `composer.json`:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../../packages/laravel-anvil",
      "options": { "symlink": true }
    }
  ],
  "require": {
    "zuqongtech/laravel-anvil": "*@dev"
  },
  "minimum-stability": "dev",
  "prefer-stable": true
}
```

```bash
composer install
ls -la vendor/zuqongtech/laravel-anvil   # must be a symlink, not a directory
```

All three stability settings are required together. The package carries no git
tags, so Composer derives a `dev-main` version from the branch; `minimum-stability`
admits it, `@dev` scopes that to this one package, and `prefer-stable` keeps
Laravel and everything else on stable releases regardless. Drop any one of the
three and the install fails with a resolution error that does not mention the
real cause.

Then the loop:

```bash
php artisan anvil:doctor                                     # is the schema generatable?
php artisan anvil:forge:app-scaffold --models --schema=all   # models first, always
php artisan anvil:forge-api --api-version=1 --dry-run        # preview
php artisan anvil:forge-api --api-version=1 --force
```

Edit the package source, re-run, observe. The one thing a symlink cannot do for
you is autoload a class that did not exist when the autoloader was built — after
adding anything to `src/Support/` or `src/Exceptions/`, run `composer dump-autoload`
in the test app before assuming you have found a bug.

---

## Testing your changes

### The package's own suite

Faster than the test app, and where most support-class regressions surface
first. Run this before reaching for anything else:

```bash
composer test              # Pest
composer test:types        # PHPStan
vendor/bin/pint --test     # formatting
vendor/bin/rector process --dry-run
```

### Against a real application

Some things only fail in a real app: service provider registration, route
resolution, Livewire component mounting, whether generated PHP actually parses.
Work through the scenarios in
[`documents/local-test.md`](documents/local-test.md#11-common-test-scenarios).
The ones worth running before every PR, regardless of what you changed:

| Scenario                                                                                    | Catches                                                                                                                   |
| ------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| [Idempotency](documents/local-test.md#scenario-6--idempotency-run-twice-no-duplicates)      | Appended duplicates in providers, route files and the `servers` block — the single most common regression in this package |
| [Full scaffold, force write](documents/local-test.md#scenario-3--full-scaffold-force-write) | Generated code that does not parse, or routes that do not resolve                                                         |
| [OpenAPI round trip](documents/local-test.md#scenario-10--openapi-round-trip)               | Drift between the API, the spec, the docs and the TypeScript client                                                       |
| [Key casing](documents/local-test.md#scenario-11--key-casing)                               | A `--case` change applied to three of the four surfaces that need it                                                      |

Because every generating command shares `RunsGenerationPipeline`,
`ResolvesGeneratedModels` and `RendersScaffoldOutput`, a change to any of those
three is a change to every command. Re-run more than the one you were working
on.

### CI gates

Each of these exits non-zero on a problem, and each is worth wiring into a
pipeline for applications built with Anvil as much as for the package itself:

```bash
php artisan anvil:doctor --strict          # schema shapes that break generation
php artisan anvil:diff --strict            # a migration shipped without regenerating
php artisan anvil:docs-sync --check        # hand edits drifted from the spec
php artisan anvil:forge-apidocs --check --strict   # a targeted version has no spec
php artisan anvil:polish --test --strict   # formatting, modernisation, model ↔ schema audit
```

### Before opening a pull request

- The package suite passes, including `pint --test` and `rector --dry-run`
- A full `--force` scaffold in the test app produces code that parses, boots and
  routes
- The same scaffold run twice produces byte-identical files
- New command flags are documented in the [command reference](#commands), with
  their default and the behaviour when omitted
- New behaviour has a scenario in `documents/local-test.md` if it cannot be covered by the package's own tests
