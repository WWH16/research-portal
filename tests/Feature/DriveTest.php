<?php

namespace Tests\Feature;

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
 * The Research Drive from scenarios 14 to 25: faculty see their own projects, admins browse one
 * folder per college, and each project page shows its studies, proponents and documents.
 */
class DriveTest extends TestCase
{
    use RefreshDatabase;

    private Department $ccsict;

    private Department $cas;

    private User $natividad;

    private User $siton;

    private User $tabago;

    private Submission $smart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        $this->ccsict = Department::create(['code' => 'CCSICT', 'name' => 'College of Computing Studies']);
        $this->cas = Department::create(['code' => 'CAS', 'name' => 'College of Arts and Sciences']);
        Department::create(['code' => 'CBM', 'name' => 'College of Business and Management']);

        $this->natividad = User::factory()->create(['name' => 'Natividad', 'department_id' => $this->ccsict->id]);
        $this->siton = User::factory()->create(['name' => 'Siton', 'department_id' => $this->ccsict->id]);
        $this->tabago = User::factory()->create(['name' => 'Tabago', 'department_id' => $this->cas->id]);

        // SMART-ResearchTrack: filed by Natividad under CCSICT, with Tabago from CAS leading Study 3.
        $this->smart = $this->project($this->natividad, 'SMART-ResearchTrack', ['status' => 'Detailed', 'detailed_path' => 'submissions/detailed.pdf']);
        $this->smart->proponents()->createMany([
            ['user_id' => $this->siton->id, 'study' => 1, 'role' => 'Staff'],
            ['user_id' => $this->tabago->id, 'study' => 3, 'role' => 'Leader'],
        ]);

