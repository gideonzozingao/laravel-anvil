# Integrating merge with the existing codebase

Working from `tree src` and the README rather than the source, so treat the
class-level detail as a proposal to check rather than a patch to apply. The
architecture holds regardless of the signatures.

---

## 1. The principle: two choke points, not thirty

Every generator writes through `FileWriter`. Every command runs
`RunsGenerationPipeline`. So:

- **No generator changes.** Not one. `ApiControllerGenerator`,
  `LivewireComponentGenerator`, `MigrationGenerator` and the rest keep working
  as they are and inherit merge for free.
- **No per-command changes** beyond adding flags, which is one trait.

If integrating merge requires editing a generator, something has leaked out of
the choke point, and the fix is to plug the leak rather than to spread the
merge logic.

The one thing generators _should_ eventually change is stubs — see the
blank-line item in §4.2 — but that is a content change, not a code change.

---

## 2. What actually gets touched

| File                                          | Change                                                  | Size    |
| --------------------------------------------- | ------------------------------------------------------- | ------- |
| `Support/FileWriter.php`                      | The merge decision. The only mandatory change.          | Medium  |
| `Support/Options/WriteOptions.php`            | `merge`, `acceptConflicts`, `mergeLabels`               | Small   |
| `Console/Concerns/RunsGenerationPipeline.php` | Plumb options in, aggregate outcomes out                | Small   |
| `Console/Concerns/RendersScaffoldOutput.php`  | Render the new outcomes                                 | Small   |
| `Support/ScaffoldReport.php`                  | Two new outcome states                                  | Small   |
| `Console/Concerns/MergesGeneratedFiles.php`   | **New.** Flag definitions for all 13 commands           | Small   |
| `LaravelAnvilServiceProvider.php`             | Bind `ThreeWayMerge`, `GeneratedBlobStore`              | Trivial |
| `config/anvil.php`                            | `merge.*` defaults                                      | Trivial |
| `Support/QualityRunner.php`                   | **The hazard.** See §4.1                                | Medium  |
| `Support/PreserveRegions.php`                 | Policy against merge. See §4.2                          | Medium  |
| `Support/ProviderRegistrar.php`               | Opt out. See §4.3                                       | Small   |
| `Console/DiffCommand.php`                     | Report mergeability alongside drift                     | Small   |
| `Console/Commands/…`                          | **New** `MergeCommand.php` for `--continue` / `--abort` | Medium  |

Roughly one substantial change, one hazard to defuse, and a lot of small
plumbing.

---

## 3. The write-path decision

All of this lives in `FileWriter`. Current behaviour is the last two rows;
everything above is new.

| On disk | Blob exists | Disk == blob        | Outcome                                                                                           |
| ------- | ----------- | ------------------- | ------------------------------------------------------------------------------------------------- |
| No      | —           | —                   | Write. Store blob.                                                                                |
| Yes     | No          | —                   | No base, so no merge. Fall back to current behaviour: skip unless `--force`. Store blob on write. |
| Yes     | Yes         | Yes                 | Untouched since generation. Overwrite. Store blob.                                                |
| Yes     | Yes         | No                  | Hand-edited. **Merge.**                                                                           |
| Yes     | Yes         | No, merge clean     | Write merged. Store the _generated_ output as blob.                                               |
| Yes     | Yes         | No, merge conflicts | Write markers if `--accept-conflicts`, else skip and report.                                      |

Three invariants worth encoding as tests, because each is a natural refactoring
mistake:

1. **The blob stores generator output, never merged output.** Store the merge
   result and the developer's edits join the base, stop reading as edits, and
   the merge silently degrades to an overwrite next run.
2. **`--dry-run` writes no blobs.** A dry run that updates the base makes the
   subsequent real run see no edits.
3. **A skipped conflict still leaves the old blob in place.** Otherwise the
   next run has no base and falls back to refusing.

---

## 4. The five collision points

### 4.1 `anvil:polish` will cause a conflict storm — fix this first

This is the one that bites.

`anvil:polish` runs Pint and Rector over generated files. After it runs, the
file on disk is formatted; the blob holds unformatted generator output. On the
next generation:

- base = unformatted
- local = formatted (Pint's work, misread as hand edits)
- remote = unformatted, newly generated

Every line Pint touched now reads as a hand edit competing with a generator
change. On a codebase where polish has run once, that is most lines in most
files. The merge feature would appear catastrophically broken on first use in
any real project.

You already have the analogous bug and its fix for provenance hashes — the
README notes polish re-stamps what the formatters touch. Blobs need the same
treatment, but re-stamping alone is not sufficient here, because the blob has
to hold _formatted_ content to be comparable.

Two options:

**A — Normalise before storing (recommended).** Run generated output through
Pint before both writing and storing the blob. Generator output, disk and blob
then all agree on formatting permanently, and polish's Pint pass becomes a
no-op on generated files. Costs a Pint invocation per generation run; buy it
back by batching one invocation over all written files at the end of the
pipeline rather than per file.

**B — Re-stamp blobs in polish.** After formatting, run the same formatters
over each corresponding blob and store the result. Cheaper to build, but keeps
two copies in sync forever, and any tool that touches generated files outside
polish reintroduces the drift.

A is more work now and less work indefinitely. It also makes byte-identical
regeneration (test scenario 17) hold under polish, which it currently does not.

Either way: **decide this before shipping merge**, not after the first bug
report.

### 4.2 `PreserveRegions` and merge overlap

Both solve "don't destroy the developer's work," differently:

- `PreserveRegions` gives a **guarantee** inside marked blocks.
- Merge gives **best-effort** everywhere.

Keep both, and give them a clear precedence:

> Preserved regions are extracted before the diff and reinserted afterwards.
> Inside a preserved region, local always wins, unconditionally. Merge operates
> only on the text outside them.

That makes the two mechanisms compose instead of competing, and it means a
preserved region can never conflict — which is the whole promise of the
feature. Implementation is a mask-and-restore around the `ThreeWayMerge` call,
not a change to the engine.

Related: §4.1's blank-line finding suggests stubs should also put a blank line
either side of every preserved-region marker.

**Stub audit.** One blank line between class members converts the most common
conflict pattern into a clean merge. Both cases are in the test suite. This is
the cheapest single improvement to the feature's real-world conflict rate, and
it belongs in the same release.

### 4.3 Append-style writes must opt out

`ProviderRegistrar` and the route generators append entries to files they do
not own outright — `bootstrap/providers.php`, `routes/api.php`, the gate
provider. These are accumulating files, not regenerated ones. Three-way merge
does not model them, and applying it would fight the idempotent-append logic
that already exists.

Give `FileWriter` an explicit bypass — a `WriteMode::Append` on the write call,
or a predicate on the target path — and enumerate the opt-outs in one place so
the list is auditable. Candidates:

| Path                         | Why it opts out                 |
| ---------------------------- | ------------------------------- |
| `bootstrap/providers.php`    | Append, idempotent              |
| `routes/*.php`               | Append, idempotent              |
| `graphql/schema.graphql`     | Written once, never regenerated |
| Any `.bak.*` from `--backup` | Not generated output            |
| The OpenAPI spec             | Owned by `SpecMerger`, see §4.5 |

### 4.4 Versioned paths need versioned blob keys

`WritesVersionedFiles` puts v1 and v2 output at different paths, so mirroring
the project structure in the blob store handles this correctly with no special
casing — `openapi/v1/openapi.yaml` and `openapi/v2/openapi.yaml` are distinct
keys.

Worth an explicit test anyway, because it is exactly the kind of thing a later
"optimisation" to hash-based blob keys would break.

### 4.5 `docs-sync` already has an ownership model — do not add a second

`SyncManifest` tracks which spec components sync owns versus which are
hand-authored. That is the same problem merge solves, solved differently, for
the one file both systems touch.

Do not put the spec under blob-based merge. Let `SpecMerger` and `SyncManifest`
keep owning it, and add the spec path to the §4.3 opt-out list. Two ownership
models competing over one file is worse than either alone.

The connection worth making instead: `--adopt` in docs-sync and blob
bootstrapping in §6 are the same gesture — "take ownership of what already
exists." Consistent naming across the two will help.

---

## 5. Command surface

Thirteen commands need the same three flags, which is exactly the pattern
`Console/Concerns/` exists for:

```php
// Console/Concerns/MergesGeneratedFiles.php

trait MergesGeneratedFiles
{
    protected function mergeSignature(): string
    {
        return '{--merge : Merge generator changes into hand-edited files}'
            .'{--no-merge : Overwrite or skip hand-edited files, pre-1.0 behaviour}'
            .'{--accept-conflicts : Write conflict markers rather than skipping}';
    }

    protected function mergeOptions(): MergeOptions { /* … */ }
}
```

Keep the option descriptions single-line and free of `{...}` tokens — the
signature-parsing failure in the troubleshooting guide is exactly what a
hastily added flag reintroduces.

**Default.** `merge` on, `accept-conflicts` off. A conflicted file is skipped
and reported, so the default can never leave markers in a file the developer
did not ask for. `--no-merge` restores current behaviour for anyone who wants
it.

**New command.** `anvil:merge` for the resolution workflow — `--status`,
`--continue` (re-stamp blobs after manual resolution), `--abort` (restore from
blob). Without `--continue` there is no way to tell Anvil a conflict has been
resolved, and the file stays permanently divergent.

**`anvil:diff` gains a column.** Report per-file whether a regeneration would
merge cleanly, merge with conflicts, or skip. That turns diff from "what
changed in the database" into "what will happen if I regenerate," which is the
question people actually have.

---

## 6. Bootstrapping projects that already generated

No existing project has blobs. Without a bootstrap, merge does nothing for
anyone until they regenerate from scratch.

```bash
php artisan anvil:merge --adopt-baseline
```

Generate every artifact in memory, write no files, store the output as blobs.
Then:

- Unchanged files: disk == blob, so the next run overwrites cleanly.
- Hand-edited files: disk != blob, and on the next run base == remote, so local
  wins and the edit is preserved. When the schema later changes, remote
  diverges and the three-way merge does real work.

**The caveat, and it is worth documenting rather than hiding:** the baseline is
what the _current_ generator produces, not what actually produced their files.
If the generator has changed since, some of that difference will be attributed
to the developer. The failure mode is conservative — it preserves too much
rather than too little — but it means the first post-adoption merge can throw
conflicts that have nothing to do with the developer's edits.

Mitigation: `--adopt-baseline --dry-run` reports how many files already differ
from current generator output, so the size of the problem is visible before
committing to it.

This is the same gesture as `--refresh-models`, and should read like it.

---

## 7. Testing

Add to `documents/local-test.md`, extending the existing numbering:

| Scenario                     | Covers                                                        |
| ---------------------------- | ------------------------------------------------------------- |
| Merge, clean                 | Disjoint edits both applied, blob updated to generator output |
| Merge, conflict              | Skipped by default, markers only with `--accept-conflicts`    |
| Merge, no base               | Falls back to current skip-unless-force behaviour             |
| **Polish then regenerate**   | §4.1. The regression that matters most. Must not conflict.    |
| Preserved region under merge | Local always wins inside markers, never conflicts             |
| Append paths untouched       | Providers and routes still idempotent, no blobs written       |
| Versioned blobs              | v1 and v2 keyed separately                                    |
| `--dry-run` writes no blobs  | Then a real run still sees the edit                           |
| Adopt baseline               | Hand edits survive the first post-adoption regeneration       |
| `anvil:merge --abort`        | Restores from blob exactly                                    |

Scenario 17 in the existing guide (idempotency) should be extended: byte-
identical regeneration must hold _after_ a polish run, which under option A
in §4.1 it will for the first time.

---

## 8. Work order

1. **Stub blank-line audit.** Prerequisite, cheap, and it changes the observed
   conflict rate more than any code in this document.
2. **Decide §4.1**, normalise-on-generate or re-stamp-in-polish. Everything
   downstream depends on it.
3. `WriteOptions` + `FileWriter` + service bindings. Merge works, no flags yet.
4. `MergesGeneratedFiles` trait, wired into all thirteen commands.
5. `ScaffoldReport` outcomes and rendering.
6. §4.2 preserved-region masking.
7. §4.3 append-path opt-outs, enumerated in one place.
8. `anvil:merge` with `--status` / `--continue` / `--abort`.
9. `--adopt-baseline`.
10. `anvil:diff` mergeability column.
11. Test scenarios, README, `local-test.md`.

Steps 1–3 are the useful milestone: merge working end to end, defaults off,
shippable behind a flag.

---

## 9. Still needed to write the code

Same list as before, unchanged:

- `Support/FileWriter.php` — the write path, and how provenance is stamped today
- `Support/Options/WriteOptions.php` and `OptionBag.php` — how options are
  carried
- `Console/Concerns/RunsGenerationPipeline.php` — what a generator returns and
  how outcomes aggregate
- `Support/ScaffoldReport.php` — the reporting shape
- `Support/PreserveRegions.php` — the marker format, for §4.2
- `Support/QualityRunner.php` — for §4.1

`FileWriter`, `RunsGenerationPipeline` and `ScaffoldReport` alone would let me
write steps 3–5 as a real patch rather than a sketch.
