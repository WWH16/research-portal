<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\ConceptAwaitingReview;
use App\Notifications\ConceptReviewed;
use App\Notifications\ProjectDeadline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
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
}
