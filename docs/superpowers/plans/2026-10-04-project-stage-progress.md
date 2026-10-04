# Project Stage Progress Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make it clear where a research project is in its stages (Submitted → Concept → Detailed → Completed), what it needs next, and how each review moved it, for faculty and the Research Office.

**Architecture:** Two model methods on `Submission` (`stageIndex()`, `nextStep()`) feed one Blade partial, `partials/project-stages`, which draws a four-step progress row with a "what's next" line. The partial goes on the Drive project page, the Edit Project form and the review panel. A History section on the Drive project page reads the project's entries from the existing `activity_logs` table through `Submission::history()`, worded by two new `ActivityLog` methods. Project entries stop being pruned after 12 months so that history stays complete.

**Tech Stack:** PHP 8.4, Laravel 13, Livewire 4 single-file components, Flux 2, Tailwind, PHPUnit-style feature tests run by Pest.

**Spec:** No separate spec. Requirements come from the conversation on 2026-10-04 and are listed below.

### Requirements

1. A stage tracker shows the four statuses in order, marks the current stage, and marks the stages already passed.
2. Under the tracker, one line says what comes next: the upload under review, a document returned with remarks and waiting for a corrected one, the document still to upload for the next stage, or that all stages are done.
3. The tracker appears on the Drive project page, on the Edit Project form (editing only, not a new proposal), and in the admin review panel. The review panel leaves out the next-step line because its "To review" callout already says that.
4. The Drive project page gets a History section: each submission, upload/edit, date change and review, newest first, with who did it, when, the stage change, and the review remarks. Faculty on the project and admins both see it.
5. In the history, reviews and date changes are credited to "Research Office", not to one staff member's name.
6. Out of scope: the admin's free four-way status choice in the review panel stays exactly as it is.

## Global Constraints

