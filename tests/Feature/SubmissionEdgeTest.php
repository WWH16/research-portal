<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Edge cases around a project's documents, dates and proponents that the main submission tests don't walk through.
 */
class SubmissionEdgeTest extends TestCase
{
    use RefreshDatabase;

    private User $maria;

    private User $jose;

    private Submission $project;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('submissions');

        $department = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);
        $this->maria = User::factory()->create(['department_id' => $department->id]);
        $this->jose = User::factory()->create(['department_id' => $department->id]);
        $this->project = $this->maria->submissions()->create([
            'category_id' => Category::create(['name' => 'Computing'])->id,
            'department_id' => $department->id,
            'title' => 'Solar Dryer Study',
            'abstract' => 'An abstract.',
            'start_date' => today(),
            'target_date' => today()->addMonth(),
            'concept_path' => 'submissions/concept.pdf',
            'awaiting_review' => false,
            'concept_passed' => true,
        ]);
        $this->project->proponents()->createMany([
            ['user_id' => $this->maria->id, 'study' => 1, 'role' => 'Leader'],
            ['user_id' => $this->jose->id, 'study' => 1, 'role' => 'Staff'],
        ]);
        $this->project->refresh();
    }

    private function form(?User $as = null)
    {
        return Livewire::actingAs($as ?? $this->maria)->test('pages::submissions.create', ['submission' => $this->project->fresh()]);
    }

    private function pdf(string $name = 'file.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 10, 'application/pdf');
    }

    public function test_replacing_the_terminal_report_after_the_target_date_keeps_it_on_time(): void
    {
        $this->project->update(['detailed_path' => 'submissions/detailed.pdf', 'status' => 'Detailed']);
        $this->project->fresh()->attachDocument('terminal', $this->pdf());
        $this->assertTrue($this->project->fresh()->completedOnTime());

        // A corrected terminal report weeks after the target date is still the same finished project.
        $this->travel(2)->months();
        $this->project->fresh()->attachDocument('terminal', $this->pdf('report-v2.pdf'));

        $this->assertTrue($this->project->fresh()->completedOnTime(), 'A correction must not turn an on-time project late.');
    }

    public function test_on_time_is_judged_to_the_end_of_the_target_day(): void
    {
        $this->project->update(['detailed_path' => 'submissions/detailed.pdf', 'status' => 'Detailed']);

        $this->travelTo($this->project->target_date->copy()->setTime(23, 59));
        $this->assertFalse($this->project->fresh()->isDelayed(), 'Not delayed on the target day itself.');
        $this->project->fresh()->attachDocument('terminal', $this->pdf());
        $this->assertTrue($this->project->fresh()->completedOnTime());

        $this->project->fresh()->update(['terminal_path' => null, 'terminal_uploaded_at' => null, 'status' => 'Detailed']);
        $this->travelTo($this->project->target_date->copy()->addDay()->startOfDay());
        $this->assertTrue($this->project->fresh()->isDelayed());
        $this->project->fresh()->attachDocument('terminal', $this->pdf());
        $this->assertFalse($this->project->fresh()->completedOnTime());
    }

    public function test_an_upload_never_moves_the_status_back(): void
    {
        $this->project->update(['detailed_path' => 'submissions/detailed.pdf', 'terminal_path' => 'submissions/t.pdf', 'terminal_uploaded_at' => now(), 'status' => 'Completed']);

        $this->form()->set('documents.detailed', $this->pdf())->call('save')->assertHasNoErrors();
        $this->assertSame('Completed', $this->project->fresh()->status);

        $this->form()->set('documents.concept', $this->pdf())->call('save')->assertHasNoErrors();
        $project = $this->project->fresh();
        $this->assertSame(['Completed', 'passed'], [$project->status, $project->conceptReview()]);
    }

    public function test_an_edit_cannot_slip_in_new_dates(): void
    {
        $this->form()
            ->set('start_date', today()->addYear()->toDateString())
            ->set('target_date', today()->addYears(2)->toDateString())
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($this->project->fresh()->target_date->isSameDay(today()->addMonth()), 'Only the Research Office moves the dates.');
    }

    public function test_a_document_key_outside_the_three_stages_is_refused(): void
    {
        $this->form()->set('documents.evil', $this->pdf())->call('save')->assertHasErrors('documents');

        $this->assertSame(['submissions/concept.pdf', null, null], array_values($this->project->fresh()->only(['concept_path', 'detailed_path', 'terminal_path'])));
    }

    public function test_a_proponent_removed_while_editing_can_no_longer_save(): void
    {
        $form = $this->form($this->jose)->set('title', 'Taken over');

        $this->project->proponents()->where('user_id', $this->jose->id)->delete();

        $form->call('save')->assertForbidden();
        $this->assertSame('Solar Dryer Study', $this->project->fresh()->title);
    }

    public function test_the_form_cannot_be_pointed_at_someone_elses_project(): void
    {
        $other = User::factory()->create();
        $theirs = $other->submissions()->create(['category_id' => $this->project->category_id, 'department_id' => $this->project->department_id, 'title' => 'Not Yours', 'abstract' => 'A.']);

        try {
            $this->form()->set('submission', $theirs->id)->set('title', 'Hijacked')->call('save');
        } catch (\Throwable) {
            // Livewire refuses to swap the model; either way the other project must be untouched.
        }

        $this->assertSame('Not Yours', $theirs->fresh()->title);
    }

    public function test_only_verified_faculty_can_be_listed_as_proponents(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $unverified = User::factory()->unverified()->create();

        $this->form()->set('proponents.1.user_id', $admin->id)->call('save')->assertHasErrors('proponents.1.user_id');
        $this->form()->set('proponents.1.user_id', $unverified->id)->call('save')->assertHasErrors('proponents.1.user_id');
    }

    public function test_a_listed_proponent_re_verifying_a_changed_email_never_blocks_the_project(): void
    {
        // Changing an email in Profile clears email_verified_at until the new address is confirmed.
        $this->jose->forceFill(['email_verified_at' => null])->save();

        $this->form()
            ->assertSee($this->jose->name)
            ->set('title', 'Solar Dryer Study, revised')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('Solar Dryer Study, revised', $this->project->fresh()->title);
    }

    public function test_the_export_carries_remarks_only_while_the_concept_is_returned(): void
    {
        $this->project->update(['concept_passed' => false, 'remarks' => 'Add the methodology.']);
        $export = fn () => base64_decode(data_get(Livewire::actingAs(User::factory()->create(['role' => 'admin']))->test('pages::submissions.index')->call('export')->effects, 'download.content'));

        $this->assertStringContainsString('Add the methodology.', $export());

        // A corrected concept proposal is in and waits for review, so the old remarks no longer apply.
        $this->project->fresh()->attachDocument('concept', $this->pdf('concept-v2.pdf'));
        $this->assertStringNotContainsString('Add the methodology.', $export());
    }

    public function test_returned_remarks_are_shown_as_text_never_as_html(): void
    {
        $this->project->update(['concept_passed' => false, 'remarks' => '<script>alert(1)</script>']);

        $this->actingAs($this->maria)->get(route('drive.show', $this->project))
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_a_missing_or_unknown_document_is_not_found(): void
    {
        $this->actingAs($this->maria);

        $this->get(route('submissions.document', [$this->project, 'terminal']))->assertNotFound();
        $this->get(route('submissions.document', [$this->project, 'concept']))->assertNotFound(); // Path set, file gone from the disk.
        $this->get('/submissions/'.$this->project->id.'/documents/evil')->assertNotFound();
    }
}
