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

Anvil is no longer a single monolithic command. It is a suite of dedicated Artisan commands coordinated by a `GeneratorRegistry` and a `GenerationOrchestrator`, with the shared execution path living in the `RunsGenerationPipeline` trait. Testing therefore means exercising each command _and_ the orchestrated combinations, since the shared trait is where most cross-command regressions surface.

---

## 2. Directory Structure

The setup assumes the following layout. Adjust paths to match your own workspace if they differ, but keep them consistent — mismatched absolute paths in `composer.json` are the single most common cause of a broken symlink.

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
    "php": "^8.2",
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

Several Anvil commands scaffold onto Livewire or Sanctum. Install those in the test app before exercising `anvil:generate-auth` or `anvil:generate-web`:

```bash
composer require livewire/livewire
php artisan install:api          # for Sanctum-backed API auth
```

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

Seed it with additional tables to exercise specific introspection paths — relationships, composite primary keys, soft deletes, enum columns, check constraints, column comments:

```bash
php artisan make:migration create_posts_table
php artisan make:migration create_comments_table
# Edit the migration files, then:
php artisan migrate
```

Enum-backed columns are worth deliberate coverage: `EnumDetector` and `EnumColumn` behave differently across drivers (native `ENUM` on MySQL, check-constraint emulation on PostgreSQL, nothing usable on SQLite).

### Option B — SQLite (quickest for basic testing)

```dotenv
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/laravel-anvil-test-local/database/database.sqlite
```

```bash
touch database/database.sqlite
php artisan migrate
```

> SQLite does not support check constraints or table/column comments. Use MySQL or PostgreSQL to test those introspection paths, and to test `EnumDetector` meaningfully.

### Using a non-default connection

Every command accepts `--connection` to target any connection defined in `config/database.php`:

