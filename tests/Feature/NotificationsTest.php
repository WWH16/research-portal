<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\GoogleDriveConnection;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\AccountApproved;
use App\Notifications\ConceptAwaitingReview;
use App\Notifications\ConceptReviewed;
use App\Notifications\DriveDisconnected;
use App\Notifications\DriveFull;
use App\Notifications\ProjectDatesChanged;
use App\Notifications\ProjectDeadline;
use App\Notifications\SignupAwaitingApproval;
use App\Services\DocumentStorage;
use Illuminate\Auth\Events\Verified;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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

    public function test_a_new_concept_proposal_tells_every_admin(): void
    {
        Notification::fake();
        $this->fakeDrive();
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
            && $notification->uploader === 'M Santos' && str_starts_with($notification->toArray($first)['message'], 'New concept proposal to review: Solar Dryer Study, from M Santos.')
            && $notification->toArray($first)['url'] === route('reviews.index', ['review' => $project->id]));
        Notification::assertNotSentTo($this->maria, ConceptAwaitingReview::class);
    }

    public function test_a_verified_sign_up_tells_every_admin_in_the_portal_only(): void
    {
        Notification::fake();
        [$first, $second] = [$this->admin(), $this->admin()];
        $signUp = User::factory()->pending()->create(['name' => 'Juan Dela Cruz', 'email' => 'juan@isu.edu.ph']);

        event(new Verified($signUp));

        Notification::assertSentTo([$first, $second], SignupAwaitingApproval::class, fn (SignupAwaitingApproval $notification) => $notification->user->is($signUp)
            && $notification->via($first) === ['database']
            && $notification->toArray($first) === [
                'message' => 'New sign-up waiting for approval: JD Cruz (juan@isu.edu.ph).',
                'url' => route('users.index', ['status' => 'pending']),
            ]);
        Notification::assertNotSentTo($this->maria, SignupAwaitingApproval::class);
    }

    public function test_an_approved_member_verifying_a_new_email_tells_no_admin(): void
    {
        Notification::fake();
        $this->admin();

        event(new Verified($this->maria));

        Notification::assertNothingSent();
    }

    public function test_a_concept_reuploaded_while_waiting_tells_admins_again(): void
    {
        Notification::fake();
        $this->fakeDrive();
        $admin = $this->admin();
        $project = $this->project();
        $this->actingAs($this->maria);

        Livewire::test('pages::submissions.create', ['submission' => $project])
            ->set('documents.concept', UploadedFile::fake()->create('proposal-v2.pdf', 500, 'application/pdf'))
            ->call('save')
            ->assertHasNoErrors();

        // Worded apart from the first notice, so the two don't read as a duplicate.
        Notification::assertSentTo($admin, ConceptAwaitingReview::class, fn (ConceptAwaitingReview $notification) => str_starts_with($notification->toArray($admin)['message'], 'Corrected concept proposal to review: Solar Dryer Study, from M Santos.'));
        Notification::assertSentToTimes($admin, ConceptAwaitingReview::class, 1);
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

        Notification::assertNotSentTo($admin, ConceptAwaitingReview::class);
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

    public function test_a_deleted_notice_comes_back_exactly_on_undo(): void
    {
        $project = $this->project();
        $this->maria->notify(new ConceptReviewed($project, 'passed', null));
        $before = $this->maria->notifications()->sole()->getRawOriginal();
        $this->actingAs($this->maria);

        $page = Livewire::test('pages::notifications.index')
            ->call('delete', $before['id'])
            ->assertSee('Notice deleted.')
            ->assertSee('No notifications yet.');
        $this->assertSame(0, $this->maria->notifications()->count());

        $page->call('undoDelete')->assertSee('Concept proposal passed: Solar Dryer Study');
        $this->assertSame($before, $this->maria->notifications()->sole()->getRawOriginal());
    }

    public function test_delete_all_read_keeps_unread_notices_and_can_be_undone(): void
    {
        $project = $this->project();
        $this->maria->notify(new ConceptReviewed($project, 'passed', null));
        $this->maria->notify(new ConceptReviewed($project, 'returned', 'Narrow the scope.'));
        $this->maria->notify(new ProjectDeadline($project, 7));
        $this->maria->notifications()->where('data->message', 'like', 'Concept%')->update(['read_at' => now()]);
        $this->actingAs($this->maria);

        $page = Livewire::test('pages::notifications.index')
            ->assertSee('Delete 2 read notices?')
            ->call('deleteAllRead')
            ->assertSee('2 notices deleted.');
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

    public function test_an_approved_member_hears_they_can_sign_in(): void
    {
        Notification::fake();
        $waiting = User::factory()->pending()->create();
        $this->actingAs($this->admin());

        Livewire::test('pages::users.index')->call('approve', $waiting->id);

        Notification::assertSentTo($waiting, AccountApproved::class, fn (AccountApproved $notification) => $notification->via($waiting) === ['database', 'mail']
            && $notification->toArray($waiting) === ['message' => 'Your account is approved. You can now sign in to the Faculty Research Portal.', 'url' => route('dashboard')]);
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
            'message' => 'New dates for Solar Dryer Study: starts '.$project->start_date->format('M j, Y').', due '.$due->format('M j, Y').'.',
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

    public function test_every_notice_that_emails_has_an_email(): void
    {
        // A notice without its own email gets the portal's plain one, with the same sentence and link.
        $mail = (new DriveFull(GoogleDriveConnection::factory()->create(['email' => 'office@isu.edu.ph'])))->toMail($this->maria);

        $this->assertStringContainsString('Google Drive office@isu.edu.ph is full', $mail->introLines[0]);
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
            ->assertDontSee('New concept proposals to review')
            ->set('emailMe.reminders', false)
            ->assertHasNoErrors();

        $this->assertFalse($this->maria->fresh()->wantsEmail('reminders'));
        $this->assertTrue($this->maria->fresh()->wantsEmail('reviews'));

        // Only known kinds are saved; anything else typed into the request is dropped.
        Livewire::test('pages::settings.profile')->set('emailMe.role', false);
        $this->assertArrayNotHasKey('role', $this->maria->fresh()->email_preferences);
        $this->assertSame('faculty', $this->maria->fresh()->role);

        $this->actingAs($this->admin());
        Livewire::test('pages::settings.profile')->assertSee('New concept proposals to review');
    }

    public function test_the_list_shows_a_new_notice_when_the_live_count_finds_it(): void
    {
        $this->actingAs($this->maria);
        $page = Livewire::test('pages::notifications.index')->assertSee('No notifications yet.');

        $this->maria->notify(new ConceptReviewed($this->project(), 'passed', null));

        $page->dispatch('open-work-changed', changed: ['unread'])->assertSee('Concept proposal passed: Solar Dryer Study');
    }

    public function test_changing_a_notice_asks_the_sidebar_to_count_again(): void
    {
        $this->maria->notify(new ConceptReviewed($this->project(), 'passed', null));
        $notice = $this->maria->notifications()->sole();
        $this->actingAs($this->maria);

        Livewire::test('pages::notifications.index')->call('toggleRead', $notice->id)->assertDispatched('notifications-changed');
        Livewire::test('pages::notifications.index')->call('delete', $notice->id)->assertDispatched('notifications-changed');

        $this->get(route('notifications.index'))
            ->assertSeeHtml('x-show="unread"')
            ->assertSeeHtml('x-on:notifications-changed.window="refresh()"');
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

    public function test_a_concept_notice_queued_before_corrections_were_recorded_still_sends(): void
    {
        // A queued copy leaves out a property still at its default, so this is the same shape as one saved before
        // $corrected existed, which has no value for it at all.
        $queued = serialize(new ConceptAwaitingReview($this->project(), 'M Santos'));
        $this->assertStringNotContainsString('corrected', $queued);

        $this->assertSame('New concept proposal to review: Solar Dryer Study, from M Santos.', unserialize($queued)->toArray($this->maria)['message']);
    }

    public function test_a_failed_send_is_retried(): void
    {
        $project = $this->project();

        foreach ([new ConceptReviewed($project, 'passed', null), new ConceptAwaitingReview($project, 'Maria Santos'), new ProjectDeadline($project, 7)] as $notification) {
            $job = new SendQueuedNotifications(collect([$this->maria]), $notification, ['mail']);

            $this->assertSame(3, $job->tries, $notification::class);
            $this->assertSame(60, $job->backoff(), $notification::class);
        }
    }

    public function test_markdown_in_a_title_or_name_never_becomes_a_link_in_an_email(): void
    {
        $project = $this->project(['title' => '[Review here](https://evil.example)']);

        foreach ([new ConceptReviewed($project, 'returned', '[Fix](https://evil.example)'), new ConceptAwaitingReview($project, '[Admin](https://evil.example)'), new ProjectDeadline($project, 7)] as $notification) {
            $html = (string) $notification->toMail($this->maria)->render();

            $this->assertStringNotContainsString('href="https://evil.example"', $html, $notification::class);
            $this->assertStringContainsString('[Review here](https://evil.example)', $html, $notification::class);
        }
    }

    public function test_reminders_run_on_one_server_only(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'projects:remind'));

        $this->assertTrue($event->onOneServer);
    }
}
