# Automated Notifications Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tell project people about concept review decisions and nearing or passed target dates, and tell admins about concept proposals waiting for review. Each notification appears in the portal and is sent by email.

**Architecture:** Three Laravel Notification classes (`database` + `mail`, queued). Each one is sent directly from the code path where its action already happens: `ReviewsSubmissions::saveReview`, the project form's `save`, and a daily `projects:remind` closure command. A Livewire single-file page lists the signed-in user's notifications, and a sidebar item shows the unread count.

**Tech Stack:** Laravel 13 Notifications, Livewire 4 single-file pages, Flux 2, database queue, Laravel scheduler, PHPUnit-style test classes run by Pest.

**Spec:** `docs/superpowers/specs/2026-10-11-automated-notifications-design.md`

## Global Constraints

- Channels: `via()` returns `['database', 'mail']`, or only `['database']` when `! $notifiable->hasVerifiedEmail()`.
- Every notification class implements `ShouldQueue`, uses `Queueable`, and sets `public bool $deleteWhenMissingModels = true;`.
- The acting user is never notified about their own action.
- Project people = filer plus proponent users, unique, from `Submission::people()`.
- Reminders: 30 and 7 days before `target_date`, then 1, 8, 15… days overdue, only while `terminal_uploaded_at` is null.
- `projects:remind` runs `->dailyAt('07:00')`. The app timezone is `Asia/Manila`.
- Announcements are out of scope. Do not add notifications for them.
- No new packages.
- Create files with artisan where a generator exists (`make:notifications-table`, `make:notification`).
- UI uses stock Flux components. Controls are stock `flux:button`, never a hand-styled `<button>`.
- Run `vendor/bin/pint` only on changed files under `app/`, `routes/` and `tests/`. Never run Pint on `⚡` pages.
- Commits: Conventional Commits. No Claude `Co-Authored-By` trailer (project rule).
- Tests follow the repo's existing style: PHPUnit classes in `tests/Feature/` with `RefreshDatabase`. Run them with `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest`.

## Review Focus

- **A concept re-uploaded while it is still waiting for review.** Expected: admins are notified again, once per upload, because there is a new file to read. Test in Task 2.
- **A passed concept that gets replaced.** Expected: no admin notification, because `awaiting_review` stays false. Test in Task 2.
- **A person listed in two studies, or a filer who is also listed as a proponent.** Expected: one notification, not two. Test in Task 1.
- **A project due today (`$days === 0`) or one with no target date.** Expected: no reminder. Test in Task 3.
- **Opening another user's notification id on the notifications page.** Expected: 404, and the notification is not marked read. Test in Task 4.

---

### Task 1: Notifications table, `Submission::people()`, review decision notification

**Files:**
- Create: `database/migrations/<timestamp>_create_notifications_table.php` (generated)
- Create: `app/Notifications/ConceptReviewed.php` (generated, then filled in)
- Modify: `app/Models/Submission.php` (add `people()` after `involves()`)
- Modify: `app/Concerns/ReviewsSubmissions.php` (`saveReview`)
- Test: `tests/Feature/NotificationsTest.php` (new)

**Interfaces:**
- Produces: `Submission::people(): \Illuminate\Database\Eloquent\Collection<int, User>`
- Produces: `new ConceptReviewed(Submission $submission, string $decision, ?string $remarks)`. Public readonly props `$submission`, `$decision` (`'passed'|'returned'`) and `$remarks`. `toArray()` returns `['submission_id' => int, 'message' => string, 'url' => string]`.
- Produces: `tests/Feature/NotificationsTest.php` with the helpers `$this->maria`, `$this->jose`, `$this->admin()`, `$this->project(array $attributes = [])`.

- [ ] **Step 1: Generate the table and the class**