```bash
php artisan anvil:generate --connection=reporting --dry-run
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

This creates `config/anvil.php`. Edit it to adjust ignored tables, namespaces, key casing, and per-generator settings.

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

| Command                    | Produces                                                      | Key support classes                                              |
| -------------------------- | ------------------------------------------------------------- | ---------------------------------------------------------------- |
| `anvil:generate`           | Eloquent models from schema                                   | `ModelBuilder`, `RelationNamer`, `ModelMetadata`, `EnumDetector` |
| `anvil:generate-api`       | Versioned JSON API — controllers, resources, requests, routes | `ApiVersionProfile`, `KeyCase`                                   |
| `anvil:generate-openapi`   | OpenAPI 3.1 specification                                     | `OpenApiLocator`                                                 |
| `anvil:generate-apidocs`   | Swagger UI wired to the generated spec                        | `OpenApiLocator`                                                 |
| `anvil:generate-web`       | Livewire CRUD components and views                            | `LivewireComponentGenerator`, `FormStateProperty`                |
| `anvil:generate-auth`      | Livewire authentication scaffold                              | `AuthScaffolder`                                                 |
| `anvil:generate-client`    | TypeScript client from the OpenAPI spec                       | `OpenApiLocator`, `KeyCase`                                      |
| `anvil:generate-graphql`   | Lighthouse SDL schema                                         | `GraphQLSchemaBuilder`                                           |
| `anvil:diff`               | Schema drift report against `SchemaManifest`                  | `SchemaManifest`, `SchemaSelection`                              |
| `anvil:doctor`             | Health check of models vs. live schema                        | `ModelAuditor`, `ModelFixer`, `SchemaFixer`                      |
| `anvil:polish`             | Rector + Pint over generated code                             | `QualityRunner`, `PhpSyntaxCheck`                                |
| `anvil:docs-sync`          | Refreshes docs against the current spec                       | `OpenApiLocator`                                                 |
| `anvil:frontend`           | Frontend asset scaffolding                                    | `ImportSet`                                                      |
| `anvil:install-swagger-ui` | Vendors Swagger UI assets                                     | —                                                                |

Shared behaviour lives in the concerns: `RunsGenerationPipeline` (execution and reporting), `ResolvesGeneratedModels` (model lookup via `ModelDiscovery` / `ModelRegistry` / `ModelReference`), and `RendersScaffoldOutput` (console output via `ScaffoldReport`). A bug in any of these shows up in _every_ command, so when something breaks in one place, re-run the others before assuming it is local.

---

## 9. Running the Generators

### Verify registration

```bash
php artisan list anvil
```

Every command in the table above should appear. If one is missing, the `GeneratorRegistry` binding or the service provider's `commands()` call is the place to look.

```bash
php artisan anvil:generate-api --help
```

All flags should be listed. If flags are silently absent, there is a signature parsing issue — see [Section 13](#13-troubleshooting).

### Dry runs first

Every command supports `--dry-run`. Use it before any `--force` run on a schema you have not generated against before.

```bash
php artisan anvil:generate --dry-run
```

### Models

```bash
php artisan anvil:generate --force
php artisan anvil:generate --tables=posts --tables=comments --force
```

### Versioned JSON API

```bash
php artisan anvil:generate-api --api-version=1 --force
php artisan route:list | grep "api/v1"
```

### OpenAPI specification and docs

```bash
php artisan anvil:generate-openapi --force
php artisan anvil:generate-apidocs --force
php artisan anvil:install-swagger-ui
```

Then visit the docs route in a browser. Loading the spec over HTTP from a different origin is the CORS failure path — see [Section 13](#13-troubleshooting). Prefer a spec served from the same origin, or a spec inlined at build time.

### Livewire web scaffold

```bash
php artisan anvil:generate-web --tables=posts --force
```

### Authentication

```bash
php artisan anvil:generate-auth --force
```

### TypeScript client

```bash
php artisan anvil:generate-client --force
```

The client is generated _from the OpenAPI spec_, not from the database. Run `anvil:generate-openapi` first, or the client will be built from a stale spec — or none at all.

### GraphQL

```bash
php artisan anvil:generate-graphql --force
```

### Diff, doctor, polish

```bash
php artisan anvil:diff
php artisan anvil:doctor
php artisan anvil:doctor --fix
php artisan anvil:polish
```

`anvil:polish` shells out to Rector and Pint via `QualityRunner`. Both must be installed in the _test app_, not the package:

```bash
composer require --dev rector/rector laravel/pint driftingly/rector-laravel
```

### Orchestrated full scaffold

```bash
php artisan anvil:generate --all --force
```

`--all` routes through `GenerationOrchestrator`, which resolves generator ordering. Ordering matters: models before API, API before OpenAPI, OpenAPI before client and docs. If a command runs out of order the failure is usually a missing input rather than an exception, so read the `ScaffoldReport` output rather than only checking the exit code.

---

## 10. Iterating on Package Source

Because `vendor/zuqongtech/laravel-anvil` is a symlink to your package source, the edit cycle is:

1. Edit a file in `packages/laravel-anvil/src/`
2. Switch to the test app and run the relevant `anvil:*` command
3. Observe the output immediately — no `composer update` needed

**The one exception:** if you add a new class that needs autoloading, regenerate the autoloader. This now happens often, given how many `src/Support/` and `src/Exceptions/` classes are in active development:

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
vendor/bin/pest        # or vendor/bin/phpunit
vendor/bin/pint --test
vendor/bin/rector process --dry-run
```

---

## 11. Common Test Scenarios

Run these in order, simplest to most complex.

### Scenario 1 — Models only (baseline)

```bash
php artisan anvil:generate --dry-run
```

Confirms: `DatabaseInspector`, `RelationshipDetector`, `RelationNamer`, `ModelBuilder`, `FileWriter`.

### Scenario 2 — Full scaffold, dry run

```bash
php artisan anvil:generate --all --dry-run
```

Confirms: `GenerationOrchestrator` resolves ordering and every registered generator executes without exceptions against your schema.

### Scenario 3 — Full scaffold, force write

```bash
php artisan anvil:generate --all --force
php artisan route:list
php artisan test
```

Confirms: generated files are syntactically valid PHP (`PhpSyntaxCheck` should catch anything that is not), routes resolve, tests pass.

### Scenario 4 — Versioned API

```bash
php artisan anvil:generate-api --api-version=1 --force
php artisan route:list | grep "api/v1"
```

Confirms: `ApiVersionProfile` applies, `ForceJsonApiServiceProvider` is registered, versioned routes exist, JSON enforcement is active.

### Scenario 5 — Multi-version API

```bash
php artisan anvil:generate-api --api-version=1 --tables=posts --force
php artisan anvil:generate-api --api-version=2 --tables=posts --force
php artisan route:list | grep "api/v"
```

