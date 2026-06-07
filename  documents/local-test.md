# Local Testing Guide

This document describes how to test `laravel-anvil` locally during development using a path-repository Laravel application that symlinks directly to your package source.

---

## Table of Contents

1. [Overview](#1-overview)
2. [Directory Structure](#2-directory-structure)
3. [Creating the Test Application](#3-creating-the-test-application)
4. [Linking the Package via Path Repository](#4-linking-the-package-via-path-repository)
5. [Test Application Setup](#5-test-application-setup)
6. [Configuring the Database](#6-configuring-the-database)
7. [Publishing Anvil Assets](#7-publishing-anvil-assets)
8. [Running the Generator](#8-running-the-generator)
9. [Iterating on Package Source](#9-iterating-on-package-source)
10. [Common Test Scenarios](#10-common-test-scenarios)
11. [Resetting the Test Application](#11-resetting-the-test-application)
12. [Troubleshooting](#12-troubleshooting)

---

## 1. Overview

Because `laravel-anvil` is a code-generation package, the most effective way to test it during development is against a real Laravel application with a live database. Rather than publishing a release to Packagist on every change, Composer's **path repository** feature symlinks the package directory directly into the test application's `vendor/` folder. Any change you save in the package source is immediately reflected in the test app — no `composer update` required for most changes.

```
workspace/
├── opensource-projects/
│   └── laravel-anvil/          ← Package source (what you edit)
└── opensource-projects-tests/
    └── laravel-anvil-test-local/   ← Test Laravel app (throwaway)
```

---

## 2. Directory Structure

The setup assumes the following layout on your machine. Adjust paths to match your own workspace if they differ.

```
~/workspace/projects/zuqongtech/
├── packages/
│   └── laravel-anvil/              ← git repo: zuqongtech/laravel-anvil
│       ├── src/
│       ├── config/
│       ├── stubs/
│       ├── tests/
│       └── composer.json
│
└── opensource-projects-tests/
    └── laravel-anvil-test-local/   ← fresh Laravel 12 app (not committed)
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

Edit the test application's `composer.json` to add a `repositories` entry that points to your local package source. This is the key step — Composer will symlink `vendor/zuqongtech/laravel-anvil` directly to your package directory.

```json
{
    "$schema": "https://getcomposer.org/schema.json",
    "name": "laravel/laravel",
    "type": "project",
    "repositories": [
        {
            "type": "path",
            "url": "/home/<username>/.../laravel-anvil",
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
    "require-dev": {
        "driftingly/rector-laravel": "^2.5",
        "fakerphp/faker": "^1.23",
        "laravel/pail": "^1.2.2",
        "laravel/pint": "^1.24",
        "laravel/sail": "^1.41",
        "mockery/mockery": "^1.6",
        "nunomaduro/collision": "^8.6",
        "phpunit/phpunit": "^11.5.50",
        "rector/rector": "^2.4"
    },
    "autoload": {
        "psr-4": {
            "App\\": "app/",
            "Database\\Factories\\": "database/factories/",
            "Database\\Seeders\\": "database/seeders/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/"
        }
    },
    "scripts": {
        "setup": [
            "composer install",
            "@php -r \"file_exists('.env') || copy('.env.example', '.env');\"",
            "@php artisan key:generate",
            "@php artisan migrate --force",
            "npm install",
            "npm run build"
        ],
        "test": [
            "@php artisan config:clear --ansi",
            "@php artisan test"
        ],
        "post-autoload-dump": [
            "Illuminate\\Foundation\\ComposerScripts::postAutoloadDump",
            "@php artisan package:discover --ansi"
        ],
        "post-update-cmd": [
            "@php artisan vendor:publish --tag=laravel-assets --ansi --force"
        ],
        "post-root-package-install": [
            "@php -r \"file_exists('.env') || copy('.env.example', '.env');\""
        ],
        "pre-package-uninstall": [
            "Illuminate\\Foundation\\ComposerScripts::prePackageUninstall"
        ]
    },
    "extra": {
        "laravel": {
            "dont-discover": []
        }
    },
    "config": {
        "optimize-autoloader": true,
        "preferred-install": "dist",
        "sort-packages": true,
        "allow-plugins": {
            "pestphp/pest-plugin": true,
            "php-http/discovery": true
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

> **Note:** The version constraint is `"*@dev"` rather than `"^1.0"`. This is required because the package has no git tags yet. Once you tag a release, you can tighten this to `"^1.0"`.

Then install:

```bash
composer install
```

Verify the symlink was created:

```bash
ls -la vendor/zuqongtech/laravel-anvil
# Should show: vendor/zuqongtech/laravel-anvil -> /home/gzozingao/.../laravel-anvil
```

---

## 5. Test Application Setup

Run the setup script to initialise the application:

```bash
composer setup
```

This runs `composer install`, copies `.env.example` to `.env`, generates an application key, runs migrations, and builds frontend assets.

If you prefer to do it step by step:

```bash
cp .env.example .env
php artisan key:generate
php artisan migrate
```

---

## 6. Configuring the Database

Anvil introspects a **live database**, so you need a real connection with tables in it. The test application's `.env` controls which database Anvil reads.

### Option A — MySQL / PostgreSQL (recommended for thorough testing)

Update `.env` with your local database credentials:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=anvil_test
DB_USERNAME=root
DB_PASSWORD=
```

Create the database and run a few migrations to give Anvil something to introspect:

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS anvil_test;"
php artisan migrate
```

You can also seed it with additional tables to test specific scenarios (relationships, composite PKs, soft deletes, etc.):

```bash
php artisan make:migration create_posts_table
php artisan make:migration create_comments_table
# Edit the migration files, then:
php artisan migrate
```

### Option B — SQLite (quickest for basic testing)

```dotenv
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/laravel-anvil-test-local/database/database.sqlite
```

```bash
touch database/database.sqlite
php artisan migrate
```

> SQLite does not support check constraints or table/column comments. Use MySQL or PostgreSQL to test those introspection paths.

### Using a non-default connection

Anvil supports the `--connection` flag to target any connection defined in `config/database.php`. To test this, add a second connection and run:

```bash
php artisan anvil:generate --connection=reporting --all --dry-run
```

---

## 7. Publishing Anvil Assets

Publish the config file so you can customise it in the test app:

```bash
php artisan vendor:publish \
    --provider="Zuqongtech\LaravelAnvil\LaravelAnvilServiceProvider" \
    --tag="config"
```

This creates `config/anvil.php`. Edit it to adjust ignored tables, namespaces, and generator settings.

To test stub customisation, also publish the stubs:

```bash
php artisan vendor:publish \
    --provider="Zuqongtech\LaravelAnvil\LaravelAnvilServiceProvider" \
    --tag="stubs"
```

Stubs land in `stubs/anvil/` and take precedence over the package-bundled templates automatically.

> **Tip:** Because the package is symlinked, changes you make to stubs inside the package source (`laravel-anvil/stubs/`) are reflected immediately without republishing.

---

## 8. Running the Generator

### Verify the command is registered

```bash
php artisan anvil:generate --help
```

All flags including `--api` and `--api-version` should be listed. If any are missing, there is a signature parsing issue — see [Section 12](#12-troubleshooting).

### Basic generation (models only)

```bash
php artisan anvil:generate
```

### Dry run — preview without writing files

```bash
php artisan anvil:generate --all --dry-run
```

Use this first on any new test to see exactly what Anvil plans to write.

### Full scaffold

```bash
php artisan anvil:generate --all --force
```

### Versioned JSON API

```bash
php artisan anvil:generate --api --api-version=1 --force
```

### Target specific tables

```bash
php artisan anvil:generate \
    --tables=posts \
    --tables=comments \
    --all \
    --force
```

### With analysis output

```bash
php artisan anvil:generate \
    --all \
    --analyze-constraints \
    --show-recommendations \
    --validate-fk \
    --dry-run
```

---

## 9. Iterating on Package Source

Because `vendor/zuqongtech/laravel-anvil` is a symlink to your package source, the edit cycle is:

1. Edit a file in `opensource-projects/laravel-anvil/src/`
2. Switch to the test app and run `php artisan anvil:generate ...`
3. Observe the output immediately — no `composer update` needed

**The one exception:** if you add a new class that needs autoloading, regenerate the autoloader:

```bash
composer dump-autoload
```

**If you change `composer.json` inside the package** (add a dependency, change the service provider registration), run:

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

---

## 10. Common Test Scenarios

These are the scenarios most likely to surface issues during development. Run them in order from simplest to most complex.

### Scenario 1 — Models only (baseline)

```bash
php artisan anvil:generate --dry-run
```

Confirms: DatabaseInspector, RelationshipDetector, ModelBuilder, FileWriter.

### Scenario 2 — Full scaffold, dry run

```bash
php artisan anvil:generate --all --dry-run
```

Confirms: all generators execute without exceptions against your schema.

### Scenario 3 — Full scaffold, force write

```bash
php artisan anvil:generate --all --force
php artisan route:list
php artisan test
```

Confirms: generated files are syntactically valid PHP, routes resolve, tests pass.

### Scenario 4 — Versioned API

```bash
php artisan anvil:generate --api --api-version=1 --force
php artisan route:list | grep "api/v1"
```

Confirms: `ForceJsonApiServiceProvider` is registered, versioned routes exist, JSON enforcement is active.

### Scenario 5 — Multi-version API

```bash
php artisan anvil:generate --api --api-version=1 --tables=posts --force
php artisan anvil:generate --api --api-version=2 --tables=posts --force
php artisan route:list | grep "api/v"
```

Confirms: `ForceJsonApiServiceProvider::$versions` is updated with both versions, v1 files are not modified by the v2 run.

### Scenario 6 — Idempotency (run twice, no duplicates)

```bash
php artisan anvil:generate --all --force
php artisan anvil:generate --all --force
```

Compare file contents before and after the second run. Files should be identical. Check `GateServiceProvider`, `RepositoryServiceProvider`, and route files for duplicate entries.

### Scenario 7 — Backup mode

```bash
php artisan anvil:generate --all --backup --force
ls app/Models/
```

Each model should have a `.backup.{timestamp}` sibling.

### Scenario 8 — Non-default connection

```bash
php artisan anvil:generate --connection=sqlite --all --dry-run
```

Confirms: driver-specific introspection paths for SQLite work correctly.

### Scenario 9 — Constraint analysis

```bash
php artisan anvil:generate \
    --analyze-constraints \
    --show-recommendations \
    --validate-fk \
    --dry-run
```

Confirms: `ConstraintAnalyzer` output is readable and correct for your schema.

### Scenario 10 — Rector and Pint on generated code

After a full `--force` run, check that generated code passes static analysis:

```bash
# From the test app root
vendor/bin/rector process --dry-run
vendor/bin/pint --test
```

All generated files should be PSR-12 compliant and pass Rector without errors.

---

## 11. Resetting the Test Application

When you want a clean slate — for example, after testing `--all --force` and wanting to verify a fresh generation:

### Reset generated files only

```bash
# Remove all generated application code (keeps migrations and .env)
rm -rf app/Http/Controllers/Api
rm -rf app/Http/Resources
rm -rf app/Http/Requests
rm -rf app/Services
rm -rf app/Repositories
rm -rf app/Policies
rm -rf app/Observers
rm -rf app/Events
rm -rf app/Providers/RepositoryServiceProvider.php
rm -rf app/Providers/GateServiceProvider.php
rm -rf app/Providers/ForceJsonApiServiceProvider.php
rm -rf app/Http/Middleware/ForceJsonResponse.php
rm -rf routes/api
rm -rf tests/Feature
find database/factories -name "*.php" ! -name "UserFactory.php" -delete
find database/seeders -name "*.php" ! -name "DatabaseSeeder.php" -delete
find app/Models -name "*.php" ! -name "User.php" -delete

php artisan clear-compiled
php artisan cache:clear
```

### Full reset (nuclear)

Delete the test application entirely and recreate it from scratch:

```bash
cd ~/workspace/projects/onzaec-digitals/opensource-projects-tests
rm -rf laravel-anvil-test-local
composer create-project laravel/laravel laravel-anvil-test-local
cd laravel-anvil-test-local
# Re-apply the composer.json changes from Section 4
composer install
```

---

## 12. Troubleshooting

### `An argument with name "n" already exists`

The `$signature` in `GenerateModelsFromDatabase` contains a multi-line option description or a `{n}` placeholder that Symfony Console is parsing as an argument. All option descriptions in `$signature` must be single-line strings with no `{...}` tokens. See the command source for the fixed version.

### `--api` or `--api-version` missing from `--help`

Same root cause as above — Symfony stops parsing the signature at the first malformed token and drops all subsequent options. Fix the signature and run:

```bash
php artisan clear-compiled && php artisan anvil:generate --help
```

### `Target [...RepositoryInterface] is not instantiable`

`RepositoryServiceProvider` is not registered in `bootstrap/providers.php`. Add it:

```php
// bootstrap/providers.php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\RepositoryServiceProvider::class,
];
```

Then clear the compiled container:

```bash
php artisan clear-compiled
```

### `Redefinition of parameter $user` (PHP fatal)

Anvil has been run multiple times with `--force` and appended duplicate gate/policy blocks, or generated `User` model methods with `fn (User $user, User $user)` duplicate parameters. Clean up the affected file and rename the subject parameter to `$target`:

```php
// Before (fatal)
public function view(User $user, User $user): bool

// After
public function view(User $user, User $target): bool
```

This is a known generator bug affecting the `User` model specifically. Fix the generator's policy and gate templates to detect when the model type matches the authenticated user type.

### `Cannot use App\Models\User as User because the name is already in use`

Duplicate `use App\Models\User;` import in a generated file. Remove the second occurrence. This is caused by the same multi-run append issue as above.

### Changes to package source are not reflected

If you added a new class or changed `composer.json` inside the package:

```bash
# In the test app
composer dump-autoload
php artisan clear-compiled
php artisan package:discover --ansi
```

If you changed the service provider's `register()` or `boot()` method:

```bash
php artisan config:clear
php artisan cache:clear
```

### `composer update` fails on `zuqongtech/laravel-anvil`

Ensure `minimum-stability` is set to `"dev"` in the test app's `composer.json` — the package has no stable release tags yet. Also confirm the `url` in the `repositories` block points to the correct absolute path and that the directory exists.