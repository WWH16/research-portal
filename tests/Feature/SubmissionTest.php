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
use Illuminate\Support\Str;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Livewire;
use Tests\TestCase;

class SubmissionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Freeze the clock so a test that runs across midnight or New Year can't see two different "today"s.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('submissions.create'))->assertRedirect(route('login'));
        $this->get(route('submissions.index'))->assertRedirect(route('login'));
    }

    public function test_create_page_is_displayed(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('submissions.create'))->assertOk()->assertSee('Submit Proposal')->assertSee('Proponents');
    }

    public function test_admins_cannot_open_the_submit_form(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('submissions.create'))
            ->assertForbidden()
            ->assertSee('Admins monitor submissions and don’t submit proposals.');
    }

    public function test_admins_monitor_every_project_without_a_new_button(): void
    {
        [$maria, $jose] = $this->twoFacultyWithSubmissions();

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('submissions.index'))
            ->assertOk()
            ->assertSee('Every project filed across the portal.')
            ->assertSee('Maria Proposal')
            ->assertSee('Jose Proposal')
            ->assertSee($maria->name)
            ->assertSee($jose->name)
            ->assertSeeHtml('data-test="department-column"')
            ->assertDontSee(route('submissions.create'), escape: false);
    }

    public function test_faculty_see_only_projects_they_are_on(): void
    {
        [$maria] = $this->twoFacultyWithSubmissions();

        $this->actingAs($maria);

        $this->get(route('submissions.index'))
            ->assertOk()
            ->assertSee('Maria Proposal')
            ->assertDontSee('Jose Proposal')
            ->assertDontSeeHtml('data-test="department-column"')
            ->assertSee(route('submissions.create'), escape: false);
    }

    public function test_form_shows_the_members_own_department_instead_of_a_picker(): void
    {
        $department = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);
        Department::create(['code' => 'CBM', 'name' => 'College of Business and Management']);

        $this->actingAs(User::factory()->create(['department_id' => $department->id]));

        $component = Livewire::test('pages::submissions.create')
            ->assertSee('CCS')
            ->assertDontSee('College of Computer Studies')
            ->assertSee('From your profile')
            ->assertDontSee('College of Business and Management')
            ->assertDontSeeHtml('wire:model="department_id"');

        $this->assertStringContainsString('data-flux-loading-indicator', $this->submitButton($component->html()), 'Submit shows a spinner while saving.');
    }

    public function test_members_without_a_department_cannot_submit(): void
    {
        Storage::fake('local');

        $this->actingAs(User::factory()->create(['department_id' => null]));

        $component = $this->fillProject(Livewire::test('pages::submissions.create'))
            ->assertSee('Your account has no college yet');

        // A disabled submit button would otherwise show a spinner that never stops.
        $this->assertStringNotContainsString('data-flux-loading-indicator', $this->submitButton($component->html()));

        $component
            ->call('save')
            ->assertHasErrors(['department']);

        $this->assertSame(0, Submission::count());
    }

    public function test_project_with_several_studies_can_be_submitted(): void
    {
        Storage::fake('local');

        $department = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);
        $natividad = User::factory()->create(['name' => 'Natividad', 'department_id' => $department->id]);
        $siton = User::factory()->create(['name' => 'Siton']);
        $tabago = User::factory()->create(['name' => 'Tabago']);

        $this->actingAs($natividad);

        $this->fillProject(Livewire::test('pages::submissions.create'), 'SMART-ResearchTrack')
            ->assertSet('proponents', [['study' => 1, 'user_id' => $natividad->id, 'role' => 'Leader']])
            ->call('addProponent')
            ->assertCount('proponents', 2)
            ->set('proponents', [
                ['study' => 1, 'user_id' => $natividad->id, 'role' => 'Leader'],
                ['study' => 1, 'user_id' => $siton->id, 'role' => 'Staff'],
                ['study' => 2, 'user_id' => $natividad->id, 'role' => 'Leader'],
                ['study' => 2, 'user_id' => $siton->id, 'role' => 'Staff'],
                ['study' => 3, 'user_id' => $tabago->id, 'role' => 'Leader'],
                ['study' => 3, 'user_id' => $natividad->id, 'role' => 'Co-Leader'],
            ])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('submissions.index'));

        $project = Submission::sole();

        $this->assertSame($natividad->id, $project->user_id);
        $this->assertSame($department->id, $project->department_id);
        $this->assertSame('Submitted', $project->fresh()->status);
        $this->assertTrue($project->fresh()->awaiting_review);
        $this->assertSame(now()->year, $project->year);
        $this->assertSame(6, $project->proponents()->count());
        Storage::disk('local')->assertExists($project->concept_path);

        // Natividad is listed three times and Siton twice, but only three faculty are on the project.
        $this->assertSame(3, User::whereHas('projects')->count());
        $this->assertSame([$project->id], $siton->projects->pluck('id')->all());

        $this->get(route('submissions.index'))->assertSee('SMART-ResearchTrack');

        session()->flash('status', 'Proposal submitted.');

        Livewire::test('pages::submissions.index')->assertDispatched('toast-show', fn ($name, $params) => $params['slots']['text'] === 'Proposal submitted.' && $params['dataset']['variant'] === 'success');
    }

    public function test_proponents_are_validated(): void
    {
        Storage::fake('local');

        $department = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);
        $natividad = User::factory()->create(['department_id' => $department->id]);
        $siton = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($natividad);

        $this->fillProject(Livewire::test('pages::submissions.create'))
            ->set('proponents', [['study' => 1, 'user_id' => $siton->id, 'role' => 'Leader']])
            ->call('save')
            ->assertHasErrors(['proponents'])
            ->set('proponents', [['study' => 1, 'user_id' => $natividad->id, 'role' => 'Leader'], ['study' => 1, 'user_id' => $natividad->id, 'role' => 'Staff']])
            ->call('save')
            ->assertHasErrors(['proponents'])
            ->set('proponents', [['study' => 1, 'user_id' => $natividad->id, 'role' => 'Leader'], ['study' => 1, 'user_id' => $admin->id, 'role' => 'Staff']])
            ->call('save')
            ->assertHasErrors(['proponents.1.user_id' => 'exists'])
            ->set('proponents', [['study' => 0, 'user_id' => $natividad->id, 'role' => 'Boss']])
            ->call('save')
            ->assertHasErrors(['proponents.0.study' => 'between', 'proponents.0.role' => 'in']);

        $this->assertSame(0, Submission::count());
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
                'start_date' => 'required',
                'target_date' => 'required',
                'documents' => 'required',
            ]);

        $this->assertSame(0, Submission::count());
    }

    public function test_blank_choices_are_real_options_and_still_required(): void
    {
        $department = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);
        ResearchType::create(['name' => 'Concept Proposal']);

        $this->actingAs(User::factory()->create(['department_id' => $department->id]));

        // A disabled placeholder can't stay shown after a re-render, so the blank choice must be a real option.
        Livewire::test('pages::submissions.create')
            ->assertSeeHtml('>Select type</option>')
            ->assertDontSeeHtml('class="placeholder"')
            ->set('research_type_id', '')
            ->set('category_id', '')
            ->set('proponents.0.user_id', '')
            ->call('save')
            ->assertHasErrors(['research_type_id' => 'required', 'category_id' => 'required', 'proponents.0.user_id' => 'required']);
    }

    public function test_completion_date_must_come_after_the_starting_date(): void
    {
        Storage::fake('local');

        $department = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);

        $this->actingAs(User::factory()->create(['department_id' => $department->id]));

        $this->fillProject(Livewire::test('pages::submissions.create'))
            ->set('start_date', '2026-06-01')
            ->set('target_date', '2026-05-31')
            ->call('save')
            ->assertHasErrors(['target_date' => 'after']);

        $this->assertSame(0, Submission::count());
    }

    public function test_documents_must_be_pdf_or_word_files_under_ten_megabytes(): void
    {
        Storage::fake('local');

        $department = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);

        $this->actingAs(User::factory()->create(['department_id' => $department->id]));

        Livewire::test('pages::submissions.create')
            ->set('documents.concept', UploadedFile::fake()->create('proposal.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'))
            ->set('documents.detailed', UploadedFile::fake()->create('proposal.doc', 100, 'application/msword'))
            ->call('save')
            ->assertHasNoErrors(['documents', 'documents.concept', 'documents.detailed']);

        Livewire::test('pages::submissions.create')
            ->set('documents.concept', UploadedFile::fake()->create('proposal.png', 100, 'image/png'))
            ->set('documents.terminal', UploadedFile::fake()->create('report.pdf', 10241, 'application/pdf'))
            ->call('save')
            ->assertHasErrors(['documents.concept' => 'mimes', 'documents.terminal' => 'max']);
    }

    public function test_similar_projects_are_flagged_before_saving(): void
    {
        Storage::fake('local');

        [$natividad, $siton] = $this->twoFacultyWithSubmissions();
        $natividad->submissions()->sole()->update(['title' => 'SMART-ResearchTrack']);
        $natividad->submissions()->sole()->proponents()->create(['user_id' => $siton->id, 'study' => 1, 'role' => 'Staff']);

        $this->actingAs($siton);

        $component = $this->fillProject(Livewire::test('pages::submissions.create'), 'SMART ResearchTrack')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('A similar project is already in the portal')
            ->assertSee('SMART-ResearchTrack')
            ->assertSee('You are listed as a proponent.')
            ->assertNoRedirect();

        $this->assertSame(2, Submission::count());

        // Unrelated titles go straight through, and a confirmed duplicate is saved on purpose.
        $component->call('submitAnyway')->assertRedirect(route('submissions.index'));
        $this->assertSame(3, Submission::count());

        $this->fillProject(Livewire::test('pages::submissions.create'), 'Water Quality of the Cagayan River')
            ->call('save')
            ->assertRedirect(route('submissions.index'));
    }

    public function test_proponent_added_after_registering_sees_and_edits_the_project(): void
    {
        Storage::fake('local');

        [$natividad] = $this->twoFacultyWithSubmissions();
        $project = $natividad->submissions()->sole();
        $project->update(['awaiting_review' => false]);

        // Siton registers after the project was filed.
        $siton = User::factory()->create(['name' => 'Siton']);
        $this->actingAs($siton)->get(route('submissions.index'))->assertOk()->assertDontSee('Maria Proposal');
        $this->get(route('submissions.edit', $project))->assertForbidden();

        $this->actingAs($natividad);

        Livewire::test('pages::submissions.create', ['submission' => $project])
            ->assertSee('Edit Project')
            ->assertSeeHtml('data-test="back-to-submissions"')
            ->assertSeeHtml('aria-label="Back to Submissions"')
            ->assertSet('title', 'Maria Proposal')
            ->set('proponents', [
                ['study' => 1, 'user_id' => $natividad->id, 'role' => 'Leader'],
                ['study' => 1, 'user_id' => $siton->id, 'role' => 'Staff'],
                ['study' => 2, 'user_id' => $siton->id, 'role' => 'Staff'],
            ])
            ->set('start_date', '2026-08-15')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('submissions.index'));

        $this->assertSame(2, Submission::count(), 'Editing never adds a second record.');
        $this->assertFalse($project->fresh()->awaiting_review, 'Editing proponents alone does not need a review.');
        $this->assertSame('2026-08-15', $project->fresh()->start_date->toDateString());

        $this->actingAs($siton)->get(route('submissions.index'))->assertSee('Maria Proposal');
        $this->get(route('submissions.edit', $project))->assertOk();
    }

    public function test_only_proponents_can_edit_a_project(): void
    {
        [$maria, $jose] = $this->twoFacultyWithSubmissions();
        $project = $maria->submissions()->sole();

        $this->actingAs($jose)->get(route('submissions.edit', $project))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('submissions.edit', $project))->assertForbidden();
        $this->actingAs($maria)->get(route('submissions.edit', $project))->assertOk();
    }

    public function test_next_document_goes_on_the_same_project_and_only_admins_move_the_status(): void
    {
        Storage::fake('local');

        [$maria] = $this->twoFacultyWithSubmissions();
        $project = $maria->submissions()->sole();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin);
        $this->reviewAs($project, 'Concept');
        $this->assertSame('Concept', $project->fresh()->status);
        $this->assertFalse($project->fresh()->awaiting_review);

        $this->actingAs($maria);
        Livewire::test('pages::submissions.create', ['submission' => $project])
            ->set('documents.detailed', UploadedFile::fake()->create('detailed.pdf', 100, 'application/pdf'))
            ->call('save')
            ->assertHasNoErrors();

        $project->refresh();
        $this->assertSame(2, Submission::count(), 'The next document goes on the same record.');
        $this->assertSame('Project updated. The new document is waiting for the Research Office to review.', session('status'));
        $this->assertSame('Concept', $project->status, 'Uploading never moves the status.');
        $this->assertTrue($project->awaiting_review);
        Storage::disk('local')->assertExists($project->detailed_path);

        $this->actingAs($admin);

        // The review panel names the document that is new, and says so plainly once it is reviewed.
        Livewire::test('pages::submissions.index')
            ->call('review', $project->id)
            ->assertSee('To review: Detailed proposal')
            ->assertDontSeeHtml('data-test="review-nothing-new"');

        $this->reviewAs($project, 'Detailed');
        $this->assertSame('Detailed', $project->fresh()->status);

        Livewire::test('pages::submissions.index')
            ->call('review', $project->id)
            ->assertSee('Already reviewed. No new document since then.')
            ->assertDontSeeHtml('data-test="review-to-check"');
    }

    public function test_corrected_upload_replaces_the_file_and_goes_back_for_review(): void
    {
        Storage::fake('local');

        [$maria] = $this->twoFacultyWithSubmissions();
        $project = $maria->submissions()->sole();
        $this->actingAs($maria);

        Livewire::test('pages::submissions.create', ['submission' => $project])
            ->set('documents.detailed', UploadedFile::fake()->create('detailed.pdf', 100, 'application/pdf'))
            ->call('save');
        $first = $project->fresh()->detailed_path;

        // The Research Office finds it incomplete: remarks, no status change.
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test('pages::submissions.index')
            ->call('review', $project->id)
            ->set('remarks', 'Missing the budget section.')
            ->call('saveReview')
            ->assertHasNoErrors();
        $this->assertFalse($project->fresh()->awaiting_review);
        $this->actingAs($maria)->get(route('submissions.index'))->assertSee('Remarks: Missing the budget section.');

        Livewire::test('pages::submissions.create', ['submission' => $project])
            ->set('documents.detailed', UploadedFile::fake()->create('detailed-v2.pdf', 100, 'application/pdf'))
            ->call('save');

        $project->refresh();
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($project->detailed_path);
        $this->assertTrue($project->awaiting_review);
        $this->assertSame('Submitted', $project->status);
    }

    public function test_a_review_never_changes_the_year_or_college(): void
    {
        [$maria] = $this->twoFacultyWithSubmissions();
        $project = $maria->submissions()->sole();
        $cas = Department::create(['code' => 'CAS', 'name' => 'College of Arts and Sciences']);

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $component = Livewire::test('pages::submissions.index')
            ->call('review', $project->id)
            ->assertSee(now()->year)
            ->assertDontSeeHtml('wire:model="year"')
            ->assertDontSeeHtml('data-test="review-college-select"')
            ->set('status', 'Concept')
            ->call('saveReview')
            ->assertHasNoErrors();

        $this->assertSame(now()->year, $project->fresh()->year);
        $this->assertNotSame($cas->id, $project->fresh()->department_id);

        // There is no year or college on the review form for a crafted request to set.
        $this->expectException(PublicPropertyNotFoundException::class);
        $component->set('departmentId', $cas->id);
    }

    public function test_review_status_is_validated(): void
    {
        [$maria] = $this->twoFacultyWithSubmissions();
        $project = $maria->submissions()->sole();

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::test('pages::submissions.index')
            ->call('review', $project->id)
            ->set('status', 'Rejected')
            ->call('saveReview')
            ->assertHasErrors(['status' => 'in']);

        $this->assertSame('Submitted', $project->fresh()->status);
    }

    public function test_faculty_cannot_review_projects(): void
    {
        [$maria] = $this->twoFacultyWithSubmissions();
        $project = $maria->submissions()->sole();

        $this->actingAs($maria);

        Livewire::test('pages::submissions.index')
            ->assertDontSee('data-test="review-submission-button"', escape: false)
            ->call('review', $project->id)
            ->assertForbidden();

        Livewire::test('pages::submissions.index')
            ->set('reviewingId', $project->id)
            ->set('status', 'Completed')
            ->call('saveReview')
            ->assertForbidden();

        $this->assertSame('Submitted', $project->fresh()->status);
    }

    public function test_delayed_and_on_time_are_judged_on_the_terminal_report_upload_date(): void
    {
        [$maria, $jose] = $this->twoFacultyWithSubmissions();
        $late = $maria->submissions()->sole();
        $late->update(['target_date' => today()->subDay()]);
        $onTime = $jose->submissions()->sole();
        $onTime->update(['target_date' => today()->subDays(14), 'terminal_path' => 'submissions/report.pdf', 'terminal_uploaded_at' => today()->subDays(15)->setTime(16, 0)]);

        $this->assertTrue($late->fresh()->isDelayed());
        $this->assertFalse($onTime->fresh()->isDelayed());
        $this->assertSame([$late->id], Submission::delayed()->pluck('id')->all());

        // Reviewed two weeks after the target date, it still counts as finished on time.
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->reviewAs($onTime, 'Completed');
        $this->assertTrue($onTime->fresh()->completedOnTime());

        Livewire::withQueryParams(['status' => 'delayed'])->test('pages::submissions.index')->assertSee('Maria Proposal')->assertDontSee('Jose Proposal');
        $this->get(route('submissions.index'))->assertSee('Finished on time');

        $this->actingAs($maria)->get(route('dashboard'))->assertOk()->assertSee('Target date '.today()->subDay()->format('M j, Y').' has passed');
    }

    public function test_admins_search_by_faculty_and_year(): void
    {
        [$maria, $jose] = $this->twoFacultyWithSubmissions();
        $jose->submissions()->sole()->proponents()->create(['user_id' => $maria->id, 'study' => 2, 'role' => 'Staff']);
        $maria->submissions()->sole()->update(['year' => now()->year - 1]);

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::test('pages::submissions.index')
            ->set('search', 'Maria')
            ->assertSee('Maria Proposal')
            ->assertSee('Jose Proposal')
            ->set('yearFilter', (string) now()->year)
            ->assertDontSee('Maria Proposal')
            ->assertSee('Jose Proposal')
            ->set('search', 'Nobody')
            ->assertSee('No projects match these filters.');
    }

    public function test_admins_filter_projects_by_department(): void
    {
        [$maria] = $this->twoFacultyWithSubmissions();
        $cbm = Department::create(['code' => 'CBM', 'name' => 'College of Business and Management']);
        $maria->submissions()->sole()->update(['department_id' => $cbm->id]);

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::test('pages::submissions.index')
            ->assertSeeHtml('data-test="department-column"')
            ->assertSee('All colleges')
            ->set('department', 'CBM')
            ->assertSee('Maria Proposal')
            ->assertDontSee('Jose Proposal')
            ->set('department', 'CAS')
            ->assertSee('No projects match these filters.')
            ->call('clearFilters')
            ->assertSet('department', '')
            ->assertSee('Jose Proposal');
    }

    public function test_admins_export_the_filtered_list_for_excel(): void
    {
        [$maria, $jose] = $this->twoFacultyWithSubmissions();
        $project = $maria->submissions()->sole();
        $project->update(['title' => '=HYPERLINK("http://x")', 'status' => 'Completed', 'terminal_path' => 'submissions/report.pdf', 'terminal_uploaded_at' => now()]);
        $siton = User::factory()->create(['name' => 'Siton Ñuñez']);
        $project->proponents()->createMany([
            ['user_id' => $siton->id, 'study' => 2, 'role' => 'Staff'],
            ['user_id' => $maria->id, 'study' => 2, 'role' => 'Co-Leader'],
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $component = Livewire::test('pages::submissions.index')
            ->set('yearFilter', (string) now()->year)
            ->set('statusFilter', 'Completed')
            ->call('export')
            ->assertFileDownloaded('submissions-'.now()->year.'-completed.csv');

        $csv = base64_decode(data_get($component->effects, 'download.content'));

        $this->assertStringStartsWith("\xEF\xBB\xBF".'Title,Year,College,Status', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv, 'Formula-looking cells are escaped.');
        $this->assertStringContainsString('"Study 1: Maria Santos (Leader); Study 2: Siton Ñuñez (Staff), Maria Santos (Co-Leader)",2', $csv);
        $this->assertStringContainsString('Concept, Terminal', $csv);
        $this->assertStringNotContainsString('Jose Proposal', $csv);

        $this->actingAs($jose);
        Livewire::test('pages::submissions.index')->call('export')->assertForbidden();
    }

    public function test_only_proponents_and_admins_can_open_a_document(): void
    {
        Storage::fake('local');

        [$maria, $jose] = $this->twoFacultyWithSubmissions();
        $project = $maria->submissions()->sole();
        Storage::disk('local')->put($project->concept_path, '%PDF-1.4');

        $url = route('submissions.document', [$project, 'concept']);

        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($jose)->get($url)->assertForbidden();
        $this->actingAs($maria)->get($url)->assertOk();
        $this->actingAs($maria)->get(route('submissions.document', [$project, 'detailed']))->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get($url)->assertOk();

        $project->proponents()->create(['user_id' => $jose->id, 'study' => 1, 'role' => 'Staff']);
        $this->actingAs($jose)->get($url)->assertOk();
    }

    public function test_word_documents_download_with_their_own_extension(): void
    {
        Storage::fake('local');

        [$maria] = $this->twoFacultyWithSubmissions();
        $project = $maria->submissions()->sole();
        $project->update(['terminal_path' => 'submissions/sample.docx']);
        Storage::disk('local')->put($project->terminal_path, 'docx');

        $this->actingAs($maria)
            ->get(route('submissions.document', [$project, 'terminal']))
            ->assertOk()
            ->assertHeader('content-disposition', 'inline; filename='.Str::slug($project->title.' terminal').'.docx');
    }

    public function test_submissions_are_paged_fifteen_at_a_time(): void
    {
        [$maria] = $this->twoFacultyWithSubmissions();
        $proposal = $maria->submissions()->sole();

        foreach (range(1, 14) as $number) {
            $proposal->replicate()->fill(['title' => "Extra Proposal {$number}"])->save();
        }

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $component = Livewire::test('pages::submissions.index');
        $this->assertCount(15, $component->instance()->submissions);
        $this->assertSame(16, $component->instance()->submissions->total());

        $component->call('nextPage');
        $this->assertCount(1, $component->instance()->submissions);

        // A new filter starts from the first page.
        $component->set('statusFilter', 'review')->assertSet('paginators.page', 1);

        // Reviewing the only project on the last filtered page steps back to a page with rows.
        $last = $component->call('nextPage')->instance()->submissions->sole();
        $component->call('review', $last->id)
            ->call('saveReview')
            ->assertHasNoErrors()
            ->assertSet('paginators.page', 1);
    }

    public function test_the_list_and_the_review_panel_load_only_what_they_show(): void
    {
        [$maria] = $this->twoFacultyWithSubmissions();
        $project = Submission::where('title', 'Maria Proposal')->first();

        $this->actingAs($maria);
        $this->assertQueriesAtMost(6, fn () => $this->get(route('submissions.index'))->assertOk());

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->assertQueriesAtMost(8, fn () => $this->get(route('submissions.index'))->assertOk());

        // Calls the way a click inside the review panel sends them, scoped to the review island.
        $component = Livewire::test('pages::submissions.index');
        $inReviewIsland = fn (string $method, ...$params) => $component->update(calls: [['method' => $method, 'params' => $params, 'path' => '', 'metadata' => ['island' => ['name' => 'review', 'mode' => 'morph']]]]);

        // Opening a review from its button redraws only the review island, not the list.
        $inReviewIsland('review', $project->id);
        $fragments = implode('', $component->effects['islandFragments'] ?? []);
        $this->assertStringContainsString('Filed by Maria Santos', $fragments);
        $this->assertStringNotContainsString('Jose Proposal', $fragments);

        // Saving from inside the panel also redraws the list, so the new status shows without a reload.
        $component->set('status', 'Concept');
        $inReviewIsland('saveReview');
        $this->assertStringContainsString('data-test="status-filter"', implode('', $component->effects['islandFragments'] ?? []));
        $this->assertSame('Concept', $project->fresh()->status);

        // Once the panel closes, filtering the list no longer reloads the reviewed project.
        $component->call('closeReview')->assertSet('reviewingId', null);
        $this->assertQueriesAtMost(8, fn () => $component->set('statusFilter', 'Submitted'));
    }

    /**
     * The submit button's markup alone, so other buttons' spinners don't count.
     */
    private function submitButton(string $html): string
    {
        preg_match('/<button[^>]*data-test="submit-proposal-button".*?<\/button>/s', $html, $match);

        return $match[0] ?? '';
    }

    private function fillProject($component, string $title = 'Sample Proposal')
    {
        return $component
            ->set('title', $title)
            ->set('abstract', 'An abstract.')
            ->set('research_type_id', ResearchType::firstOrCreate(['name' => 'Thesis'])->id)
            ->set('category_id', Category::firstOrCreate(['name' => 'Computing'])->id)
            ->set('start_date', now()->toDateString())
            ->set('target_date', now()->addYear()->toDateString())
            ->set('documents.concept', UploadedFile::fake()->create('proposal.pdf', 500, 'application/pdf'));
    }

    private function reviewAs(Submission $project, string $status): void
    {
        Livewire::test('pages::submissions.index')
            ->call('review', $project->id)
            ->set('status', $status)
            ->call('saveReview')
            ->assertHasNoErrors();
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
                'abstract' => 'An abstract.',
                'start_date' => now(),
                'target_date' => now()->addYear(),
                'concept_path' => 'submissions/sample.pdf',
            ])->proponents()->create(['user_id' => $user->id, 'study' => 1, 'role' => 'Leader']);
        }

        return [$maria, $jose];
    }
}