Confirms: both versions are registered, v1 files are untouched by the v2 run.

### Scenario 6 — Idempotency (run twice, no duplicates)

```bash
php artisan anvil:generate --all --force
git -C . diff --stat            # if the test app is a git repo
php artisan anvil:generate --all --force
```

Files should be byte-identical after the second run. The recurring offenders are `GateServiceProvider`, `RepositoryServiceProvider`, route files, and the OpenAPI spec's `servers` block and path keys. `PreserveRegions` is what protects hand-edited sections — add a manual edit inside a preserved region and confirm the second run does not clobber it.

Initialising the test app as a git repo makes this scenario far easier to judge:

```bash
git init && git add -A && git commit -m "baseline"
```

### Scenario 7 — Backup mode

```bash
php artisan anvil:generate --all --backup --force
ls app/Models/
```

Each model should have a `.backup.{timestamp}` sibling.

### Scenario 8 — Non-default connection

```bash
php artisan anvil:generate --connection=sqlite --dry-run
```

Confirms: driver-specific introspection works, and — importantly — that `--connection` survives the config merge rather than resolving to `null`.

### Scenario 9 — Constraint and enum analysis

```bash
php artisan anvil:generate \
    --analyze-constraints \
    --show-recommendations \
    --validate-fk \
    --dry-run
```

Confirms: `ConstraintAnalyzer` and `EnumDetector` output is readable and correct for your schema.

### Scenario 10 — OpenAPI round trip

```bash
php artisan anvil:generate-api --api-version=1 --force
php artisan anvil:generate-openapi --force
php artisan anvil:generate-apidocs --force
php artisan anvil:generate-client --force
```

Confirms: `OpenApiLocator` finds the spec each downstream command needs; path keys are not doubled; `servers` appears exactly once; the generated TypeScript compiles:

```bash
npx tsc --noEmit
```

Validate the spec independently rather than trusting Swagger UI to render it:

```bash
npx @redocly/cli lint public/openapi.json
```

### Scenario 11 — Key casing

```bash
php artisan anvil:generate-api --force        # with config key_case = snake
# flip key_case to camel in config/anvil.php
php artisan anvil:generate-api --force
```

Confirms: `KeyCase` is applied consistently across resources, requests, the OpenAPI schema, and the TypeScript client. DTO key mismatches between these four surfaces are a known regression class — check all four, not just the API response.

### Scenario 12 — Schema drift

```bash
php artisan anvil:generate --force
php artisan anvil:diff                        # expect: no drift
php artisan make:migration add_status_to_posts_table
# add a column, then:
php artisan migrate
php artisan anvil:diff                        # expect: one added column
```

Confirms: `SchemaManifest` is written on generation and `anvil:diff` reads it correctly.

### Scenario 13 — Doctor and fixers

```bash
# Hand-break a generated model: remove a fillable entry, wrong cast, stale relation
php artisan anvil:doctor
php artisan anvil:doctor --fix
```

Confirms: `ModelAuditor` detects each defect and `ModelFixer` / `SchemaFixer` repair it without touching unrelated code.

### Scenario 14 — Polish

```bash
php artisan anvil:polish
vendor/bin/rector process --dry-run
vendor/bin/pint --test
```

All generated files should be PSR-12 compliant and pass Rector cleanly. If `anvil:polish` reports success but a direct Rector run finds changes, `QualityRunner` is not passing the right paths.

### Scenario 15 — Auth and web scaffold

```bash
php artisan anvil:generate-auth --force
php artisan anvil:generate-web --tables=posts --force
php artisan serve
```

Confirms: `AuthScaffolder` registers routes and views, Livewire components mount, `FormStateProperty` produces valid wire models, and login/register/logout actually work in a browser.

### Scenario 16 — GraphQL

```bash
composer require nuwave/lighthouse
php artisan anvil:generate-graphql --force
php artisan lighthouse:validate-schema
```

Confirms: `GraphQLSchemaBuilder` emits valid SDL that Lighthouse accepts.

---

## 12. Resetting the Test Application

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
rm -rf resources/js/api
rm -rf public/openapi.json public/docs public/vendor/swagger-ui
rm -rf graphql/

# Anvil state
rm -f  .anvil/schema-manifest.json