Run:
```bash
php artisan make:notifications-table
php artisan make:notification ConceptReviewed
```
Expected: one new migration, plus `app/Notifications/ConceptReviewed.php`.

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/NotificationsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\ConceptReviewed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $maria;

    private User $jose;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();

        $department = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);
        $this->maria = User::factory()->create(['name' => 'Maria Santos', 'department_id' => $department->id]);
        $this->jose = User::factory()->create(['name' => 'Jose Reyes', 'department_id' => $department->id]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Maria files the project and leads study 1. Jose is staff on study 1 and leads study 2, so he is listed twice.
     */
    private function project(array $attributes = []): Submission
    {
        $project = $this->maria->submissions()->create([
            'category_id' => Category::firstOrCreate(['name' => 'Computing'])->id,
            'department_id' => $this->maria->department_id,
            'title' => 'Solar_Dryer Study',
            'abstract' => 'An abstract.',
            'start_date' => now(),
            'target_date' => now()->addYear(),
            'concept_path' => 'submissions/concept.pdf',
        ]);
        $project->proponents()->createMany([
            ['user_id' => $this->maria->id, 'study' => 1, 'role' => 'Leader'],
            ['user_id' => $this->jose->id, 'study' => 1, 'role' => 'Staff'],
            ['user_id' => $this->jose->id, 'study' => 2, 'role' => 'Leader'],
        ]);
        $project->forceFill(['awaiting_review' => true, ...$attributes])->save();

        return $project->refresh();
    }

    public function test_project_people_are_the_filer_and_proponents_once_each(): void
    {
        $project = $this->project();
        User::factory()->create(['name' => 'Not On It']);

        $this->assertEqualsCanonicalizing([$this->maria->id, $this->jose->id], $project->people()->modelKeys());
    }

    public function test_a_passed_review_tells_project_people_but_not_the_reviewer(): void
    {
        Notification::fake();
        $project = $this->project();
        $this->actingAs($admin = $this->admin());

        Livewire::test('pages::reviews.index')
            ->call('review', $project->id)
            ->call('saveReview', 'passed')
            ->assertHasNoErrors();

        Notification::assertSentTo([$this->maria, $this->jose], ConceptReviewed::class, fn (ConceptReviewed $notification) => $notification->decision === 'passed' && $notification->remarks === null);
        Notification::assertNotSentTo($admin, ConceptReviewed::class);
        Notification::assertSentTimes(ConceptReviewed::class, 2);
    }

    public function test_a_returned_review_carries_the_remarks(): void
    {
        Notification::fake();
        $project = $this->project();
        $this->actingAs($this->admin());

        Livewire::test('pages::reviews.index')
            ->call('review', $project->id)
            ->set('remarks', 'Narrow the scope.')
            ->call('saveReview', 'returned')
            ->assertHasNoErrors();

        Notification::assertSentTo($this->maria, ConceptReviewed::class, function (ConceptReviewed $notification) use ($project) {
            $data = $notification->toArray($this->maria);

            return $notification->decision === 'returned'
                && $notification->remarks === 'Narrow the scope.'
                && $data['url'] === route('drive.show', $project)
                && str_contains($data['message'], 'Solar Dryer Study');
        });
    }

    public function test_a_refused_review_tells_nobody(): void
    {
        Notification::fake();
        $project = $this->project();
        $this->actingAs($this->admin());

        Livewire::test('pages::reviews.index')
            ->call('review', $project->id)
            ->call('saveReview', 'returned')
            ->assertHasErrors(['remarks']);

        Notification::assertNothingSent();
    }

    public function test_unverified_members_get_the_notice_in_the_portal_only(): void
    {
        $project = $this->project();
        $unverified = User::factory()->unverified()->create();

        $this->assertSame(['database'], (new ConceptReviewed($project, 'passed', null))->via($unverified));
        $this->assertSame(['database', 'mail'], (new ConceptReviewed($project, 'passed', null))->via($this->maria));
    }
}
```

- [ ] **Step 3: Run the tests and confirm they fail**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/NotificationsTest.php`
Expected: FAIL. The output includes `Call to undefined method App\Models\Submission::people()`, and the review tests fail because nothing is sent.

- [ ] **Step 4: Add `people()` to `Submission`**

In `app/Models/Submission.php`, add this after `involves()`:

```php
    /**
     * Everyone the project concerns: the filer and each proponent with an account, once each. Notifications go to them.
     *
     * @return EloquentCollection<int, User>
     */
    public function people(): EloquentCollection
    {
        return User::whereKey($this->user_id)->orWhereIn('id', $this->proponents()->select('user_id'))->get();
    }
```

`EloquentCollection` is already imported in this file.

- [ ] **Step 5: Write `ConceptReviewed`**

Replace the contents of `app/Notifications/ConceptReviewed.php`:

```php
<?php

namespace App\Notifications;

use App\Models\Submission;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a project's people that the Research Office passed its concept proposal or returned it for revision.
 */
class ConceptReviewed extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    /**
     * The decision and remarks are passed in, not read from the project, so a later upload can't change what the email says.
     *
     * @param  'passed'|'returned'  $decision
     */
    public function __construct(
        public readonly Submission $submission,
        public readonly string $decision,
        public readonly ?string $remarks,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        // An address the member hasn't confirmed gets no mail; the notice still waits in the portal.
        return $notifiable->hasVerifiedEmail() ? ['database', 'mail'] : ['database'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->decision === 'passed' ? __('Concept proposal passed') : __('Concept proposal returned for revision'))
            ->line($this->message());

        if ($this->remarks) {
            $mail->line(__('Remarks: :remarks', ['remarks' => $this->remarks]));
        }

        return $mail->action(__('Open project'), $this->url());
    }

    /**
     * @return array{submission_id: int, message: string, url: string}
     */
    public function toArray(User $notifiable): array
    {
        return ['submission_id' => $this->submission->id, 'message' => $this->message(), 'url' => $this->url()];
    }

    private function message(): string
    {
        return $this->decision === 'passed'
            ? __('Concept proposal passed: :title. You can now upload the detailed proposal.', ['title' => $this->submission->displayTitle()])
            : __('Concept proposal returned for revision: :title.', ['title' => $this->submission->displayTitle()]);
    }

    private function url(): string
    {
        return route('drive.show', $this->submission);
    }
}
```

