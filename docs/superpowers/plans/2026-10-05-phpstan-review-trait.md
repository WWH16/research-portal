# PHPStan Review Trait Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `composer ci:check` pass again by clearing PHPStan's `trait.unused` error on `App\Concerns\ReviewsSubmissions`, without changing app behavior.

**Architecture:** The trait is used, but only by two Livewire 4 single-file pages in `resources/views/pages/`. PHPStan's `paths` cover only `app/`, `bootstrap/app.php`, `config/`, `database/`, and `routes/`, and Larastan cannot parse single-file components. So PHPStan sees a trait with no users. The fix tells PHPStan, for this one file and this one identifier, that its users live outside the analysed paths. Adding the trait to a class (the CI bot's "Option 1") does not apply: it already has users, and a dummy class just to satisfy the analyser would be dead code. Deleting the trait (the bot's "Option 3") would mean copying 120 lines back into both pages.

**Tech Stack:** PHPStan level 7 with Larastan, Livewire 4 single-file components, Pest.

**Spec:** None. Source is the CI failure report and this diagnosis:
- **CI step:** `composer ci:check` runs `pint --test`, then `phpstan analyse`, then `php artisan test`.
- **Error:** `Trait App\Concerns\ReviewsSubmissions is used zero times and is not analysed.`, identifier `trait.unused`, at `app/Concerns/ReviewsSubmissions.php:19`.
- **Users of the trait:** `resources/views/pages/submissions/⚡index.blade.php` and `resources/views/pages/drive/⚡show.blade.php`.

## Global Constraints

- Keep PHPStan at `level: 7`, and keep the existing `paths` unchanged.
- Suppress only the identifier `trait.unused`, and only for `app/Concerns/ReviewsSubmissions.php`. Do not add a global ignore, a baseline, or `@phpstan-ignore` comments.
- Make no change to app code or tests.
- Leave out any Claude `Co-Authored-By` trailer in the commit (project rule).

## Review Focus

- A new trait in `app/Concerns/` that only single-file pages use hits the same error. Expected: the ignore names a single path, so the new trait fails CI loudly instead of being silently ignored.
- The ignore entry stops matching (file renamed or moved). Expected: PHPStan reports "Ignored error pattern ... was not matched", because `reportUnmatchedIgnoredErrors` is on by default. Checked by Step 3.
- The trait's own methods are not type-checked, because PHPStan analyses a trait only through a class that uses it. Expected: accepted. This logic lived unanalysed inside the single-file page before the trait existed, so coverage is no worse.
- Review behavior on both pages is unchanged. Expected: the full Pest suite stays green (Step 5).

---

### Task 1: Ignore `trait.unused` for the review trait

**Files:**
- Modify: `phpstan.neon` (append under `parameters:`)

**Interfaces:**
- Consumes: nothing.
- Produces: a passing `composer types:check`.

- [ ] **Step 1: Confirm the failure**

Run: `vendor/bin/phpstan analyse --no-progress`
Expected: FAIL, 1 error, `trait.unused` at `app/Concerns/ReviewsSubmissions.php:19`.

- [ ] **Step 2: Add the scoped ignore**

Append to the end of `phpstan.neon`, under `parameters:` and indented to match `level: 7`:

```neon

    ignoreErrors:
        # Used by the Submissions and Research Drive single-file pages in resources/views/pages, which PHPStan does not analyse.
        -
            identifier: trait.unused
            path: app/Concerns/ReviewsSubmissions.php
```

- [ ] **Step 3: Confirm PHPStan passes**

Run: `vendor/bin/phpstan analyse --no-progress`
Expected: `[OK] No errors`. If it reports "Ignored error pattern ... was not matched", the `path` is wrong. Fix the path; do not widen the ignore.

- [ ] **Step 4: Confirm the ignore is narrow**

Copy the trait to `app/Concerns/ProbeTrait.php`, renaming `trait ReviewsSubmissions` to `trait ProbeTrait`, and run PHPStan.
Expected: FAIL with `trait.unused` for `ProbeTrait`. This proves the ignore covers only the one file. Delete `app/Concerns/ProbeTrait.php`.

- [ ] **Step 5: Run the full CI check**

Run: `composer ci:check`
Expected: Pint passes, PHPStan reports `[OK] No errors`, and every Pest test passes.

- [ ] **Step 6: Commit**

```bash
git add phpstan.neon
git commit -m "ci(phpstan): ignore unused-trait error for review trait"
```
