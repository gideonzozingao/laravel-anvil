# Contributing to Laravel Anvil

Thank you for taking the time to contribute. Laravel Anvil is a build-time code
generation tool — contributions that make it more robust against real-world
schemas, more extensible, or better documented are especially welcome.

---

## Table of contents

- [Code of conduct](#code-of-conduct)
- [Ways to contribute](#ways-to-contribute)
- [Reporting bugs](#reporting-bugs)
- [Suggesting features](#suggesting-features)
- [Development setup](#development-setup)
- [Running the test suite](#running-the-test-suite)
- [Static analysis](#static-analysis)
- [Coding standards](#coding-standards)
- [Submitting a pull request](#submitting-a-pull-request)
- [Writing a new generator](#writing-a-new-generator)
- [Commit message format](#commit-message-format)
- [Release process](#release-process)

---

## Code of conduct

Be direct, be kind, assume good intent. Dismissive or hostile comments on issues
and pull requests will be removed without discussion.

---

## Ways to contribute

You don't have to write code to contribute:

- **Bug reports** — especially those that include the DDL that triggered the
  problem. Unusual schemas are exactly what hardens the tool.
- **Feature requests** — describe the real workflow you're trying to improve,
  not just the implementation you have in mind.
- **Documentation** — typos, missing examples, unclear flag descriptions.
- **Testing against new schemas** — if you have a legacy or unconventional
  database and you're willing to share a sanitised DDL, open an issue.
- **Code** — new generators, schema driver improvements, bug fixes, tests.

---

## Reporting bugs

Open an issue and include:

1. **The DDL** for the table(s) that caused the problem, or a minimal
   reproduction (`CREATE TABLE` statements are enough — no real data needed).
2. The exact command you ran, including all flags.
3. The full output, including any stack trace.
4. Your PHP version, Laravel version, and database driver + version
   (`php --version`, `php artisan --version`, `psql --version` / `mysql --version`).

Without the DDL, schema-related bugs are nearly impossible to reproduce. Please
include it even if it seems obvious.

---

## Suggesting features

Open a GitHub issue with the `enhancement` label. Describe:

- The problem you are trying to solve.
- The workflow or use case that motivates it.
- Any constraints — Laravel conventions it must respect, schemas it must handle
  gracefully.

For larger changes (new generator types, changes to the pipeline contract,
additional stacks) it is worth opening a discussion issue before writing code,
to avoid investing effort in a direction that doesn't fit the project.

---

## Development setup

```bash
# 1. Fork and clone
git clone https://github.com/your-fork/laravel-anvil.git
cd laravel-anvil

# 2. Install dependencies
composer install

# 3. Copy the test environment file
cp .env.example .env.testing

# 4. Set a database connection for integration tests
#    SQLite in-memory is the default and requires no configuration.
#    For MySQL/PostgreSQL tests, set DB_CONNECTION and the DB_* vars
#    in .env.testing accordingly.
```

Anvil has no front-end build step. All generated views use Tailwind via CDN.

---

## Running the test suite

```bash
# Full suite
composer test

# With coverage (requires Xdebug or PCOV)
composer test:coverage

# A single file or test
vendor/bin/pest tests/Unit/ModelMetadataTest.php
vendor/bin/pest --filter "it generates a factory"
```

The suite includes:

- **Unit tests** — generators, metadata parsing, option resolution.
- **Integration tests** — full generation runs against an in-memory SQLite
  database, asserting the content of generated files.
- **Schema driver tests** — column type mapping per driver (SQLite always runs;
  MySQL and PostgreSQL require a running server configured in `.env.testing`).

Pull requests must not reduce test coverage for the code paths they touch. New
generators must ship with integration tests that assert at least the structure
of their output.

---

## Static analysis

```bash
# PHPStan (Larastan)
composer analyse

# Rector (dry run — preview only, do not commit Rector's output directly)
vendor/bin/rector process --dry-run
```

All code must pass PHPStan at the configured level with zero errors before a
pull request is reviewed. If PHPStan reports a false positive that genuinely
cannot be fixed without degrading real type coverage, suppress it with an inline
`@phpstan-ignore` comment that explains why.

---

## Coding standards

```bash
# Check
composer format:check

# Fix
composer format
```

Anvil uses **Laravel Pint** with the `laravel` preset. A few additional
conventions that Pint doesn't enforce:

- **`declare(strict_types=1)`** at the top of every PHP file.
- **`final`** on all classes that are not designed for extension. If a class
  needs to be extended (generator base classes, the `Generator` contract
  implementations in tests), leave it open and document why.
- **No `public` properties** on non-DTO classes. Use constructor promotion for
  readonly DTOs; use accessors otherwise.
- **Return types always declared** — including `void`. No untyped returns.
- **No `mixed`** except where a third-party interface forces it. Narrow the type
  or use a union.
- **One class per file**, matching the PSR-4 namespace.
- Generator classes are **named `{Artifact}Generator`** and live in
  `src/Generators/`.
- Stub files are plain PHP/Blade with no logic — rendering logic lives in the
  generator, not the stub.

---

## Submitting a pull request

1. **Branch from `main`.**

```bash
   git checkout -b feat/my-new-generator
```

2. **Write tests first** where practical — especially for schema-parsing logic
   and generator output. Integration tests that assert file content are the most
   valuable.

3. **Keep the scope tight.** A PR that adds one generator and fixes one related
   bug is easier to review than one that refactors the pipeline, adds two
   generators, and changes the config format.

4. **Update documentation** — `README.md` flag tables, configuration examples,
   and docblocks on public API surfaces.

5. **Run the full CI suite locally** before pushing:

```bash
   composer ci
```

This runs `format:check`, `analyse`, and `test` in sequence — the same steps
the CI pipeline runs.

6. **Open the pull request** against `main`. Fill in the PR template:
   - What problem does this solve?
   - What schema shapes or edge cases did you test against?
   - Breaking changes, if any.
   - Checklist: tests pass, PHPStan clean, Pint clean, docs updated.

7. **One approval is required** before merge. For changes to the pipeline
   contracts (`Generator`, `GenerationOptions`, `ModelMetadata`) two approvals
   are required, as these are the public extension points.

---

## Writing a new generator

The fastest path to a working generator:

1. Implement `Zuqongtech\LaravelAnvil\Contracts\Generator`:

```php
   <?php

   declare(strict_types=1);

   namespace Zuqongtech\LaravelAnvil\Generators;

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
           // Write your file(s) and return one result row per file.
           return [[
               'type'   => $this->getName(),
               'name'   => $meta->model,
               'path'   => $path,
               'status' => 'success',
           ]];
       }
   }
```

2. Add a stub under `resources/stubs/` if the generator produces a PHP file.
   Keep stubs logic-free — all substitution happens in `generate()`.

3. Register the generator in `LaravelAnvilServiceProvider` by appending it to
   the generator list. Generators are resolved through the container so
   constructor dependencies autowire.

4. Add the corresponding flag to `GenerationOptions` and to the flag table in
   `README.md`.

5. Write an integration test that runs `generate()` against a known
   `ModelMetadata` fixture and asserts the content of the output file.

If your generator needs a once-per-run step (writing a root manifest, stitching
files together), implement `finalize(GenerationOptions $options): void` — the
orchestrator calls it after all per-model passes complete.

---

## Commit message format

Anvil follows [Conventional Commits](https://www.conventionalcommits.org/):