- [ ] **Step 6: Send it from `saveReview`**

In `app/Concerns/ReviewsSubmissions.php`:

Add these imports:
```php
use App\Notifications\ConceptReviewed;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
```

Change `DB::transaction(function () use ($validated) {` to `$submission = DB::transaction(function () use ($validated) {`. Then, as the closure's last line after `ActivityLog::record(...)`, add:

```php
            return $submission;
```

Directly after the transaction's closing `});`, add:

```php
        // Sent only once the review has saved, so a rolled-back review tells nobody.
        Notification::send($submission->people()->except(Auth::id()), new ConceptReviewed($submission, $validated['decision'], $submission->remarks));
```

`Eloquent\Collection::except()` takes model keys, so this removes the reviewer if the reviewer is on the project.

- [ ] **Step 7: Run the tests and confirm they pass**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/NotificationsTest.php tests/Feature/ReviewsTest.php tests/Feature/ConceptReviewEdgeTest.php`
Expected: all PASS.

- [ ] **Step 8: Run Pint on the changed PHP files and commit**

```bash
vendor/bin/pint app/Models/Submission.php app/Concerns/ReviewsSubmissions.php app/Notifications/ConceptReviewed.php tests/Feature/NotificationsTest.php database/migrations/*_create_notifications_table.php
git add app/Models/Submission.php app/Concerns/ReviewsSubmissions.php app/Notifications/ConceptReviewed.php tests/Feature/NotificationsTest.php database/migrations/*_create_notifications_table.php
git commit -m "feat(notifications): tell project people when a concept proposal is reviewed"
```

---

### Task 2: Admins hear of each concept proposal waiting for review

**Files:**
- Create: `app/Notifications/ConceptAwaitingReview.php` (generated, then filled in)
- Modify: `resources/views/pages/submissions/⚡create.blade.php` (the `save()` transaction, about lines 172-219)
- Test: `tests/Feature/NotificationsTest.php` (add methods)

**Interfaces:**
- Consumes: `NotificationsTest` helpers from Task 1.
- Produces: `new ConceptAwaitingReview(Submission $submission, string $uploader)`. Public readonly props `$submission` and `$uploader`. `toArray()` returns `['submission_id' => int, 'message' => string, 'url' => string]`, where `url` is `route('reviews.index', ['review' => $submission->id])`.

- [ ] **Step 1: Generate the class**

Run: `php artisan make:notification ConceptAwaitingReview`

- [ ] **Step 2: Write the failing tests**

Add these imports to `tests/Feature/NotificationsTest.php`:
```php
use App\Notifications\ConceptAwaitingReview;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
```

Add these methods:

```php
    public function test_a_new_concept_proposal_tells_every_admin(): void
    {
        Notification::fake();
        Storage::fake('submissions');
        [$first, $second] = [$this->admin(), $this->admin()];
        $this->actingAs($this->maria);

        Livewire::test('pages::submissions.create')
            ->set('title', 'Solar Dryer Study')
            ->set('abstract', 'An abstract.')
            ->set('category_id', Category::firstOrCreate(['name' => 'Computing'])->id)
            ->set('start_date', now()->toDateString())
            ->set('target_date', now()->addYear()->toDateString())
            ->set('documents.concept', UploadedFile::fake()->create('proposal.pdf', 500, 'application/pdf'))
            ->call('save')
            ->assertHasNoErrors();

        $project = Submission::sole();
        Notification::assertSentTo([$first, $second], ConceptAwaitingReview::class, fn (ConceptAwaitingReview $notification) => $notification->submission->is($project)
            && $notification->uploader === 'Maria Santos'
            && $notification->toArray($first)['url'] === route('reviews.index', ['review' => $project->id]));
        Notification::assertNotSentTo($this->maria, ConceptAwaitingReview::class);
    }

    public function test_a_concept_reuploaded_while_waiting_tells_admins_again(): void
    {
        Notification::fake();
        Storage::fake('submissions');
        $admin = $this->admin();
        $project = $this->project();
        $this->actingAs($this->maria);

        Livewire::test('pages::submissions.create', ['submission' => $project])
            ->set('documents.concept', UploadedFile::fake()->create('proposal-v2.pdf', 500, 'application/pdf'))
            ->call('save')
            ->assertHasNoErrors();

        Notification::assertSentToTimes($admin, ConceptAwaitingReview::class, 1);
    }

    public function test_replacing_a_passed_concept_or_uploading_a_later_document_tells_no_admin(): void
    {
        Notification::fake();
        Storage::fake('submissions');
        $admin = $this->admin();
        $project = $this->project(['awaiting_review' => false, 'concept_passed' => true]);
        $this->actingAs($this->maria);

        Livewire::test('pages::submissions.create', ['submission' => $project])
            ->set('documents.concept', UploadedFile::fake()->create('proposal-v2.pdf', 500, 'application/pdf'))
            ->set('documents.detailed', UploadedFile::fake()->create('detailed.pdf', 500, 'application/pdf'))
            ->call('save')
            ->assertHasNoErrors();

        Notification::assertNotSentTo($admin, ConceptAwaitingReview::class);
    }
```

- [ ] **Step 3: Run the tests and confirm they fail**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/NotificationsTest.php --filter=admin`
Expected: FAIL on the first two tests with `The expected [App\Notifications\ConceptAwaitingReview] notification was not sent.`

- [ ] **Step 4: Write `ConceptAwaitingReview`**

Replace the contents of `app/Notifications/ConceptAwaitingReview.php`:

```php
<?php

namespace App\Notifications;

use App\Models\Submission;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the Research Office that a concept proposal came in and waits for a decision.
 */
