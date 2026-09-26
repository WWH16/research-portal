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

    public function test_admins_cannot_open_the_submit_form(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('submissions.create'))
            ->assertForbidden()
            ->assertSee('Admins monitor submissions and don’t submit proposals.');
    }

    public function test_admins_monitor_every_submission_without_a_new_button(): void
    {
        [$maria, $jose] = $this->twoFacultyWithSubmissions();

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('submissions.index'))
            ->assertOk()
            ->assertSee('Every proposal filed across the portal.')
            ->assertSee('Maria Proposal')
            ->assertSee('Jose Proposal')
            ->assertSee($maria->name)
            ->assertSee($jose->name)
            ->assertSee('CCS')
            ->assertDontSee(route('submissions.create'), escape: false);
    }

    public function test_faculty_see_only_their_own_submissions(): void
    {
        [$maria] = $this->twoFacultyWithSubmissions();

        $this->actingAs($maria);

        $this->get(route('submissions.index'))
            ->assertOk()
            ->assertSee('Maria Proposal')
            ->assertDontSee('Jose Proposal')
            ->assertSee(route('submissions.create'), escape: false);
    }

    public function test_form_shows_the_members_own_department_instead_of_a_picker(): void
    {
        $department = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);
        Department::create(['code' => 'CBM', 'name' => 'College of Business and Management']);

        $this->actingAs(User::factory()->create(['department_id' => $department->id]));

        Livewire::test('pages::submissions.create')
            ->assertSee('CCS · College of Computer Studies')
            ->assertSee('From your profile')
            ->assertDontSee('College of Business and Management')
            ->assertDontSeeHtml('wire:model="department_id"');
    }

    public function test_members_without_a_department_cannot_submit(): void
    {
        Storage::fake('local');

        $researchType = ResearchType::create(['name' => 'Thesis']);
        $category = Category::create(['name' => 'Computing']);

        $this->actingAs(User::factory()->create(['department_id' => null]));

        Livewire::test('pages::submissions.create')
            ->assertSee('Your account has no department yet')
            ->set('title', 'Sample Proposal')
            ->set('abstract', 'An abstract.')
            ->set('research_type_id', $researchType->id)
            ->set('category_id', $category->id)
            ->set('document', UploadedFile::fake()->create('proposal.pdf', 500, 'application/pdf'))
            ->call('save')
            ->assertHasErrors(['department']);

        $this->assertSame(0, Submission::count());
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
        $department = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);

        $this->actingAs(User::factory()->create(['department_id' => $department->id]));

        Livewire::test('pages::submissions.create')
            ->call('save')
            ->assertHasErrors([
                'title' => 'required',
                'abstract' => 'required',
                'research_type_id' => 'required',
                'category_id' => 'required',
                'document' => 'required',
            ]);

        $this->assertSame(0, Submission::count());
    }

    public function test_document_must_be_a_pdf_under_ten_megabytes(): void
    {
        Storage::fake('local');

        $department = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);

        $this->actingAs(User::factory()->create(['department_id' => $department->id]));

        Livewire::test('pages::submissions.create')
            ->set('document', UploadedFile::fake()->create('proposal.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'))
            ->call('save')
            ->assertHasErrors(['document' => 'mimes']);

        Livewire::test('pages::submissions.create')
            ->set('document', UploadedFile::fake()->create('proposal.pdf', 10241, 'application/pdf'))
            ->call('save')
            ->assertHasErrors(['document' => 'max']);
    }

    /**
     * @return array{User, User}
     */
    private function twoFacultyWithSubmissions(): array
    {
        $department = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);
        $researchType = ResearchType::create(['name' => 'Thesis']);
        $category = Category::create(['name' => 'Computing']);

        $maria = User::factory()->create(['name' => 'Maria Santos', 'department_id' => $department->id]);
        $jose = User::factory()->create(['name' => 'Jose Reyes', 'department_id' => $department->id]);

        foreach ([[$maria, 'Maria Proposal'], [$jose, 'Jose Proposal']] as [$user, $title]) {
            $user->submissions()->create([
                'research_type_id' => $researchType->id,
                'category_id' => $category->id,
                'department_id' => $department->id,
                'title' => $title,
                'file_path' => 'submissions/sample.pdf',
            ]);
        }

        return [$maria, $jose];
    }
}
