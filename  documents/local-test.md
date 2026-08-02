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
9. [Running the Generators](#9-running-the-generators)
10. [Iterating on Package Source](#10-iterating-on-package-source)
11. [Common Test Scenarios](#11-common-test-scenarios)
12. [Resetting the Test Application](#12-resetting-the-test-application)
13. [Troubleshooting](#13-troubleshooting)

---

## 1. Overview

Because `laravel-anvil` is a code-generation package, the most effective way to test it during development is against a real Laravel application with a live database. Rather than publishing a release to Packagist on every change, Composer's **path repository** feature symlinks the package directory directly into the test application's `vendor/` folder. Any change you save in the package source is immediately reflected in the test app — no `composer update` required for most changes.

Anvil is a suite of thirteen Artisan commands. Every generating command runs the identical pipeline through the `RunsGenerationPipeline` trait, resolves models through `ResolvesGeneratedModels`, and reports through `RendersScaffoldOutput`. Testing therefore means exercising each command _and_ the sequences that chain them, because the shared traits are where cross-command regressions surface.

> **Command names changed.** Everything that was `anvil:generate*` is now `anvil:forge*`. If you have muscle memory or scripts from before the rename, see the mapping table in [Section 8](#8-the-command-suite).

---

## 2. Directory Structure

The setup assumes the following layout. Adjust paths to match your own workspace if they differ, but keep them consistent — mismatched paths in `composer.json` are the single most common cause of a broken symlink.

```
~/workspace/projects/zuqongtech/
├── packages/
│   └── laravel-anvil/                  ← git repo: zuqongtech/laravel-anvil
│       ├── bin/
│       ├── src/
│       │   ├── Console/
│       │   │   └── Concerns/
│       │   ├── Exceptions/
│       │   ├── Generators/
│       │   └── Support/
│       ├── config/
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

Use `anvil:frontend` rather than installing Livewire and Tailwind by hand — testing the installer _is_ part of testing the package, and it is the only path that also writes the Tailwind config wiring.

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

| Shape                                           | Why it matters                                                                              |
| ----------------------------------------------- | ------------------------------------------------------------------------------------------- |
| Two FKs from one table to `users`               | Duplicate relation method names — the fatal redeclaration `RelationNamer` must disambiguate |
| A composite primary key                         | Skipped with a report by `anvil:forge-graphql`; handled specially elsewhere                 |
| A table with no primary key                     | Doctor error path                                                                           |
| An unindexed foreign key                        | Doctor warning path                                                                         |
| A native `ENUM` column                          | `EnumDetector` / `EnumColumn`                                                               |
| A free-text `status` column                     | Doctor's enum-candidate suggestion                                                          |
| A reserved word as a column name                | Doctor error path                                                                           |
| A column that camelises onto an Eloquent method | Model collision detection                                                                   |
| A second authenticatable table                  | Must extend `Illuminate\Foundation\Auth\User`, not `Model`                                  |
| A table with no `created_at`/`updated_at`       | Timestamp warning                                                                           |
| A soft-deletable table                          | `Restored` event generation                                                                 |
| Column comments and a check constraint          | `--with-constraints`, and driver divergence                                                 |

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

Quote the list. See the `No arguments expected` entry in [Troubleshooting](#13-troubleshooting).

### Option B — SQLite (quickest for basic testing)

```dotenv
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/laravel-anvil-test-local/database/database.sqlite
```

```bash
touch database/database.sqlite
php artisan migrate
```

> SQLite has no check constraints, no table or column comments, and no native enum type. Use MySQL or PostgreSQL to test those introspection paths. SQLite is for fast iteration on generator logic, not for validating introspection.

### Using a non-default connection

Every command accepts `--connection`:

```bash
php artisan anvil:forge:app-scaffold --models --connection=reporting --dry-run
```

This flag is also the regression test for shallow config merges — see [Troubleshooting](#13-troubleshooting).

---

## 7. Publishing Anvil Assets

Publish the config so you can customise it in the test app:

```bash
php artisan vendor:publish \
    --provider="Zuqongtech\LaravelAnvil\LaravelAnvilServiceProvider" \
    --tag="config"
```

This creates `config/anvil.php`. Edit it to adjust ignored tables, namespaces, casing, cache defaults, and per-generator settings.

To test stub customisation:

```bash
php artisan vendor:publish \
    --provider="Zuqongtech\LaravelAnvil\LaravelAnvilServiceProvider" \
    --tag="stubs"
```

Stubs land in `stubs/anvil/` and take precedence over the package-bundled templates automatically.

> **Tip:** Because the package is symlinked, changes to stubs inside the package source (`packages/laravel-anvil/stubs/`) are reflected immediately without republishing. Publishing is only needed to test the _override_ mechanism itself.

**Always test the published-config path at least once.** Publishing produces a partial config file, and a shallow merge against the package defaults is how `connection` ends up `null` at runtime. Delete `config/anvil.php` and re-run to compare behaviour.

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

`anvil:diff`, `anvil:doctor`, `anvil:docs-sync`, `anvil:polish` and `anvil:frontend` are unchanged.

`anvil:forge-openapi` is an alias of `anvil:forge-api`, which reads better with `--spec-only`.

### Generation

| Command                    | Produces                                                        | Key support classes                                              |
| -------------------------- | --------------------------------------------------------------- | ---------------------------------------------------------------- |
| `anvil:forge:app-scaffold` | Models plus the core per-model artifacts                        | `ModelBuilder`, `RelationNamer`, `ModelMetadata`, `EnumDetector` |
| `anvil:forge-api`          | Versioned JSON API + OpenAPI 3.1 spec                           | `ApiVersionProfile`, `KeyCase`, `OpenApiLocator`                 |
| `anvil:forge-apidocs`      | Docs for one or all versions, plus a report of where each lives | `OpenApiLocator`                                                 |
| `anvil:forge-webapp`       | Web CRUD front end (Blade or Livewire)                          | `LivewireComponentGenerator`, `FormStateProperty`, `ImportSet`   |
| `anvil:forge-auth`         | Livewire auth + RBAC from the users table                       | `AuthScaffolder`                                                 |
| `anvil:forge-graphql`      | Lighthouse SDL schema                                           | `GraphQLSchemaBuilder`                                           |
| `anvil:forge-client`       | Typed TypeScript client for a versioned API                     | `ApiVersionProfile`, `KeyCase`                                   |

### Inspection

| Command           | Reports                                                   | Key support classes                 |
| ----------------- | --------------------------------------------------------- | ----------------------------------- |
| `anvil:doctor`    | Schema shapes that break generation, before they break it | schema introspection                |
| `anvil:diff`      | What changed in the database since the last generation    | `SchemaManifest`, `SchemaSelection` |
| `anvil:docs-sync` | Drift between hand-edited payloads and the OpenAPI spec   | `DocsSynchronizer`                  |

### Maintenance

| Command                    | Does                                              | Key support classes                               |
| -------------------------- | ------------------------------------------------- | ------------------------------------------------- |
| `anvil:polish`             | Pint, Rector, PHPStan, and a model ↔ schema audit | `QualityRunner`, `ModelAuditor`, `PhpSyntaxCheck` |
| `anvil:frontend`           | Checks or installs Livewire and Tailwind          | —                                                 |
| `anvil:install:swagger-ui` | Vendors the Swagger UI assets                     | `OpenApiLocator`                                  |

### Two distinctions worth internalising before you test

**`anvil:doctor` inspects the schema. `anvil:polish --audit` inspects the models.** Doctor is read-only and runs _before_ generation — it tells you the schema will produce broken code. The model ↔ schema audit runs _after_ generation and tells you the models have drifted from the schema. They are different checks at different points in the cycle, and doctor has no `--fix`.

**Only `anvil:forge:app-scaffold --models` writes models.** Every other command _resolves_ them, from `storage/anvil/models.json` or by scanning the model path. That split is what stops a schema-namespaced `App\Models\Core\User` being re-derived as `App\Models\User` downstream, and stops a web or API run reverting hand edits to a model. Test it deliberately: a downstream command with no manifest and no models on disk must fail and name the table, not emit a controller importing a class that was never written.

Shared behaviour lives in three concerns — `RunsGenerationPipeline`, `ResolvesGeneratedModels`, `RendersScaffoldOutput`. A bug in any of them shows up in _every_ command, so when something breaks in one place, re-run the others before assuming it is local.

---

## 9. Running the Generators

### Verify registration

```bash
php artisan list anvil
```

All thirteen commands should appear. If one is missing, the `GeneratorRegistry` binding or the service provider's `commands()` call is the place to look.

```bash
php artisan anvil:forge-api --help
```

All flags should be listed. If flags are silently absent, there is a signature parsing issue — see [Section 13](#13-troubleshooting).

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

Models are always generated unless skipped; every other artifact is opt-in, or all at once with `--all`.

```bash
php artisan anvil:forge:app-scaffold --models --schema=all
php artisan anvil:forge:app-scaffold --all --tables=posts --tables=comments --force
php artisan anvil:forge:app-scaffold --events --listeners --listener-style=subscriber
php artisan anvil:forge:app-scaffold --models --with-phpdoc --with-inverse --with-constraints
php artisan anvil:forge:app-scaffold --refresh-models        # rebuild manifest, generate nothing
```

`--refresh-models` short-circuits the pipeline and touches no database beyond resolving the default schema. It is how you recover from a stale or deleted manifest without regenerating.

`--listeners` implies `--events`. `--queued-listeners` applies only to `per-event` style, and the command says so rather than ignoring it.

### `anvil:forge-api`

```bash
php artisan anvil:forge-api --api-version=1 --force
php artisan anvil:forge-api --api-version=2 --case=camel --pagination=25 --force
php artisan anvil:forge-api --api-version=1 --spec-only --format=json --force
php artisan anvil:forge-api --api-version=1 --cache --etag --cache-ttl=single=300,list=60
php artisan route:list | grep "api/v1"
```

`--auth` decides both the route middleware and the spec's `securityScheme`, so the running API and its documentation cannot disagree — worth asserting in both places after changing it.

`--no-spec` and `--spec-only` are mutually exclusive. `--pagination-max` must be ≥ `--pagination`. Free-text options (`--auth`, `--format`, `--security`, `--case`, `--throttle`) are validated before anything is written, so a typo should fail fast rather than produce 32 tables of wrong output.

The summary table printed before generation shows the resolved profile. Read it — it is the cheapest way to catch a flag that did not take effect.

### `anvil:forge-apidocs`

```bash
php artisan anvil:forge-apidocs --check                  # what exists?
php artisan anvil:forge-apidocs --all-versions --force
php artisan anvil:forge-apidocs --check --strict         # CI gate
php artisan anvil:forge-apidocs --check --json
```

Generation is delegated to `anvil:forge-api --spec-only`; this command owns version targeting and reporting. `--all-versions` and `--api-version` are mutually exclusive. A dry run legitimately leaves nothing on disk, so `--dry-run` never fails `--strict`.

### `anvil:install:swagger-ui`

```bash
php artisan anvil:install:swagger-ui --check
php artisan anvil:install:swagger-ui --source=http        # skip npm entirely
php artisan anvil:install:swagger-ui --timeout=1800       # slow link
```

`auto` tries `node_modules`, then a direct download, then npm. Test each `--source` explicitly at least once — the fallback chain is the part that breaks, and it breaks on constrained links where you are least able to debug it. After install, point the docs page at the vendored copy:

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

The frontend preflight runs before the pipeline, and an install run exits and asks you to re-run — a Composer install cannot take effect in the process that performed it, because the autoloader is already built and the providers already registered. That exit-and-re-run is correct behaviour, not a failure; confirm it happens rather than treating it as a bug.

### `anvil:forge-auth`

```bash
php artisan anvil:forge-auth --dry-run
php artisan anvil:forge-auth --accent=emerald --dark --default-role=member --force
php artisan anvil:forge-auth --no-2fa --no-lockout --no-verification --force
```

Pre-flight runs before anything is written: Livewire installed, `--accent` known, guard exists in `config/auth.php` and resolves to a provider whose model class exists, users table present with `email` and `password`. Non-fatal gaps are reported as warnings after the configuration table. A partial scaffold exits non-zero.

### `anvil:forge-graphql`

```bash
php artisan anvil:forge-graphql --guard=default --policies --force
php artisan lighthouse:validate-schema
```

`graphql/schema.graphql` is written once and then left alone — it is where hand-written operations go. Everything under `graphql/types/` is regenerated freely. Confirm both halves of that: a hand-edit to the root file must survive a `--force` run, and a type file must not.

Composite-PK tables are skipped and reported. Running without `--guard` warns loudly.

### `anvil:forge-client`

```bash
php artisan anvil:forge-client --api-version=1 --hooks --force
npx tsc --noEmit
```

Output lands in `resources/js/api/v{n}/`. Everything resolves through `ApiVersionProfile` — the same object the PHP requests and resources read — so a camelCase v2 must produce camelCase interfaces and a `?perPage=` query parameter.

### Inspection and maintenance

```bash
php artisan anvil:doctor --data --strict --json
php artisan anvil:diff
php artisan anvil:diff --accept                 # record the baseline
php artisan anvil:docs-sync --check --diff
php artisan anvil:polish --test --strict
php artisan anvil:polish --audit
php artisan anvil:polish --publish-config
```

`anvil:polish` shells out to Pint, Rector and PHPStan, which must be installed in the **test app**, not the package. Each pass skips cleanly when its tool is absent, so a run that reports "skipped" four times means you installed nothing, not that everything passed.

---

## 10. Iterating on Package Source

Because `vendor/zuqongtech/laravel-anvil` is a symlink to your package source, the edit cycle is:

1. Edit a file in `packages/laravel-anvil/src/`
2. Switch to the test app and run the relevant `anvil:*` command
3. Observe the output immediately — no `composer update` needed

**The one exception:** if you add a new class that needs autoloading, regenerate the autoloader. This happens often given how many `src/Support/` and `src/Exceptions/` classes are in active development:

```bash
composer dump-autoload
```

A missing `ModelReference`, `ImportSet`, or exception class almost always means a stale autoloader rather than a real bug.

**If you change `composer.json` inside the package** (add a dependency, change the service provider registration):

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

**Package test suite.** Run the package's own tests before reaching for the test app — they are faster and catch most support-class regressions:

```bash
cd ~/workspace/projects/zuqongtech/packages/laravel-anvil
composer test              # Pest
composer test:types        # PHPStan
vendor/bin/pint --test
vendor/bin/rector process --dry-run
```

---

## 11. Common Test Scenarios

Run in order, simplest to most complex.

### Scenario 1 — Doctor on a deliberately awkward schema

```bash
php artisan anvil:doctor
php artisan anvil:doctor --data
php artisan anvil:doctor --strict --json
```

Confirms: every shape you planted in [Section 6](#a-schema-worth-testing-against) is detected, classified correctly as `error` / `warning` / `note`, and carries a suggested fix. `--data` should sample stored password hashes and flag anything that is not a recognised algorithm. `--strict` exits non-zero only on errors, not warnings.

This scenario is also how you validate your test schema. Nothing reported means nothing being tested.

### Scenario 2 — Models only, dry run

```bash
php artisan anvil:forge:app-scaffold --models --dry-run
```

Confirms: introspection, `RelationshipDetector`, `RelationNamer`, `ModelBuilder`, `FileWriter` — with nothing written.

### Scenario 3 — Models, and the manifest

```bash
php artisan anvil:forge:app-scaffold --models --schema=all --force
cat storage/anvil/models.json
```

Confirms: every table has a manifest entry, and the namespace recorded matches where the file actually landed. For a multi-schema run, `App\Models\Core\User` must be recorded as such, not flattened.

### Scenario 4 — Manifest recovery

```bash
mv storage/anvil/models.json /tmp/
php artisan anvil:forge-webapp --tables=posts --dry-run    # should resolve by scanning
php artisan anvil:forge:app-scaffold --refresh-models
diff <(jq -S . storage/anvil/models.json) <(jq -S . /tmp/models.json)
```

Confirms: the scan fallback works, and `--refresh-models` reconstructs an equivalent manifest without touching the database beyond schema resolution.

Then the failure path:

```bash
rm storage/anvil/models.json
rm app/Models/Post.php
php artisan anvil:forge-webapp --tables=posts
```

Confirms: this fails, names the `posts` table, and does not emit a controller importing a class that was never written.

### Scenario 5 — Full app scaffold

```bash
php artisan anvil:forge:app-scaffold --all --force
php artisan route:list
php artisan test
```

Confirms: generated files parse (`PhpSyntaxCheck` should catch anything that does not), providers register, routes resolve, tests pass.

### Scenario 6 — Listener styles

```bash
php artisan anvil:forge:app-scaffold --events --listeners --force
php artisan anvil:forge:app-scaffold --events --listeners --listener-style=subscriber --force
php artisan anvil:forge:app-scaffold --events --listeners --listener-style=subscriber --queued-listeners --force
```

Confirms: `per-event` emits one class per event, `subscriber` one per model, and the third invocation _warns_ that `--queued-listeners` does not apply to subscriber style rather than silently dropping it.

### Scenario 7 — Versioned API

```bash
php artisan anvil:forge-api --api-version=1 --force
php artisan route:list | grep "api/v1"
```

Confirms: `ApiVersionProfile` applies, the `ForceJsonResponse` middleware and provider are registered, versioned routes exist, JSON enforcement is active. Cross-check that the spec's `securityScheme` matches the `--auth` value that produced the route middleware.

### Scenario 8 — Two versions with different wire formats

```bash
php artisan anvil:forge-api --api-version=1 --tables=posts --force
php artisan anvil:forge-api --api-version=2 --tables=posts --case=camel --pagination=25 --force
php artisan route:list | grep "api/v"
```

Confirms: both versions registered, v1 untouched by the v2 run, and v2 genuinely camelCase in resources, form requests, the spec and the page-size parameter (`perPage`, not `per_page`).

### Scenario 9 — Response caching

```bash
php artisan anvil:forge-api --api-version=1 --cache --etag --force
php artisan anvil:forge-api --api-version=2 --cache --etag --force
```

Confirms:

- Generated services cache reads, and invalidation works on the `file` driver — the point of generation stamps over tags is that tagless drivers work, so test on `file`, not `redis`
- `$cacheVariant` defaults to `static::class`, so v1 and v2 reading the same table do not collide on a key. Hit both, compare payloads, confirm each returns its own shape
- `--cache-jitter=0.1` produces TTLs spread around the nominal value rather than identical ones
- `--cache-model=Category:reference` and `--cache-model=PriceHistory:off` apply per-model rather than globally
- `--no-cache` overrides `anvil.cache.enabled`
- `ETag` / `If-None-Match` round-trips to a `304`, and the spec documents it

### Scenario 10 — OpenAPI round trip

```bash
php artisan anvil:forge-api --api-version=1 --force
php artisan anvil:forge-apidocs --check
php artisan anvil:forge-client --api-version=1 --hooks --force
npx tsc --noEmit
npx @redocly/cli lint openapi/v1/openapi.yaml
```

Confirms: `OpenApiLocator` finds the spec each downstream command needs; path keys are not doubled; `servers` appears exactly once; the generated TypeScript compiles. Validate the spec with an independent linter rather than trusting Swagger UI to render it.

Then switch format and confirm reporting is not fooled:

```bash
php artisan anvil:forge-api --api-version=1 --spec-only --format=json --force
php artisan anvil:forge-apidocs --check
```

A spec written in the format you did _not_ ask for must still be reported as present.

### Scenario 11 — Swagger UI without a CDN

```bash
php artisan anvil:install:swagger-ui --check
php artisan anvil:install:swagger-ui --source=http --force
php artisan anvil:install:swagger-ui --source=local --force
php artisan anvil:install:swagger-ui --source=npm --timeout=1800 --force
php artisan serve
```

Confirms: each strategy works in isolation, `--check` reports without writing, `--skip-generate` skips the spec regeneration, and a total failure produces an actionable list rather than a `ProcessTimedOutException` naming a vendor file. Then load the docs route in a browser with devtools open — no CDN requests, no CORS errors.

### Scenario 12 — Docs sync

```bash
php artisan anvil:docs-sync --check                 # clean
# hand-edit a generated API resource: add a field, remove another
php artisan anvil:docs-sync --check --diff          # should report both, non-zero
php artisan anvil:docs-sync --check --breaking-only
php artisan anvil:docs-sync --dry-run
php artisan anvil:docs-sync
```

Confirms: drift detected in both directions, severity is direction-dependent (a response change treated more seriously than the same request change), `--check` never writes, a partial read never prunes, and hand-authored components are untouched.

The version-scoping bug is worth an explicit check, since a container-bound synchroniser would fix its spec directory at construction:

```bash
php artisan anvil:docs-sync --api-version=v2 --check
```

This must read v2's spec, not v1's.

### Scenario 13 — Web scaffold, both stacks

```bash
php artisan anvil:frontend --check                          # expect non-zero when missing
php artisan anvil:frontend --install --stack=livewire
php artisan anvil:forge-webapp --stack=blade --force
php artisan anvil:forge-webapp --stack=livewire --force
php artisan anvil:forge-webapp --stack=livewire --per-page=30 --force
php artisan serve
```

Confirms: both stacks produce working CRUD, the frontend preflight exits and asks for a re-run after installing, and a non-standard `--per-page` is inserted into the generated `<select>` so the dropdown never opens showing a value nobody chose.

Also confirm the warning paths:

```bash
php artisan anvil:forge-webapp --layout=layouts.custom --no-layout --dry-run   # should warn
php artisan anvil:forge-webapp --skip-models --dry-run                          # deprecated no-op, should warn
php artisan anvil:forge-webapp --assets-mode=cdn --dry-run                      # should warn: not for production
```

### Scenario 14 — Auth scaffold and its preflight

Test the failures before the success — the preflight is most of this command's value:

```bash
php artisan anvil:forge-auth --accent=chartreuse         # unknown accent
php artisan anvil:forge-auth --guard=nonexistent         # guard not in config/auth.php
php artisan anvil:forge-auth --users-table=userz         # should offer near-miss candidates
```

Then add a `setPasswordAttribute()` mutator to the `User` model and run again — it must warn, because that mutator hashes the hash the register form already produced and silently breaks every login.

```bash
php artisan anvil:forge-auth --force
php artisan serve
```

Confirms: login, register, logout, password reset, verification, 2FA and lockout all work in a browser; RBAC middleware and gates read your actual roles/permissions tables; accent classes appear as literal strings so Tailwind's scanner sees them.

### Scenario 15 — GraphQL

```bash
composer require nuwave/lighthouse
php artisan anvil:forge-graphql --force                    # expect a no-guard warning
php artisan anvil:forge-graphql --guard=default --policies --force
php artisan lighthouse:validate-schema
```

Then hand-edit `graphql/schema.graphql`, add a type file change, and re-run with `--force`. The root file must survive; `graphql/types/*` must be replaced. Composite-PK tables must be skipped _and named_.

### Scenario 16 — Idempotency and provenance

```bash
git add -A && git commit -m "before"
php artisan anvil:forge:app-scaffold --all --force
php artisan anvil:forge-api --api-version=1 --force
git add -A && git commit -m "generated"

php artisan anvil:forge:app-scaffold --all --force
php artisan anvil:forge-api --api-version=1 --force
git status --porcelain        # must be empty
```

The recurring offenders are `GateServiceProvider`, `RepositoryServiceProvider`, route files, and the spec's `servers` block and path keys.

Then the provenance half:

```bash
# hand-edit a generated model
php artisan anvil:forge:app-scaffold --models --force    # must refuse, or preserve the edit
```

Confirms: the provenance hash marks the file as hand-edited and `--force` respects it. This is the mechanism `anvil:polish` has to re-stamp after formatting — see Scenario 18.

### Scenario 17 — Schema drift

```bash
php artisan anvil:diff --accept                 # baseline
php artisan anvil:diff                          # expect: no drift
php artisan make:migration add_status_to_posts_table
# add a column, then:
php artisan migrate
php artisan anvil:diff                          # expect: one added column
php artisan anvil:diff --strict                 # expect: non-zero
php artisan anvil:diff --json
```

Then the orphan path — drop a table and confirm the report lists artifacts belonging to a table that no longer exists, and that regeneration does not delete them:

```bash
php artisan migrate:rollback
php artisan anvil:diff
```

### Scenario 18 — Polish, and the re-stamp

```bash
php artisan anvil:polish --test --strict        # CI mode: reports, changes nothing
php artisan anvil:polish --pint
php artisan anvil:polish --audit
php artisan anvil:polish                        # everything installed
php artisan anvil:polish --publish-config       # must not overwrite an existing rector.php
```

Confirms: each pass skips cleanly when its tool is absent, only manifest-listed files are touched by default, `--all-paths` widens it, and the run reports how many files it re-stamped.

The re-stamp is the part that breaks quietly, so verify it directly:

```bash
php artisan anvil:polish --pint
php artisan anvil:forge:app-scaffold --models --force    # must still regenerate
```

If `--force` now refuses, Pint's rewrite invalidated the provenance hash and the re-stamp did not happen.

### Scenario 19 — Multi-schema and the quoting trap

```bash
php artisan anvil:forge:app-scaffold --models --schema="core,reporting" --force
php artisan anvil:forge:app-scaffold --models --schema=core, reporting --dry-run
```

The second must recover the split fragment, report what it recovered, and proceed — not abort with `No arguments expected`. Then confirm a real typo is still refused by name:

```bash
php artisan anvil:forge:app-scaffold --models --schema=core --tables=posts nonsense
```

### Scenario 20 — Deprecated flag forwarding

```bash
php artisan anvil:forge:app-scaffold --api --api-version=1 --dry-run
php artisan anvil:forge:app-scaffold --openapi --openapi-format=json --dry-run
```

Confirms: each prints a deprecation warning naming the replacement and forwards to `anvil:forge-api` rather than failing or silently doing nothing. Worth keeping until the flags are actually removed at 1.0.

### Scenario 21 — The full CI gate

Everything that exits non-zero, run together, on a clean generated tree:

```bash
php artisan anvil:doctor --strict
php artisan anvil:diff --strict
php artisan anvil:docs-sync --check
php artisan anvil:forge-apidocs --check --strict
php artisan anvil:polish --test --strict
php artisan anvil:frontend --check
```

All should pass. Then break one thing at a time and confirm exactly one gate fails, with a message that names the cause.

---

## 12. Resetting the Test Application

If the test app is under git — and it should be — this replaces everything below:

```bash
git clean -fd && git checkout .
```

### Reset generated files only

```bash
# Application code
rm -rf app/Http/Controllers/Api
rm -rf app/Http/Resources
rm -rf app/Http/Requests
rm -rf app/Services
rm -rf app/Repositories
rm -rf app/Policies
rm -rf app/Observers
rm -rf app/Events
rm -rf app/Listeners
rm -rf app/Livewire
rm -f  app/Providers/RepositoryServiceProvider.php
rm -f  app/Providers/GateServiceProvider.php
rm -f  app/Providers/ForceJsonApiServiceProvider.php
rm -f  app/Http/Middleware/ForceJsonResponse.php
rm -rf routes/api
rm -rf tests/Feature

# Views, specs, docs, clients, schemas
rm -rf resources/views/livewire
rm -rf resources/views/auth
rm -rf resources/views/layouts/guest.blade.php
rm -rf resources/js/api
rm -rf openapi/
rm -rf public/api-docs
rm -rf graphql/

# Anvil state — the manifest, and the diff baseline
rm -rf storage/anvil

# Models and factories, keeping the framework defaults
find database/factories -name "*.php" ! -name "UserFactory.php" -delete
find database/seeders  -name "*.php" ! -name "DatabaseSeeder.php" -delete
find app/Models        -name "*.php" ! -name "User.php" -delete

php artisan clear-compiled
php artisan cache:clear
```

Adjust to match your published `config/anvil.php` — output paths for the spec (`--output`, `--flat`), the client (`--output`), GraphQL (`--output`) and the docs route are all configurable.

Note that deleting `storage/anvil` discards the `anvil:diff` baseline as well as the model manifest. Re-establish it with `anvil:diff --accept` after the next generation, or the first diff will report the entire schema as new.

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

## 13. Troubleshooting

### Composer

**`Could not find a matching version of package zuqongtech/laravel-anvil`**

Stability resolution. Confirm all three of: `"minimum-stability": "dev"`, `"prefer-stable": true`, and a `"*@dev"` constraint. If you set `canonical`, confirm it is a sibling of `url` and not nested inside `options`, where it is silently ignored. Then:

```bash
rm -f composer.lock && composer install
```

**`composer update` fails on `zuqongtech/laravel-anvil`**

Confirm the `url` in the `repositories` block resolves to an existing directory. Relative paths are resolved from the directory containing `composer.json`, not from your shell's working directory.

**Package is copied instead of symlinked**

Delete `vendor/zuqongtech` and `composer.lock`, confirm `"options": { "symlink": true }`, reinstall. On Windows without developer mode, symlinks require an elevated shell.

**Parse error inside `vendor/zuqongtech/laravel-anvil/src/...`**

Your PHP is older than the syntax the package uses. The declared constraint is `^8.3`; parts of the source need 8.4. Check with `php -v`.

### Command registration and arguments

**`An argument with name "n" already exists`**

A `$signature` contains a multi-line option description or a `{n}` placeholder that Symfony Console is parsing as an argument. All option descriptions in `$signature` must be single-line strings with no `{...}` tokens.

**Flags missing from `--help`**

Same root cause: Symfony stops parsing the signature at the first malformed token and drops everything after it. Fix the signature, then:

```bash
php artisan clear-compiled && php artisan anvil:forge-api --help
```

**`No arguments expected` on a schema list**

`--schema=core, admin_db` reaches Symfony as `--schema=core,` plus a stray argument. The positional catch-all recovers fragments shaped like schema names and reports what it recovered; anything else is refused by name. Quote the list:

```bash
php artisan anvil:forge:app-scaffold --models --schema="core,admin_db"
```

**Mutually exclusive flags accepted silently**

`--no-spec` with `--spec-only`, or `--all-versions` with `--api-version`, must both fail with an explanation. If either combination proceeds, the validation is missing.

**A command hangs, or the same output repeats indefinitely**

Recursive command invocation. Commands should invoke generators through `GeneratorRegistry`, not by calling sibling Artisan commands — with the deliberate exception of `anvil:forge-apidocs`, which delegates to `anvil:forge-api --spec-only` so there is only one implementation of the spec pipeline.

### Model resolution

**A downstream command reports a model it cannot find**

Correct behaviour, and the alternative is worse. Generate models first:

```bash
php artisan anvil:forge:app-scaffold --models --schema=all
```

If the models exist but the manifest is stale or missing:

```bash
php artisan anvil:forge:app-scaffold --refresh-models
```

**A schema-namespaced model is imported from the wrong namespace**

`App\Models\Core\User` re-derived as `App\Models\User` means the command derived the namespace instead of resolving it through `ResolvesGeneratedModels`. Check `storage/anvil/models.json` first — if the manifest is right and the import is wrong, the bug is in the consuming generator.

**`--force` refuses to regenerate a file you did not edit**

The provenance hash says otherwise. A formatter almost certainly rewrote it — `anvil:polish` re-stamps what Pint and Rector touch, so if you ran them directly rather than through `polish`, the stamps are stale. Re-run through `anvil:polish`, or regenerate with `--backup` and accept the overwrite.

### Configuration and connections

**`Connection [] not configured` / connection resolves to `null`**

A shallow `array_merge` of the published `config/anvil.php` over the package defaults drops nested keys. Test both with and without a published config file, and use `Arr::get()` with an explicit default rather than assuming a key is present:

```bash
php artisan tinker --execute="dd(config('anvil'));"
```

### Generated code

**Duplicate relation method names on a model**

Two foreign keys pointing at the same parent produce colliding method names. `RelationNamer` should disambiguate on the local column (`author()` and `editor()`, not two `user()` methods). `anvil:doctor` reports this shape before generation — if doctor is silent on a table that then generates a redeclaration, the detection is the bug.

**`Redefinition of parameter $user` (PHP fatal)**

A generated policy method with `fn (User $user, User $user)`. The generator must rename the second parameter when the subject type matches the authenticated user type:

```php
public function view(User $user, User $target): bool
```

**`Cannot use App\Models\User as User because the name is already in use`**

Duplicate import. `ImportSet` exists to deduplicate — if duplicates appear, the generator is emitting `use` statements directly rather than routing them through it.

**`Target [...RepositoryInterface] is not instantiable`**

`RepositoryServiceProvider` is not registered in `bootstrap/providers.php`. `--repositories` should auto-register it; if it did not, that is the bug. Add it manually to unblock, then `php artisan clear-compiled`.

**Generated file has a syntax error that reaches disk**

`PhpSyntaxCheck` should reject it before the write. If invalid PHP is landing, the generator is bypassing the pipeline in `RunsGenerationPipeline`.

### Auth

**`SessionGuard::__construct(): Argument #3 must be of type UserProvider` / TypeError at login**

The authenticatable model extends `Model` instead of `Illuminate\Foundation\Auth\User`. Any table with a `password` column needs the latter. `anvil:doctor` reports this.

**"This password does not use the Bcrypt algorithm"**

Stored hashes are not bcrypt. Confirm with `anvil:doctor --data`, which samples up to fifty and names the ones it does not recognise.

**Registration succeeds but login always fails**

A `setPasswordAttribute()` mutator on the user model, hashing the hash the register form already produced. `anvil:forge-auth` warns about this — check the warnings printed after the configuration table, which is where non-fatal findings go.

**Accent colour classes have no effect**

Tailwind's scanner only sees literal class names. If accents were assembled at runtime rather than interpolated at generation time, the classes exist in no file Tailwind reads.

### OpenAPI, docs, and client

**Doubled path keys, or `servers` appearing twice**

The spec is being merged into an existing file rather than regenerated, or a base path is being prepended to paths that already carry it. Delete and regenerate to isolate which:

```bash
rm -rf openapi/v1 && php artisan anvil:forge-api --api-version=1 --spec-only --force
```

**Path template placeholders rendered literally**

`/posts/{id}` in the spec needs a matching `parameters` entry with `in: path`. Check the route-to-spec mapping, not the stub.

**`--check` reports a spec as missing when the file is there**

Format mismatch. Reporting should recognise a spec written as JSON when you asked for YAML. If it does not, switching `--format` will make every existing spec read as absent.

**Swagger UI shows "Failed to fetch" or a CORS error**

The UI is fetching the spec cross-origin. Serve the spec from the same origin as the docs page, or inline it at generation time. Permissive CORS headers are not the fix; they move the problem to whoever deploys this.

**`ProcessTimedOutException` naming a vendor file**

The npm strategy timed out. Skip it:

```bash
php artisan anvil:install:swagger-ui --source=http
```

Or pre-seed and use the local strategy:

```bash
npm install --no-save swagger-ui-dist@5.17.14 \
  && php artisan anvil:install:swagger-ui --source=local
```

**TypeScript client keys do not match API responses**

A casing mismatch. Resources, form requests, the spec and the client all read `ApiVersionProfile`, so they cannot legitimately disagree — if they do, one of them is deriving casing itself instead of reading the profile. Regenerate all four after any `--case` change.

**`--hooks` output does not compile**

Requires `@tanstack/react-query` v5. The generated `client.ts` has no dependencies; the hooks file does.

### Cache

**Cached reads never invalidate**

Invalidation uses generation stamps, not tags, specifically so tagless drivers work. If it only fails on `file` or `database`, something reintroduced a tag dependency.

**v1 and v2 return each other's payloads**

`$cacheVariant` is not defaulting to `static::class`, so two versioned services reading one table share a key. This is the failure mode Scenario 9 exists to catch.

### Symlink staleness

**Changes to package source are not reflected**

```bash
composer dump-autoload
php artisan clear-compiled
php artisan package:discover --ansi
```

If you changed the service provider's `register()` or `boot()`:

```bash
php artisan config:clear
php artisan cache:clear
```

If a _newly added_ class is not found, it is almost certainly the autoloader — `composer dump-autoload` before investigating anything else.
