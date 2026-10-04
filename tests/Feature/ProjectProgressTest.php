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
        $this->assertSame('concept', $this->project(['status' => 'Submitted', 'awaiting_review' => true, 'concept_path' => 'submissions/concept.pdf'])->documentUnderReview());
        $this->assertNull($this->project(['status' => 'Submitted', 'awaiting_review' => false, 'concept_path' => 'submissions/concept.pdf'])->documentUnderReview());
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

        $this->assertSame('detailed', $project->documentUnderReview());
    }

    public function test_an_upload_missing_from_disk_is_still_named_by_its_own_stage(): void
    {
        // Only the detailed proposal is on file, but its file is gone from the disk.
        $project = $this->project(['status' => 'Submitted', 'awaiting_review' => true, 'detailed_path' => 'submissions/lost.pdf']);

        $this->assertSame('detailed', $project->documentUnderReview());
    }

    public function test_a_completed_project_with_a_new_upload_says_it_waits_for_review(): void
    {
        Storage::disk('submissions')->put('submissions/terminal.pdf', '%PDF');
        $project = $this->project(['status' => 'Completed', 'awaiting_review' => true, 'terminal_path' => 'submissions/terminal.pdf']);

        $this->actingAs($this->maria)
            ->get(route('drive.show', $project))
            ->assertSee('The terminal report is waiting for the Research Office to review.')
            ->assertDontSee('All stages are done.');
    }

    public function test_a_review_entry_without_a_status_still_reads_cleanly(): void
    {
        $entry = ActivityLog::record('submission.reviewed', $this->project(), [], $this->admin);

        $this->assertSame('Reviewed the project', $entry->historyHeadline());
    }

    public function test_reopening_for_uploads_reads_as_its_own_step(): void
    {
        $entry = ActivityLog::record('submission.reviewed', $this->project(), ['status' => ['Completed', 'Completed'], 'reopened' => true], $this->admin);

        $this->assertSame('Reopened it for a corrected upload', $entry->historyHeadline());
        $this->assertSame('Reopened for a corrected upload', $entry->note());
    }

    public function test_a_status_outside_the_stages_counts_as_submitted(): void
    {
        $project = $this->project(['status' => 'Pending', 'awaiting_review' => false]);

        $this->assertSame(0, $project->stageIndex());
        $this->assertSame('Concept', $project->nextStep()['stage']);
    }

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

    public function test_stages_past_the_next_one_show_as_locked(): void
    {
        $project = $this->project(['status' => 'Submitted', 'awaiting_review' => true, 'concept_path' => 'submissions/concept.pdf']);

        $this->actingAs($this->maria)
            ->get(route('drive.show', $project))
            ->assertSeeInOrder(['Concept', '(next)', 'Detailed', '(locked)', 'Completed', '(locked)']);
    }

    public function test_the_next_step_says_when_a_document_was_returned_or_all_stages_are_done(): void
    {
        $this->actingAs($this->maria);

        $returned = $this->project(['status' => 'Concept', 'awaiting_review' => false, 'detailed_path' => 'submissions/detailed.pdf', 'remarks' => 'Add the budget table.']);
        $this->get(route('drive.show', $returned))->assertSee('Returned with remarks. Waiting for a corrected detailed proposal.');

        // Moved back, or accepted only as far as Concept, without remarks: nothing to go looking for.
        $unremarked = $this->project(['status' => 'Concept', 'awaiting_review' => false, 'detailed_path' => 'submissions/detailed.pdf']);
        $this->get(route('drive.show', $unremarked))
            ->assertSee('The detailed proposal was reviewed without moving the project to Detailed. Upload a new one when it’s ready.')
            ->assertDontSee('Returned with remarks');

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
