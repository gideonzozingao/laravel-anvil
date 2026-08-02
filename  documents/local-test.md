# Local Testing Guide

This document describes how to test `zuqongtech/laravel-anvil` locally during development using a path-repository Laravel application that symlinks directly to your package source.

---

## Table of Contents

1. [Overview](#1-overview)
2. [Directory Structure](#2-directory-structure)
3. [Creating the Test Application](#3-creating-the-test-application)
4. [Linking the Package via Path Repository](#4-linking-the-package-via-path-repository)
5. [Test Application Setup](#5-test-application-setup)
6. [Configuring the Database](#6-configuring-the-database)
7. [Publishing Anvil Assets](#7-publishing-anvil-assets)
8. [The Command Suite](#8-the-command-suite)
9. [Source Layout](#9-source-layout)
10. [Running the Generators](#10-running-the-generators)
11. [Iterating on Package Source](#11-iterating-on-package-source)
12. [Common Test Scenarios](#12-common-test-scenarios)
13. [Resetting the Test Application](#13-resetting-the-test-application)
14. [Troubleshooting](#14-troubleshooting)

---

## 1. Overview

Because `laravel-anvil` is a code-generation package, the most effective way to test it during development is against a real Laravel application with a live database. Rather than publishing a release to Packagist on every change, Composer's **path repository** feature symlinks the package directory directly into the test application's `vendor/` folder. Any change you save in the package source is immediately reflected in the test app — no `composer update` required for most changes.

Anvil is a suite of thirteen Artisan commands. Every generating command runs the identical pipeline through the `RunsGenerationPipeline` trait, resolves models through `ResolvesGeneratedModels`, and reports through `RendersScaffoldOutput`. Testing therefore means exercising each command _and_ the sequences that chain them, because the shared traits are where cross-command regressions surface.

Two things about the package shape the testing approach, and both are easy to miss:

**Command signatures were renamed; the classes were not.** Everything that was `anvil:generate*` is now `anvil:forge*`, but the files are still `GenerateWebCommand.php`, `GenerateAuthCommand.php` and so on. See [Section 9](#9-source-layout) for the mapping — without it, hunting a bug in `anvil:forge-webapp` means grepping for a filename that does not exist.

**Anvil ships runtime code, not just generators.** `src/Runtime/Cache/` and `src/Livewire/Concerns/` are consumed by the generated application while it serves requests, and `src/Http/DocsController.php` handles the docs route. A change to those directories can break a running app without changing a single generated file, so re-running the generator is not sufficient to test them.

---

## 2. Directory Structure

The setup assumes the following layout. Adjust paths to match your own workspace if they differ, but keep them consistent — mismatched paths in `composer.json` are the single most common cause of a broken symlink.

```
~/workspace/projects/zuqongtech/
├── packages/
│   └── laravel-anvil/                  ← git repo: zuqongtech/laravel-anvil
│       ├── bin/
│       ├── config/
│       ├── src/                        ← see Section 9
│       ├── stubs/
│       ├── tests/
│       └── composer.json
│
└── opensource-projects-tests/
    └── laravel-anvil-test-local/       ← fresh Laravel 12 app (not committed)
        ├── app/
        ├── config/
        ├── database/
        └── composer.json
```

---

## 3. Creating the Test Application

Create a fresh Laravel application alongside your package. You only need to do this once.

```bash
cd ~/workspace/projects/zuqongtech/opensource-projects-tests

composer create-project laravel/laravel laravel-anvil-test-local
cd laravel-anvil-test-local
```

Initialise it as a git repository straight away. It is not committed anywhere, but a local baseline makes the idempotency and provenance scenarios trivially checkable:

```bash
git init && git add -A && git commit -m "baseline"
```

---

## 4. Linking the Package via Path Repository

Edit the test application's `composer.json` to add a `repositories` entry that points to your local package source. Composer will symlink `vendor/zuqongtech/laravel-anvil` directly to your package directory.

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../../packages/laravel-anvil",
      "options": {
        "symlink": true
      }
    }
  ],
  "require": {
    "php": "^8.3",
    "laravel/framework": "^12.0",
    "laravel/sanctum": "^4.0",
    "laravel/tinker": "^2.10.1",
    "zuqongtech/laravel-anvil": "*@dev"
  },
  "minimum-stability": "dev",
  "prefer-stable": true
}
```

Keep the rest of the stock Laravel `composer.json` (`require-dev`, `autoload`, `scripts`, `config`, `extra`) unchanged.

> **PHP constraint.** The package's `composer.json` declares `^8.3`, but parts of the source use PHP 8.4 syntax. Until that is reconciled, run the test app on 8.4 — an 8.3 runtime will parse-error on package internals rather than on anything you generated, which is a confusing place to start debugging.

### Stability resolution — the three things that matter

An untagged path repository will fail to resolve under the default `"minimum-stability": "stable"`. There are three interacting settings; get all of them right and the install is clean:

| Setting             | Value     | Why                                                                                                         |
| ------------------- | --------- | ----------------------------------------------------------------------------------------------------------- |
| `minimum-stability` | `"dev"`   | The package has no git tags, so Composer derives `dev-main` from the branch name — a dev-stability version. |
| `prefer-stable`     | `true`    | Keeps every _other_ dependency (Laravel, PHPUnit, etc.) on stable releases despite the relaxed minimum.     |
| Constraint          | `"*@dev"` | The inline `@dev` flag permits dev stability for this package specifically.                                 |

**Relative vs absolute `url`.** A relative path is resolved from the directory containing `composer.json`, so `../../packages/laravel-anvil` is portable across machines. An absolute path works too but hardcodes your home directory into a file you may be tempted to commit.

**`canonical` placement.** If you need it, `canonical` is a **sibling of `type` and `url`**, not a member of `options`:

```json
{
  "type": "path",
  "url": "../../packages/laravel-anvil",
  "canonical": false,
  "options": { "symlink": true }
}
```

Nesting it inside `options` silently does nothing. `canonical: false` restricts the repository to packages you require directly, letting Packagist satisfy everything else — useful if a transitive dependency name ever collides.

**Alternative: hardcode a version.** If you would rather leave the test app on `"minimum-stability": "stable"`, add a `version` field to the _package's_ `composer.json` during development:

```json
{
  "name": "zuqongtech/laravel-anvil",
  "version": "1.0.0-dev"
}
```

Then require `"zuqongtech/laravel-anvil": "*"` with no stability relaxation. Remove the `version` field before tagging a real release — Packagist rejects packages that hardcode it.

### Install and verify

```bash
composer install

ls -la vendor/zuqongtech/laravel-anvil
# Should show a symlink → ../../packages/laravel-anvil
```

If `ls -la` shows a real directory instead of a symlink, Composer copied the package (mirror mode). Delete `vendor/zuqongtech` and `composer.lock`, confirm `"symlink": true`, and reinstall.

---

## 5. Test Application Setup

```bash
composer setup
```

This runs `composer install`, copies `.env.example` to `.env`, generates an application key, runs migrations, and builds frontend assets.

Step by step, if you prefer:

```bash
cp .env.example .env
php artisan key:generate
php artisan migrate
```

### Optional dependencies, by command

Anvil checks for these and explains rather than emitting code nothing can run, so a missing dependency is a clean failure rather than a mystery. Install them as you need each command:

| Command                               | Needs                                                     | Install                                                               |
| ------------------------------------- | --------------------------------------------------------- | --------------------------------------------------------------------- |
| `anvil:forge-webapp --stack=livewire` | Livewire 3, Tailwind                                      | `php artisan anvil:frontend --install --stack=livewire`               |
| `anvil:forge-auth`                    | Livewire 3                                                | as above                                                              |
| `anvil:forge-auth` (2FA)              | `pragmarx/google2fa`                                      | `composer require pragmarx/google2fa`                                 |
| `anvil:forge-api --auth=sanctum`      | Sanctum                                                   | `php artisan install:api`                                             |
| `anvil:forge-graphql`                 | `nuwave/lighthouse`                                       | `composer require nuwave/lighthouse`                                  |
| `anvil:forge-client --hooks`          | `@tanstack/react-query` v5                                | `npm i @tanstack/react-query`                                         |
| `anvil:polish`                        | Pint, Rector, PHPStan — each pass skips cleanly if absent | `composer require --dev laravel/pint rector/rector larastan/larastan` |

Use `anvil:frontend` rather than installing Livewire and Tailwind by hand — testing `FrontendInstaller` _is_ part of testing the package, and it is the only path that also writes the Tailwind config wiring.

---

## 6. Configuring the Database

Anvil introspects a **live database**, so you need a real connection with tables in it. The test application's `.env` controls which database Anvil reads.

### Option A — MySQL / PostgreSQL (recommended for thorough testing)

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=anvil_test
DB_USERNAME=root
DB_PASSWORD=
```

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS anvil_test;"
php artisan migrate
```

### A schema worth testing against

The stock Laravel migrations exercise almost nothing. Build a schema that deliberately contains the shapes `anvil:doctor` reports on, because those are the shapes generation gets wrong:

| Shape                                           | Exercises                                                     |
| ----------------------------------------------- | ------------------------------------------------------------- |
| Two FKs from one table to `users`               | `RelationNamer` disambiguation — the fatal redeclaration path |
| A composite primary key                         | Skipped with a report by `anvil:forge-graphql`                |
| A table with no primary key                     | `DoctorCommand` error path                                    |
| An unindexed foreign key                        | `DoctorCommand` warning path                                  |
| A native `ENUM` column                          | `EnumDetector`, `EnumColumn`, `EnumGenerator`                 |
| A free-text `status` column                     | Doctor's enum-candidate suggestion                            |
| A reserved word as a column name                | `ReservedNames`                                               |
| A column that camelises onto an Eloquent method | Model collision detection                                     |
| A second authenticatable table                  | Must extend `Illuminate\Foundation\Auth\User`, not `Model`    |
| No `created_at`/`updated_at`                    | Timestamp warning                                             |
| A soft-deletable table                          | `Restored` event in `EventGenerator`                          |
| Column comments and a check constraint          | `ConstraintAnalyzer`, and driver divergence                   |
| Two tables whose models resolve to one name     | `AmbiguousModelException` from `ModelRegistry`                |

```bash
php artisan make:migration create_posts_table
php artisan make:migration create_comments_table
# Edit the migrations to include the shapes above, then:
php artisan migrate
php artisan anvil:doctor          # confirm it sees what you planted
```

`anvil:doctor` is the fastest way to confirm your test schema is actually testing something. If it reports nothing, the schema is too clean to be useful.

### Multi-schema testing

Postgres makes this easy and it exercises the schema-namespacing path that a single-schema setup never touches:

```sql
CREATE SCHEMA core;
CREATE SCHEMA reporting;
```

```bash
php artisan anvil:forge:app-scaffold --models --schema="core,reporting"
```

Quote the list. See the `No arguments expected` entry in [Troubleshooting](#14-troubleshooting).

### Option B — SQLite (quickest for basic testing)

```dotenv
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/laravel-anvil-test-local/database/database.sqlite
```

```bash
touch database/database.sqlite
php artisan migrate
```

> SQLite has no check constraints, no table or column comments, and no native enum type. Use MySQL or PostgreSQL to test those `DatabaseInspector` paths. SQLite is for fast iteration on generator logic, not for validating introspection.

### Using a non-default connection

```bash
php artisan anvil:forge:app-scaffold --models --connection=reporting --dry-run
```

This flag is also the regression test for shallow config merges — see [Troubleshooting](#14-troubleshooting).

---

## 7. Publishing Anvil Assets

```bash
php artisan vendor:publish \
    --provider="Zuqongtech\LaravelAnvil\LaravelAnvilServiceProvider" \
    --tag="config"
```

This creates `config/anvil.php`. Edit it to adjust ignored tables, namespaces, casing, cache defaults, `openapi.sync.readers`, and per-generator settings.

To test stub customisation:

```bash
php artisan vendor:publish \
    --provider="Zuqongtech\LaravelAnvil\LaravelAnvilServiceProvider" \
    --tag="stubs"
```

Stubs land in `stubs/anvil/` and take precedence over the package-bundled templates automatically — `StubGenerator` handles the lookup order, so that is where to look if an override is ignored.

> **Tip:** Because the package is symlinked, changes to stubs inside the package source (`packages/laravel-anvil/stubs/`) are reflected immediately without republishing. Publishing is only needed to test the _override_ mechanism itself.

**Always test the published-config path at least once.** Publishing produces a partial config file, and a shallow merge against the package defaults is how `connection` ends up `null` at runtime. Delete `config/anvil.php` and re-run to compare behaviour. `ConfigValidator` is the class that should be catching malformed config before generation starts.

---

## 8. The Command Suite

### Rename mapping

| Was                                    | Now                           |
| -------------------------------------- | ----------------------------- |
| `anvil:generate`                       | `anvil:forge:app-scaffold`    |
| `anvil:generate-api`                   | `anvil:forge-api`             |
| `anvil:generate-openapi`               | `anvil:forge-api --spec-only` |
| `anvil:generate-apidocs`, `anvil:docs` | `anvil:forge-apidocs`         |
| `anvil:generate-web`                   | `anvil:forge-webapp`          |
| `anvil:generate-auth`                  | `anvil:forge-auth`            |
| `anvil:generate-graphql`               | `anvil:forge-graphql`         |
| `anvil:generate-client`                | `anvil:forge-client`          |
| `anvil:install-swagger-ui`             | `anvil:install:swagger-ui`    |

`anvil:diff`, `anvil:doctor`, `anvil:docs-sync`, `anvil:polish` and `anvil:frontend` are unchanged. `anvil:forge-openapi` is an alias of `anvil:forge-api`.

### Generation

| Command                    | Produces                                    | Where the work happens                                                  |
| -------------------------- | ------------------------------------------- | ----------------------------------------------------------------------- |
| `anvil:forge:app-scaffold` | Models plus the core per-model artifacts    | `ModelBuilder`, `RelationNamer`, `EnumGenerator`, `Generators/*`        |
| `anvil:forge-api`          | Versioned JSON API + OpenAPI 3.1 spec       | `Generators/Api*`, `Generators/OpenApi/*`, `ApiVersionProfile`          |
| `anvil:forge-apidocs`      | Docs for one or all versions, plus a report | `OpenApiLocator`, delegates to `anvil:forge-api --spec-only`            |
| `anvil:forge-webapp`       | Web CRUD front end (Blade or Livewire)      | `WebControllerGenerator`, `ViewGenerator`, `LivewireComponentGenerator` |
| `anvil:forge-auth`         | Livewire auth + RBAC from the users table   | `AuthScaffolder` → `Support/Auth/Parts/*`                               |
| `anvil:forge-graphql`      | Lighthouse SDL schema                       | `GraphQLSchemaBuilder`                                                  |
| `anvil:forge-client`       | Typed TypeScript client                     | `ApiVersionProfile`, `KeyCase`                                          |

### Inspection

| Command           | Reports                                                   | Where the work happens               |
| ----------------- | --------------------------------------------------------- | ------------------------------------ |
| `anvil:doctor`    | Schema shapes that break generation, before they break it | `DatabaseInspector`, `ReservedNames` |
| `anvil:diff`      | What changed in the database since the last generation    | `SchemaManifest`, `SchemaSelection`  |
| `anvil:docs-sync` | Drift between hand-edited payloads and the spec           | `DocsSync/*`                         |

### Maintenance

| Command                    | Does                                              | Where the work happens                                   |
| -------------------------- | ------------------------------------------------- | -------------------------------------------------------- |
| `anvil:polish`             | Pint, Rector, PHPStan, and a model ↔ schema audit | `QualityRunner`, `ModelAuditor`, `PhpSyntaxCheck`        |
| `anvil:frontend`           | Checks or installs Livewire and Tailwind          | `FrontendDetector`, `FrontendInstaller`, `FrontendState` |
| `anvil:install:swagger-ui` | Vendors the Swagger UI assets                     | `SwaggerUiInstaller`                                     |

### Two distinctions worth internalising before you test

**`anvil:doctor` inspects the schema. `anvil:polish --audit` inspects the models.** Doctor is read-only and runs _before_ generation — it tells you the schema will produce broken code. `ModelAuditor` runs _after_ generation and tells you the models have drifted from the schema. Different checks, different points in the cycle, and doctor has no `--fix`.

**Only `anvil:forge:app-scaffold --models` writes models.** Every other command _resolves_ them via `ResolvesGeneratedModels` → `ModelDiscovery` / `ModelRegistry` / `ModelReference`, reading `storage/anvil/models.json` or scanning the model path. That split is what stops a schema-namespaced `App\Models\Core\User` being re-derived as `App\Models\User` downstream. A resolution failure raises `ModelNotRegisteredException` or `AmbiguousModelException` and names the table — a downstream command that instead emits a controller importing a class nobody wrote is the bug.

---

## 9. Source Layout

Knowing which directory owns a symptom saves more time than any other single thing in this document.

```
src/
├── Concerns/SyncsApiDocs.php        ← the auto-sync hook wired into generation
├── Console/
│   ├── Commands/                    ← EMPTY. See note below.
│   ├── Concerns/                    ← shared command behaviour; a bug here hits every command
│   └── *Command.php                 ← one file per command, old names (see mapping)
├── Contracts/                       ← Generator, ShapeReader, SpecCodec — the extension points
├── DocsSync/                        ← the anvil:docs-sync subsystem, self-contained
├── Exceptions/                      ← AmbiguousModelException, ModelNotRegisteredException
├── Generators/                      ← one class per artifact type
├── Http/DocsController.php          ← RUNTIME: serves the docs route
├── Livewire/Concerns/               ← RUNTIME: consumed by generated components
├── Runtime/Cache/                   ← RUNTIME: consumed by generated services
└── Support/                         ← everything else
```

### Command signatures were renamed; the class files were not

| Signature                  | File                             |
| -------------------------- | -------------------------------- |
| `anvil:forge:app-scaffold` | `GenerateModelsFromDatabase.php` |
| `anvil:forge-api`          | `GenerateOpenApiCommand.php`     |
| `anvil:forge-apidocs`      | `GenerateOpenApiDocsCommand.php` |
| `anvil:forge-webapp`       | `GenerateWebCommand.php`         |
| `anvil:forge-auth`         | `GenerateAuthCommand.php`        |
| `anvil:forge-graphql`      | `GenerateGraphQLCommand.php`     |
| `anvil:forge-client`       | `GenerateClientCommand.php`      |
| `anvil:install:swagger-ui` | `InstallSwaggerUi.php`           |
| `anvil:doctor`             | `DoctorCommand.php`              |
| `anvil:diff`               | `DiffCommand.php`                |
| `anvil:docs-sync`          | `DocsSyncCommand.php`            |
| `anvil:polish`             | `PolishCommand.php`              |
| `anvil:frontend`           | `FrontendCommand.php`            |

`GenerateModelsFromDatabase.php` in particular no longer describes what the class does — it owns every artifact type, not just models. Worth renaming before 1.0, while the churn is still cheap.

`src/Console/Commands/` is empty. Either it is the intended destination of a rename that stalled, or it is dead and should go. An empty directory that looks like it should hold the commands is a trap for anyone new to the codebase.

### Runtime code versus generation code

This is the distinction most likely to cost you an afternoon. Three directories ship as part of the running application:

| Directory                 | Consumed by                                          | How to test a change            |
| ------------------------- | ---------------------------------------------------- | ------------------------------- |
| `src/Runtime/Cache/`      | Generated services, via the `CachesQueries` trait    | Serve the app and hit endpoints |
| `src/Livewire/Concerns/`  | Generated Livewire components, `NormalizesFormState` | Interact in a browser           |
| `src/Http/DocsController` | The docs route                                       | Load `/docs`                    |

Editing any of these and re-running the generator proves nothing — the generated files are unchanged, and the behaviour lives in the symlinked package. `php artisan serve` and exercise the app.

It also means the generated application carries a **runtime dependency** on Anvil, not just a development one. If the README's positioning says otherwise, one of the two needs to change.

### Subsystem map

| Symptom                                          | Look in                                                                  |
| ------------------------------------------------ | ------------------------------------------------------------------------ |
| Wrong flag parsing, missing option               | `Console/*Command.php`, then `Support/Options/OptionBag.php`             |
| A behaviour wrong in _every_ command             | `Console/Concerns/`                                                      |
| Ordering, which generators ran                   | `Support/GenerationOrchestrator.php`, `Support/Options/GeneratorSet.php` |
| Console output, summary tables                   | `RendersScaffoldOutput`, `ScaffoldReport`, `GenerationReport`            |
| Cache flags not taking effect at generation time | `Console/Concerns/ConfiguresGeneratedCache.php`                          |
| Cache misbehaving at request time                | `Runtime/Cache/`                                                         |
| Frontend install loop, preflight                 | `Console/Concerns/InstallsFrontendAssets.php`, `Support/Frontend*`       |
| Spec content wrong                               | `Generators/OpenApi/` — Root, Path and Schema are separate               |
| Spec file layout, versioned paths                | `Generators/Concerns/WritesVersionedFiles.php`, `OpenApiLocator`         |
| YAML output malformed                            | `Support/OpenApiYamlSerializer.php`                                      |
| Type mapping wrong in the spec                   | `Support/OpenApiTypeMapper.php`                                          |
| docs-sync anything                               | `DocsSync/` — see below                                                  |
| An auth part missing or broken                   | `Support/Auth/Parts/`                                                    |
| Auth markup, icons, form styling                 | `Support/Auth/Ui/FormKit.php`, `IconSet.php`                             |
| Livewire form state not binding                  | `FormStateProperty`, `LivewirePropertyMap`, `NormalizesFormState`        |
| A provider not registered                        | `Support/ProviderRegistrar.php`                                          |
| Hand edits clobbered, or `--force` refusing      | `Support/PreserveRegions.php`                                            |
| Stub override ignored                            | `Support/StubGenerator.php`                                              |

### `DocsSync/` in detail

The only subsystem large enough to need its own map. It is self-contained, which makes it the easiest part of the package to unit-test without the test app:

```
DocsSync/
├── DocsSynchronizer.php      ← entry point; all merge safety rules live here
├── TargetDiscovery.php       ← which resources and requests to read
├── Readers/                  ← FormRequestShapeReader, ResourceShapeReader
├── Php/SourceTokens.php      ← the PHP tokenizer the readers use
├── Mappers/                  ← code shape ↔ OpenAPI schema
├── Codecs/                   ← JSON and YAML spec I/O (Contracts\SpecCodec)
├── SpecMerger.php            ← the write side
├── SchemaDiff.php            ← drift detection
├── SchemaChange.php          ← one change, with severity
├── SyncManifest.php          ← what sync owns versus what is hand-authored
└── Sync{Config,Options,Report,Target}.php
```

The synchroniser is built per invocation and never resolved from the container — a bound singleton would fix its spec directory at construction, so `--api-version=v2` would silently reconcile v1's spec. If you find yourself binding it, that is the reason not to.

`src/Concerns/SyncsApiDocs.php` sits outside `Console/` because it is the hook used by the `--docs-sync` pipeline flag as well as the command. All three entry points — command, flag, hook — go through `DocsSynchronizer`, which is what stops them drifting apart. Test at least two of the three after any change to the safety rules.

### Extension points

Three contracts, each worth a test that implements it from outside the package:

| Contract      | Implement to                                  | Registered via                 |
| ------------- | --------------------------------------------- | ------------------------------ |
| `Generator`   | Add an artifact type to the pipeline          | `GeneratorSet` / orchestrator  |
| `ShapeReader` | Teach docs-sync to read a new source of truth | `anvil.openapi.sync.readers`   |
| `SpecCodec`   | Support a spec format beyond JSON and YAML    | codec resolution in `DocsSync` |

`ShapeReader` is the one with a documented config key, so it is the one most likely to be used. Write a trivial reader in the test app and confirm it is picked up.

### Auth is a part system

`AuthScaffolder` composes `Support/Auth/Parts/*`, each implementing `Contracts\ScaffoldPart`, with cross-cutting concerns in `Fragments/` (lockout, two-factor gating) and shared context in `AuthContext` / `TokenMap`. `ScaffoldWriter` does the writing.

This means the `--no-2fa` / `--no-lockout` / `--no-verification` flags are part _selection_, not conditionals threaded through one big template. Test each combination — a part omitted must not leave a dangling route, import or view reference from a part that remains.

---

## 10. Running the Generators

### Verify registration

```bash
php artisan list anvil
```

All thirteen commands should appear. If one is missing, check `LaravelAnvilServiceProvider`.

```bash
php artisan anvil:forge-api --help
```

All flags should be listed. If flags are silently absent, there is a signature parsing issue — see [Section 14](#14-troubleshooting).

### The canonical order

```bash
# 1. Is the schema generatable at all?
php artisan anvil:doctor

# 2. Models — everything downstream resolves them
php artisan anvil:forge:app-scaffold --models --schema=all

# 3. Core per-model artifacts
php artisan anvil:forge:app-scaffold --all --force

# 4. Versioned API + spec + UI
php artisan anvil:forge-api --api-version=1 --ui --force

# 5. Front end
php artisan anvil:frontend --install --stack=livewire
php artisan anvil:forge-webapp --stack=livewire --force
php artisan anvil:forge-auth --force

# 6. Typed client
php artisan anvil:forge-client --api-version=1 --hooks --force

# 7. Format, modernise, audit
php artisan anvil:polish
```

Every command supports `--dry-run`. Use it before any `--force` run against a schema you have not generated from before.

### `anvil:forge:app-scaffold`

```bash
php artisan anvil:forge:app-scaffold --models --schema=all
php artisan anvil:forge:app-scaffold --all --tables=posts --tables=comments --force
php artisan anvil:forge:app-scaffold --events --listeners --listener-style=subscriber
php artisan anvil:forge:app-scaffold --models --with-phpdoc --with-inverse --with-constraints
php artisan anvil:forge:app-scaffold --refresh-models        # rebuild manifest, generate nothing
```

`--refresh-models` short-circuits the pipeline and touches no database beyond resolving the default schema. `--listeners` implies `--events`. `--queued-listeners` applies only to `per-event` style, and the command says so rather than ignoring it.

### `anvil:forge-api`

```bash
php artisan anvil:forge-api --api-version=1 --force
php artisan anvil:forge-api --api-version=2 --case=camel --pagination=25 --force
php artisan anvil:forge-api --api-version=1 --spec-only --format=json --force
php artisan anvil:forge-api --api-version=1 --cache --etag --cache-ttl=single=300,list=60
php artisan route:list | grep "api/v1"
```

`--auth` decides both the route middleware and the spec's `securityScheme`, so the running API and its documentation cannot disagree — assert both after changing it.

`--no-spec` and `--spec-only` are mutually exclusive. `--pagination-max` must be ≥ `--pagination`. Free-text options are validated by `ConfigValidator` before anything is written, so a typo in `--auth`, `--format`, `--security`, `--case` or `--throttle` should fail fast rather than produce 32 tables of wrong output.

### `anvil:forge-apidocs`

```bash
php artisan anvil:forge-apidocs --check
php artisan anvil:forge-apidocs --all-versions --force
php artisan anvil:forge-apidocs --check --strict         # CI gate
php artisan anvil:forge-apidocs --check --json
```

`--all-versions` and `--api-version` are mutually exclusive. A dry run legitimately leaves nothing on disk, so `--dry-run` never fails `--strict`.

### `anvil:install:swagger-ui`

```bash
php artisan anvil:install:swagger-ui --check
php artisan anvil:install:swagger-ui --source=http
php artisan anvil:install:swagger-ui --timeout=1800
```

`SwaggerUiInstaller` tries `node_modules`, then a direct download, then npm. Test each `--source` explicitly — the fallback chain is what breaks, and it breaks on constrained links. After install:

```php
// config/anvil.php
'openapi' => ['docs' => ['asset_base' => '/api-docs/v1/assets']],
```

### `anvil:forge-webapp`

```bash
php artisan anvil:frontend --check
php artisan anvil:forge-webapp --tables=posts --force
php artisan anvil:forge-webapp --stack=livewire --install-assets --force
php artisan anvil:forge-webapp --stack=livewire --assets-mode=vite --force
```

**This command does not generate models.** `--namespace` and `--path` tell it where to _locate_ them.

The preflight in `InstallsFrontendAssets` runs before the pipeline, and an install run exits and asks you to re-run — a Composer install cannot take effect in the process that performed it. That exit-and-re-run is correct behaviour; confirm it happens rather than treating it as a bug.

### `anvil:forge-auth`

```bash
php artisan anvil:forge-auth --dry-run
php artisan anvil:forge-auth --accent=emerald --dark --default-role=member --force
php artisan anvil:forge-auth --no-2fa --no-lockout --no-verification --force
```

Pre-flight runs before anything is written. Non-fatal gaps are reported as warnings after the configuration table. A partial scaffold exits non-zero.

### `anvil:forge-graphql`

```bash
php artisan anvil:forge-graphql --guard=default --policies --force
php artisan lighthouse:validate-schema
```

`graphql/schema.graphql` is written once and left alone; everything under `graphql/types/` is regenerated freely. Confirm both halves.

### `anvil:forge-client`

```bash
php artisan anvil:forge-client --api-version=1 --hooks --force
npx tsc --noEmit
```

Output lands in `resources/js/api/v{n}/`. Everything resolves through `ApiVersionProfile` — the same object the PHP requests and resources read.

### Inspection and maintenance

```bash
php artisan anvil:doctor --data --strict --json
php artisan anvil:diff
php artisan anvil:diff --accept
php artisan anvil:docs-sync --check --diff
php artisan anvil:polish --test --strict
php artisan anvil:polish --audit
php artisan anvil:polish --publish-config
```

`QualityRunner` shells out to Pint, Rector and PHPStan, which must be installed in the **test app**. Each pass skips cleanly when its tool is absent, so a run that reports "skipped" four times means you installed nothing, not that everything passed.

---

## 11. Iterating on Package Source

The edit cycle:

1. Edit a file in `packages/laravel-anvil/src/`
2. Switch to the test app and run the relevant `anvil:*` command
3. Observe the output immediately — no `composer update` needed

**Except for `src/Runtime/`, `src/Livewire/` and `src/Http/`.** Those are runtime code. Re-running the generator changes nothing; serve the app and exercise it.

**And except for new classes.** Adding anything to `src/Support/`, `src/Exceptions/` or `src/DocsSync/` needs:

```bash
composer dump-autoload
```

A missing `ModelReference`, `ImportSet`, `SyncTarget` or exception class almost always means a stale autoloader rather than a real bug.

**If you change `composer.json` inside the package:**

```bash
composer update zuqongtech/laravel-anvil
php artisan package:discover --ansi
```

**Clearing caches** between test runs:

```bash
php artisan clear-compiled
php artisan cache:clear
php artisan config:clear
```

**Package test suite.** Faster than the test app, and enough for most support-class work. `DocsSync/`, `Support/Options/` and `Support/Auth/Parts/` are all self-contained enough to test without a database:

```bash
cd ~/workspace/projects/zuqongtech/packages/laravel-anvil
composer test              # Pest
composer test:types        # PHPStan
vendor/bin/pint --test
vendor/bin/rector process --dry-run
```

---

## 12. Common Test Scenarios

Run in order, simplest to most complex.

### Scenario 1 — Doctor on a deliberately awkward schema

```bash
php artisan anvil:doctor
php artisan anvil:doctor --data
php artisan anvil:doctor --strict --json
```

Confirms: every shape from [Section 6](#a-schema-worth-testing-against) is detected and classified as `error` / `warning` / `note` with a suggested fix. `--data` samples stored password hashes. `--strict` exits non-zero on errors, not warnings.

This scenario also validates your test schema. Nothing reported means nothing being tested.

### Scenario 2 — Models only, dry run

```bash
php artisan anvil:forge:app-scaffold --models --dry-run
```

Confirms: `DatabaseInspector`, `RelationshipDetector`, `RelationNamer`, `ModelBuilder`, `FileWriter` — with nothing written.

### Scenario 3 — Models, and the manifest

```bash
php artisan anvil:forge:app-scaffold --models --schema=all --force
cat storage/anvil/models.json
```

Confirms: every table has a `ModelReference` entry and the recorded namespace matches where the file landed. For a multi-schema run, `App\Models\Core\User` must be recorded as such, not flattened.

### Scenario 4 — Model resolution, including its failures

```bash
mv storage/anvil/models.json /tmp/
php artisan anvil:forge-webapp --tables=posts --dry-run    # ModelDiscovery scan fallback
php artisan anvil:forge:app-scaffold --refresh-models
diff <(jq -S . storage/anvil/models.json) <(jq -S . /tmp/models.json)
```

Then both exception paths:

```bash
rm storage/anvil/models.json app/Models/Post.php
php artisan anvil:forge-webapp --tables=posts
# expect: ModelNotRegisteredException, naming the posts table
```

```bash
# create a second model resolving to the same reference, then:
php artisan anvil:forge-webapp --tables=posts
# expect: AmbiguousModelException, naming both candidates
```

Both must fail cleanly and name the table. Neither may emit a controller importing a class that was never written.

### Scenario 5 — Full app scaffold

```bash
php artisan anvil:forge:app-scaffold --all --force
php artisan route:list
php artisan test
```

Confirms: generated files parse (`PhpSyntaxCheck`), `ProviderRegistrar` registered what it should, routes resolve, tests pass.

### Scenario 6 — Listener styles

```bash
php artisan anvil:forge:app-scaffold --events --listeners --force
php artisan anvil:forge:app-scaffold --events --listeners --listener-style=subscriber --force
php artisan anvil:forge:app-scaffold --events --listeners --listener-style=subscriber --queued-listeners --force
```

Confirms: `per-event` emits one class per event, `subscriber` one per model, and the third invocation _warns_ that `--queued-listeners` does not apply rather than silently dropping it.

### Scenario 7 — Enum generation

```bash
php artisan anvil:forge:app-scaffold --models --tables=posts --force
```

Confirms: `EnumDetector` finds the native enum column, `EnumGenerator` writes a backed enum, `ModelBuilder` casts to it, the form request validates against it, and the OpenAPI schema declares the same values. Four surfaces from one detection — check all four.

### Scenario 8 — Versioned API

```bash
php artisan anvil:forge-api --api-version=1 --force
php artisan route:list | grep "api/v1"
```

Confirms: `ApiVersionProfile` applies, `ForceJsonServiceProviderGenerator` output is registered, versioned routes exist. Cross-check that the spec's `securityScheme` matches the `--auth` value that produced the middleware.

### Scenario 9 — Two versions with different wire formats

```bash
php artisan anvil:forge-api --api-version=1 --tables=posts --force
php artisan anvil:forge-api --api-version=2 --tables=posts --case=camel --pagination=25 --force
php artisan route:list | grep "api/v"
```

Confirms: `WritesVersionedFiles` keeps v1 untouched by the v2 run, and v2 is genuinely camelCase in resources, form requests, the spec and the page-size parameter.

### Scenario 10 — Response caching, at runtime

Generation-time and request-time are separate halves; test both.

```bash
php artisan anvil:forge-api --api-version=1 --cache --etag --force
php artisan anvil:forge-api --api-version=2 --cache --etag --force
grep -r CachesQueries app/Services        # generation half
php artisan serve                          # runtime half
```

Confirms:

- `ConfiguresGeneratedCache` resolved the flags into the generated services
- `CacheStamps` invalidation works on the `file` driver — the point of stamps over tags is that tagless drivers work, so test on `file`, not `redis`
- `CacheKey` includes `$cacheVariant`, defaulting to `static::class`, so v1 and v2 reading one table do not collide. Hit both, compare payloads
- `CacheInvalidationListener` fires on write and clears the right stamp
- `CachePolicy` honours `--cache-model=Category:reference` and `PriceHistory:off` per-model
- `--cache-jitter=0.1` spreads TTLs rather than making them identical
- `--no-cache` overrides `anvil.cache.enabled`
- `ETag` / `If-None-Match` round-trips to `304`, and the spec documents it

Because this is runtime code in `src/Runtime/Cache/`, a fix here needs the server restarted and the endpoints hit again — regenerating proves nothing.

### Scenario 11 — OpenAPI round trip

```bash
php artisan anvil:forge-api --api-version=1 --force
php artisan anvil:forge-apidocs --check
php artisan anvil:forge-client --api-version=1 --hooks --force
npx tsc --noEmit
npx @redocly/cli lint openapi/v1/openapi.yaml
```

Confirms: `OpenApiLocator` finds the spec each downstream command needs; `OpenApiRootGenerator` emits `servers` exactly once; `OpenApiPathGenerator` does not double path keys; `OpenApiTypeMapper` produces types the client compiles against; `OpenApiYamlSerializer` output passes an independent linter.

Then switch format:

```bash
php artisan anvil:forge-api --api-version=1 --spec-only --format=json --force
php artisan anvil:forge-apidocs --check
```

A spec written in the format you did _not_ ask for must still be reported as present.

### Scenario 12 — Swagger UI without a CDN

```bash
php artisan anvil:install:swagger-ui --check
php artisan anvil:install:swagger-ui --source=http --force
php artisan anvil:install:swagger-ui --source=local --force
php artisan anvil:install:swagger-ui --source=npm --timeout=1800 --force
php artisan serve
```

Confirms: each `SwaggerUiInstaller` strategy works in isolation, `--check` reports without writing, and total failure produces an actionable list rather than a `ProcessTimedOutException`. Load the docs route with devtools open: no CDN requests, no CORS errors, and `DocsController` serving the page.

### Scenario 13 — Docs sync

```bash
php artisan anvil:docs-sync --check                 # clean
# hand-edit a generated API resource: add a field, remove another
php artisan anvil:docs-sync --check --diff          # both reported, non-zero
php artisan anvil:docs-sync --check --breaking-only
php artisan anvil:docs-sync --dry-run
php artisan anvil:docs-sync
```

Confirms: `ResourceShapeReader` and `FormRequestShapeReader` parse the edits via `SourceTokens`; `SchemaDiff` classifies severity direction-dependently; `SpecMerger` writes only what `SyncManifest` says it owns; `--check` never writes; a partial read never prunes.

Version scoping, which a container-bound synchroniser would break:

```bash
php artisan anvil:docs-sync --api-version=v2 --check      # must read v2's spec
```

Then the three entry points, which must agree:

```bash
php artisan anvil:docs-sync --check
php artisan anvil:forge-api --api-version=1 --docs-sync --dry-run
# and the SyncsApiDocs hook during a normal generation run
```

And a custom reader, to exercise `Contracts\ShapeReader`:

```php
// config/anvil.php
'openapi' => ['sync' => ['readers' => [App\Anvil\MyShapeReader::class]]],
```

### Scenario 14 — Web scaffold, both stacks

```bash
php artisan anvil:frontend --check                          # non-zero when missing
php artisan anvil:frontend --install --stack=livewire
php artisan anvil:forge-webapp --stack=blade --force
php artisan anvil:forge-webapp --stack=livewire --force
php artisan anvil:forge-webapp --stack=livewire --per-page=30 --force
php artisan serve
```

Confirms: `WebStack` selection works both ways, `FrontendDetector` and `FrontendState` report accurately, the preflight exits and asks for a re-run after installing, and a non-standard `--per-page` is inserted into the generated `<select>`.

In the browser, confirm `NormalizesFormState` actually binds — this is runtime code, so it cannot be verified by reading generated files.

```bash
php artisan anvil:forge-webapp --layout=layouts.custom --no-layout --dry-run   # should warn
php artisan anvil:forge-webapp --skip-models --dry-run                          # deprecated no-op
php artisan anvil:forge-webapp --assets-mode=cdn --dry-run                      # not for production
```

### Scenario 15 — Auth parts, and their combinations

The preflight is most of this command's value, so test the failures first:

```bash
php artisan anvil:forge-auth --accent=chartreuse         # unknown accent
php artisan anvil:forge-auth --guard=nonexistent         # guard not in config/auth.php
php artisan anvil:forge-auth --users-table=userz         # should offer near-miss candidates
```

Add a `setPasswordAttribute()` mutator to the `User` model and run again — it must warn, because that mutator hashes the hash the register form already produced.

Then the part combinations, since `--no-*` flags select parts rather than branching inside one template:

```bash
php artisan anvil:forge-auth --force
php artisan anvil:forge-auth --no-2fa --force
php artisan anvil:forge-auth --no-lockout --force
php artisan anvil:forge-auth --no-verification --force
php artisan anvil:forge-auth --no-2fa --no-lockout --no-verification --force
```

Each must leave no dangling route, import or view reference from an omitted part. `RoutesPart` is where a stale reference will surface; `TwoFactorGateFragment` and `LockoutFragment` are the cross-cutting bits most likely to leak.

Then exercise every flow in a browser.

### Scenario 16 — GraphQL

```bash
composer require nuwave/lighthouse
php artisan anvil:forge-graphql --force                    # expect a no-guard warning
php artisan anvil:forge-graphql --guard=default --policies --force
php artisan lighthouse:validate-schema
```

Hand-edit `graphql/schema.graphql`, then re-run with `--force`: the root file must survive, `graphql/types/*` must be replaced. Composite-PK tables must be skipped _and named_.

### Scenario 17 — Idempotency and preserved regions

```bash
git add -A && git commit -m "before"
php artisan anvil:forge:app-scaffold --all --force
php artisan anvil:forge-api --api-version=1 --force
git add -A && git commit -m "generated"

php artisan anvil:forge:app-scaffold --all --force
php artisan anvil:forge-api --api-version=1 --force
git status --porcelain        # must be empty
```

The recurring offenders are the gate and repository providers (`ProviderRegistrar` appending rather than replacing), route files, and the spec's `servers` block and path keys.

Then `PreserveRegions`: add a hand edit inside a preserved region, regenerate, confirm it survives. Add one outside a region, regenerate, confirm the expected behaviour — whichever it is, it should be the same every time.

### Scenario 18 — Schema drift

```bash
php artisan anvil:diff --accept                 # baseline into SchemaManifest
php artisan anvil:diff                          # no drift
php artisan make:migration add_status_to_posts_table
php artisan migrate
php artisan anvil:diff                          # one added column
php artisan anvil:diff --strict                 # non-zero
php artisan anvil:diff --json
```

Then the orphan path — drop a table, confirm the report lists artifacts for a table that no longer exists, and that regeneration does not delete them.

### Scenario 19 — Polish, and the re-stamp

```bash
php artisan anvil:polish --test --strict        # CI mode
php artisan anvil:polish --pint
php artisan anvil:polish --audit                # ModelAuditor only
php artisan anvil:polish                        # everything installed
php artisan anvil:polish --publish-config       # must not overwrite an existing rector.php
```

Then verify the re-stamp directly, because it fails quietly:

```bash
php artisan anvil:polish --pint
php artisan anvil:forge:app-scaffold --models --force    # must still regenerate
```

If `--force` now refuses, Pint's rewrite invalidated the provenance hash and `QualityRunner` did not re-stamp.

### Scenario 20 — Multi-schema and the quoting trap

```bash
php artisan anvil:forge:app-scaffold --models --schema="core,reporting" --force
php artisan anvil:forge:app-scaffold --models --schema=core, reporting --dry-run
```

The second must recover the split fragment via `SchemaSelection`, report what it recovered, and proceed — not abort with `No arguments expected`. Then confirm a real typo is still refused by name:

```bash
php artisan anvil:forge:app-scaffold --models --schema=core nonsense
```

### Scenario 21 — Deprecated flag forwarding

```bash
php artisan anvil:forge:app-scaffold --api --api-version=1 --dry-run
php artisan anvil:forge:app-scaffold --openapi --openapi-format=json --dry-run
```

Each must print a deprecation warning naming the replacement and forward to `anvil:forge-api`. Keep until the flags are removed at 1.0.

### Scenario 22 — A custom generator

Implement `Contracts\Generator` in the test app and register it. Confirm the orchestrator picks it up, `GeneratorSet` includes it in `--all`, and it reports through `ScaffoldReport` like any built-in. If this is awkward, the extension point is not yet real — worth knowing before the README promises it.

### Scenario 23 — The full CI gate

```bash
php artisan anvil:doctor --strict
php artisan anvil:diff --strict
php artisan anvil:docs-sync --check
php artisan anvil:forge-apidocs --check --strict
php artisan anvil:polish --test --strict
php artisan anvil:frontend --check
```

All should pass on a clean generated tree. Then break one thing at a time and confirm exactly one gate fails, with a message naming the cause.

---

## 13. Resetting the Test Application

If the test app is under git — and it should be — this replaces everything below:

```bash
git clean -fd && git checkout .
```

### Reset generated files only

```bash
# Application code
rm -rf app/Http/Controllers/Api app/Http/Resources app/Http/Requests
rm -rf app/Services app/Repositories app/Policies app/Observers
rm -rf app/Events app/Listeners app/Livewire app/Enums
rm -f  app/Providers/RepositoryServiceProvider.php
rm -f  app/Providers/GateServiceProvider.php
rm -f  app/Providers/ForceJsonApiServiceProvider.php
rm -f  app/Http/Middleware/ForceJsonResponse.php
rm -rf routes/api tests/Feature

# Views, specs, docs, clients, schemas
rm -rf resources/views/livewire resources/views/auth
rm -f  resources/views/layouts/guest.blade.php
rm -rf resources/js/api
rm -rf openapi/ public/api-docs graphql/

# Anvil state — model manifest, diff baseline, sync manifest
rm -rf storage/anvil

# Models and factories, keeping the framework defaults
find database/factories -name "*.php" ! -name "UserFactory.php" -delete
find database/seeders  -name "*.php" ! -name "DatabaseSeeder.php" -delete
find app/Models        -name "*.php" ! -name "User.php" -delete

php artisan clear-compiled
php artisan cache:clear
```

Adjust to match your published `config/anvil.php` — spec, client and GraphQL output paths are all configurable.

Deleting `storage/anvil` discards the `SchemaManifest` baseline and the `SyncManifest` alongside the model manifest. Re-establish with `anvil:diff --accept` after the next generation, or the first diff reports the entire schema as new, and docs-sync will treat previously-owned components as unmanaged.

### Full reset (nuclear)

```bash
cd ~/workspace/projects/zuqongtech/opensource-projects-tests
rm -rf laravel-anvil-test-local
composer create-project laravel/laravel laravel-anvil-test-local
cd laravel-anvil-test-local
# Re-apply the composer.json changes from Section 4
composer install
git init && git add -A && git commit -m "baseline"
```

---

## 14. Troubleshooting

### Composer

**`Could not find a matching version of package zuqongtech/laravel-anvil`**

Stability resolution. Confirm `"minimum-stability": "dev"`, `"prefer-stable": true`, and a `"*@dev"` constraint. If you set `canonical`, confirm it is a sibling of `url`, not nested inside `options` where it is silently ignored. Then `rm -f composer.lock && composer install`.

**`composer update` fails on the package**

Confirm the `url` resolves to an existing directory. Relative paths resolve from the directory containing `composer.json`, not your shell's working directory.

**Package is copied instead of symlinked**

Delete `vendor/zuqongtech` and `composer.lock`, confirm `"options": { "symlink": true }`, reinstall. On Windows without developer mode, symlinks need an elevated shell.

**Parse error inside `vendor/zuqongtech/laravel-anvil/src/...`**

Your PHP is older than the syntax the package uses. Declared constraint is `^8.3`; parts of the source need 8.4.

### Command registration and arguments

**`An argument with name "n" already exists`**

A `$signature` contains a multi-line option description or a `{n}` placeholder Symfony is parsing as an argument. Option descriptions must be single-line with no `{...}` tokens.

**Flags missing from `--help`**

Same root cause: Symfony stops parsing at the first malformed token and drops everything after it. Fix the signature, then `php artisan clear-compiled`.

**Grep for a command's file finds nothing**

The classes kept their old `Generate*` names through the signature rename. See the mapping in [Section 9](#command-signatures-were-renamed-the-class-files-were-not).

**`No arguments expected` on a schema list**

`--schema=core, admin_db` reaches Symfony as `--schema=core,` plus a stray argument. `SchemaSelection` recovers fragments shaped like schema names; anything else is refused by name. Quote the list.

**Mutually exclusive flags accepted silently**

`--no-spec` with `--spec-only`, or `--all-versions` with `--api-version`, must both fail with an explanation. If either proceeds, the validation is missing.

### Model resolution

**`ModelNotRegisteredException`**

Correct behaviour, and the alternative is worse. Generate models first, or rebuild the manifest:

```bash
php artisan anvil:forge:app-scaffold --models --schema=all
php artisan anvil:forge:app-scaffold --refresh-models
```

**`AmbiguousModelException`**

Two candidates resolve to one `ModelReference` — commonly the same table name in two schemas, or a stale model left behind after a namespace change. The message should name both; if it does not, that is a `ModelRegistry` bug.

**A schema-namespaced model imported from the wrong namespace**

`App\Models\Core\User` re-derived as `App\Models\User` means the command derived the namespace instead of resolving through `ResolvesGeneratedModels`. Check `storage/anvil/models.json` first — if the manifest is right and the import is wrong, the bug is in the consuming generator.

**`--force` refuses to regenerate a file you did not edit**

The provenance hash says otherwise. A formatter almost certainly rewrote it; `anvil:polish` re-stamps what Pint and Rector touch, so if you ran them directly, the stamps are stale.

### Configuration and connections

**`Connection [] not configured` / connection resolves to `null`**

A shallow `array_merge` of the published `config/anvil.php` over the package defaults drops nested keys. Test both with and without a published config, and use `Arr::get()` with an explicit default:

```bash
php artisan tinker --execute="dd(config('anvil'));"
```

`ConfigValidator` should be catching this shape before generation starts.

### Generated code

**Duplicate relation method names**

Two FKs to the same parent produce colliding methods. `RelationNamer` should disambiguate on the local column. `anvil:doctor` reports the shape before generation — if doctor is silent on a table that then generates a redeclaration, the detection is the bug.

**`Redefinition of parameter $user`**

`PolicyGenerator` must rename the second parameter when the subject type matches the authenticated user type: `view(User $user, User $target)`.

**`Cannot use App\Models\User as User because the name is already in use`**

Duplicate import. `ImportSet` exists to deduplicate — if duplicates appear, the generator is emitting `use` statements directly rather than routing them through it.

**`Target [...RepositoryInterface] is not instantiable`**

`ProviderRegistrar` should have registered `RepositoryServiceProvider` in `bootstrap/providers.php`. If it did not, that is the bug, not your setup. Add it manually to unblock, then `php artisan clear-compiled`.

**A syntax error reaches disk**

`PhpSyntaxCheck` should reject it before the write. If invalid PHP is landing, the generator is bypassing the pipeline in `RunsGenerationPipeline`.

**Stub override ignored**

`StubGenerator` owns the lookup order. Confirm the published stub is in `stubs/anvil/` with the exact expected filename.

### Auth

**`SessionGuard` TypeError at login**

The authenticatable model extends `Model` instead of `Illuminate\Foundation\Auth\User`. Any table with a `password` column needs the latter. `anvil:doctor` reports it.

**"This password does not use the Bcrypt algorithm"**

Stored hashes are not bcrypt. Confirm with `anvil:doctor --data`.

**Registration succeeds but login always fails**

A `setPasswordAttribute()` mutator, hashing the hash the register form already produced. `anvil:forge-auth` warns after the configuration table, which is where non-fatal findings go.

**A route or view references a part you disabled**

`RoutesPart` or a fragment did not respect the part selection. Check `Support/Auth/Parts/RoutesPart.php` and the two fragments.

**Accent classes have no effect**

Tailwind's scanner only sees literal class names. If `Ui`/`FormKit` assembled them at runtime rather than interpolating at generation time, they exist in no file Tailwind reads.

### OpenAPI, docs, and client

**Doubled path keys, or `servers` twice**

`OpenApiRootGenerator` and `OpenApiPathGenerator` are separate; a doubled `servers` is the root generator, doubled paths the path generator. Delete and regenerate to isolate:

```bash
rm -rf openapi/v1 && php artisan anvil:forge-api --api-version=1 --spec-only --force
```

**Path template placeholders rendered literally**

`/posts/{id}` needs a matching `parameters` entry with `in: path`. Check `OpenApiPathGenerator`, not the stub.

**Malformed YAML**

`OpenApiYamlSerializer` is hand-rolled. Multi-line strings, special characters and deep nesting are the usual suspects. Lint independently rather than trusting Swagger UI to render it.

**`--check` reports a spec as missing when the file is there**

Format mismatch in `OpenApiLocator`. It must recognise a JSON spec when you asked for YAML.

**Swagger UI "Failed to fetch" or CORS**

The UI is fetching cross-origin. Serve the spec from the same origin, or inline it at generation. Permissive CORS headers move the problem to whoever deploys this.

**`ProcessTimedOutException` naming a vendor file**

The npm strategy timed out:

```bash
php artisan anvil:install:swagger-ui --source=http
# or
npm install --no-save swagger-ui-dist@5.17.14 \
  && php artisan anvil:install:swagger-ui --source=local
```

**TypeScript keys do not match API responses**

Resources, form requests, the spec and the client all read `ApiVersionProfile`. If they disagree, one of them is deriving casing itself instead of reading the profile.

### Docs sync

**Sync rewrites a hand-authored component**

`SyncManifest` decides what sync owns. A component it never adopted must be untouched. `--adopt` is the only way ownership should be acquired.

**`--api-version=v2` reconciles v1's spec**

`DocsSynchronizer` was resolved from the container rather than built per invocation, fixing its spec directory at construction.

**A hand edit is not detected**

`SourceTokens` failed to parse it. Reduce to the smallest resource or form request that reproduces — the tokenizer is the likeliest culprit, not the mapper.

**Command, `--docs-sync` flag and hook behave differently**

They must all route through `DocsSynchronizer`. If one diverges, it has its own copy of a rule.

### Cache

**Cached reads never invalidate**

`CacheStamps`, not tags, specifically so tagless drivers work. If it fails only on `file` or `database`, something reintroduced a tag dependency.

**v1 and v2 return each other's payloads**

`CacheKey` is not including `$cacheVariant`. This is the failure Scenario 10 exists to catch.

**A cache fix has no effect**

`src/Runtime/Cache/` is runtime code. Restart the server and hit the endpoints — regenerating changes nothing.

### Symlink staleness

**Changes to package source not reflected**

```bash
composer dump-autoload
php artisan clear-compiled
php artisan package:discover --ansi
```

If you changed the service provider's `register()` or `boot()`, add `config:clear` and `cache:clear`.

If a _newly added_ class is not found, it is almost certainly the autoloader — `composer dump-autoload` before investigating anything else.

**A change to `src/Runtime/`, `src/Livewire/` or `src/Http/` appears to do nothing**

Those are runtime, not generation. Re-running the generator cannot show the change. Serve the app.
