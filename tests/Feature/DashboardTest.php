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

    public function test_the_sidebar_names_the_campus_and_the_signed_in_role(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('dashboard'))->assertSeeInOrder(['Faculty Research Portal', 'ISU - Cauayan', 'Filing options', 'Administrator']);

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
            ->assertSeeInOrder(['Concept proposals to review', 'Proposal 1', 'Proposal 5'])
            ->assertDontSee('Proposal 6')
            ->assertDontSee('Already reviewed')
            ->assertSee('View all 6')
            ->assertSee(route('reviews.index', ['review' => Submission::firstWhere('title', 'Proposal 1')->id]), escape: false)
            ->assertSee(route('reviews.index'), escape: false);
    }

    public function test_yearly_summary_counts_each_faculty_member_once_per_group(): void
    {
        $navarro = $this->facultyInDepartment('Navarro');
        $soriano = $this->facultyInDepartment('Soriano');
        $torres = $this->facultyInDepartment('Torres');
        // A year after go-live, so "last year" is one the portal tracks.
        $this->travelTo(now()->setYear(Submission::FIRST_YEAR + 1));
        $year = now()->year;

        // SMART-ResearchTrack: Navarro in all three studies, Soriano in two, Torres in one.
        $smart = $this->submit($navarro, 'SMART-ResearchTrack', 'Detailed');
        $smart->proponents()->createMany([
            ['user_id' => $soriano->id, 'study' => 1, 'role' => 'Staff'],
            ['user_id' => $navarro->id, 'study' => 2, 'role' => 'Leader'],
            ['user_id' => $soriano->id, 'study' => 2, 'role' => 'Staff'],
            ['user_id' => $torres->id, 'study' => 3, 'role' => 'Leader'],
            ['user_id' => $navarro->id, 'study' => 3, 'role' => 'Co-Leader'],
        ]);

        // Navarro also finished another project on time, and last year's project stays out of this year.
        $this->submit($navarro, 'Finished Study', 'Completed', ['target_date' => today(), 'terminal_uploaded_at' => now()->subDay()]);
        // travel() moves the frozen clock and leaves it there; travelTo() with a callback would reset it to real time.
        $this->travel(-1)->years();
        $this->submit($torres, 'Old Study', 'Completed', ['year' => $year - 1]);
        $this->travel(1)->years();

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $component = Livewire::test('pages::dashboard')->assertSet('from', "{$year}-01-01")->assertSet('to', "{$year}-12-31");
        $this->assertSame(['submitted' => 3, 'pending' => 0, 'proposal' => 3, 'midyear' => 0, 'completed' => 1, 'delayed' => 0], $component->instance()->facultyCounts);
        $component->assertSee('Across 2 projects in '.$year)->assertSee('1 of 1 project finished on time');

        $lastYear = Livewire::test('pages::dashboard')->call('preset', 'last-year');
        $this->assertSame(['submitted' => 1, 'pending' => 2, 'proposal' => 0, 'midyear' => 0, 'completed' => 1, 'delayed' => 0], $lastYear->instance()->facultyCounts);

        // The proposal-stage list names the projects without status badges.
        Livewire::withQueryParams(['group' => 'proposal'])
            ->test('pages::dashboard')
            ->assertSee('SMART-ResearchTrack')
            ->assertDontSee('Awaiting review');
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
        $navarro = $this->facultyInDepartment('Navarro');
        $soriano = $this->facultyInDepartment('Soriano');
        $report = ['awaiting_review' => false, 'terminal_path' => 'submissions/report.pdf', 'terminal_uploaded_at' => now()->subDay()];
        $this->submit($navarro, 'Finished Study', 'Completed', $report + ['target_date' => today()]);
        $this->submit($navarro, 'Overdue Study', 'Completed', $report + ['target_date' => today()->subWeek()]);
        $this->submit($soriano, 'Ongoing Study', 'Concept', ['awaiting_review' => false]);

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('dashboard'))->assertSee(route('dashboard', ['from' => now()->year.'-01-01', 'to' => now()->year.'-12-31', 'group' => 'completed']));

        Livewire::withQueryParams(['group' => 'completed'])
            ->test('pages::dashboard')
            ->assertSee('Completed, '.now()->year)
            ->assertSee('Navarro')
            ->assertSee('Finished Study')
            // The list names the projects only; the stat card already says which group they are in.
            ->assertSeeInOrder(['Navarro', 'CCS', '· 2', 'projects', 'Finished Study', 'Overdue Study'])
            ->assertDontSee('Late')
            ->assertDontSee('Soriano')
            ->call('export')
            ->assertFileDownloaded('completed-'.now()->year.'-01-01-'.now()->year.'-12-31.csv');

        $this->actingAs($navarro);
        Livewire::withQueryParams(['group' => 'completed'])->test('pages::dashboard')->assertDontSee('data-test="faculty-list"', escape: false)->call('export')->assertForbidden();
    }

    public function test_admins_see_faculty_not_yet_submitted_by_department(): void
    {
        $year = now()->year;
        $ccs = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);
        $cbm = Department::create(['code' => 'CBM', 'name' => 'College of Business and Management']);
        $navarro = User::factory()->create(['name' => 'Navarro', 'department_id' => $ccs->id]);
        $soriano = User::factory()->create(['name' => 'Soriano', 'department_id' => $ccs->id]);
        $reyes = User::factory()->create(['name' => 'Reyes', 'department_id' => $cbm->id]);
        User::factory()->unverified()->create(['name' => 'Unverified Person', 'department_id' => $cbm->id]);

        // Soriano is only a co-proponent, which still counts as submitting; Reyes filed last year only.
        $this->submit($navarro, 'SMART-ResearchTrack', 'Concept', ['awaiting_review' => false])
            ->proponents()->create(['user_id' => $soriano->id, 'study' => 1, 'role' => 'Staff']);
        $this->travelTo(now()->subYear(), fn () => $this->submit($reyes, 'Old Study', 'Concept', ['year' => $year - 1, 'awaiting_review' => false]));

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

    public function test_delayed_list_shows_how_to_reach_each_member(): void
    {
        $maria = $this->facultyInDepartment('Maria Santos');
        $jose = $this->facultyInDepartment('Jose Reyes');
        $this->submit($maria, 'Late Study', 'Detailed', ['awaiting_review' => false, 'target_date' => today()->subWeek()]);
        $this->submit($jose, 'Just Late Study', 'Concept', ['awaiting_review' => false, 'target_date' => today()->subDay()]);

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::withQueryParams(['group' => 'delayed'])
            ->test('pages::dashboard')
            ->assertSeeInOrder(['Jose Reyes', 'Just Late Study', 'Maria Santos', 'Late Study'])
            ->assertDontSee('days overdue')
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
        $this->submit($maria, 'Last month B', 'Detailed');
        $this->travelTo(now()->subYears(2));
        $this->submit($maria, 'Too old to chart');
        $this->travelBack();

        $lastMonth = now()->subMonthNoOverflow()->format('F Y');

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $sinceLastMonth = ['from' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'to' => today()->toDateString()];

        $this->get(route('dashboard', $sinceLastMonth))
            ->assertSee('Submissions per month')
            ->assertSee("{$lastMonth}: 2 projects, 1 Concept, 1 Detailed, 0 Mid-year, 0 Completed")
            ->assertSee('Show as table');

        // Projects have no research type any more, so the summary charts colleges and categories only.
        $breakdowns = Livewire::withQueryParams($sinceLastMonth)->test('pages::dashboard')->instance()->breakdowns;
        $this->assertSame([__('By college'), __('By category')], array_keys($breakdowns));
        $this->assertSame([['label' => 'Computing', 'title' => 'Computing', 'count' => 2]], $breakdowns[__('By category')]->all());

        // The chart follows the college filter like the rest of the summary.
        Department::create(['code' => 'CAS', 'name' => 'College of Arts and Sciences']);
        $this->get(route('dashboard', $sinceLastMonth + ['dept' => 'CAS']))
            ->assertSee('No projects in CAS, ', escape: false)
            ->assertDontSee("{$lastMonth}: 2 projects");
    }

    public function test_admins_are_told_about_missing_departments_and_setup(): void
    {
        User::factory()->create(['role' => 'faculty', 'department_id' => null]);

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('dashboard'))
            ->assertSee('One faculty member has no college')
            ->assertSee('Finish setup')
            ->assertSee(route('categories.index'), escape: false)
            ->assertSee('No concept proposals are waiting for review.');
    }

    public function test_faculty_see_their_own_progress_and_what_needs_attention(): void
    {
        $maria = $this->facultyInDepartment('Maria Santos');
        $jose = $this->facultyInDepartment('Jose Reyes');

        $this->submit($maria, 'Maria Proposal', 'Concept', ['awaiting_review' => false, 'remarks' => 'Add a methodology section.']);
        $this->submit($maria, 'Late Study', 'Detailed', ['target_date' => today()->subWeek()]);
        $this->submit($jose, 'Jose Proposal');

        $this->actingAs($maria);

        $this->get(route('dashboard'))
            ->assertSee('Needs your attention')
            ->assertSee('Concept needs revision')
            ->assertSee('What to fix: Add a methodology section.')
            ->assertSee('Late Study')
            ->assertSee('Target date '.today()->subWeek()->format('M j, Y').' has passed')
            ->assertSee('Maria Proposal')
            ->assertDontSee('Jose Proposal')
            ->assertDontSee('data-test="waiting-tile"', escape: false)
            ->assertDontSee('data-test="year-select"', escape: false)
            ->assertSee('My submissions per month')
            ->assertSee(route('submissions.create'), escape: false);
    }

    public function test_faculty_with_nothing_due_get_a_calm_dashboard(): void
    {
        $maria = $this->facultyInDepartment('MARIA DELA SANTOS');
        $due = today()->addMonths(3);
        $this->submit($maria, 'Sentiment_Analysis_Study', 'Concept', ['target_date' => $due]);
        $this->submit($maria, 'Second Study');

        $this->actingAs($maria);

        $this->get(route('dashboard'))
            ->assertSee('Welcome, MD Santos.')
            // One line instead of an empty panel, and a zero delayed count stays gray
            ->assertSee('Nothing needs your attention.')
            ->assertDontSee('Needs your attention')
            ->assertDontSee('bg-status-delayed', escape: false)
            ->assertSee('bg-zinc-300', escape: false)
            // Underscores read as spaces, and each project shows when it is due
            ->assertSee('Sentiment Analysis Study')
            ->assertDontSee('Sentiment_Analysis_Study')
            ->assertSee('Due '.$due->format('M j, Y'))
            // Two projects in one month: the axis tops out at 3, above the tallest bar
            ->assertSeeHtml('<span class="-my-2">3</span>')
            ->assertSee('Show as table');
    }

    public function test_faculty_without_a_department_are_told_to_contact_the_research_office(): void
    {
        $this->actingAs(User::factory()->create(['department_id' => null]));

        $this->get(route('dashboard'))
            ->assertSee('Your account has no college yet.')
            ->assertSee('Nothing needs your attention.')
            ->assertDontSee(route('submissions.create'), escape: false);
    }

    public function test_admins_arrive_on_reviews_reviewing(): void
    {
        $waiting = $this->submit($this->facultyInDepartment('Maria Santos'), 'Waiting Proposal');

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::withQueryParams(['review' => $waiting->id])
            ->test('pages::reviews.index')
            ->assertSet('reviewingId', $waiting->id)
            ->assertSee('To review: Concept proposal');
    }

    public function test_faculty_tiles_open_the_list_they_count(): void
    {
        $maria = $this->facultyInDepartment('Maria Santos');
        $this->submit($maria, 'Concept Study', 'Concept');
        $this->submit($maria, 'Finished Study', 'Completed');
        $this->submit($maria, 'Late Study', 'Detailed', ['target_date' => today()->subWeek()]);

        $this->actingAs($maria);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('submissions.index', ['status' => 'proposal']), escape: false)
            ->assertSee(route('submissions.index', ['status' => 'Mid-year']), escape: false)
            ->assertSee(route('submissions.index', ['status' => 'Completed']), escape: false)
            ->assertSee(route('submissions.index', ['status' => 'delayed']), escape: false);

        Livewire::withQueryParams(['status' => 'proposal'])->test('pages::submissions.index')
            ->assertSeeHtml('data-test="status-filter"')
            ->assertSee('Concept Study')->assertSee('Late Study')->assertDontSee('Finished Study');
        Livewire::withQueryParams(['status' => 'delayed'])->test('pages::submissions.index')
            ->assertSee('Late Study')->assertDontSee('Concept Study');
    }

    public function test_the_dashboard_runs_a_fixed_number_of_queries(): void
    {
        $maria = $this->facultyInDepartment('Maria Santos');
        $jose = $this->facultyInDepartment('Jose Reyes');

        foreach (range(1, 12) as $i) {
            $this->submit($i % 2 ? $maria : $jose, "Project {$i}", Submission::STATUSES[$i % 3], ['target_date' => today()->subDays($i - 6)]);
        }

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->assertQueriesAtMost(18, fn () => $this->get(route('dashboard'))->assertOk());

        $this->actingAs($maria);
        // One of these is the sidebar's count of projects that need revision.
        $this->assertQueriesAtMost(4, fn () => $this->get(route('dashboard'))->assertOk());
    }

    private function facultyInDepartment(string $name): User
    {
        $department = Department::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computer Studies']);

        return User::factory()->create(['name' => $name, 'department_id' => $department->id]);
    }

    private function submit(User $user, string $title, string $status = 'Concept', array $attributes = []): Submission
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
