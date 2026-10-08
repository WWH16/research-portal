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

class ConceptReviewEdgeTest extends TestCase
{
    use RefreshDatabase;

    private User $maria;

    private Submission $project;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('submissions');

        $department = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);
        $this->maria = User::factory()->create(['department_id' => $department->id]);
        $this->project = $this->maria->submissions()->create([
            'category_id' => Category::create(['name' => 'Computing'])->id,
            'department_id' => $department->id,
            'title' => 'Solar Dryer Study',
            'abstract' => 'An abstract.',
            'start_date' => now(),
            'target_date' => now()->addYear(),
            'concept_path' => 'submissions/concept.pdf',
        ]);
        $this->project->proponents()->create(['user_id' => $this->maria->id, 'study' => 1, 'role' => 'Leader']);
        $this->project->refresh();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function upload(array $documents)
    {
        $form = Livewire::actingAs($this->maria)->test('pages::submissions.create', ['submission' => $this->project->fresh()]);
        foreach ($documents as $stage) {
            $form->set("documents.$stage", UploadedFile::fake()->create("$stage.pdf", 10, 'application/pdf'));
        }

        return $form->call('save');
    }

    public function test_a_new_project_waits_for_review_at_concept(): void
    {
        $this->assertSame('pending', $this->project->conceptReview());
        $this->assertSame('Concept', $this->project->status);
        $this->assertSame(['concept'], $this->project->uploadableStages());
    }

    public function test_whitespace_only_remarks_cannot_return_a_concept(): void
    {
        Livewire::actingAs($this->admin())->test('pages::submissions.index')
            ->call('review', $this->project->id)
            ->set('remarks', "   \n  ")
            ->call('saveReview', 'returned')
            ->assertHasErrors(['remarks' => 'required']);

        $this->assertSame('pending', $this->project->fresh()->conceptReview());
    }

    public function test_remarks_over_2000_characters_are_refused(): void
    {
        Livewire::actingAs($this->admin())->test('pages::submissions.index')
            ->call('review', $this->project->id)
            ->set('remarks', str_repeat('a', 2001))
            ->call('saveReview', 'returned')
            ->assertHasErrors(['remarks' => 'max']);
    }

    public function test_two_admins_cannot_both_decide_the_same_upload(): void
    {
        $first = Livewire::actingAs($this->admin())->test('pages::submissions.index')->call('review', $this->project->id);
        $second = Livewire::actingAs($this->admin())->test('pages::submissions.index')->call('review', $this->project->id);

        $first->call('saveReview', 'passed')->assertHasNoErrors();
        $second->set('remarks', 'Redo it.')->call('saveReview', 'returned')->assertHasErrors('review');

        $this->assertSame('passed', $this->project->fresh()->conceptReview());
    }

    public function test_a_passed_concept_and_a_detailed_proposal_can_come_in_one_save(): void
    {
        $this->project->update(['awaiting_review' => false, 'concept_passed' => true]);

        $this->upload(['concept', 'detailed'])->assertHasNoErrors();

        $project = $this->project->fresh();
        $this->assertSame(['passed', 'Detailed'], [$project->conceptReview(), $project->status]);
        $this->assertSame('Project updated. The document is uploaded.', session('status'), 'A passed concept proposal is not sent for review again.');
    }

    public function test_save_waits_for_uploads_still_in_progress(): void
    {
        // Livewire sets no loading state during the file transfer itself, so the form holds Save on the upload events.
        Livewire::actingAs($this->maria)->test('pages::submissions.create', ['submission' => $this->project])
            ->assertSeeHtml('x-on:livewire-upload-start="uploading++"')
            ->assertSeeHtml('x-on:livewire-upload-error="uploading--"')
            ->assertSeeHtml('wire:submit="save"')
            ->assertSeeHtml('x-on:submit.capture="uploading && ($event.preventDefault(), $event.stopImmediatePropagation())"');
    }

    public function test_a_refused_file_shows_once_in_a_toast_and_holds_the_next_save_once(): void
    {
        $this->project->update(['awaiting_review' => false, 'concept_passed' => true]);
        $refused = fn ($name, $params) => $params['dataset']['variant'] === 'danger' && str_contains($params['slots']['text'], 'detailed proposal');

        // What Livewire calls when the server refuses the file, such as one over PHP's upload_max_filesize.
        $form = Livewire::actingAs($this->maria)->test('pages::submissions.create', ['submission' => $this->project])
            ->set('title', 'Solar Dryer Study, revised')
            ->call('_uploadErrored', 'documents.detailed', null, false)
            ->assertHasErrors('documents.detailed')
            ->assertDispatched('toast-show', $refused)
            ->assertDontSee('documents.detailed failed to upload');
        $this->assertSame(1, substr_count($form->html(), 'The detailed proposal couldn’t be uploaded.'), 'Shown under the field only, not again below the list.');
        // A file over the limit and a dropped connection look the same to the server, so the message names both.
        $form->assertSee('PDF or Word file of')->assertSee('your connection is on');

        // Saving now would report success without the document, so it stops and says why.
        $form->call('save')->assertHasErrors('documents.detailed')->assertDispatched('toast-show', $refused);
        $this->assertSame('Solar Dryer Study', $this->project->fresh()->title);

        // Saving again keeps the other changes without that document, so a member who gives up on it isn't stuck.
        $form->call('save')->assertHasNoErrors();
        $this->assertSame('Solar Dryer Study, revised', $this->project->fresh()->title);
        $this->assertNull($this->project->fresh()->detailed_path);
    }

    public function test_passing_a_revision_drops_the_remarks_of_the_earlier_return(): void
    {
        $admin = $this->admin();
        Livewire::actingAs($admin)->test('pages::submissions.index')
            ->call('review', $this->project->id)->set('remarks', 'Fix the objectives.')->call('saveReview', 'returned');

        $this->project->refresh()->attachDocument('concept', UploadedFile::fake()->create('concept-v2.pdf', 10, 'application/pdf'));

        Livewire::actingAs($admin)->test('pages::submissions.index')
            ->call('review', $this->project->id)
            ->assertSet('remarks', '')
            ->call('saveReview', 'passed')
            ->assertHasNoErrors();

        $project = $this->project->fresh();
        $this->assertSame('passed', $project->conceptReview());
        $this->assertNull($project->remarks);
        $this->actingAs($this->maria)->get(route('drive.show', $project))->assertDontSeeHtml('data-test="concept-remarks"');
    }

    public function test_a_pass_saves_no_remarks_and_the_decided_panel_has_no_remarks_box(): void
    {
        $panel = Livewire::actingAs($this->admin())->test('pages::submissions.index')
            ->call('review', $this->project->id)
            ->assertSeeHtml('wire:model="remarks"')
            ->set('remarks', 'Nice work.')
            ->call('saveReview', 'passed')
            ->assertHasNoErrors();

        $this->assertNull($this->project->fresh()->remarks);
        $panel->call('review', $this->project->id)->assertDontSeeHtml('wire:model="remarks"');
    }

    public function test_replacing_the_concept_late_keeps_the_stage_and_the_later_documents(): void
    {
        $this->project->update(['awaiting_review' => false, 'concept_passed' => true]);
        $this->upload(['detailed']);
        $this->upload(['concept'])->assertHasNoErrors();

        $project = $this->project->fresh();
        $this->assertSame('Detailed', $project->status, 'A status never moves back.');
        $this->assertSame('passed', $project->conceptReview(), 'A passed concept proposal stays passed.');
        $this->assertSame(['concept', 'detailed', 'terminal'], $project->uploadableStages());
    }

    public function test_a_review_of_a_project_that_is_gone_is_not_found(): void
    {
        $panel = Livewire::actingAs($this->admin())->test('pages::submissions.index')->call('review', $this->project->id);
        $this->project->proponents()->delete();
        $this->project->delete();

        $panel->call('saveReview', 'passed')->assertNotFound();
    }
}