- Statuses stay `Submission::STATUSES` = `['Submitted', 'Concept', 'Detailed', 'Completed']`. Add no new status values.
- Pages are Livewire 4 single-file components in `resources/views/pages/` (file names start with `⚡`). UI uses Flux components (`<flux:text>`, `<flux:heading>`, …) before custom HTML.
- Every user-facing string goes through `__()`.
- Match the surrounding comment style: a docblock on each method saying *why*, written in plain sentences.
- Feature tests are PHPUnit-style classes in `tests/Feature/` (`class XTest extends TestCase`, `use RefreshDatabase`, `$this->freezeTime()` in `setUp`), like `tests/Feature/DriveTest.php`.
- Run tests with: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest`
- Run `vendor/bin/pint` only on changed `.php` files under `app/`, `database/`, `routes/` and `tests/`. **Never run Pint on Blade files**: it restyles the PHP block of the single-file components.
- Commits use Conventional Commits (`feat(scope): …`). **Never add a `Co-Authored-By` trailer.**
- Create migrations with `php artisan make:migration`.

## Review Focus

1. **A status outside the four stages** (old data, such as `Pending`): the tracker must treat it as Submitted and must not crash. Pinned in Task 1 (`test_a_status_outside_the_stages_counts_as_submitted`).
2. **A project that skips ahead** (status Submitted, only the detailed proposal uploaded, waiting for review): the next-step line must name the detailed proposal as the upload under review, not say "concept proposal not uploaded". Pinned in Task 1 (`test_a_project_that_skips_ahead_names_the_upload_under_review`).
3. **An account or college logged under the same id number as the project**: `activity_logs.subject_id` has no type column, so `user.*` and `filing.*` entries can share the number. They must never appear in the project's history. Pinned in Task 3 (`test_history_holds_only_this_projects_entries`).
4. **Remarks or names that contain HTML**: the history must show them escaped. Pinned in Task 3 (`test_history_text_typed_by_people_is_escaped`).
5. **A project running longer than 12 months**: the daily prune must not delete its early reviews. Pinned in Task 4 (`test_project_entries_outlive_the_retention_period`).

---

### Task 1: Stage position and next step on the model

**Files:**
- Modify: `app/Models/Submission.php` (constants block near `DOCUMENTS`, and new methods after `completedOnTime()`)
- Create: `tests/Feature/ProjectProgressTest.php`

**Interfaces:**
- Consumes: the existing `Submission::STATUSES`, `Submission::DOCUMENTS` and `Submission::latestUpload(): ?array{stage: string, at: Carbon}`.
- Produces:
  - `Submission::STAGE_DOCUMENTS` = `['Concept' => 'concept', 'Detailed' => 'detailed', 'Completed' => 'terminal']`
  - `Submission::stageIndex(): int` (0–3)
  - `Submission::nextStep(): ?array{stage: string, document: string, state: 'review'|'returned'|'missing'}`. `document` is a `DOCUMENTS` key (`concept`, `detailed`, `terminal`). It returns `null` when the project is Completed.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ProjectProgressTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Department;
use App\Models\ResearchType;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * How a project shows its way through the stages: the step row with what it needs next, and the
 * history of uploads and reviews on its Drive page.
 */
class ProjectProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $maria;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        Storage::fake('submissions');

        $department = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);
        $this->maria = User::factory()->create(['name' => 'Maria Santos', 'department_id' => $department->id]);
        $this->admin = User::factory()->create(['name' => 'Ana Admin', 'role' => 'admin']);
    }

    public function test_the_next_step_follows_the_status_and_the_documents(): void
    {
        $this->assertSame(
            ['stage' => 'Concept', 'document' => 'concept', 'state' => 'review'],
            $this->project(['status' => 'Submitted', 'awaiting_review' => true, 'concept_path' => 'submissions/concept.pdf'])->nextStep(),
        );
        $this->assertSame(
            ['stage' => 'Detailed', 'document' => 'detailed', 'state' => 'missing'],
            $this->project(['status' => 'Concept', 'awaiting_review' => false])->nextStep(),
        );
        $this->assertSame(
            ['stage' => 'Detailed', 'document' => 'detailed', 'state' => 'returned'],
            $this->project(['status' => 'Concept', 'awaiting_review' => false, 'detailed_path' => 'submissions/detailed.pdf'])->nextStep(),
        );
        $this->assertNull($this->project(['status' => 'Completed', 'awaiting_review' => false])->nextStep());
    }

    public function test_a_project_that_skips_ahead_names_the_upload_under_review(): void
    {
        Storage::disk('submissions')->put('submissions/detailed.pdf', '%PDF');

        $project = $this->project(['status' => 'Submitted', 'awaiting_review' => true, 'detailed_path' => 'submissions/detailed.pdf']);

        $this->assertSame(['stage' => 'Concept', 'document' => 'detailed', 'state' => 'review'], $project->nextStep());
    }

    public function test_a_status_outside_the_stages_counts_as_submitted(): void
    {
        $project = $this->project(['status' => 'Pending', 'awaiting_review' => false]);

        $this->assertSame(0, $project->stageIndex());
        $this->assertSame('Concept', $project->nextStep()['stage']);
    }

    private function project(array $attributes = []): Submission
    {
        $project = $this->maria->submissions()->create([
            'research_type_id' => ResearchType::firstOrCreate(['name' => 'Thesis'])->id,
            'category_id' => Category::firstOrCreate(['name' => 'Computing'])->id,
            'department_id' => $this->maria->department_id,
            'title' => 'Solar Dryer Study',
            'start_date' => now(),
            'target_date' => now()->addYear(),
            ...$attributes,
        ]);
        $project->proponents()->create(['user_id' => $this->maria->id, 'study' => 1, 'role' => 'Leader']);

        // Reload, so database defaults such as the status are on the model.
        return $project->fresh();
    }
}
```

(`ActivityLog` and `Livewire` are imported now because Tasks 2 and 3 add tests to this file that use them.)

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/ProjectProgressTest.php`
Expected: 3 failures with `Call to undefined method App\Models\Submission::nextStep()` (or `stageIndex()`).

- [ ] **Step 3: Implement**

In `app/Models/Submission.php`, add the constant after `DOCUMENTS`:

```php
    /** The document whose acceptance moves a project into each stage after Submitted. */
    public const STAGE_DOCUMENTS = ['Concept' => 'concept', 'Detailed' => 'detailed', 'Completed' => 'terminal'];