# Models and factories, keeping the framework defaults
find database/factories -name "*.php" ! -name "UserFactory.php" -delete
find database/seeders  -name "*.php" ! -name "DatabaseSeeder.php" -delete
find app/Models        -name "*.php" ! -name "User.php" -delete

php artisan clear-compiled
php artisan cache:clear
```

Adjust the paths above to match whatever your published `config/anvil.php` sets — several are configurable and the defaults have moved.

If the test app is under git, this is all replaced by:

```bash
git clean -fd && git checkout .
```

### Full reset (nuclear)

```bash
cd ~/workspace/projects/zuqongtech/opensource-projects-tests
rm -rf laravel-anvil-test-local
composer create-project laravel/laravel laravel-anvil-test-local
cd laravel-anvil-test-local
# Re-apply the composer.json changes from Section 4
composer install
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

### Command registration

**`An argument with name "n" already exists`**

A `$signature` contains a multi-line option description or a `{n}` placeholder that Symfony Console is parsing as an argument. All option descriptions in `$signature` must be single-line strings with no `{...}` tokens.

**Flags missing from `--help`**

Same root cause: Symfony stops parsing the signature at the first malformed token and drops everything after it. Fix the signature, then:

```bash
php artisan clear-compiled && php artisan anvil:generate-api --help
```

**A command hangs, or the same output repeats indefinitely**

Recursive command invocation. A command that dispatches another Anvil command via `$this->call()` will recurse if the callee's `--all` handling routes back through the orchestrator. Commands should invoke generators through `GeneratorRegistry`, not by calling sibling Artisan commands.

### Configuration and connections

**`Connection [] not configured` / connection resolves to `null`**

A shallow `array_merge` of the published `config/anvil.php` over the package defaults drops nested keys. Test both with and without a published config file, and use `Arr::get()` with an explicit default rather than assuming a key is present. Confirm with:

```bash
php artisan tinker --execute="dd(config('anvil'));"
```

### Generated code

**Duplicate relation method names on a model**

Two foreign keys pointing at the same related table produce colliding method names. `RelationNamer` should disambiguate using the local column name (`author()` and `editor()` rather than two `user()` methods). Reproduce with a `posts` table carrying both `author_id` and `editor_id` referencing `users`.

**`Redefinition of parameter $user` (PHP fatal)**

A generated policy method with `fn (User $user, User $user)`. The generator must detect when the subject model type matches the authenticated user type and rename the second parameter:

```php
// Before (fatal)
public function view(User $user, User $user): bool

// After
public function view(User $user, User $target): bool
```

**`Cannot use App\Models\User as User because the name is already in use`**

Duplicate import. `ImportSet` exists to deduplicate imports — if duplicates appear, the generator is emitting `use` statements directly rather than routing them through `ImportSet`.

**`Target [...RepositoryInterface] is not instantiable`**

`RepositoryServiceProvider` is not registered in `bootstrap/providers.php`:

```php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\RepositoryServiceProvider::class,
];
```

Then `php artisan clear-compiled`.

**Generated file has a syntax error that reaches disk**

`PhpSyntaxCheck` should reject it before the write. If invalid PHP is landing in the project, the generator is bypassing the check — confirm the write path goes through the pipeline in `RunsGenerationPipeline`.

### OpenAPI, docs, and client

**Doubled path keys, or `servers` appearing twice**

The spec is being merged into an existing file rather than regenerated, or a base path is being prepended to paths that already carry it. Delete the spec and regenerate from scratch to isolate which:

```bash
rm -f public/openapi.json && php artisan anvil:generate-openapi --force
```

**Path template placeholders rendered literally**

Interpolation is failing somewhere between the route definition and the spec — `/posts/{id}` in the spec should have a matching `parameters` entry with `in: path`. Check the route-to-spec mapping rather than the stub.

**Swagger UI shows "Failed to fetch" or a CORS error**

The UI is fetching the spec cross-origin. Serve the spec from the same origin as the docs page, or inline it into the docs HTML at generation time. Adding permissive CORS headers to make the browser happy is not the fix — it just moves the problem to whoever deploys this.

**TypeScript client keys do not match API responses**

A `KeyCase` mismatch. Resources, form requests, the OpenAPI schema, and the client must all read from the same casing configuration. Regenerate all four together after changing `key_case`.

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

If a _newly added_ class is not found, it is almost certainly the autoloader — `composer dump-autoload` first, before investigating anything else.
