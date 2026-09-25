<?php

namespace Tests\Feature;

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

class SubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('submissions.create'))->assertRedirect(route('login'));
        $this->get(route('submissions.index'))->assertRedirect(route('login'));
    }

    public function test_create_page_is_displayed(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('submissions.create'))->assertOk()->assertSee('Submit Proposal');
    }

    public function test_department_defaults_to_the_users_department(): void
    {
        $department = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);

        $this->actingAs(User::factory()->create(['department_id' => $department->id]));

        Livewire::test('pages::submissions.create')
            ->assertSet('department_id', $department->id);
    }

    public function test_proposal_can_be_submitted(): void
    {
        Storage::fake('local');

        $department = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);
        $researchType = ResearchType::create(['name' => 'Thesis']);
        $category = Category::create(['name' => 'Computing']);
        $user = User::factory()->create(['department_id' => $department->id]);

        $this->actingAs($user);

        Livewire::test('pages::submissions.create')
            ->set('title', 'Sample Proposal')
            ->set('abstract', 'An abstract.')
            ->set('research_type_id', $researchType->id)
            ->set('category_id', $category->id)
            ->set('document', UploadedFile::fake()->create('proposal.pdf', 500, 'application/pdf'))
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('submissions.index'));

        $submission = Submission::sole();

        $this->assertSame($user->id, $submission->user_id);
        $this->assertSame($department->id, $submission->department_id);
        $this->assertSame('Pending', $submission->fresh()->status);
        $this->assertNull($submission->designation);
        Storage::disk('local')->assertExists($submission->file_path);

        $this->get(route('submissions.index'))->assertSee('Proposal submitted.')->assertSee('Sample Proposal');
    }

    public function test_required_fields_are_validated(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::submissions.create')
            ->call('save')
            ->assertHasErrors([
                'title' => 'required',
                'abstract' => 'required',
                'research_type_id' => 'required',
                'department_id' => 'required',
                'category_id' => 'required',
                'document' => 'required',
            ]);

        $this->assertSame(0, Submission::count());
    }

    public function test_document_must_be_a_pdf_under_ten_megabytes(): void
    {
        Storage::fake('local');

        $this->actingAs(User::factory()->create());

        Livewire::test('pages::submissions.create')
            ->set('document', UploadedFile::fake()->create('proposal.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'))
            ->call('save')
            ->assertHasErrors(['document' => 'mimes']);

        Livewire::test('pages::submissions.create')
            ->set('document', UploadedFile::fake()->create('proposal.pdf', 10241, 'application/pdf'))
            ->call('save')
            ->assertHasErrors(['document' => 'max']);
    }
}