```

Add these methods after `completedOnTime()`:

```php
    /**
     * Where the project is in STATUSES, counting from 0. A status outside the list, such as one left
     * from before the stages existed, counts as Submitted.
     */
    public function stageIndex(): int
    {
        return (int) array_search($this->status, self::STATUSES, true);
    }

    /**
     * The stage the project moves to next and where its document stands: "review" while an upload waits
     * for the Research Office, "returned" when the document was reviewed but the stage didn't move, and
     * "missing" when it hasn't been uploaded. Null once the project is Completed.
     *
     * @return array{stage: string, document: string, state: 'review'|'returned'|'missing'}|null
     */
    public function nextStep(): ?array
    {
        $stage = self::STATUSES[$this->stageIndex() + 1] ?? null;

        if ($stage === null) {
            return null;
        }

        $document = self::STAGE_DOCUMENTS[$stage];

        return match (true) {
            // The upload under review can be a later stage's document, when a project skips ahead.
            $this->awaiting_review === true => ['stage' => $stage, 'document' => $this->latestUpload()['stage'] ?? $document, 'state' => 'review'],
            (bool) $this->{$document.'_path'} => ['stage' => $stage, 'document' => $document, 'state' => 'returned'],
            default => ['stage' => $stage, 'document' => $document, 'state' => 'missing'],
        };
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/ProjectProgressTest.php`
Expected: 3 passed.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint app/Models/Submission.php tests/Feature/ProjectProgressTest.php
git add app/Models/Submission.php tests/Feature/ProjectProgressTest.php
git commit -m "feat(submissions): work out a project's stage and next step"
```

---

### Task 2: Stage tracker on the Drive page, the edit form and the review panel

**Files:**
- Create: `resources/views/partials/project-stages.blade.php`
- Modify: `resources/views/pages/drive/⚡show.blade.php` (after the closing `</dl>` of the project facts, before the `@if ($project->remarks …)` callout)
- Modify: `resources/views/pages/submissions/⚡create.blade.php` (after the `@if ($submission?->remarks …)` callout's `@endif`, before `<form wire:submit="save"`)
- Modify: `resources/views/pages/submissions/⚡index.blade.php` (in the review panel, right after the `<div class="pe-8">…</div>` heading block)
- Test: `tests/Feature/ProjectProgressTest.php`

**Interfaces:**
- Consumes: `Submission::STATUSES`, `Submission::DOCUMENTS`, `Submission::stageIndex()`, `Submission::nextStep()` from Task 1.
- Produces: `@include('partials.project-stages', ['submission' => $project])`. Pass `'next' => false` to leave out the next-step line. Test hooks: `data-test="project-stages"` and `data-test="project-next-step"`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/ProjectProgressTest.php`, above the `project()` helper:

```php
    public function test_the_drive_page_and_edit_form_show_the_stages_and_the_next_step(): void
    {
        $project = $this->project(['status' => 'Concept', 'awaiting_review' => false]);
        $this->actingAs($this->maria);

        $this->get(route('drive.show', $project))
            ->assertOk()
            ->assertSee('data-test="project-stages"', false)
            ->assertSeeInOrder(['Submitted', 'aria-current="step"', 'Concept', 'Detailed', 'Completed'], false)
            ->assertSee('Next: the detailed proposal, to move to Detailed.');

        // The edit route reuses the create component (see routes/web.php).
        Livewire::test('pages::submissions.create', ['submission' => $project])
            ->assertSee('Next: the detailed proposal, to move to Detailed.');

        // A new proposal has no stage yet.
        Livewire::test('pages::submissions.create')->assertDontSee('data-test="project-stages"', false);
    }

    public function test_the_next_step_says_when_a_document_was_returned_or_all_stages_are_done(): void
    {
        $this->actingAs($this->maria);

        $returned = $this->project(['status' => 'Concept', 'awaiting_review' => false, 'detailed_path' => 'submissions/detailed.pdf']);
        $this->get(route('drive.show', $returned))->assertSee('Returned with remarks. Waiting for a corrected detailed proposal.');

        $waiting = $this->project(['status' => 'Submitted', 'awaiting_review' => true, 'concept_path' => 'submissions/concept.pdf']);
        $this->get(route('drive.show', $waiting))->assertSee('The concept proposal is waiting for the Research Office to review.');

        $done = $this->project(['status' => 'Completed', 'awaiting_review' => false]);
        $this->get(route('drive.show', $done))->assertSee('All stages are done.');
    }

    public function test_the_review_panel_shows_the_stages_without_repeating_what_to_review(): void
    {
        $project = $this->project(['status' => 'Submitted', 'awaiting_review' => true, 'concept_path' => 'submissions/concept.pdf']);
        $this->actingAs($this->admin);

        Livewire::test('pages::submissions.index')
            ->call('review', $project->id)
            ->assertSee('data-test="project-stages"', false)
            ->assertDontSee('data-test="project-next-step"', false);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/ProjectProgressTest.php`
Expected: the 3 new tests fail with `Failed asserting that … contains "data-test="project-stages""` (or the next-step text). The Task 1 tests still pass.

- [ ] **Step 3: Create the partial**

Create `resources/views/partials/project-stages.blade.php`:

```blade
{{--
    A project's stages as a row of steps, with what it needs next underneath.
    Expects: $submission; optional $next = false to leave out the next-step line, as the review panel does,
    where the "To review" callout already says what came in.
--}}
@php($current = $submission->stageIndex())
<div data-test="project-stages">
    {{-- A bar per stage with its name under it, so four stages fit side by side on phones --}}
    <ol class="grid grid-cols-4 gap-2">
        @foreach (\App\Models\Submission::STATUSES as $index => $stage)
            <li class="flex min-w-0 flex-col gap-1.5" @if ($index === $current) aria-current="step" @endif>
                <span class="h-1.5 rounded-full {{ $index <= $current ? 'bg-isu-green-700' : 'bg-zinc-200' }}" aria-hidden="true"></span>
                <span @class([
                    'truncate text-sm',
                    'font-semibold text-zinc-800' => $index === $current,
                    'text-zinc-600' => $index < $current,
                    'text-zinc-400' => $index > $current,
                ])>
                    {{ __($stage) }}
                    <span class="sr-only">{{ match (true) { $index < $current => __('(done)'), $index === $current => __('(current stage)'), default => __('(not reached)') } }}</span>
                </span>
            </li>
        @endforeach
    </ol>

    @if ($next ?? true)
        @php($step = $submission->nextStep())
        <flux:text class="mt-3 text-sm" data-test="project-next-step">
            @if ($step === null)
                {{ __('All stages are done.') }}
            @else
                @php($document = \Illuminate\Support\Str::lower(__(\App\Models\Submission::DOCUMENTS[$step['document']])))
                {{ match ($step['state']) {
                    'review' => __('The :document is waiting for the Research Office to review.', ['document' => $document]),
                    'returned' => __('Returned with remarks. Waiting for a corrected :document.', ['document' => $document]),
                    default => __('Next: the :document, to move to :stage.', ['document' => $document, 'stage' => __($step['stage'])]),
                } }}
            @endif
        </flux:text>
    @endif
</div>
```

- [ ] **Step 4: Include it on the three screens**

In `resources/views/pages/drive/⚡show.blade.php`, insert right after the `</dl>` that closes the College / Year / Filed by facts:

```blade
    <section class="mt-8" aria-labelledby="progress-heading">
        <flux:heading level="2" id="progress-heading">{{ __('Progress') }}</flux:heading>
        <div class="mt-3">@include('partials.project-stages', ['submission' => $project])</div>
    </section>
```

In `resources/views/pages/submissions/⚡create.blade.php`, insert right after the `@endif` that closes the "Remarks from the Research Office" callout, before `<form wire:submit="save" …>`:

```blade
    {{-- Only an existing project has a stage; a new proposal starts at Submitted once it's saved --}}
    @if ($submission)
        <div class="mt-6">@include('partials.project-stages')</div>
    @endif
```

In `resources/views/pages/submissions/⚡index.blade.php`, inside the review form, insert right after the `<div class="pe-8">…</div>` block that holds the title and "Filed by":

```blade
                    @include('partials.project-stages', ['submission' => $this->reviewing, 'next' => false])
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/ProjectProgressTest.php`
Expected: 6 passed.

- [ ] **Step 6: Run the whole suite**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest`
Expected: all pass. If a test in `DriveTest` or `SubmissionTest` fails on `assertSeeInOrder` because a stage name now appears earlier on the page, tighten that assertion with its section's `data-test` hook. Don't change the partial for it.

- [ ] **Step 7: Check it at phone width**

Run the app (`composer run dev`, or the project's usual command). Open a Drive project page and the review panel at 375px wide. All four stage names must fit on one row without page-wide horizontal scroll. Long names truncate.

- [ ] **Step 8: Format the test file and commit** (Pint on the test file only, never on the Blade files)

```bash
vendor/bin/pint tests/Feature/ProjectProgressTest.php
git add resources/views/partials/project-stages.blade.php "resources/views/pages/drive/⚡show.blade.php" "resources/views/pages/submissions/⚡create.blade.php" "resources/views/pages/submissions/⚡index.blade.php" tests/Feature/ProjectProgressTest.php
git commit -m "feat(submissions): show a project's stages and next step"
```

---

### Task 3: Project history on the Drive page

**Files:**
- Modify: `app/Models/Submission.php` (new `history()` method, new import)
- Modify: `app/Models/ActivityLog.php` (new `historyActor()` and `historyHeadline()`, and `note()` also covers `submission.created`)
- Create: migration `database/migrations/<timestamp>_add_subject_index_to_activity_logs_table.php`
- Modify: `resources/views/pages/drive/⚡show.blade.php` (top `@php` block, and a new section after "Studies and proponents")
- Test: `tests/Feature/ProjectProgressTest.php`

**Interfaces:**
- Consumes: `ActivityLog::record(string $action, ?Model $subject = null, array $properties = [], ?User $user = null)`, and the existing `ActivityLog::change()`, `note()`, `remarks()` and `day()`.
- Produces:
  - `Submission::history(): \Illuminate\Database\Eloquent\Collection<int, ActivityLog>`, newest first
  - `ActivityLog::historyActor(): string`
  - `ActivityLog::historyHeadline(): string`
  - Test hook `data-test="drive-history"`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/ProjectProgressTest.php`, above the `project()` helper:

```php
    public function test_the_drive_page_lists_uploads_and_reviews_newest_first(): void
    {
        $project = $this->project(['status' => 'Concept', 'awaiting_review' => false]);

        ActivityLog::record('submission.created', $project, ['documents' => ['concept']], $this->maria);
        ActivityLog::record('submission.reviewed', $project, ['status' => ['Submitted', 'Submitted'], 'remarks' => 'Add the budget table.'], $this->admin);
        ActivityLog::record('submission.updated', $project, ['documents' => ['concept']], $this->maria);
        ActivityLog::record('submission.reviewed', $project, ['status' => ['Submitted', 'Concept']], $this->admin);

        $this->actingAs($this->maria)
            ->get(route('drive.show', $project))
            ->assertSee('data-test="drive-history"', false)
            ->assertSeeInOrder([
                'Research Office', 'Moved the project from Submitted to Concept',
                'Maria Santos', 'Updated the project', 'Uploaded the concept proposal',
                'Research Office', 'Returned it with remarks, kept at Submitted', 'Remarks: Add the budget table.',
                'Maria Santos', 'Submitted the proposal', 'Uploaded the concept proposal',
            ])
            // Reviews are the office's, not one staff member's.
            ->assertDontSee('Ana Admin');
    }

    public function test_history_holds_only_this_projects_entries(): void
    {
        $project = $this->project(['status' => 'Submitted', 'awaiting_review' => false]);
        $other = $this->project(['status' => 'Submitted', 'awaiting_review' => false]);

        ActivityLog::record('submission.created', $project, [], $this->maria);
        ActivityLog::record('submission.created', $other, [], $this->maria);
        // A college or account logged under the same id number is not part of the project.
        ActivityLog::create(['action' => 'filing.updated', 'subject_id' => $project->id, 'subject_label' => 'CCS', 'properties' => ['kind' => 'college']]);
        ActivityLog::create(['action' => 'user.updated', 'subject_id' => $project->id, 'subject_label' => 'Maria Santos']);

        $history = $project->history();

        $this->assertSame(['submission.created'], $history->pluck('action')->all());
        $this->assertSame([$project->id], $history->pluck('subject_id')->all());
    }

    public function test_history_text_typed_by_people_is_escaped(): void
    {
        $project = $this->project(['status' => 'Submitted', 'awaiting_review' => false]);
        ActivityLog::record('submission.reviewed', $project, ['status' => ['Submitted', 'Submitted'], 'remarks' => '<b>Fix this</b>'], $this->admin);

        $this->actingAs($this->maria)
            ->get(route('drive.show', $project))
            ->assertSee('Remarks: <b>Fix this</b>')
            ->assertDontSee('<b>Fix this</b>', false);
    }

    public function test_a_project_with_no_recorded_history_says_so(): void
    {
        $project = $this->project(['status' => 'Submitted', 'awaiting_review' => false]);

        $this->actingAs($this->maria)
            ->get(route('drive.show', $project))
            ->assertSee('Nothing recorded yet.')
            ->assertDontSee('data-test="drive-history"', false);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/ProjectProgressTest.php --filter history`
Expected: failures. `test_history_holds_only_this_projects_entries` fails with `Call to undefined method App\Models\Submission::history()`, and the page tests fail on missing text.

- [ ] **Step 3: Add the index migration**

Run: `php artisan make:migration add_subject_index_to_activity_logs_table --table=activity_logs`

Fill the generated file's `up()` and `down()`:

```php
    /**
     * A project's Drive page reads its history by subject id and action, so look those up by index
     * instead of scanning a year of sign-ins.
     */
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index(['subject_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['subject_id', 'action']);
        });
    }
```

- [ ] **Step 4: Add `Submission::history()`**

In `app/Models/Submission.php`, add the import:

```php
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
```

Add after `nextStep()`:

```php
    /**
     * What happened to the project, newest first, from the activity log. Accounts and colleges are logged
     * with their own ids in the same column, so only submission.* entries belong to the project.
     *
     * @return EloquentCollection<int, ActivityLog>
     */
    public function history(): EloquentCollection
    {
        return ActivityLog::where('subject_id', $this->id)->where('action', 'like', 'submission.%')->latest('id')->get();
    }
```

- [ ] **Step 5: Add the history wording to `ActivityLog`**

In `app/Models/ActivityLog.php`, add after `remarks()`:

```php
    /**
     * Who to name for an entry in a project's history. Reviews and date changes are the Research Office's,
     * so faculty see the office rather than one staff member.
     */
    public function historyActor(): string
    {
        return in_array($this->action, ['submission.reviewed', 'submission.dates_changed'], true)
            ? __('Research Office')
            : ($this->actor_name ?? __('Unknown'));
    }

    /**
     * What happened, as a line in the project's own history, where naming the project would only repeat the page.
     */
    public function historyHeadline(): string
    {
        $status = $this->properties['status'] ?? [null, null];

        return match (true) {
            $this->action === 'submission.created' => __('Submitted the proposal'),
            $this->action === 'submission.updated' => __('Updated the project'),
            $this->action === 'submission.dates_changed' => __('Changed the dates'),
            $this->change() !== null => __('Moved the project from :from to :to', ['from' => __($status[0]), 'to' => __($status[1])]),
            $this->remarks() !== null => __('Returned it with remarks, kept at :status', ['status' => __((string) $status[1])]),
            default => __('Reviewed it, kept at :status', ['status' => __((string) $status[1])]),
        };
    }
```

In `note()`, change the project-edit arm so that a new proposal also names the documents it came with:

```php
            in_array($this->action, ['submission.created', 'submission.updated', 'submission.dates_changed'], true) => $this->projectEditNote($p['values'] ?? [], $p['changed'] ?? [], $p['proponents'] ?? [], $p['documents'] ?? []),
```

- [ ] **Step 6: Render the History section**

In `resources/views/pages/drive/⚡show.blade.php`, add one line to the top `@php` block:

```blade
@php
    $project = $submission;
    $admin = auth()->user()->isAdmin();
    $onTime = $project->completedOnTime();
    $history = $project->history();
@endphp
```

Insert after the closing `</section>` of the "Studies and proponents" section, before the page's final `</section>`:

```blade
    <section class="mt-10" aria-labelledby="history-heading">
        <flux:heading level="2" id="history-heading">{{ __('History') }}</flux:heading>
        @if ($history->isEmpty())
            <flux:text class="mt-3 text-sm">{{ __('Nothing recorded yet.') }}</flux:text>
        @else
            <ol class="mt-3 divide-y divide-line border-y border-line" data-test="drive-history">
                @foreach ($history as $entry)
                    <li class="py-3">
                        <p class="text-sm text-zinc-500">
                            <span class="font-medium text-zinc-700">{{ $entry->historyActor() }}</span>
                            · <time datetime="{{ $entry->created_at->toIso8601String() }}">{{ $entry->day() }}, {{ $entry->created_at->format('g:i A') }}</time>
                        </p>
                        <p class="mt-1 font-medium text-zinc-800">{{ $entry->historyHeadline() }}</p>
                        {{-- A review's note only repeats the headline's "kept at" --}}
                        @if ($entry->action !== 'submission.reviewed' && ($note = $entry->note()) !== '')
                            <p class="mt-1 text-sm text-zinc-600">{{ $note }}</p>
                        @endif
                        @if ($remarks = $entry->remarks())
                            <p class="mt-1 whitespace-pre-line text-sm text-amber-800">{{ __('Remarks: :remarks', ['remarks' => $remarks]) }}</p>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/ProjectProgressTest.php`
Expected: 10 passed.

- [ ] **Step 8: Run the whole suite**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest`
Expected: all pass. `ActivityLogTest` shows the same `note()` on the Activity Log page, where a new proposal now also reads "Uploaded the concept proposal". If an existing assertion there expected an empty note for `submission.created`, update it to expect that text.

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint app/Models/Submission.php app/Models/ActivityLog.php tests/Feature/ProjectProgressTest.php database/migrations/*_add_subject_index_to_activity_logs_table.php
git add app/Models/Submission.php app/Models/ActivityLog.php database/migrations/ "resources/views/pages/drive/⚡show.blade.php" tests/Feature/ProjectProgressTest.php
git commit -m "feat(drive): show each project's uploads and reviews as a history"
```

---

### Task 4: Keep project history past the 12-month prune

**Files:**
- Modify: `app/Models/ActivityLog.php` (`KEEP_MONTHS` docblock and `prunable()`)
- Modify: `routes/console.php:11` (comment)
- Test: `tests/Feature/ActivityLogTest.php`

**Interfaces:**
- Consumes: the existing `ActivityLog::prunable()`, run daily by `Schedule::command('model:prune')` in `routes/console.php`, and the `ActivityLogTest::project(string $title)` helper.
- Produces: nothing new. The behaviour change is that `submission.*` entries are never pruned.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/ActivityLogTest.php`, right after `test_entries_older_than_the_retention_period_are_pruned`:

```php
    public function test_project_entries_outlive_the_retention_period(): void
    {
        $project = $this->project('Solar Dryer Study');

        $this->travelTo(now()->subMonths(ActivityLog::KEEP_MONTHS)->subDay());
        $review = ActivityLog::record('submission.reviewed', $project, ['status' => ['Submitted', 'Concept']], $this->admin);
        ActivityLog::record('auth.login', user: $this->admin);
        $this->travelBack();

        $this->artisan('model:prune', ['--model' => [ActivityLog::class]])->assertSuccessful();

        // A project can run for years, and its Drive page shows the whole history.
        $this->assertSame([$review->id], ActivityLog::pluck('id')->all());
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/ActivityLogTest.php --filter outlive`
Expected: FAIL. The old review was pruned (`Failed asserting that two arrays are identical`, actual `[]`).

- [ ] **Step 3: Implement**

In `app/Models/ActivityLog.php`, replace the `KEEP_MONTHS` docblock and `prunable()`:

```php
    /** How long entries are kept. The daily model:prune run deletes anything older, except project entries. */
    public const KEEP_MONTHS = 12;
```

```php
    /**
     * Entries older than KEEP_MONTHS, for the daily model:prune run. Project entries stay: a project can run
     * for years, and its Drive page shows the whole history.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subMonths(self::KEEP_MONTHS))->where('action', 'not like', 'submission.%');
    }
```

In `routes/console.php`, change the comment on line 11 to:

```php
// Deletes activity log entries past ActivityLog::KEEP_MONTHS, except each project's history.
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/ActivityLogTest.php`
Expected: all pass, including `test_entries_older_than_the_retention_period_are_pruned`.

- [ ] **Step 5: Run the whole suite, format and commit**

```bash
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest
vendor/bin/pint app/Models/ActivityLog.php routes/console.php tests/Feature/ActivityLogTest.php
git add app/Models/ActivityLog.php routes/console.php tests/Feature/ActivityLogTest.php
git commit -m "feat(activity-log): keep project history past the yearly prune"
```
