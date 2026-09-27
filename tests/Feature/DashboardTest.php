<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\ResearchType;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_admins_see_the_oldest_proposals_waiting_for_review(): void
    {
        $maria = $this->facultyInDepartment('Maria Santos');

        foreach (range(1, 6) as $day) {
            Carbon::setTestNow(now()->startOfYear()->addDays($day));
            $this->submit($maria, "Proposal {$day}");
        }
        Carbon::setTestNow();

        $this->submit($maria, 'Already approved', 'OK');

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Waiting for review', 'Proposal 1', 'Proposal 5'])
            ->assertDontSee('Proposal 6')
            ->assertDontSee('Already approved')
            ->assertSee(route('submissions.index', ['review' => Submission::firstWhere('title', 'Proposal 1')->id]), escape: false)
            ->assertSee(route('submissions.index', ['status' => 'Pending']), escape: false);
    }

    public function test_admins_see_submissions_per_month_by_status(): void
    {
        $maria = $this->facultyInDepartment('Maria Santos');

        Carbon::setTestNow(now()->subMonthNoOverflow()->startOfMonth()->addDays(3));
        $this->submit($maria, 'Last month A');
        $this->submit($maria, 'Last month B', 'OK');
        Carbon::setTestNow(now()->subYears(2));
        $this->submit($maria, 'Too old to chart');
        Carbon::setTestNow();

        $lastMonth = now()->subMonthNoOverflow()->format('F Y');

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('dashboard'))
            ->assertSee('Submissions per month')
            ->assertSee("{$lastMonth}: 2 proposals, 1 Pending, 0 For Revision, 1 OK")
            ->assertSee('Show as table')
            ->assertSeeInOrder(['Proposals filed', '3', 'Waiting for review', '2', 'Approved', '1'])
            ->assertSeeInOrder(['By research type', 'Thesis', '3']);
    }

    public function test_admins_are_told_about_missing_departments_and_setup(): void
    {
        User::factory()->create(['role' => 'faculty', 'department_id' => null]);

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('dashboard'))
            ->assertSee('One faculty member has no department')
            ->assertSee('Finish setup')
            ->assertSee(route('research-types.index'), escape: false)
            ->assertSee('Nothing is waiting for review.');
    }

    public function test_faculty_see_their_revisions_and_only_their_own_proposals(): void
    {
        $maria = $this->facultyInDepartment('Maria Santos');
        $jose = $this->facultyInDepartment('Jose Reyes');

        $this->submit($maria, 'Maria Proposal', 'For Revision', 'Add a methodology section.');
        $this->submit($jose, 'Jose Proposal');

        $this->actingAs($maria);

        $this->get(route('dashboard'))
            ->assertSee('Needs your attention')
            ->assertSee('Remarks: Add a methodology section.')
            ->assertSee('Maria Proposal')
            ->assertDontSee('Jose Proposal')
            ->assertDontSee('data-test="waiting-tile"', escape: false)
            ->assertSee('My submissions per month')
            ->assertSee(now()->format('F Y').': one proposal, 0 Pending, 1 For Revision, 0 OK')
            ->assertSee(route('submissions.create'), escape: false);
    }

    public function test_faculty_without_a_department_are_told_to_contact_the_research_office(): void
    {
        $this->actingAs(User::factory()->create(['department_id' => null]));

        $this->get(route('dashboard'))
            ->assertSee('Your account has no department yet.')
            ->assertSee('Nothing needs your attention.')
            ->assertDontSee(route('submissions.create'), escape: false);
    }

    public function test_admins_arrive_on_submissions_filtered_or_reviewing(): void
    {
        $maria = $this->facultyInDepartment('Maria Santos');
        $pending = $this->submit($maria, 'Pending Proposal');
        $this->submit($maria, 'Approved Proposal', 'OK');

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::withQueryParams(['status' => 'Pending'])
            ->test('pages::submissions.index')
            ->assertSee('Pending Proposal')
            ->assertDontSee('Approved Proposal');

        Livewire::withQueryParams(['review' => $pending->id])
            ->test('pages::submissions.index')
            ->assertSet('reviewingId', $pending->id)
            ->assertSet('status', 'Pending');
    }

    private function facultyInDepartment(string $name): User
    {
        $department = Department::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computer Studies']);

        return User::factory()->create(['name' => $name, 'department_id' => $department->id]);
    }

    private function submit(User $user, string $title, string $status = 'Pending', ?string $remarks = null): Submission
    {
        return $user->submissions()->create([
            'research_type_id' => ResearchType::firstOrCreate(['name' => 'Thesis'])->id,
            'category_id' => Category::firstOrCreate(['name' => 'Computing'])->id,
            'department_id' => $user->department_id,
            'title' => $title,
            'file_path' => 'submissions/sample.pdf',
            'status' => $status,
            'remarks' => $remarks,
        ]);
    }
}