        $this->project($this->tabago, 'CAS Soil Survey');
    }

    public function test_faculty_see_only_projects_they_are_on_whatever_the_college(): void
    {
        $this->actingAs($this->siton);
        Livewire::test('pages::drive.index')
            ->assertSee('SMART-ResearchTrack')
            ->assertSee('Study 1 Staff')
            ->assertDontSee('CAS Soil Survey')
            ->assertDontSee('data-test="college-folders"', escape: false);

        // Tabago is from CAS but still sees the CCSICT project she is on, plus her own.
        $this->actingAs($this->tabago);
        Livewire::test('pages::drive.index')->assertSee('SMART-ResearchTrack')->assertSee('CAS Soil Survey');

        $this->actingAs(User::factory()->create(['department_id' => $this->ccsict->id]));
        Livewire::test('pages::drive.index')->assertSee('You’re not on any projects yet')->assertDontSee('SMART-ResearchTrack');
    }

    public function test_admins_see_every_college_folder_even_empty_ones(): void
    {
        $this->actingAs($this->admin());

        Livewire::test('pages::drive.index')
            ->assertSeeInOrder(['CAS', '1 project', 'CBM', 'No projects yet', 'CCSICT', '1 project'])
            ->assertDontSee('SMART-ResearchTrack');
    }

    public function test_a_project_is_filed_once_under_its_own_college_and_filtered_by_year(): void
    {
        $lastYear = now()->year - 1;
        $this->project($this->natividad, 'Old CCSICT Study', ['year' => $lastYear]);

        $this->actingAs($this->admin());

        Livewire::withQueryParams(['college' => 'CCSICT'])
            ->test('pages::drive.index')
            ->assertSee('SMART-ResearchTrack')
            ->assertSee('Old CCSICT Study')
            ->set('year', (string) $lastYear)
            ->assertDontSee('SMART-ResearchTrack')
            ->assertSee('Old CCSICT Study');

        // Tabago is a proponent, but the project is filed under CCSICT only.
        Livewire::withQueryParams(['college' => 'CAS'])->test('pages::drive.index')->assertSee('CAS Soil Survey')->assertDontSee('SMART-ResearchTrack');

        Livewire::withQueryParams(['year' => (string) now()->year])->test('pages::drive.index')->assertSeeInOrder(['CCSICT', '1 project']);
    }

    public function test_project_page_shows_studies_roles_colleges_and_documents(): void
    {
        Storage::fake('submissions');
        Storage::disk('submissions')->put('submissions/concept.pdf', '%PDF');

        $this->actingAs($this->tabago)
            ->get(route('drive.show', $this->smart))
            ->assertOk()
            ->assertSee('Detailed')
            ->assertSeeInOrder(['Study 1', 'Natividad', 'Leader', 'CCSICT', 'Siton', 'Staff', 'CCSICT', 'Study 3', 'Tabago', 'Leader', 'CAS'])
            ->assertSeeInOrder(['Concept proposal', 'Uploaded', 'Detailed proposal', 'Terminal report', 'Not uploaded yet'])
            ->assertSee(route('submissions.document', [$this->smart, 'concept']), escape: false)
            ->assertSee(route('submissions.edit', [$this->smart, 'from' => 'drive']), escape: false);

        $this->actingAs($this->admin())
            ->get(route('drive.show', $this->smart))
            ->assertOk()
            ->assertSee('data-test="drive-review-button"', escape: false);
    }

    public function test_editing_from_the_drive_returns_to_the_drive_project(): void
    {
        $this->actingAs($this->natividad);

        $page = $this->get(route('submissions.edit', [$this->smart, 'from' => 'drive']))->assertOk()->assertSee('Back to Research Drive');

        // The sidebar keeps Research Drive highlighted, not Submissions.
        $current = fn (string $url) => preg_match('#<a\b[^>]*href="'.preg_quote($url, '#').'"[^>]*\sdata-current[=\s>]#', $page->getContent());
        $this->assertSame(1, $current(route('drive.index')));
        $this->assertSame(0, $current(route('submissions.index')));

        Livewire::withQueryParams(['from' => 'drive'])
            ->test('pages::submissions.create', ['submission' => $this->smart])
            ->set('abstract', 'A study.')
            ->set('start_date', '2026-01-01')
            ->set('target_date', '2026-12-31')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('drive.show', $this->smart));

        $this->get(route('drive.show', $this->smart))->assertSee('Project updated.');

        // Opened from Submissions, or with any other value, it goes back to the list as before.
        Livewire::withQueryParams(['from' => 'https://example.com'])
            ->test('pages::submissions.create', ['submission' => $this->smart])
            ->set('abstract', 'A study.')
            ->set('start_date', '2026-01-01')
            ->set('target_date', '2026-12-31')
            ->call('save')
            ->assertRedirect(route('submissions.index'));
    }

    public function test_a_review_opens_over_the_drive_project_and_returns_to_it(): void
    {
        $this->actingAs($this->admin());

        Livewire::test('pages::drive.show', ['submission' => $this->smart])
            ->call('review', $this->smart->id)
            ->assertSet('reviewingId', $this->smart->id)
            ->assertSee('Save review')
            ->set('status', 'Concept')
            ->call('saveReview')
            ->assertHasNoErrors()
            ->assertRedirect(route('drive.show', $this->smart));

        $this->assertSame('Concept', $this->smart->fresh()->status);
        $this->get(route('drive.show', $this->smart))->assertSee('Review saved.');

        // Faculty see the page but cannot review from it.
        $this->actingAs($this->natividad);
        Livewire::test('pages::drive.show', ['submission' => $this->smart])
            ->assertDontSee('data-test="drive-review-button"', escape: false)
            ->call('review', $this->smart->id)
            ->assertForbidden();
    }

    public function test_strangers_cannot_open_the_project_or_its_files(): void
    {
        Storage::fake('submissions');
        Storage::disk('submissions')->put('submissions/detailed.pdf', '%PDF');

        $stranger = User::factory()->create(['department_id' => $this->ccsict->id]);

        $this->actingAs($stranger)->get(route('drive.show', $this->smart))->assertForbidden();
        $this->actingAs($stranger)->get(route('submissions.document', [$this->smart, 'detailed']))->assertForbidden();
        $this->actingAs($this->siton)->get(route('submissions.document', [$this->smart, 'detailed']))->assertOk();
    }

    public function test_a_review_keeps_the_project_in_its_college_folder(): void
    {
        $this->actingAs($this->admin());

        Livewire::test('pages::submissions.index')
            ->call('review', $this->smart->id)
            ->assertSee('Tabago (Leader, CAS)')
            ->set('status', 'Concept')
            ->call('saveReview')
            ->assertHasNoErrors();

        $this->assertSame($this->ccsict->id, $this->smart->fresh()->department_id);
        Livewire::withQueryParams(['college' => 'CCSICT'])->test('pages::drive.index')->assertSee('SMART-ResearchTrack');
        Livewire::withQueryParams(['college' => 'CAS'])->test('pages::drive.index')->assertDontSee('SMART-ResearchTrack');
    }

    public function test_college_counts_use_each_faculty_members_own_college(): void
    {
        $this->actingAs($this->admin());

        $ccsict = Livewire::withQueryParams(['dept' => 'CCSICT'])->test('pages::dashboard')->instance()->facultyCounts;
        $cas = Livewire::withQueryParams(['dept' => 'CAS'])->test('pages::dashboard')->instance()->facultyCounts;

        // Natividad and Siton count under CCSICT; Tabago counts under CAS, though SMART is filed under CCSICT.
        $this->assertSame(2, $ccsict['submitted']);
        $this->assertSame(1, $cas['submitted']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function project(User $by, string $title, array $attributes = []): Submission
    {
        $project = $by->submissions()->create([
            'research_type_id' => ResearchType::firstOrCreate(['name' => 'Thesis'])->id,
            'category_id' => Category::firstOrCreate(['name' => 'Computing'])->id,
            'department_id' => $by->department_id,
            'title' => $title,
            'concept_path' => 'submissions/concept.pdf',
            ...$attributes,
        ]);

        $project->proponents()->create(['user_id' => $by->id, 'study' => 1, 'role' => 'Leader']);

        return $project;
    }
}