class ConceptAwaitingReview extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly Submission $submission,
        public readonly string $uploader,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        // An address the member hasn't confirmed gets no mail; the notice still waits in the portal.
        return $notifiable->hasVerifiedEmail() ? ['database', 'mail'] : ['database'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Concept proposal to review'))
            ->line($this->message())
            ->action(__('Review it'), $this->url());
    }

    /**
     * @return array{submission_id: int, message: string, url: string}
     */
    public function toArray(User $notifiable): array
    {
        return ['submission_id' => $this->submission->id, 'message' => $this->message(), 'url' => $this->url()];
    }

    private function message(): string
    {
        return __('New concept proposal to review: :title, from :name.', ['title' => $this->submission->displayTitle(), 'name' => $this->uploader]);
    }

    private function url(): string
    {
        return route('reviews.index', ['review' => $this->submission->id]);
    }
}
```

- [ ] **Step 5: Send it from the project form**

In `resources/views/pages/submissions/⚡create.blade.php`:

Add these imports next to the others at the top:
```php
use App\Notifications\ConceptAwaitingReview;
use Illuminate\Support\Facades\Notification;
```

In `save()`, change `DB::transaction(function () use ($validated, $rows) {` to `$submission = DB::transaction(function () use ($validated, $rows) {`.

The closure ends with the `ActivityLog::record(...)` block wrapped in `if (! $this->submission || ...) { ... }`. After that `if` block, as the closure's last line, add:

```php
            return $submission;
```

Directly after the transaction's closing `});`, and before `session()->flash(...)`, add:

```php
        // The Research Office hears of each concept proposal that waits for a decision, once the save has committed.
        if (isset($this->documents['concept']) && $submission->awaiting_review) {
            Notification::send(User::where('role', 'admin')->whereKeyNot(Auth::id())->get(), new ConceptAwaitingReview($submission, Auth::user()->name));
        }
```

`User` and `Auth` are already imported in this page. Do not run Pint on this file.

- [ ] **Step 6: Run the tests and confirm they pass**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/NotificationsTest.php tests/Feature/SubmissionTest.php tests/Feature/SubmissionEdgeTest.php tests/Feature/ActivityLogTest.php`
Expected: all PASS.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint app/Notifications/ConceptAwaitingReview.php tests/Feature/NotificationsTest.php
git add app/Notifications/ConceptAwaitingReview.php "resources/views/pages/submissions/⚡create.blade.php" tests/Feature/NotificationsTest.php
git commit -m "feat(notifications): tell admins when a concept proposal waits for review"
```

---

### Task 3: Daily deadline reminders

**Files:**
- Create: `app/Notifications/ProjectDeadline.php` (generated, then filled in)
- Modify: `routes/console.php`
- Test: `tests/Feature/NotificationsTest.php` (add methods)

**Interfaces:**
- Consumes: `Submission::people()` from Task 1, and the `NotificationsTest` helpers.
- Produces: `new ProjectDeadline(Submission $submission, int $days)`. Public readonly props `$submission` and `$days`: positive means "due in N days", negative means "overdue by N days". `toArray()` returns `['submission_id' => int, 'message' => string, 'url' => string]`.
- Produces: artisan command `projects:remind`, scheduled daily at 07:00.

- [ ] **Step 1: Generate the class**

Run: `php artisan make:notification ProjectDeadline`

- [ ] **Step 2: Write the failing tests**

Add this import to `tests/Feature/NotificationsTest.php`:
```php
use App\Notifications\ProjectDeadline;
```

Add these methods:

```php
    /**
     * The days each reminder Maria got carries, smallest first.
     *
     * @return list<int>
     */
    private function remindedDays(): array
    {
        return Notification::sent($this->maria, ProjectDeadline::class)->map(fn (ProjectDeadline $notification) => $notification->days)->sort()->values()->all();
    }

    public function test_reminds_project_people_thirty_and_seven_days_before_the_target_date(): void
    {
        Notification::fake();
        foreach ([30, 29, 7, 1] as $days) {
            $this->project(['target_date' => today()->addDays($days)]);
        }

        $this->artisan('projects:remind')->assertSuccessful();

        $this->assertSame([7, 30], $this->remindedDays());
        Notification::assertSentToTimes($this->jose, ProjectDeadline::class, 2);
    }

    public function test_reminds_the_day_after_the_target_date_then_weekly(): void
    {
        Notification::fake();
        foreach ([-1, -2, -7, -8, -15, -16] as $days) {
            $this->project(['target_date' => today()->addDays($days)]);
        }

        $this->artisan('projects:remind')->assertSuccessful();

        $this->assertSame([-15, -8, -1], $this->remindedDays());
    }

    public function test_no_reminder_on_the_day_itself_without_a_target_date_or_once_finished(): void
    {
        Notification::fake();
        $this->project(['target_date' => today()]);
        $this->project(['target_date' => null]);
        $this->project(['target_date' => today()->addDays(7), 'terminal_uploaded_at' => now()]);
        $this->project(['target_date' => today()->subDay(), 'terminal_uploaded_at' => now()]);

        $this->artisan('projects:remind')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_reminders_read_as_due_or_overdue(): void
    {
        $project = $this->project(['target_date' => today()->addDays(7)]);

        $this->assertStringContainsString('Due in 7 days: Solar Dryer Study', (new ProjectDeadline($project, 7))->toArray($this->maria)['message']);
        $this->assertStringContainsString('Overdue by 1 day: Solar Dryer Study', (new ProjectDeadline($project, -1))->toArray($this->maria)['message']);
        $this->assertSame(route('drive.show', $project), (new ProjectDeadline($project, -8))->toArray($this->maria)['url']);
    }

    public function test_reminders_are_scheduled_every_morning(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('projects:remind')->assertSuccessful();
    }
```

- [ ] **Step 3: Run the tests and confirm they fail**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/NotificationsTest.php --filter=remind`
Expected: FAIL with `The command "projects:remind" does not exist.`

- [ ] **Step 4: Write `ProjectDeadline`**

Replace the contents of `app/Notifications/ProjectDeadline.php`:

```php
<?php

namespace App\Notifications;

use App\Models\Submission;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Reminds a project's people that its target date is near, or has passed without a terminal report.
 */
class ProjectDeadline extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    /**
     * @param  int  $days  Days until the target date; negative once it has passed.
     */
    public function __construct(
        public readonly Submission $submission,
        public readonly int $days,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        // An address the member hasn't confirmed gets no mail; the notice still waits in the portal.
        return $notifiable->hasVerifiedEmail() ? ['database', 'mail'] : ['database'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->days > 0 ? __('Target date in :days days', ['days' => $this->days]) : __('Project overdue'))
            ->line($this->message())
            ->action(__('Open project'), $this->url());
    }

    /**
     * @return array{submission_id: int, message: string, url: string}
     */
    public function toArray(User $notifiable): array
    {
        return ['submission_id' => $this->submission->id, 'message' => $this->message(), 'url' => $this->url()];
    }

    private function message(): string
    {
        $replace = ['days' => abs($this->days), 'title' => $this->submission->displayTitle(), 'date' => $this->submission->target_date?->format('F j, Y')];

        return $this->days > 0
            ? trans_choice('{1} Due in 1 day: :title. Upload the terminal report by :date.|[2,*] Due in :days days: :title. Upload the terminal report by :date.', $this->days, $replace)
            : trans_choice('{1} Overdue by 1 day: :title. The terminal report was due :date.|[2,*] Overdue by :days days: :title. The terminal report was due :date.', abs($this->days), $replace);
    }

    private function url(): string
    {
        return route('drive.show', $this->submission);
    }
}
```

- [ ] **Step 5: Add the command and its schedule**

In `routes/console.php`, add these imports:
```php
use App\Models\Submission;
use App\Notifications\ProjectDeadline;
use Illuminate\Support\Facades\Notification;
```

Append:

```php
// Reminds each open project's people 30 and 7 days before its target date, then the day after it passes and weekly until the terminal report is in.
Artisan::command('projects:remind', function () {
    // ponytail: stateless, so a day the scheduler misses is a reminder skipped; record sent milestones if that ever matters.
    // ponytail: loads every open project and filters in PHP; compute the matching target dates in SQL if projects reach the thousands.
    Submission::whereNotNull('target_date')->whereNull('terminal_uploaded_at')->each(function (Submission $submission) {
        $days = (int) today()->diffInDays($submission->target_date, false);

        if (in_array($days, [30, 7], true) || ($days <= -1 && (-$days - 1) % 7 === 0)) {
            Notification::send($submission->people(), new ProjectDeadline($submission, $days));
        }
    });
})->purpose('Remind proponents of near and passed target dates');

Schedule::command('projects:remind')->dailyAt('07:00');
```

- [ ] **Step 6: Run the tests and confirm they pass**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/NotificationsTest.php`
Expected: all PASS.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint app/Notifications/ProjectDeadline.php routes/console.php tests/Feature/NotificationsTest.php
git add app/Notifications/ProjectDeadline.php routes/console.php tests/Feature/NotificationsTest.php
git commit -m "feat(notifications): remind proponents of near and passed target dates"
```

---

### Task 4: Notifications page and sidebar unread count

**Files:**
- Create: `resources/views/pages/notifications/⚡index.blade.php`
- Modify: `routes/web.php` (inside the `auth` + `verified` group, after the `announcements.index` line)
- Modify: `resources/views/layouts/app/sidebar.blade.php` (the `@php` block, `<body>` `x-data`, the Overview group, and the phone menu dot)
- Test: `tests/Feature/NotificationsTest.php` (add methods)

**Interfaces:**
- Consumes: `ConceptReviewed` from Task 1 (used to create notifications in tests), and the `toArray()` keys `message` and `url`.
- Produces: route `notifications.index` at `/notifications`. Page methods `open(string $id): void` and `markAllRead(): void`. Sidebar item `data-test="notifications-nav"`.

- [ ] **Step 1: Write the failing tests**

Add these methods to `tests/Feature/NotificationsTest.php`:

```php
    public function test_guests_cannot_open_notifications(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
    }

    public function test_members_see_only_their_own_notifications(): void
    {
        $project = $this->project();
        $this->maria->notify(new ConceptReviewed($project, 'passed', null));
        $this->admin()->notify(new ConceptAwaitingReview($project, 'Maria Santos'));

        $this->actingAs($this->maria)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Concept proposal passed: Solar Dryer Study')
            ->assertDontSee('New concept proposal to review');
    }

    public function test_opening_a_notification_marks_it_read_and_goes_to_the_project(): void
    {
        $project = $this->project();
        $this->maria->notify(new ConceptReviewed($project, 'passed', null));
        $notification = $this->maria->notifications()->sole();
        $this->actingAs($this->maria);

        Livewire::test('pages::notifications.index')
            ->call('open', $notification->id)
            ->assertRedirect(route('drive.show', $project));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_another_members_notification_cannot_be_opened(): void
    {
        $project = $this->project();
        $this->jose->notify(new ConceptReviewed($project, 'passed', null));
        $joses = $this->jose->notifications()->sole();
        $this->actingAs($this->maria);

        Livewire::test('pages::notifications.index')
            ->call('open', $joses->id)
            ->assertStatus(404);

        $this->assertNull($joses->fresh()->read_at);
    }

    public function test_mark_all_read_clears_the_sidebar_count(): void
    {
        $project = $this->project();
        $this->maria->notify(new ConceptReviewed($project, 'passed', null));
        $this->maria->notify(new ConceptReviewed($project, 'returned', 'Narrow the scope.'));
        $this->actingAs($this->maria);

        $this->get(route('dashboard'))
            ->assertSeeHtml('data-test="notifications-nav"')
            ->assertSeeHtml('title="2 unread notifications"');

        Livewire::test('pages::notifications.index')
            ->call('markAllRead')
            ->assertRedirect(route('notifications.index'));

        $this->assertSame(0, $this->maria->unreadNotifications()->count());
        $this->get(route('dashboard'))->assertDontSeeHtml('unread notifications"');
    }

    public function test_an_empty_list_says_so(): void
    {
        $this->actingAs($this->maria)
            ->get(route('notifications.index'))
            ->assertSee('No notifications yet.')
            ->assertDontSeeHtml('data-test="mark-all-read"');
    }
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/NotificationsTest.php --filter="notification|guests|empty"`
Expected: FAIL with `Route [notifications.index] not defined.`

- [ ] **Step 3: Register the route**

In `routes/web.php`, add this after the `announcements.index` line:

```php
    Route::livewire('notifications', 'pages::notifications.index')->name('notifications.index');
```

- [ ] **Step 4: Create the page**

Run `php artisan make:livewire pages::notifications.index` if the generator supports the `pages::` namespace. Otherwise create the file by hand. Replace its contents with:

```blade
<?php

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * The signed-in member's notifications, newest first. Opening one marks it read and goes where it points.
 */
new #[Title('Notifications')] class extends Component {
    use WithPagination;

    #[Computed]
    public function notifications(): LengthAwarePaginator
    {
        return Auth::user()->notifications()->paginate(20);
    }

    /**
     * Mark one notification read and open what it is about. Only the member's own are searched, so any other id is a 404.
     */
    public function open(string $id): void
    {
        $notification = Auth::user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        $this->redirect($notification->data['url'], navigate: true);
    }

    /**
     * Mark every unread notification read. The page reloads, so the sidebar count in the layout drops too.
     */
    public function markAllRead(): void
    {
        Auth::user()->unreadNotifications()->update(['read_at' => now()]);

        $this->redirectRoute('notifications.index', navigate: true);
    }
}; ?>

<section class="mx-auto w-full max-w-4xl">
    <header class="flex items-center justify-between gap-4 border-b border-line pb-6">
        <div class="min-w-0">
            <flux:heading size="xl" level="1">{{ __('Notifications') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Reviews, concept proposals and target dates that need your attention.') }}</flux:text>
        </div>

        @if (auth()->user()->unreadNotifications()->exists())
            <flux:button wire:click="markAllRead" data-test="mark-all-read">{{ __('Mark all read') }}</flux:button>
        @endif
    </header>

    @if ($this->notifications->isEmpty())
        <flux:text class="mt-8">{{ __('No notifications yet.') }}</flux:text>
    @else
        <ul class="divide-y divide-line">
            @foreach ($this->notifications as $notification)
                <li wire:key="{{ $notification->id }}" class="flex items-center justify-between gap-4 py-4">
                    <div class="min-w-0">
                        <flux:text @class(['font-semibold text-zinc-900' => ! $notification->read_at])>
                            @unless ($notification->read_at)
                                <span class="sr-only">{{ __('Unread:') }}</span>
                            @endunless
                            {{ $notification->data['message'] }}
                        </flux:text>
                        <flux:text size="sm" class="mt-1">{{ $notification->created_at->diffForHumans() }}</flux:text>
                    </div>

                    <flux:button size="sm" wire:click="open('{{ $notification->id }}')" aria-label="{{ __('Open: :message', ['message' => $notification->data['message']]) }}">{{ __('Open') }}</flux:button>
                </li>
            @endforeach
        </ul>

        <flux:pagination :paginator="$this->notifications" class="mt-4" />
    @endif
</section>
```

Do not run Pint on this file.

- [ ] **Step 5: Add the sidebar item and the phone dot**

In `resources/views/layouts/app/sidebar.blade.php`:

In the `@php` block, after the `$revisionLabel = ...` line, add:
```php
        $unread = auth()->user()->unreadNotifications()->count();
        $unreadLabel = trans_choice('{1} 1 unread notification|[2,*] :count unread notifications', $unread);
```

On `<body>`, change the `x-data` from `{ toReview: @js($toReview), toRevise: @js($toRevise), reviewLabel: @js($reviewLabel) }` to `{ toReview: @js($toReview), toRevise: @js($toRevise), unread: @js($unread), reviewLabel: @js($reviewLabel) }`.

In the Overview group, after the Announcements `flux:sidebar.item`, add:
```blade
                    {{-- Unread notices show as a count on every page; screen readers hear the sentence, not the bare number --}}
                    <flux:sidebar.item icon="bell" :href="route('notifications.index')" :current="request()->routeIs('notifications.*')" :badge="$unread ? $capped($unread) : null" :badge:title="$unreadLabel" badge:aria-hidden="true" wire:navigate data-test="notifications-nav">
                        {{ __('Notifications') }}
                        @if ($unread)
                            <span class="sr-only">{{ $unreadLabel }}</span>
                        @endif
                    </flux:sidebar.item>
```

On the phone menu dot `<span ... data-test="sidebar-toggle-dot">`:
- Change `x-show="toReview || toRevise"` to `x-show="toReview || toRevise || unread"`.
- Change `@style(['display: none' => ! ($toReview || $toRevise)])` to `@style(['display: none' => ! ($toReview || $toRevise || $unread)])`.
- Leave the colour classes unchanged.

- [ ] **Step 6: Run the tests and confirm they pass**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/NotificationsTest.php tests/Feature/SidebarBadgesTest.php tests/Feature/DashboardTest.php`
Expected: all PASS. If a `SidebarBadgesTest` dot test fails because of an unread notification, the test data is wrong, not the layout. No existing test creates notifications.

- [ ] **Step 7: Check it in the browser**

Run `composer dev`. Sign in as a faculty member and open `/notifications`. In tinker, run `User::find(<id>)->notify(new App\Notifications\ConceptReviewed(App\Models\Submission::first(), 'passed', null))`. Reload: the bell badge shows 1, and the row is bold. Click Open: you land on the project, and the badge is gone after the navigation.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint tests/Feature/NotificationsTest.php routes/web.php
git add routes/web.php "resources/views/pages/notifications/⚡index.blade.php" resources/views/layouts/app/sidebar.blade.php tests/Feature/NotificationsTest.php
git commit -m "feat(notifications): add notifications page and sidebar unread count"
```

---

### Task 5: Clean up on user delete, deploy notes, full check

**Files:**
- Modify: `resources/views/pages/users/⚡index.blade.php` (`delete()`, about line 247)
- Create: `docs/notifications.md`
- Test: `tests/Feature/NotificationsTest.php` (add a method)

**Interfaces:**
- Consumes: `ConceptReviewed` from Task 1.

- [ ] **Step 1: Write the failing test**

Add this method to `tests/Feature/NotificationsTest.php`:

```php
    public function test_deleting_a_user_deletes_their_notifications(): void
    {
        $leaving = User::factory()->create();
        $leaving->notify(new ConceptReviewed($this->project(), 'passed', null));
        $this->maria->notify(new ConceptReviewed($this->project(), 'passed', null));
        $this->actingAs($this->admin());

        Livewire::test('pages::users.index')
            ->call('confirmDelete', $leaving->id)
            ->call('delete');

        $this->assertModelMissing($leaving);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $leaving->id, 'notifiable_type' => User::class]);
        $this->assertSame(1, $this->maria->notifications()->count());
    }
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/NotificationsTest.php --filter=deleting`
Expected: FAIL. `assertDatabaseMissing` finds the leaving user's row.

- [ ] **Step 3: Delete the notifications with the user**

In `resources/views/pages/users/⚡index.blade.php`, inside `delete()`'s `DB::transaction(function () {`, add this before `$this->deleting->delete();`:

```php
            // Notifications point at the user without a foreign key, so they would outlive the account.
            $this->deleting->notifications()->delete();
```

Do not run Pint on this file.

- [ ] **Step 4: Run it and confirm it passes**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/NotificationsTest.php tests/Feature/ManageUsersTest.php`
Expected: all PASS.

- [ ] **Step 5: Write the deploy notes**

Create `docs/notifications.md`:

````markdown
# Notifications

The portal tells people about three things, in the portal (the bell in the sidebar) and by email:

- **Concept review decision.** When the Research Office passes or returns a concept proposal, the project's filer and proponents are told. A return includes the remarks.
- **Concept proposal to review.** When a concept proposal is uploaded and waits for a decision, every admin is told.
- **Target date reminders.** Every morning at 07:00 (Asia/Manila), each open project's people are reminded 30 and 7 days before its target date, the day after it passes, and then every 7 days until the terminal report is uploaded.

Members with an unconfirmed email address get the notice in the portal only.

## What the server needs

Notifications are queued, and reminders run on the scheduler, so production needs both of these running.

1. **The scheduler.** Add one cron entry for the web server user:

   ```
   * * * * * cd /path/to/research-portal && php artisan schedule:run >> /dev/null 2>&1
   ```

2. **A queue worker.** Keep `php artisan queue:work` running with supervisor or the host's process manager. Restart it after each deploy with `php artisan queue:restart`.

3. **Mail settings.** Ask ISU IT for SMTP details and set them in `.env`:

   ```
   MAIL_MAILER=smtp
   MAIL_HOST=
   MAIL_PORT=
   MAIL_USERNAME=
   MAIL_PASSWORD=
   MAIL_FROM_ADDRESS=
   ```

   Until these are set, keep `MAIL_MAILER=log`. In-app notifications still work, and the emails are written to `storage/logs/laravel.log`.

## Checking it by hand

- `php artisan projects:remind` sends today's reminders straight away.
- `php artisan schedule:list` shows `projects:remind` at 07:00.
- `php artisan queue:failed` lists emails that failed after their retries.
````

- [ ] **Step 6: Run the full check**

Run: `composer ci:check`
Expected: Pint `--test` passes, PHPStan reports no errors, and every test passes.

If PHPStan flags `people()` or a notification as unused, check that the caller is in `app/` or `routes/`. Both callers are (`ReviewsSubmissions`, `routes/console.php`), so it should not happen. If `composer ci:check` can't load SQLite, run `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest` for the test step and run `vendor/bin/phpstan analyse` separately.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint tests/Feature/NotificationsTest.php
git add "resources/views/pages/users/⚡index.blade.php" docs/notifications.md tests/Feature/NotificationsTest.php
git commit -m "feat(notifications): drop a deleted user's notifications, document server setup"
```
