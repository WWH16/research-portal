<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Category;
use App\Models\Department;
use App\Models\GoogleDriveConnection;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\AccountApproved;
use App\Notifications\ConceptReviewed;
use App\Notifications\DriveDisconnected;
use App\Notifications\DriveFull;
use App\Notifications\ProjectCompleted;
use App\Notifications\ProjectDatesChanged;
use App\Notifications\ProjectDeadline;
use App\Notifications\ProjectLate;
use App\Services\DocumentStorage;
use Illuminate\Auth\Events\Verified;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
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
            'concept_path' => 'drive-1/submissions/concept.pdf',
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

    public function test_a_new_concept_proposal_sends_admins_no_notice(): void
    {
        // The Concept Reviews badge counts it live instead.
        Notification::fake();
        $this->fakeDrive();
        $admin = $this->admin();
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

        Notification::assertNothingSentTo($admin);
    }

    public function test_a_verified_sign_up_sends_admins_no_notice(): void
    {
        // The Manage Users badge counts it live instead.
        Notification::fake();
        $this->admin();
        $signUp = User::factory()->pending()->create(['name' => 'Juan Dela Cruz', 'email' => 'juan@isu.edu.ph']);

        event(new Verified($signUp));

        Notification::assertNothingSent();
    }

    public function test_an_approved_member_verifying_a_new_email_tells_no_admin(): void
    {
        Notification::fake();
        $this->admin();

        event(new Verified($this->maria));

        Notification::assertNothingSent();
    }

    public function test_unread_notices_carry_a_dot_and_one_page_shows_no_pager(): void
    {
        $project = $this->project();
        $this->maria->notify(new ConceptReviewed($project, 'passed', null));
        $this->maria->notify(new ConceptReviewed($project, 'returned', 'Narrow the scope.'));
        $this->maria->notifications()->latest()->first()->markAsRead();

        $this->actingAs($this->maria)
            ->get(route('notifications.index'))
            ->assertSee('isu_seal-128.png')
            ->assertDontSee('Showing 1 to');

        $page = $this->get(route('notifications.index'))->getContent();
        $this->assertSame(1, substr_count($page, 'data-test="unread-dot"'));
    }

    public function test_replacing_a_passed_concept_or_uploading_a_later_document_tells_no_admin(): void
    {
        Notification::fake();
        $this->fakeDrive();
        $admin = $this->admin();
        $project = $this->project(['awaiting_review' => false, 'concept_passed' => true]);
        $this->actingAs($this->maria);

        Livewire::test('pages::submissions.create', ['submission' => $project])
            ->set('documents.concept', UploadedFile::fake()->create('proposal-v2.pdf', 500, 'application/pdf'))
            ->set('documents.detailed', UploadedFile::fake()->create('detailed.pdf', 500, 'application/pdf'))
            ->call('save')
            ->assertHasNoErrors();

        Notification::assertNothingSentTo($admin);
    }

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

    public function test_admins_hear_once_the_day_after_a_project_runs_late(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $this->maria->update(['name' => 'MARIA DELA PAZ SANTOS']);
        foreach ([-1, -2, -8] as $days) {
            $this->project(['target_date' => today()->addDays($days), 'title' => "Study {$days}"]);
        }

        $this->artisan('projects:remind')->assertSuccessful();

        // Only the project that passed its date yesterday; the weekly overdue reminders stay with its proponents.
        Notification::assertSentToTimes($admin, ProjectLate::class, 1);
        Notification::assertSentTo($admin, ProjectLate::class, fn (ProjectLate $notification) => $notification->via($admin) === ['database']
            && $notification->toArray($admin) === [
                'message' => '“Study -1”, filed by Maria Dela Paz Santos, passed its completion date of '.today()->subDay()->format('F j, Y').' without a terminal report. Its proponents have been reminded.',
                'url' => route('submissions.index', ['status' => 'delayed']),
            ]);
        Notification::assertNotSentTo($this->maria, ProjectLate::class);
    }

    public function test_staff_hear_once_the_day_after_a_project_runs_late(): void
    {
        Notification::fake();
        $staff = User::factory()->create(['role' => 'staff']);
        foreach ([-1, -2, -8] as $days) {
            $this->project(['target_date' => today()->addDays($days), 'title' => "Study {$days}"]);
        }

        $this->artisan('projects:remind')->assertSuccessful();

        Notification::assertSentToTimes($staff, ProjectLate::class, 1);
        Notification::assertSentTo($staff, ProjectLate::class, fn (ProjectLate $notification) => $notification->via($staff) === ['database']);
    }

    public function test_the_first_terminal_report_tells_staff_the_project_is_complete(): void
    {
        Notification::fake();
        $this->fakeDrive();
        $staff = User::factory()->create(['role' => 'staff']);
        $project = $this->project(['awaiting_review' => false, 'concept_passed' => true, 'status' => 'Mid-year', 'detailed_path' => 'drive-1/submissions/detailed.pdf', 'midyear_path' => 'drive-1/submissions/midyear.pdf']);
        $this->actingAs($this->maria);

        Livewire::test('pages::submissions.create', ['submission' => $project->fresh()])
            ->set('documents.terminal', UploadedFile::fake()->create('report.pdf', 100, 'application/pdf'))
            ->call('save')
            ->assertHasNoErrors();

        Notification::assertSentTo($staff, ProjectCompleted::class, fn (ProjectCompleted $notification) => $notification->via($staff) === ['database']);
    }

    public function test_staff_see_what_their_notices_will_be(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($staff)
            ->get(route('notifications.index'))
            ->assertSee('Completed and late projects, and new and changed announcements, will appear here.')
            ->assertDontSee('Google Drive');
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

        $due = $project->target_date->format('F j, Y');

        $this->assertSame("The completion date of “Solar Dryer Study” is in 7 days, on {$due}. Please upload the terminal report on or before that date.", (new ProjectDeadline($project, 7))->toArray($this->maria)['message']);
        $this->assertSame("The completion date of “Solar Dryer Study” was {$due}, 1 day ago, and the terminal report has not been uploaded yet. Please upload it as soon as you can.", (new ProjectDeadline($project, -1))->toArray($this->maria)['message']);
        $this->assertSame(route('drive.show', $project), (new ProjectDeadline($project, -8))->toArray($this->maria)['url']);
    }

    public function test_reminders_are_scheduled_every_morning(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('projects:remind')->assertSuccessful();
    }

    public function test_guests_cannot_open_notifications(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
    }

    public function test_members_see_only_their_own_notifications(): void
    {
        $project = $this->project();
        $this->maria->notify(new ConceptReviewed($project, 'passed', null));
        $this->jose->notify(new ConceptReviewed($project, 'returned', 'Narrow the scope.'));

        $this->actingAs($this->maria)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Your concept proposal for “Solar Dryer Study” has passed the Research Office’s review.')
            ->assertDontSee('returned your concept proposal');
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

    public function test_a_notice_opens_like_a_message_and_is_marked_read(): void
    {
        $project = $this->project();
        $this->maria->notify(new ConceptReviewed($project, 'passed', null));
        $notice = $this->maria->notifications()->sole();
        $this->actingAs($this->maria);

        Livewire::test('pages::notifications.index')
            ->call('read', $notice->id)
            ->assertDispatched('open-work-stale')
            ->assertSee('Concept proposal decision')
            ->assertSee('Your concept proposal for “Solar Dryer Study” has passed the Research Office’s review. You may now upload the detailed proposal.')
            ->assertSee($notice->created_at->format('F j, Y, g:i A'))
            ->assertSee('Open the project')
            ->assertNoRedirect();

        $this->assertNotNull($notice->fresh()->read_at);
    }

    public function test_another_members_notice_cannot_be_read(): void
    {
        $this->jose->notify(new ConceptReviewed($this->project(), 'passed', null));
        $this->actingAs($this->maria);

        Livewire::test('pages::notifications.index')->call('read', $this->jose->notifications()->sole()->id)->assertStatus(404);
    }

    public function test_opening_a_notice_stays_on_the_address_the_member_is_using(): void
    {
        // Saved with the address the portal had when it was sent; another address, or another site, never takes over.
        $this->maria->notifications()->create(['id' => (string) Str::uuid(), 'type' => AccountApproved::class, 'data' => ['message' => 'Welcome.', 'url' => 'http://192.168.1.50:8000/submissions/create?from=welcome']]);
        $notice = $this->maria->notifications()->sole();
        $this->actingAs($this->maria);

        Livewire::test('pages::notifications.index')
            ->call('open', $notice->id)
            ->assertRedirect(url('/submissions/create?from=welcome'));
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

    public function test_a_deleted_notice_comes_back_exactly_on_undo(): void
    {
        $project = $this->project();
        $this->maria->notify(new ConceptReviewed($project, 'passed', null));
        $before = $this->maria->notifications()->sole()->getRawOriginal();
        $this->actingAs($this->maria);

        $page = Livewire::test('pages::notifications.index')
            ->call('delete', $before['id'])
            ->assertSee('Notification deleted.')
            ->assertSee('No notifications yet.');
        $this->assertSame(0, $this->maria->notifications()->count());

        $page->call('undoDelete')->assertSee('Your concept proposal for “Solar Dryer Study” has passed the Research Office’s review.');
        $this->assertSame($before, $this->maria->notifications()->sole()->getRawOriginal());
    }

    public function test_delete_all_read_keeps_unread_notices_and_can_be_undone(): void
    {
        $project = $this->project();
        $this->maria->notify(new ConceptReviewed($project, 'passed', null));
        $this->maria->notify(new ConceptReviewed($project, 'returned', 'Narrow the scope.'));
        $this->maria->notify(new ProjectDeadline($project, 7));
        $this->maria->notifications()->where('data->message', 'like', '%concept proposal%')->update(['read_at' => now()]);
        $this->actingAs($this->maria);

        $page = Livewire::test('pages::notifications.index')
            ->assertSee('Delete 2 read notifications?')
            ->call('deleteAllRead')
            ->assertSee('2 notifications deleted.');
        $this->assertSame(1, $this->maria->notifications()->count());
        $this->assertSame(1, $this->maria->unreadNotifications()->count());

        $page->call('undoDelete');
        $this->assertSame(3, $this->maria->notifications()->count());
    }

    public function test_a_notice_can_be_marked_unread_and_read_again(): void
    {
        $project = $this->project();
        $this->maria->notify(new ConceptReviewed($project, 'passed', null));
        $notice = $this->maria->notifications()->sole();
        $notice->markAsRead();
        $this->actingAs($this->maria);

        $page = Livewire::test('pages::notifications.index')->call('toggleRead', $notice->id);
        $this->assertSame(1, $this->maria->unreadNotifications()->count());

        $page->call('toggleRead', $notice->id);
        $this->assertSame(0, $this->maria->unreadNotifications()->count());
    }

    public function test_another_members_notice_cannot_be_deleted_or_marked(): void
    {
        $project = $this->project();
        $this->jose->notify(new ConceptReviewed($project, 'passed', null));
        $joses = $this->jose->notifications()->sole();
        $this->actingAs($this->maria);

        Livewire::test('pages::notifications.index')->call('delete', $joses->id)->assertStatus(404);
        Livewire::test('pages::notifications.index')->call('toggleRead', $joses->id)->assertStatus(404);

        $this->assertModelExists($joses);
        $this->assertNull($joses->fresh()->read_at);
    }

    public function test_deleting_the_last_notice_on_a_page_shows_the_page_before_it(): void
    {
        foreach (range(1, 20) as $number) {
            $this->maria->notifications()->create(['id' => (string) Str::uuid(), 'type' => ProjectDeadline::class, 'data' => ['message' => "Notice {$number}", 'url' => '/dashboard']]);
        }
        $last = $this->maria->notifications()->create(['id' => (string) Str::uuid(), 'type' => ProjectDeadline::class, 'data' => ['message' => 'Oldest notice', 'url' => '/dashboard']]);
        $last->forceFill(['created_at' => now()->subYear()])->save();

        $this->actingAs($this->maria);

        Livewire::withQueryParams(['page' => 2])->test('pages::notifications.index')
            ->assertSee('Oldest notice')
            ->call('delete', $last->id)
            ->assertDontSee('No notifications yet.')
            ->assertSee('Notice 20');
    }

    public function test_an_approved_member_hears_they_can_sign_in(): void
    {
        Notification::fake();
        $waiting = User::factory()->pending()->create();
        $this->actingAs($this->admin());

        Livewire::test('pages::users.index')->call('approve', $waiting->id);

        Notification::assertSentTo($waiting, AccountApproved::class, fn (AccountApproved $notification) => $notification->via($waiting) === ['database', 'mail']
            && $notification->toArray($waiting) === [
                'message' => 'The Research Office has approved your account. To begin, submit your concept proposal; the Research Office will review it and notify you of its decision here. You may also add your mobile number and profile photo under My profile.',
                'url' => route('submissions.create'),
            ]
            && $notification->toMail($waiting)->introLines[1] === 'You may now sign in to submit research proposals, upload the documents for each stage, and follow the progress of your projects.'
            && $notification->toMail($waiting)->actionUrl === route('login'));
    }

    public function test_changed_dates_tell_the_project_people_once_each(): void
    {
        Notification::fake();
        $project = $this->project();
        $this->actingAs($this->admin());
        $due = today()->addYears(2);

        Livewire::test('pages::submissions.index')
            ->call('editDates', $project->id)
            ->set('target_date', $due->toDateString())
            ->call('saveDates')
            ->assertHasNoErrors();

        Notification::assertSentTo([$this->maria, $this->jose], ProjectDatesChanged::class, fn (ProjectDatesChanged $notification) => $notification->toArray($this->maria) === [
            'message' => 'The Research Office has changed the dates of “Solar Dryer Study”. The project now starts on '.$project->start_date->format('F j, Y').', and its completion date is '.$due->format('F j, Y').'.',
            'url' => route('drive.show', $project),
        ]);
        Notification::assertSentToTimes($this->jose, ProjectDatesChanged::class, 1);
    }

    public function test_saving_the_same_dates_tells_nobody(): void
    {
        Notification::fake();
        $project = $this->project();
        $this->actingAs($this->admin());

        Livewire::test('pages::submissions.index')->call('editDates', $project->id)->call('saveDates')->assertHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_admins_hear_once_when_google_drive_disconnects(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $account = GoogleDriveConnection::factory()->create(['email' => 'office@isu.edu.ph']);
        $revoked = new RuntimeException('Client error: 400 Bad Request {"error": "invalid_grant"}');

        app(DocumentStorage::class)->markIfUnreachable($account, $revoked);
        app(DocumentStorage::class)->markIfUnreachable($account, $revoked);

        Notification::assertSentToTimes($admin, DriveDisconnected::class, 1);
        Notification::assertSentTo($admin, DriveDisconnected::class, fn (DriveDisconnected $notification) => $notification->toArray($admin) === [
            'message' => 'Google Drive office@isu.edu.ph is disconnected, so documents can’t be uploaded or opened. Connect it again on System → Google Drive.',
            'url' => route('google-drive.index'),
        ]);
        Notification::assertNotSentTo($this->maria, DriveDisconnected::class);
    }

    public function test_a_disconnect_found_by_a_failed_save_is_kept_and_told_once(): void
    {
        $admin = $this->admin();
        $account = GoogleDriveConnection::factory()->create();
        $revoked = new RuntimeException('Client error: 400 Bad Request {"error": "invalid_grant"}');
        // An upload saves inside a transaction, which rolls back when Google refuses the portal's access.
        $failedUpload = fn () => rescue(fn () => DB::transaction(function () use ($account, $revoked) {
            app(DocumentStorage::class)->markIfUnreachable($account->fresh(), $revoked);
            throw $revoked;
        }), report: false);

        $failedUpload();
        $failedUpload();

        $this->assertFalse($account->fresh()->isConnected());
        $this->assertSame(1, $admin->notifications()->where('type', DriveDisconnected::class)->count());
    }

    public function test_every_notice_that_emails_has_an_email(): void
    {
        // A notice without its own email gets the portal's plain one, with its lines and the same link.
        $mail = (new DriveFull(GoogleDriveConnection::factory()->create(['email' => 'office@isu.edu.ph'])))->toMail($this->maria);

        $this->assertSame('The Google Drive account **office@isu.edu.ph** is **full**, so documents can’t be uploaded to the portal.', $mail->introLines[0]);
        $this->assertSame(route('google-drive.index'), $mail->actionUrl);
        $this->assertSame('Google Drive is full', $mail->subject);
    }

    public function test_a_kind_turned_off_still_arrives_in_the_portal_but_not_by_email(): void
    {
        $project = $this->project();
        $this->maria->forceFill(['email_preferences' => ['reminders' => false]])->save();

        $this->assertSame(['database'], (new ProjectDeadline($project, 7))->via($this->maria));
        $this->assertSame(['database', 'mail'], (new ConceptReviewed($project, 'passed', null))->via($this->maria));
    }

    public function test_account_and_drive_notices_are_always_emailed(): void
    {
        $this->maria->forceFill(['email_preferences' => array_fill_keys(array_keys(User::EMAIL_KINDS), false)])->save();
        $account = GoogleDriveConnection::factory()->create();

        $this->assertSame(['database', 'mail'], (new AccountApproved)->via($this->maria));
        $this->assertSame(['database', 'mail'], (new DriveDisconnected($account))->via($this->maria));
        $this->assertSame(['database', 'mail'], (new DriveFull($account))->via($this->maria));
    }

    public function test_members_choose_their_emails_on_my_profile(): void
    {
        $this->actingAs($this->maria);

        Livewire::test('pages::settings.profile')
            ->assertSee('Deadline reminders')
            ->set('emailMe.reminders', false)
            ->assertHasNoErrors();

        $this->assertFalse($this->maria->fresh()->wantsEmail('reminders'));
        $this->assertTrue($this->maria->fresh()->wantsEmail('reviews'));

        // Only known kinds are saved; anything else typed into the request is dropped.
        Livewire::test('pages::settings.profile')->set('emailMe.role', false);
        $this->assertArrayNotHasKey('role', $this->maria->fresh()->email_preferences);
        $this->assertSame(Role::Faculty, $this->maria->fresh()->role);
    }

    public function test_admins_choose_their_emails_only_while_on_a_project(): void
    {
        $admin = $this->admin();

        // Admins can't file or join a project, so these notices never reach them and the switches would do nothing.
        $this->actingAs($admin)->get(route('profile.edit'))->assertOk()->assertDontSee('Email me about');

        // Faculty made admin stay on their old projects, so the notices still reach them and they keep the choice.
        $this->project()->proponents()->create(['user_id' => $admin->id, 'study' => 1, 'role' => 'Staff']);

        $this->get(route('profile.edit'))->assertOk()->assertSee('Email me about');
    }

    public function test_the_list_shows_a_new_notice_when_the_live_count_finds_it(): void
    {
        $this->actingAs($this->maria);
        $page = Livewire::test('pages::notifications.index')->assertSee('No notifications yet.');

        $this->maria->notify(new ConceptReviewed($this->project(), 'passed', null));

        $page->dispatch('open-work-changed', changed: ['unread'])->assertSee('Your concept proposal for “Solar Dryer Study” has passed the Research Office’s review.');
    }

    public function test_changing_a_notice_asks_the_sidebar_to_count_again(): void
    {
        $this->maria->notify(new ConceptReviewed($this->project(), 'passed', null));
        $notice = $this->maria->notifications()->sole();
        $this->actingAs($this->maria);

        Livewire::test('pages::notifications.index')->call('toggleRead', $notice->id)->assertDispatched('open-work-stale');
        Livewire::test('pages::notifications.index')->call('delete', $notice->id)->assertDispatched('open-work-stale');

        $this->get(route('notifications.index'))
            ->assertSeeHtml('x-show="unread"')
            ->assertSeeHtml('x-on:open-work-stale.window="refresh()"');
    }

    public function test_the_first_terminal_report_tells_admins_the_project_is_complete(): void
    {
        Notification::fake();
        $this->fakeDrive();
        $admin = $this->admin();
        $this->maria->update(['name' => 'MARIA DELA PAZ SANTOS']);
        $project = $this->project(['awaiting_review' => false, 'concept_passed' => true, 'status' => 'Mid-year', 'detailed_path' => 'drive-1/submissions/detailed.pdf', 'midyear_path' => 'drive-1/submissions/midyear.pdf']);
        $this->actingAs($this->maria);

        $upload = fn () => Livewire::test('pages::submissions.create', ['submission' => $project->fresh()])
            ->set('documents.terminal', UploadedFile::fake()->create('report.pdf', 100, 'application/pdf'))
            ->call('save')
            ->assertHasNoErrors();

        $upload();
        Notification::assertSentTo($admin, ProjectCompleted::class, fn (ProjectCompleted $notification) => $notification->via($admin) === ['database']
            && $notification->toArray($admin) === [
                'message' => '“Solar Dryer Study” is complete: Maria Dela Paz Santos uploaded the terminal report on '.today()->format('F j, Y').', on time.',
                'url' => route('submissions.index', ['status' => 'not-presented']),
            ]);
        Notification::assertNotSentTo($this->maria, ProjectCompleted::class);

        // A corrected terminal report later is no news.
        $upload();
        Notification::assertSentToTimes($admin, ProjectCompleted::class, 1);
    }

    public function test_a_late_finish_says_how_late(): void
    {
        $project = $this->project(['target_date' => today()->subDays(12), 'terminal_uploaded_at' => now()]);

        $this->assertStringContainsString(', 12 days after its completion date of '.$project->target_date->format('F j, Y').'.', (new ProjectCompleted($project, 'Maria Santos'))->toArray($this->admin())['message']);
    }

    public function test_an_empty_list_says_so(): void
    {
        $this->actingAs($this->maria)
            ->get(route('notifications.index'))
            ->assertSee('No notifications yet.')
            ->assertDontSeeHtml('data-test="mark-all-read"');
    }

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

    public function test_the_portal_copy_arrives_at_once_and_only_the_email_waits_for_the_queue(): void
    {
        // With no queue worker running, the bell and the Notifications page still get the notice.
        config(['queue.default' => 'database']);

        $this->maria->notify(new ConceptReviewed($this->project(), 'passed', null));

        $this->assertSame(1, $this->maria->notifications()->count());
        $this->assertSame(1, DB::table('jobs')->count(), 'Only the email waits for the queue.');
    }

    public function test_a_failed_send_is_retried(): void
    {
        $project = $this->project();

        foreach ([new ConceptReviewed($project, 'passed', null), new ProjectDatesChanged($project), new ProjectDeadline($project, 7)] as $notification) {
            $job = new SendQueuedNotifications(collect([$this->maria]), $notification, ['mail']);

            $this->assertSame(3, $job->tries, $notification::class);
            $this->assertSame(60, $job->backoff(), $notification::class);
        }
    }

    public function test_markdown_in_a_title_or_name_never_becomes_a_link_in_an_email(): void
    {
        $project = $this->project(['title' => '[Review here](https://evil.example)']);

        foreach ([new ConceptReviewed($project, 'returned', '[Fix](https://evil.example)'), new ProjectDatesChanged($project), new ProjectDeadline($project, 7)] as $notification) {
            $html = (string) $notification->toMail($this->maria)->render();

            $this->assertStringNotContainsString('href="https://evil.example"', $html, $notification::class);
            $this->assertStringContainsString('[Review here](https://evil.example)', $html, $notification::class);
        }
    }

    public function test_emails_open_with_a_headline_and_set_the_key_facts_in_bold(): void
    {
        $project = $this->project(['title' => 'Solar*Dryer Study', 'target_date' => today()->addDays(7)]);
        $start = $project->start_date->format('F j, Y');
        $due = $project->target_date->format('F j, Y');

        $emails = [
            [new ConceptReviewed($project, 'returned', 'Cite your sources.'), 'Concept proposal returned for revision', ['“Solar*Dryer Study”', 'returned it for revision', 'Remarks from the Research Office:', 'revise your concept proposal']],
            [new ConceptReviewed($project, 'passed', null), 'Your concept proposal passed review', ['“Solar*Dryer Study”', 'passed', 'upload the detailed proposal']],
            [new ProjectDeadline($project, 7), 'Completion date in 7 days', ['“Solar*Dryer Study”', $due, 'upload the terminal report']],
            [new ProjectDeadline($project, -1), 'Terminal report overdue', ['“Solar*Dryer Study”', $due, 'upload the terminal report']],
            [new ProjectDatesChanged($project), 'Project dates changed', ['“Solar*Dryer Study”', $start, $due]],
            [new AccountApproved, 'Your account has been approved', ['approved', $this->maria->email, 'submit your concept proposal']],
        ];

        foreach ($emails as [$notification, $headline, $bold]) {
            $html = (string) $notification->toMail($this->maria)->render();

            // The headline says what happened, in place of a bare "Hello!".
            $this->assertMatchesRegularExpression('/<h1[^>]*>'.preg_quote($headline, '/').'<\/h1>/', $html, $notification::class);
            $this->assertStringNotContainsString('Hello!', $html, $notification::class);

            // The title, dates, decision and next step stand out; a * in the title stays a *.
            foreach ($bold as $text) {
                $this->assertMatchesRegularExpression('/<strong[^>]*>'.preg_quote($text, '/').'<\/strong>/', $html, $notification::class);
            }
        }
    }

    public function test_reminders_run_on_one_server_only(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'projects:remind'));

        $this->assertTrue($event->onOneServer);
    }
}
