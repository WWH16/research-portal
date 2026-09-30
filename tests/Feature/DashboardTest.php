<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\ResearchType;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
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
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_the_sidebar_names_the_university_and_the_signed_in_role(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('dashboard'))->assertSeeInOrder(['Research Portal', 'Isabela State University', 'Administrator']);

        $college = Department::create(['code' => 'CCSICT', 'name' => 'College of Computing Studies']);
        $this->actingAs(User::factory()->create(['role' => 'faculty', 'department_id' => $college->id]));
        $this->get(route('dashboard'))->assertSee('Faculty, CCSICT');
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_admins_see_projects_waiting_for_review_longest_first(): void
    {
        $maria = $this->facultyInDepartment('Maria Santos');

        foreach (range(1, 6) as $day) {
            $this->travelTo(now()->startOfYear()->addDays($day));
            $this->submit($maria, "Proposal {$day}");
        }
        $this->travelBack();

        $this->submit($maria, 'Already reviewed', 'Concept', ['awaiting_review' => false]);

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Waiting for review', 'Proposal 1', 'Proposal 5'])
            ->assertDontSee('Proposal 6')
            ->assertDontSee('Already reviewed')
            ->assertSee('View all 6')
            ->assertSee(route('submissions.index', ['review' => Submission::firstWhere('title', 'Proposal 1')->id]), escape: false)
            ->assertSee(route('submissions.index', ['status' => 'review']), escape: false);
    }

    public function test_yearly_summary_counts_each_faculty_member_once_per_group(): void
    {
        $natividad = $this->facultyInDepartment('Natividad');
        $siton = $this->facultyInDepartment('Siton');
        $tabago = $this->facultyInDepartment('Tabago');
        // A year after go-live, so "last year" is one the portal tracks.
        $this->travelTo(now()->setYear(Submission::FIRST_YEAR + 1));
        $year = now()->year;

        // SMART-ResearchTrack: Natividad in all three studies, Siton in two, Tabago in one.
        $smart = $this->submit($natividad, 'SMART-ResearchTrack', 'Detailed');
        $smart->proponents()->createMany([
            ['user_id' => $siton->id, 'study' => 1, 'role' => 'Staff'],
            ['user_id' => $natividad->id, 'study' => 2, 'role' => 'Leader'],
            ['user_id' => $siton->id, 'study' => 2, 'role' => 'Staff'],
            ['user_id' => $tabago->id, 'study' => 3, 'role' => 'Leader'],
            ['user_id' => $natividad->id, 'study' => 3, 'role' => 'Co-Leader'],
        ]);

        // Natividad also finished another project on time, and last year's project stays out of this year.
        $this->submit($natividad, 'Finished Study', 'Completed', ['target_date' => today(), 'terminal_uploaded_at' => now()->subDay()]);
        // travel() moves the frozen clock and leaves it there; travelTo() with a callback would reset it to real time.
        $this->travel(-1)->years();
        $this->submit($tabago, 'Old Study', 'Completed', ['year' => $year - 1]);
        $this->travel(1)->years();

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $component = Livewire::test('pages::dashboard')->assertSet('from', "{$year}-01-01")->assertSet('to', "{$year}-12-31");
        $this->assertSame(['submitted' => 3, 'pending' => 0, 'proposal' => 3, 'completed' => 1, 'delayed' => 0], $component->instance()->facultyCounts);
        $component->assertSee('Across 2 projects in '.$year)->assertSee('1 of 1 project finished on time');

        $lastYear = Livewire::test('pages::dashboard')->call('preset', 'last-year');
        $this->assertSame(['submitted' => 1, 'pending' => 2, 'proposal' => 0, 'completed' => 1, 'delayed' => 0], $lastYear->instance()->facultyCounts);

        // The proposal-stage list flags what waits on the Research Office, next to the stage.
        Livewire::withQueryParams(['group' => 'proposal'])
            ->test('pages::dashboard')
            ->assertSeeInOrder(['SMART-ResearchTrack', 'Awaiting review', 'Detailed']);
    }

    public function test_date_range_filters_the_summary_by_filing_date(): void
    {
        $maria = $this->facultyInDepartment('Maria Santos');
        $jose = $this->facultyInDepartment('Jose Reyes');
        $this->travelTo(now()->setYear(Submission::FIRST_YEAR + 3)->startOfYear()->addDays(10));
        $this->submit($maria, 'January Study');
        $this->travel(7)->months();
        $this->submit($jose, 'August Study');

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $year = now()->year;

        $firstHalf = Livewire::withQueryParams(['from' => "{$year}-01-01", 'to' => "{$year}-06-30"])->test('pages::dashboard');
        $this->assertSame(1, $firstHalf->instance()->facultyCounts['submitted']);
        $firstHalf->assertSee("Jan 1 – Jun 30, {$year}")->assertSee('Across one project in');

        // A reversed pair is swapped instead of showing nothing.
        $reversed = Livewire::withQueryParams(['from' => "{$year}-12-31", 'to' => "{$year}-07-01"])->test('pages::dashboard');
        $this->assertSame(1, $reversed->instance()->facultyCounts['submitted']);

        // A bad date falls back to the whole year, and the chart shows one column per month in range.
        $whole = Livewire::withQueryParams(['from' => 'not-a-date', 'to' => "{$year}-12-31"])->test('pages::dashboard');
        $this->assertSame(2, $whole->instance()->facultyCounts['submitted']);
        $this->assertCount(12, $whole->instance()->monthly);

        $this->assertCount(24, Livewire::withQueryParams(['from' => ($year - 3).'-01-01', 'to' => "{$year}-12-31"])->test('pages::dashboard')->instance()->monthly);

        // Nothing before the portal went live counts, even when the dates reach back further.
        $early = Livewire::withQueryParams(['from' => (Submission::FIRST_YEAR - 5).'-01-01', 'to' => "{$year}-12-31"])->test('pages::dashboard');
        $this->assertSame(Submission::FIRST_YEAR.'-01-01', $early->instance()->range[0]->toDateString());

        Livewire::test('pages::dashboard')
            ->call('preset', 'last-12-months')
            ->assertSet('to', today()->toDateString())
            ->assertSet('from', now()->subMonths(11)->startOfMonth()->toDateString());
    }

    public function test_admins_open_the_faculty_behind_a_count_and_export_it(): void
    {
        $natividad = $this->facultyInDepartment('Natividad');
        $siton = $this->facultyInDepartment('Siton');
        $report = ['awaiting_review' => false, 'terminal_path' => 'submissions/report.pdf', 'terminal_uploaded_at' => now()->subDay()];
        $this->submit($natividad, 'Finished Study', 'Completed', $report + ['target_date' => today()]);
        $this->submit($natividad, 'Overdue Study', 'Completed', $report + ['target_date' => today()->subWeek()]);
        $this->submit($natividad, 'Unreported Study', 'Completed', ['awaiting_review' => false, 'target_date' => today()]);
        $this->submit($siton, 'Ongoing Study', 'Concept', ['awaiting_review' => false]);

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('dashboard'))->assertSee(route('dashboard', ['from' => now()->year.'-01-01', 'to' => now()->year.'-12-31', 'group' => 'completed']));

        Livewire::withQueryParams(['group' => 'completed'])
            ->test('pages::dashboard')
            ->assertSee('Completed, '.now()->year)
            ->assertSee('Natividad')
            ->assertSee('Finished Study')
            // On time is the norm and gets no badge; only late and missing reports are flagged.
            ->assertSeeInOrder(['Natividad', 'CCS', '· 3', 'projects', 'Finished Study', 'Completed', 'Overdue Study', 'Completed', 'Late', 'Unreported Study', 'Completed', 'No terminal report'])
            ->assertDontSee('On time')
            ->assertDontSee('Siton')
            ->call('export')
            ->assertFileDownloaded('completed-'.now()->year.'-01-01-'.now()->year.'-12-31.csv');

        $this->actingAs($natividad);
        Livewire::withQueryParams(['group' => 'completed'])->test('pages::dashboard')->assertDontSee('data-test="faculty-list"', escape: false)->call('export')->assertForbidden();
    }

    public function test_admins_see_faculty_not_yet_submitted_by_department(): void
    {
        $year = now()->year;
        $ccs = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);
        $cbm = Department::create(['code' => 'CBM', 'name' => 'College of Business and Management']);
        $natividad = User::factory()->create(['name' => 'Natividad', 'department_id' => $ccs->id]);
        $siton = User::factory()->create(['name' => 'Siton', 'department_id' => $ccs->id]);
        $reyes = User::factory()->create(['name' => 'Reyes', 'department_id' => $cbm->id]);
        User::factory()->unverified()->create(['name' => 'Unverified Person', 'department_id' => $cbm->id]);

        // Siton is only a co-proponent, which still counts as submitting; Reyes filed last year only.
        $this->submit($natividad, 'SMART-ResearchTrack', 'Submitted', ['awaiting_review' => false])
            ->proponents()->create(['user_id' => $siton->id, 'study' => 1, 'role' => 'Staff']);
        $this->travelTo(now()->subYear(), fn () => $this->submit($reyes, 'Old Study', 'Submitted', ['year' => $year - 1, 'awaiting_review' => false]));

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $counts = Livewire::test('pages::dashboard')->instance()->facultyCounts;
        $this->assertSame([2, 1], [$counts['submitted'], $counts['pending']]);

        Livewire::withQueryParams(['group' => 'pending', 'dept' => 'CBM'])
            ->test('pages::dashboard')
            ->assertSee("Not yet submitted, CBM, {$year}")
            ->assertSee('Reyes')
            ->assertSeeHtml('href="mailto:'.$reyes->email.'"')
            ->assertDontSee('CBM · 0')
            ->assertDontSee('Unverified Person')
            ->assertDontSee('By college')
            ->call('export')
            ->assertFileDownloaded("not-yet-submitted-cbm-{$year}-01-01-{$year}-12-31.csv");

        Livewire::withQueryParams(['group' => 'pending', 'dept' => 'CCS'])
            ->test('pages::dashboard')
            ->assertSee("Every faculty member in CCS has submitted in {$year}.");
    }

    public function test_delayed_list_shows_days_overdue_and_how_to_reach_each_member(): void
    {
        $maria = $this->facultyInDepartment('Maria Santos');
        $jose = $this->facultyInDepartment('Jose Reyes');
        $this->submit($maria, 'Late Study', 'Detailed', ['awaiting_review' => false, 'target_date' => today()->subWeek()]);
        $this->submit($jose, 'Just Late Study', 'Concept', ['awaiting_review' => false, 'target_date' => today()->subDay()]);

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::withQueryParams(['group' => 'delayed'])
            ->test('pages::dashboard')
            ->assertSeeInOrder(['Jose Reyes', 'Just Late Study', 'Concept', '1 day overdue'])
            ->assertSeeInOrder(['Maria Santos', 'Late Study', 'Detailed', '7 days overdue'])
            ->assertSeeHtml("writeText('{$maria->email}')")
            ->assertSeeHtml("message = 'Copied!'")
            ->assertSeeHtml('role="status"')
            ->assertSeeHtml('aria-label="Copy Maria Santos’s email"');
    }

    public function test_admins_see_submissions_per_month_by_status(): void
    {
        $maria = $this->facultyInDepartment('Maria Santos');

        $this->travelTo(now()->subMonthNoOverflow()->startOfMonth()->addDays(3));
        $this->submit($maria, 'Last month A');
        $this->submit($maria, 'Last month B', 'Concept');
        $this->travelTo(now()->subYears(2));
        $this->submit($maria, 'Too old to chart');
        $this->travelBack();

        $lastMonth = now()->subMonthNoOverflow()->format('F Y');

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $sinceLastMonth = ['from' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'to' => today()->toDateString()];

        $this->get(route('dashboard', $sinceLastMonth))
            ->assertSee('Submissions per month')
            ->assertSee("{$lastMonth}: 2 projects, 1 Submitted, 1 Concept, 0 Detailed, 0 Completed")
            ->assertSee('Show as table');

        $byType = Livewire::withQueryParams($sinceLastMonth)->test('pages::dashboard')->instance()->breakdowns[__('By research type')];
        $this->assertSame([['label' => 'Thesis', 'title' => 'Thesis', 'count' => 2]], $byType->all());
    }

    public function test_admins_are_told_about_missing_departments_and_setup(): void
    {
        User::factory()->create(['role' => 'faculty', 'department_id' => null]);

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('dashboard'))
            ->assertSee('One faculty member has no college')
            ->assertSee('Finish setup')
            ->assertSee(route('research-types.index'), escape: false)
            ->assertSee('Nothing is waiting for review.');
    }

    public function test_faculty_see_their_own_progress_and_what_needs_attention(): void
    {
        $maria = $this->facultyInDepartment('Maria Santos');
        $jose = $this->facultyInDepartment('Jose Reyes');

        $this->submit($maria, 'Maria Proposal', 'Submitted', ['awaiting_review' => false, 'remarks' => 'Add a methodology section.']);
        $this->submit($maria, 'Late Study', 'Detailed', ['target_date' => today()->subWeek()]);
        $this->submit($jose, 'Jose Proposal');

        $this->actingAs($maria);

        $this->get(route('dashboard'))
            ->assertSee('Needs your attention')
            ->assertSee('Remarks: Add a methodology section.')
            ->assertSee('Late Study')
            ->assertSee('Target date '.today()->subWeek()->format('M j, Y').' has passed')
            ->assertSee('Maria Proposal')
            ->assertDontSee('Jose Proposal')
            ->assertDontSee('data-test="waiting-tile"', escape: false)
            ->assertDontSee('data-test="year-select"', escape: false)
            ->assertSee('My submissions per month')
            ->assertSee(route('submissions.create'), escape: false);
    }

    public function test_faculty_without_a_department_are_told_to_contact_the_research_office(): void
    {
        $this->actingAs(User::factory()->create(['department_id' => null]));

        $this->get(route('dashboard'))
            ->assertSee('Your account has no college yet.')
            ->assertSee('Nothing needs your attention.')
            ->assertDontSee(route('submissions.create'), escape: false);
    }

    public function test_admins_arrive_on_submissions_filtered_or_reviewing(): void
    {
        $maria = $this->facultyInDepartment('Maria Santos');
        $waiting = $this->submit($maria, 'Waiting Proposal');
        $this->submit($maria, 'Reviewed Proposal', 'Concept', ['awaiting_review' => false]);

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::withQueryParams(['status' => 'review'])
            ->test('pages::submissions.index')
            ->assertSee('Waiting Proposal')
            ->assertDontSee('Reviewed Proposal');

        Livewire::withQueryParams(['review' => $waiting->id])
            ->test('pages::submissions.index')
            ->assertSet('reviewingId', $waiting->id)
            ->assertSet('status', 'Submitted');
    }

    public function test_faculty_tiles_open_the_list_they_count(): void
    {
        $maria = $this->facultyInDepartment('Maria Santos');
        $this->submit($maria, 'Concept Study', 'Concept');
        $this->submit($maria, 'Finished Study', 'Completed');
        $this->submit($maria, 'Late Study', 'Submitted', ['target_date' => today()->subWeek()]);

        $this->actingAs($maria);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('submissions.index', ['status' => 'proposal']), escape: false)
            ->assertSee(route('submissions.index', ['status' => 'Completed']), escape: false)
            ->assertSee(route('submissions.index', ['status' => 'delayed']), escape: false);

        Livewire::withQueryParams(['status' => 'proposal'])->test('pages::submissions.index')
            ->assertSeeHtml('data-test="status-filter"')
            ->assertSee('Concept Study')->assertDontSee('Finished Study')->assertDontSee('Late Study');
        Livewire::withQueryParams(['status' => 'delayed'])->test('pages::submissions.index')
            ->assertSee('Late Study')->assertDontSee('Concept Study');
    }

    private function facultyInDepartment(string $name): User
    {
        $department = Department::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computer Studies']);

        return User::factory()->create(['name' => $name, 'department_id' => $department->id]);
    }

    private function submit(User $user, string $title, string $status = 'Submitted', array $attributes = []): Submission
    {
        $project = $user->submissions()->create([
            'research_type_id' => ResearchType::firstOrCreate(['name' => 'Thesis'])->id,
            'category_id' => Category::firstOrCreate(['name' => 'Computing'])->id,
            'department_id' => $user->department_id,
            'title' => $title,
            'concept_path' => 'submissions/sample.pdf',
            'status' => $status,
            ...$attributes,
        ]);

        $project->proponents()->create(['user_id' => $user->id, 'study' => 1, 'role' => 'Leader']);

        return $project;
    }
}
