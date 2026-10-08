<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Department;
use App\Models\ResearchType;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * How a project shows its way through the stages: the step row with what it needs next, and the
 * history of uploads and concept proposal reviews on its Drive page.
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

    public function test_the_next_document_follows_the_uploads(): void
    {
        $this->assertSame('concept', $this->project()->nextDocument());
        $this->assertSame('detailed', $this->project(['concept_path' => 'submissions/concept.pdf'])->nextDocument());
        $this->assertSame('terminal', $this->project(['concept_path' => 'submissions/concept.pdf', 'detailed_path' => 'submissions/detailed.pdf'])->nextDocument());
        $this->assertNull($this->project(['concept_path' => 'submissions/c.pdf', 'detailed_path' => 'submissions/d.pdf', 'terminal_path' => 'submissions/t.pdf'])->nextDocument());
    }

    public function test_each_document_opens_once_the_one_before_it_is_uploaded(): void
    {
        $this->assertSame(['concept'], $this->project()->uploadableStages());
        // The detailed proposal waits for the Research Office to pass the concept proposal.
        $this->assertSame(['concept'], $this->project(['concept_path' => 'submissions/concept.pdf', 'awaiting_review' => true])->uploadableStages());
        $this->assertSame(['concept', 'detailed'], $this->project(['concept_path' => 'submissions/concept.pdf', 'awaiting_review' => false, 'concept_passed' => true])->uploadableStages());
        $this->assertSame(['concept', 'detailed', 'terminal'], $this->project(['concept_path' => 'submissions/concept.pdf', 'concept_passed' => true, 'detailed_path' => 'submissions/detailed.pdf'])->uploadableStages());
        // A concept proposal replaced later doesn't lock a detailed proposal already on file.
        $this->assertSame(['concept', 'detailed', 'terminal'], $this->project(['concept_path' => 'submissions/concept.pdf', 'awaiting_review' => true, 'detailed_path' => 'submissions/detailed.pdf'])->uploadableStages());
    }

    public function test_the_status_follows_the_uploads_and_only_the_concept_proposal_goes_for_review(): void
    {
        $project = $this->project(['awaiting_review' => false]);
        $file = fn (string $name) => UploadedFile::fake()->create($name, 10, 'application/pdf');

        $project->attachDocument('concept', $file('concept.pdf'));
        $this->assertSame(['Concept', true], [$project->status, $project->awaiting_review]);

        $project->update(['awaiting_review' => false]);
        $project->attachDocument('detailed', $file('detailed.pdf'));
        $this->assertSame(['Detailed', false], [$project->status, $project->awaiting_review]);

        $project->attachDocument('terminal', $file('terminal.pdf'));
        $this->assertSame(['Completed', false], [$project->status, $project->awaiting_review]);

        // A passed concept proposal is replaced without another review, and the project stays where it is.
        $project->update(['concept_passed' => true]);
        $project->attachDocument('concept', $file('concept-2.pdf'));
        $this->assertSame(['Completed', false, true], [$project->fresh()->status, $project->fresh()->awaiting_review, $project->fresh()->concept_passed]);
    }

    public function test_review_entries_never_mention_a_status(): void
    {
        $plain = ActivityLog::record('submission.reviewed', $this->project(), [], $this->admin);
        $this->assertSame('Reviewed the concept proposal', $plain->historyHeadline());

        // Entries saved while reviews still set the status keep it in their properties, but never show it. They
        // could have reviewed any document, so they don't claim to be concept proposal reviews.
        $old = ActivityLog::record('submission.reviewed', $this->project(), ['status' => ['Detailed', 'Completed'], 'reopened' => true], $this->admin);
        $this->assertSame('Reviewed the project', $old->historyHeadline());
        $this->assertSame('reviewed a project', $old->summary());
        $this->assertNull($old->change());
        $this->assertSame('', $old->note());

        $remarked = ActivityLog::record('submission.reviewed', $this->project(), ['remarks' => 'Add the budget table.'], $this->admin);
        $this->assertSame('Reviewed the concept proposal and left remarks', $remarked->historyHeadline());

        $passed = ActivityLog::record('submission.reviewed', $this->project(), ['decision' => 'passed'], $this->admin);
        $this->assertSame(['Passed the concept proposal', 'passed a concept proposal'], [$passed->historyHeadline(), $passed->summary()]);

        $returned = ActivityLog::record('submission.reviewed', $this->project(), ['decision' => 'returned', 'remarks' => 'Add the budget table.'], $this->admin);
        $this->assertSame(['Returned the concept proposal for revision', 'returned a concept proposal for revision'], [$returned->historyHeadline(), $returned->summary()]);
    }

    public function test_a_projects_file_times_are_read_once_per_request(): void
    {
        Storage::disk('submissions')->put('submissions/concept.pdf', '%PDF');
        $project = $this->project(['awaiting_review' => true, 'concept_path' => 'submissions/concept.pdf']);

        $this->assertSame(['concept'], array_keys($project->uploadTimes()));

        // The tracker and the document list both need the times; later calls reuse them instead of asking the disk again.
        Storage::disk('submissions')->delete('submissions/concept.pdf');
        $this->assertSame(['concept'], array_keys($project->uploadTimes()));

        // A new upload changes the files, so the times are read afresh.
        $project->attachDocument('detailed', UploadedFile::fake()->create('detailed.pdf', 10, 'application/pdf'));
        $this->assertSame(['detailed'], array_keys($project->uploadTimes()));
    }

    public function test_the_drive_page_and_edit_form_show_the_stages_and_the_next_step(): void
    {
        $project = $this->project(['awaiting_review' => false, 'concept_passed' => true, 'concept_path' => 'submissions/concept.pdf']);
        $this->actingAs($this->maria);

        $this->get(route('drive.show', $project))
            ->assertOk()
            ->assertSee('data-test="project-stages"', false)
            ->assertSeeInOrder(['aria-current="step"', 'Concept', 'Detailed', 'Completed'], false)
            ->assertSee('Next: upload the detailed proposal.');

        // The edit route reuses the create component (see routes/web.php).
        Livewire::test('pages::submissions.create', ['submission' => $project])
            ->assertSee('Next: upload the detailed proposal.');

        // A new proposal has no stage yet.
        Livewire::test('pages::submissions.create')->assertDontSee('data-test="project-stages"', false);
    }

    public function test_stages_past_the_next_one_show_as_locked(): void
    {
        $project = $this->project(['awaiting_review' => true, 'concept_path' => 'submissions/concept.pdf']);

        $this->actingAs($this->maria)
            ->get(route('drive.show', $project))
            ->assertSeeInOrder(['Concept', '(current stage)', 'Detailed', '(locked)', 'Completed', '(locked)']);

        $project->update(['awaiting_review' => false, 'concept_passed' => true]);
        $this->get(route('drive.show', $project))
            ->assertSeeInOrder(['Concept', '(current stage)', 'Detailed', '(next)', 'Completed', '(locked)']);
    }

    public function test_the_next_step_says_what_waits_for_review_and_when_everything_is_uploaded(): void
    {
        $this->actingAs($this->maria);

        $waiting = $this->project(['awaiting_review' => true, 'concept_path' => 'submissions/concept.pdf']);
        $this->get(route('drive.show', $waiting))
            ->assertSee('The concept proposal is waiting for the Research Office to review.')
            ->assertSee('The detailed proposal opens once it passes.')
            ->assertDontSee('Next: upload the detailed proposal.');

        $returned = $this->project(['awaiting_review' => false, 'concept_path' => 'submissions/concept.pdf', 'remarks' => 'Add the budget table.']);
        $this->get(route('drive.show', $returned))->assertSee('The Research Office returned the concept proposal for revision.');

        $passed = $this->project(['awaiting_review' => false, 'concept_passed' => true, 'concept_path' => 'submissions/concept.pdf']);
        $this->get(route('drive.show', $passed))->assertSee('Next: upload the detailed proposal.');

        // The detailed proposal and terminal report are filed, never reviewed.
        $detailed = $this->project(['status' => 'Detailed', 'awaiting_review' => false, 'concept_passed' => true, 'concept_path' => 'submissions/concept.pdf', 'detailed_path' => 'submissions/detailed.pdf']);
        $this->get(route('drive.show', $detailed))
            ->assertSee('Next: upload the terminal report.')
            ->assertDontSee('waiting for the Research Office');

        $done = $this->project(['status' => 'Completed', 'awaiting_review' => false, 'concept_passed' => true, 'concept_path' => 'submissions/c.pdf', 'detailed_path' => 'submissions/d.pdf', 'terminal_path' => 'submissions/t.pdf']);
        $this->get(route('drive.show', $done))->assertSee('All documents are uploaded.');
    }

    public function test_the_review_panel_shows_the_stages_without_repeating_what_to_review(): void
    {
        $project = $this->project(['awaiting_review' => true, 'concept_path' => 'submissions/concept.pdf']);
        $this->actingAs($this->admin);

        Livewire::test('pages::reviews.index')
            ->call('review', $project->id)
            ->assertSee('data-test="project-stages"', false)
            ->assertDontSee('data-test="project-next-step"', false);
    }

    public function test_the_drive_page_lists_uploads_and_reviews_newest_first(): void
    {
        $project = $this->project(['status' => 'Concept', 'awaiting_review' => false]);

        ActivityLog::record('submission.created', $project, ['documents' => ['concept']], $this->maria);
        ActivityLog::record('submission.reviewed', $project, ['remarks' => 'Add the budget table.'], $this->admin);
        ActivityLog::record('submission.updated', $project, ['documents' => ['concept'], 'replaced' => ['concept']], $this->maria);
        ActivityLog::record('submission.reviewed', $project, [], $this->admin);

        $this->actingAs($this->maria)
            ->get(route('drive.show', $project))
            ->assertSee('data-test="drive-history"', false)
            ->assertSeeInOrder([
                'Research Office', 'Reviewed the concept proposal',
                'Maria Santos', 'Updated the project', 'Replaced the concept proposal',
                'Research Office', 'Reviewed the concept proposal and left remarks', 'Remarks: Add the budget table.',
                'Maria Santos', 'Submitted the proposal', 'Uploaded the concept proposal',
            ])
            // Reviews are the office's, not one staff member's.
            ->assertDontSee('Ana Admin')
            // Five entries or fewer need no toggle.
            ->assertDontSee('data-test="drive-history-toggle"', false);
    }

    public function test_a_long_history_folds_entries_after_the_latest_five(): void
    {
        $project = $this->project(['awaiting_review' => false]);

        foreach (range(1, 7) as $i) {
            ActivityLog::record('submission.updated', $project, [], $this->maria);
        }

        $response = $this->actingAs($this->maria)->get(route('drive.show', $project));

        $response->assertSee('Show 2 earlier entries');
        // All seven render; only the two oldest start hidden.
        $this->assertSame(7, substr_count($response->getContent(), '<li class="py-3"'));
        $this->assertSame(2, preg_match_all('/<li class="py-3"\s+x-show="all"/', $response->getContent()));
    }

    public function test_history_holds_only_this_projects_entries(): void
    {
        $project = $this->project(['awaiting_review' => false]);
        $other = $this->project(['awaiting_review' => false]);

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
        $project = $this->project(['awaiting_review' => false]);
        ActivityLog::record('submission.reviewed', $project, ['remarks' => '<b>Fix this</b>'], $this->admin);

        $this->actingAs($this->maria)
            ->get(route('drive.show', $project))
            ->assertSee('Remarks: <b>Fix this</b>')
            ->assertDontSee('<b>Fix this</b>', false);
    }

    public function test_a_project_with_no_recorded_history_says_so(): void
    {
        $project = $this->project(['awaiting_review' => false]);

        $this->actingAs($this->maria)
            ->get(route('drive.show', $project))
            ->assertSee('Nothing recorded yet.')
            ->assertDontSee('data-test="drive-history"', false);
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
