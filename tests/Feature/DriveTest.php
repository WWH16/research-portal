<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\Category;
use App\Models\Department;
use App\Models\Publication;
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

    private User $navarro;

    private User $soriano;

    private User $torres;

    private Submission $smart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        $this->ccsict = Department::create(['code' => 'CCSICT', 'name' => 'College of Computing Studies']);
        $this->cas = Department::create(['code' => 'CAS', 'name' => 'College of Arts and Sciences']);
        Department::create(['code' => 'CBM', 'name' => 'College of Business and Management']);

        $this->navarro = User::factory()->create(['name' => 'Navarro', 'department_id' => $this->ccsict->id]);
        $this->soriano = User::factory()->create(['name' => 'Soriano', 'department_id' => $this->ccsict->id]);
        $this->torres = User::factory()->create(['name' => 'Torres', 'department_id' => $this->cas->id]);

        // SMART-ResearchTrack: filed by Navarro under CCSICT, with Torres from CAS leading Study 3.
        $this->smart = $this->project($this->navarro, 'SMART-ResearchTrack', ['status' => 'Detailed', 'detailed_path' => 'drive-1/submissions/detailed.pdf']);
        $this->smart->proponents()->createMany([
            ['user_id' => $this->soriano->id, 'study' => 1, 'role' => 'Staff'],
            ['user_id' => $this->torres->id, 'study' => 3, 'role' => 'Leader'],
        ]);

        $this->project($this->torres, 'CAS Soil Survey');
    }

    public function test_staff_see_and_export_but_do_not_act(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $project = $this->smart;

        $this->assertTrue($staff->can('viewAny', Submission::class));
        $this->assertTrue($staff->can('view', $project));
        $this->assertFalse($staff->can('review', Submission::class));
        $this->assertFalse($staff->can('update', $project));
        $this->assertFalse($staff->can('create', Publication::class));
        $this->assertFalse($staff->can('create', Announcement::class));
    }

    public function test_faculty_moved_to_staff_keep_sight_of_their_project_but_cannot_edit_it(): void
    {
        // Returned for revision, so it counts as work to revise while Navarro is still faculty.
        $this->smart->update(['awaiting_review' => false, 'concept_passed' => false]);
        $paper = Publication::factory()->create();
        $paper->faculty()->attach($this->navarro->id);
        $this->assertSame(1, $this->navarro->openWork()['toRevise']);

        $this->navarro->forceFill(['role' => 'staff'])->save();

        $this->assertTrue($this->navarro->can('view', $this->smart));
        $this->assertFalse($this->navarro->can('update', $this->smart));
        $this->actingAs($this->navarro)->get(route('submissions.edit', $this->smart))->assertForbidden();
        $this->assertSame(0, $this->navarro->openWork()['toRevise']);

        // A paper they wrote while faculty stays readable, but is no longer theirs to edit.
        $this->assertTrue($this->navarro->can('view', $paper));
        $this->assertFalse($this->navarro->can('update', $paper));
        $this->get(route('publications.edit', $paper))->assertForbidden();
    }

    public function test_faculty_see_only_projects_they_are_on_whatever_the_college(): void
    {
        $this->actingAs($this->soriano);
        Livewire::test('pages::drive.index')
            ->assertSee('SMART-ResearchTrack')
            ->assertSee('Study 1 Staff')
            ->assertDontSee('CAS Soil Survey')
            ->assertDontSee('data-test="college-folders"', escape: false);

        // Torres is from CAS but still sees the CCSICT project she is on, plus her own.
        $this->actingAs($this->torres);
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
        $this->project($this->navarro, 'Old CCSICT Study', ['year' => $lastYear]);

        $this->actingAs($this->admin());

        Livewire::withQueryParams(['college' => 'CCSICT'])
            ->test('pages::drive.index')
            ->assertSee('SMART-ResearchTrack')
            ->assertSee('Old CCSICT Study')
            ->set('year', (string) $lastYear)
            ->assertDontSee('SMART-ResearchTrack')
            ->assertSee('Old CCSICT Study');

        // Torres is a proponent, but the project is filed under CCSICT only.
        Livewire::withQueryParams(['college' => 'CAS'])->test('pages::drive.index')->assertSee('CAS Soil Survey')->assertDontSee('SMART-ResearchTrack');

        Livewire::withQueryParams(['year' => (string) now()->year])->test('pages::drive.index')->assertSeeInOrder(['CCSICT', '1 project']);
    }

    public function test_project_page_shows_studies_roles_colleges_and_documents(): void
    {
        $this->fakeDrive();
        Storage::disk('drive')->put('submissions/concept.pdf', '%PDF');

        $this->actingAs($this->torres)
            ->get(route('drive.show', $this->smart))
            ->assertOk()
            ->assertSee('Detailed')
            ->assertSeeInOrder(['Study 1', 'Navarro', 'Leader', 'CCSICT', 'Soriano', 'Staff', 'CCSICT', 'Study 3', 'Torres', 'Leader', 'CAS'])
            ->assertSeeInOrder(['Concept proposal', 'Uploaded', 'Detailed proposal', 'Mid-year progress report', 'Terminal report', 'Not uploaded yet'])
            ->assertSee(route('submissions.document', [$this->smart, 'concept']), escape: false)
            ->assertSee(route('submissions.edit', [$this->smart, 'from' => 'drive']), escape: false);

        $this->actingAs($this->admin())
            ->get(route('drive.show', $this->smart))
            ->assertOk()
            ->assertSee('data-test="drive-review-button"', escape: false);

        // Once the concept proposal is decided, nothing waits for review, so the button goes.
        $this->smart->update(['awaiting_review' => false, 'concept_passed' => true]);
        $this->get(route('drive.show', $this->smart))
            ->assertOk()
            ->assertDontSee('data-test="drive-review-button"', escape: false);
    }

    public function test_editing_from_the_drive_returns_to_the_drive_project(): void
    {
        $this->actingAs($this->navarro);

        $page = $this->get(route('submissions.edit', [$this->smart, 'from' => 'drive']))->assertOk()->assertSeeInOrder(['Research Drive', $this->smart->title, 'Edit']);

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
            ->assertSee('Pass concept')
            ->assertSee('Return for revision')
            ->call('saveReview', 'passed')
            ->assertHasNoErrors()
            ->assertRedirect(route('drive.show', $this->smart));

        $this->assertSame('Detailed', $this->smart->fresh()->status, 'A review never moves the status.');
        $this->get(route('drive.show', $this->smart))->assertSee('Review saved.');

        // Faculty see the page but cannot review from it.
        $this->actingAs($this->navarro);
        Livewire::test('pages::drive.show', ['submission' => $this->smart])
            ->assertDontSee('data-test="drive-review-button"', escape: false)
            ->call('review', $this->smart->id)
            ->assertForbidden();
    }

    public function test_admins_tick_the_in_house_review_once_a_project_is_complete(): void
    {
        $this->actingAs($this->admin());
        $list = fn () => $this->get(route('submissions.index', ['q' => 'Navarro']));

        // Before completion there is nothing to tick, and a forged tick is refused.
        $list()->assertDontSee('data-test="presented-checkbox"', false)->assertSee('Not completed yet');
        Livewire::test('pages::submissions.index')->call('setPresented', $this->smart->id, true)->assertForbidden();
        $this->assertFalse($this->smart->fresh()->presented);

        // A project finished before the mid-year report existed is flagged, since it skipped a stage.
        $this->smart->update(['status' => 'Completed', 'awaiting_review' => false, 'concept_passed' => true, 'terminal_path' => 'drive-1/submissions/t.pdf']);
        $list()->assertSee('data-test="presented-checkbox"', false)->assertSee('Not presented: SMART-ResearchTrack')->assertSee('No mid-year report');

        $this->smart->update(['midyear_path' => 'drive-1/submissions/m.pdf']);
        $list()->assertDontSee('No mid-year report');
        Livewire::test('pages::submissions.index')->call('setPresented', $this->smart->id, true)->assertHasNoErrors();
        $this->assertTrue($this->smart->fresh()->presented);

        // Setting it again, as from a page left open, changes nothing and logs nothing.
        Livewire::test('pages::submissions.index')->call('setPresented', $this->smart->id, true);
        $this->assertSame(1, ActivityLog::where('action', 'submission.presented')->count());

        // Faculty see the tick in the history, under the office's name, but can't set it.
        $this->actingAs($this->navarro);
        // Read from the rendered HTML: an update's response escapes the curly quotes.
        $this->assertMatchesRegularExpression('/Research Office.*Marked as “Presented in-house”/s', Livewire::test('pages::drive.show', ['submission' => $this->smart])->set('historyOpen', true)->html());
        Livewire::test('pages::submissions.index')
            ->assertDontSee('data-test="presented-checkbox"', escape: false)
            ->call('setPresented', $this->smart->id, false)
            ->assertForbidden();

        // A tick by mistake can be undone.
        $this->actingAs($this->admin());
        Livewire::test('pages::submissions.index')->call('setPresented', $this->smart->id, false);
        $this->assertFalse($this->smart->fresh()->presented);
    }

    public function test_staff_tick_presented_but_cannot_change_dates_or_review(): void
    {
        $this->actingAs($this->staff());

        // Not completed yet: a forged tick is refused.
        Livewire::test('pages::submissions.index')->call('setPresented', $this->smart->id, true)->assertForbidden();

        // Dates and reviews belong to admins alone.
        Livewire::test('pages::submissions.index')->call('editDates', $this->smart->id)->assertForbidden();
        Livewire::test('pages::submissions.index')->call('saveDates')->assertForbidden();

        $this->smart->update(['status' => 'Completed', 'awaiting_review' => false, 'concept_passed' => true, 'terminal_path' => 'drive-1/submissions/t.pdf']);
        Livewire::test('pages::submissions.index')->call('setPresented', $this->smart->id, true)->assertHasNoErrors();
        $this->assertTrue($this->smart->fresh()->presented);

        $this->get(route('submissions.index', ['q' => 'Navarro']))
            ->assertSee('data-test="presented-checkbox"', false)
            ->assertDontSee('data-test="change-dates-button"', false);
        $this->project($this->navarro, 'Open Dates Project');
        $this->get(route('submissions.index'))->assertDontSee('data-test="change-dates-button"', false);

        Livewire::test('pages::drive.show', ['submission' => $this->smart])
            ->call('review', $this->smart->id)
            ->assertForbidden();
        $this->smart->update(['status' => 'Concept', 'awaiting_review' => true]);
        $this->get(route('drive.show', $this->smart))->assertDontSee('data-test="drive-review-button"', false);
    }

    public function test_staff_browse_the_drive_by_college_and_see_presented(): void
    {
        $this->actingAs($this->staff());

        $this->get(route('drive.index'))->assertOk()->assertSee('CCSICT')->assertSee('CAS');

        $this->smart->update(['status' => 'Completed']);
        $this->get(route('drive.show', $this->smart))
            ->assertOk()
            ->assertSee('data-test="drive-presented"', false)
            ->assertSee(route('drive.index', ['college' => 'CCSICT']), false);
    }

    public function test_staff_download_the_submissions_report(): void
    {
        $this->actingAs($this->staff());

        Livewire::test('pages::submissions.index')
            ->call('export')
            ->assertFileDownloaded('submissions-'.today()->toDateString().'.csv');
    }

    public function test_ticking_the_last_project_on_a_page_shows_the_page_before_it(): void
    {
        foreach (range(1, 16) as $number) {
            $this->project($this->navarro, "Done {$number}", ['status' => 'Completed'])->forceFill(['created_at' => now()->subDays($number)])->save();
        }

        $this->actingAs($this->staff());

        $page = Livewire::withQueryParams(['page' => 2, 'status' => 'not-presented'])->test('pages::submissions.index')
            ->assertSee('Done 16')
            ->call('setPresented', Submission::where('title', 'Done 16')->sole()->id, true);

        $this->assertCount(15, $page->instance()->submissions);
    }

    public function test_admins_list_completed_projects_by_whether_they_presented_in_house(): void
    {
        $this->smart->update(['status' => 'Completed', 'presented' => true]);
        $this->project($this->navarro, 'Skipped Review', ['status' => 'Completed']);
        $this->actingAs($this->admin());

        Livewire::test('pages::submissions.index')
            ->set('statusFilter', 'presented')
            ->assertSee('SMART-ResearchTrack')
            ->assertDontSee('Skipped Review')
            ->assertSee(route('drive.show', $this->smart), escape: false)
            // The certificate list's other half: completed but not ticked. Projects still in progress aren't in it.
            ->set('statusFilter', 'not-presented')
            ->assertSee('Skipped Review')
            ->assertDontSee('SMART-ResearchTrack')
            ->assertDontSee('CAS Soil Survey');

        $this->get(route('drive.show', $this->smart))->assertSeeInOrder(['Presented in-house', 'Yes']);
        $this->actingAs($this->navarro)->get(route('drive.show', $this->smart))->assertDontSee('data-test="drive-presented"', false);
    }

    public function test_strangers_cannot_open_the_project_or_its_files(): void
    {
        $this->fakeDrive();
        Storage::disk('drive')->put('submissions/detailed.pdf', '%PDF');

        $stranger = User::factory()->create(['department_id' => $this->ccsict->id]);

        $this->actingAs($stranger)->get(route('drive.show', $this->smart))->assertForbidden();
        $this->actingAs($stranger)->get(route('submissions.document', [$this->smart, 'detailed']))->assertForbidden();
        $this->actingAs($this->soriano)->get(route('submissions.document', [$this->smart, 'detailed']))->assertOk();
    }

    public function test_a_review_keeps_the_project_in_its_college_folder(): void
    {
        $this->actingAs($this->admin());

        Livewire::test('pages::reviews.index')
            ->call('review', $this->smart->id)
            ->assertSee('Torres (Leader, CAS)')
            ->call('saveReview', 'passed')
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

        // Navarro and Soriano count under CCSICT; Torres counts under CAS, though SMART is filed under CCSICT.
        $this->assertSame(2, $ccsict['submitted']);
        $this->assertSame(1, $cas['submitted']);
    }

    public function test_a_college_folder_reads_like_the_submissions_list(): void
    {
        // Same title, filer name and count as the Submissions list shows for the same projects.
        $santos = User::factory()->create(['name' => 'MARIA DELA PAZ SANTOS', 'department_id' => $this->ccsict->id]);
        $this->project($santos, 'Mining_Student_Feedback');

        $this->actingAs($this->admin());

        Livewire::withQueryParams(['college' => 'CCSICT'])
            ->test('pages::drive.index')
            ->assertSee('title="Mining Student Feedback"', escape: false)
            ->assertDontSee('Mining_Student_Feedback')
            ->assertSee('MDP Santos')
            ->assertDontSee('MARIA DELA PAZ SANTOS')
            ->assertSee('2 projects')
            ->set('search', 'mining')
            ->assertSee('1 project');
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => 'staff']);
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
            'concept_path' => 'drive-1/submissions/concept.pdf',
            ...$attributes,
        ]);

        $project->proponents()->create(['user_id' => $by->id, 'study' => 1, 'role' => 'Leader']);

        return $project;
    }
}
