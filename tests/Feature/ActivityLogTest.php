<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Department;
use App\Models\ResearchType;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        $this->admin = User::factory()->create(['name' => 'Research Office', 'role' => 'admin']);
    }

    public function test_sign_ins_and_failed_attempts_are_recorded(): void
    {
        $maria = User::factory()->create(['name' => 'Maria Santos', 'email' => 'maria@isu.edu.ph']);

        $this->post(route('login.store'), ['email' => 'maria@isu.edu.ph', 'password' => 'wrong-password']);
        $this->post(route('login.store'), ['email' => 'maria@isu.edu.ph', 'password' => 'password']);

        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => 'nobody@isu.edu.ph', 'password' => 'password']);

        $this->assertSame(['auth.failed', 'auth.login', 'auth.logout', 'auth.failed'], ActivityLog::orderBy('id')->pluck('action')->all());
        $this->assertTrue(ActivityLog::where('user_id', $maria->id)->where('actor_name', 'Maria Santos')->where('action', 'auth.login')->exists());

        [$wrongPassword, $noAccount] = ActivityLog::where('action', 'auth.failed')->orderBy('id')->get();
        $this->assertSame(['email' => 'maria@isu.edu.ph'], $wrongPassword->properties);
        $this->assertSame(['Failed sign-in', 'maria@isu.edu.ph', 'Wrong password'], [$wrongPassword->summary(), $wrongPassword->subject(), $wrongPassword->note()]);
        $this->assertSame(['email' => 'nobody@isu.edu.ph', 'no_account' => true], $noAccount->properties);
        $this->assertSame('No account uses this email', $noAccount->note());
    }

    public function test_a_review_records_the_status_change(): void
    {
        $project = $this->project('Solar Dryer Study');

        $this->actingAs($this->admin);

        Livewire::test('pages::submissions.index')
            ->call('review', $project->id)
            ->set('status', 'Concept')
            ->call('saveReview')
            ->assertHasNoErrors();

        $log = ActivityLog::sole();
        $this->assertSame('submission.reviewed', $log->action);
        $this->assertSame(['status' => ['Submitted', 'Concept']], $log->properties);
        $this->assertTrue($log->subject_id === $project->id && $log->subject_label === 'Solar Dryer Study');

        $this->get(route('activity-log.index'))
            ->assertOk()
            ->assertSeeInOrder(['Research Office', 'Reviewed a project', 'Solar Dryer Study', 'Submitted', 'to', 'Concept'])
            ->assertSee(route('drive.show', $project), escape: false);
    }

    public function test_a_role_change_is_recorded_and_flagged(): void
    {
        $maria = User::factory()->create(['name' => 'Maria Santos']);

        $this->actingAs($this->admin);

        Livewire::test('pages::users.index')
            ->call('edit', $maria->id)
            ->set('role', 'admin')
            ->call('save')
            ->assertHasNoErrors();

        $log = ActivityLog::sole();
        $this->assertSame(['role' => ['faculty', 'admin']], $log->properties);
        $this->assertTrue($log->isWarning());
        $this->assertSame(['Faculty', 'Admin'], $log->change());

        $this->get(route('activity-log.index'))->assertSeeInOrder(['Edited an account', 'Security', 'Maria Santos', 'Faculty', 'Admin']);
    }

    public function test_saving_an_unchanged_account_records_nothing(): void
    {
        $maria = User::factory()->create();

        $this->actingAs($this->admin);

        Livewire::test('pages::users.index')->call('edit', $maria->id)->call('save')->assertHasNoErrors();

        $this->assertSame(0, ActivityLog::count());
    }

    public function test_deleting_an_account_keeps_its_history_under_the_saved_name(): void
    {
        $maria = User::factory()->create(['name' => 'Maria Santos']);
        ActivityLog::record('auth.login', user: $maria);

        $this->actingAs($this->admin);

        Livewire::test('pages::users.index')
            ->call('confirmDelete', $maria->id)
            ->assertDontSee('can’t be deleted')
            ->call('delete');

        $this->assertModelMissing($maria);
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.login', 'user_id' => null, 'actor_name' => 'Maria Santos']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'user.deleted', 'user_id' => $this->admin->id, 'subject_label' => 'Maria Santos']);

        $this->get(route('activity-log.index'))->assertSeeInOrder(['Research Office', 'Deleted an account', 'Maria Santos', 'Maria Santos', 'Signed in']);
    }

    public function test_names_typed_by_users_are_escaped(): void
    {
        $this->actingAs($this->admin);
        ActivityLog::record('filing.created', Category::create(['name' => '<script>alert(1)</script>']), ['kind' => 'category']);

        $this->get(route('activity-log.index'))
            ->assertDontSee('<script>alert(1)</script>', escape: false)
            ->assertSee('<script>alert(1)</script>');
    }

    public function test_the_log_can_be_filtered_by_kind_person_and_search(): void
    {
        $maria = User::factory()->create(['name' => 'Maria Santos']);
        ActivityLog::record('auth.login', user: $maria);
        ActivityLog::record('filing.created', ResearchType::create(['name' => 'Capstone']), ['kind' => 'research type'], $this->admin);

        $this->actingAs($this->admin);

        Livewire::test('pages::activity-log.index')
            ->assertSee('Signed in')
            ->assertSee('Capstone')
            ->set('group', 'filing')
            ->assertSee('Capstone')
            ->assertDontSee('Signed in')
            ->call('clearFilters')
            ->set('user', $maria->id)
            ->assertSee('Showing activity for')
            ->assertSee('Signed in')
            ->assertDontSee('Capstone')
            ->call('clearFilters')
            ->set('search', 'capst')
            ->assertSee('Capstone')
            ->assertDontSee('Signed in')
            ->set('search', 'nobody')
            ->assertSee('No activity matches these filters');
    }

    public function test_runs_of_sign_ins_fold_into_one_line_around_changes(): void
    {
        $maria = User::factory()->create(['name' => 'Maria Santos']);
        $jose = User::factory()->create(['name' => 'Jose Reyes']);
        ActivityLog::record('auth.login', user: $maria);
        ActivityLog::record('auth.logout', user: $maria);
        ActivityLog::record('filing.created', Category::create(['name' => 'Computing']), ['kind' => 'category'], $this->admin);
        ActivityLog::record('auth.login', user: $maria);
        ActivityLog::record('auth.login', user: $jose);
        ActivityLog::record('auth.login', user: $this->admin);

        $this->actingAs($this->admin);

        // Newest first: the later run of three, then the change, then the earlier run of two.
        $this->get(route('activity-log.index'))
            ->assertSeeInOrder(['3 sign-ins · 3 people', 'Jose Reyes', 'Added a category', '1 sign-in, 1 sign-out · 1 person'])
            ->assertSeeHtml('aria-expanded="false"');
    }

    public function test_a_folded_run_shows_the_time_span_it_covers(): void
    {
        $this->travelTo(today()->setTime(11, 37, 53));
        $first = ActivityLog::record('auth.logout', user: $this->admin);
        $this->travelTo(today()->setTime(11, 38, 52));
        $last = ActivityLog::record('auth.login', user: $this->admin);
        $this->travelTo(today()->setTime(13, 5));
        $afternoon = ActivityLog::record('auth.login', user: $this->admin);

        $this->assertSame('11:37 – 11:38 AM', ActivityLog::runSpan(collect([$last, $first])));
        $this->assertSame('11:38 AM', ActivityLog::runSpan(collect([$last])));
        $this->assertSame('11:37 AM – 1:05 PM', ActivityLog::runSpan(collect([$afternoon, $last, $first])));
    }

    public function test_a_failed_sign_in_never_saves_text_that_isnt_an_email(): void
    {
        User::factory()->create(['email' => 'maria@isu.edu.ph']);

        $this->post(route('login.store'), ['email' => 'MySecretPassw0rd!', 'password' => 'x']);
        $this->post(route('login.store'), ['email' => 'Maria@ISU.edu.ph', 'password' => 'wrong-password']);

        [$typo, $wrongPassword] = ActivityLog::orderBy('id')->get();
        $this->assertSame(['not_email' => true, 'no_account' => true], $typo->properties);
        $this->assertSame('Something other than an email was typed', $typo->note());
        $this->assertSame(['email' => 'maria@isu.edu.ph'], $wrongPassword->properties);

        $this->actingAs($this->admin);
        Livewire::test('pages::activity-log.index')
            ->set('search', 'MARIA@isu')
            ->assertSee('maria@isu.edu.ph')
            ->assertDontSee('MySecretPassw0rd!');
    }

    public function test_entries_older_than_the_retention_period_are_pruned(): void
    {
        $this->travelTo(now()->subMonths(ActivityLog::KEEP_MONTHS)->subDay());
        ActivityLog::record('auth.login', user: $this->admin);
        $this->travelBack();
        $recent = ActivityLog::record('auth.login', user: $this->admin);

        $this->artisan('model:prune', ['--model' => [ActivityLog::class]])->assertSuccessful();

        $this->assertSame([$recent->id], ActivityLog::pluck('id')->all());
    }

    public function test_project_entries_outlive_the_retention_period(): void
    {
        $project = $this->project('Solar Dryer Study');

        $this->travelTo(now()->subMonths(ActivityLog::KEEP_MONTHS)->subDay());
        $review = ActivityLog::record('submission.reviewed', $project, ['status' => ['Submitted', 'Concept']], $this->admin);
        ActivityLog::record('auth.login', user: $this->admin);
        $this->travelBack();

        $this->artisan('model:prune', ['--model' => [ActivityLog::class]])->assertSuccessful();

        // A project can run for years, and its Drive page shows the whole history.
        $this->assertSame([$review->id], ActivityLog::pluck('id')->all());
    }

    public function test_bad_dates_in_the_url_are_ignored_and_a_reversed_range_is_swapped(): void
    {
        $this->travelTo(today()->setDate(2026, 10, 1)->setTime(9, 0));
        ActivityLog::record('filing.created', Category::create(['name' => 'Computing']), ['kind' => 'category'], $this->admin);

        $this->actingAs($this->admin);

        Livewire::test('pages::activity-log.index')
            ->set('from', '2026-10-05')->set('to', '2026-09-28')
            ->assertSee('Computing')
            ->set('from', '2026-13-45')->set('to', '')
            ->assertSee('Computing');
    }

    public function test_a_page_past_the_end_says_so_instead_of_claiming_the_log_is_empty(): void
    {
        ActivityLog::record('auth.login', user: $this->admin);

        $this->actingAs($this->admin);

        $this->get(route('activity-log.index', ['page' => 9]))
            ->assertSee('This page is past the end of the log')
            ->assertDontSee('No activity recorded yet');
    }

    public function test_a_run_cut_by_the_page_break_says_where_the_rest_is(): void
    {
        foreach (range(1, 103) as $i) {
            ActivityLog::record('auth.login', user: $this->admin);
        }

        $this->actingAs($this->admin);

        $this->get(route('activity-log.index'))
            ->assertSee('100 sign-ins · 1 person')
            ->assertSee('continues on the next page');
        $this->get(route('activity-log.index', ['page' => 2]))
            ->assertSee('3 sign-ins · 1 person')
            ->assertSee('continued from the previous page');
    }

    public function test_a_project_edit_names_the_fields_it_changed(): void
    {
        $project = $this->project('Solar Dryer Study');
        $maria = $project->user;
        $project->proponents()->create(['user_id' => $maria->id, 'study' => 1, 'role' => 'Leader']);
        $project->proponents()->create(['user_id' => User::factory()->create(['name' => 'Ana Cruz'])->id, 'study' => 1, 'role' => 'Co-Leader']);

        $this->actingAs($maria);

        $form = Livewire::test('pages::submissions.create', ['submission' => $project]);
        $form->call('save')->assertHasNoErrors();
        $this->assertSame(0, ActivityLog::count(), 'Saving without changes is left out of the log.');

        $form->set('title', 'Solar Dryer Study, Phase 2')
            ->set('proponents.0.role', 'Co-Leader')
            ->set('research_type_id', ResearchType::create(['name' => 'Capstone'])->id)
            ->call('removeProponent', 1)
            ->set('proponents.1', ['study' => 1, 'user_id' => User::factory()->create(['name' => 'Jose Reyes'])->id, 'role' => 'Staff'])
            ->call('save')
            ->assertHasNoErrors();

        $log = ActivityLog::sole();
        $this->assertSame([
            'values' => [
                'title' => ['Solar Dryer Study', 'Solar Dryer Study, Phase 2'],
                'research_type_id' => ['Thesis', 'Capstone'],
            ],
            'proponents' => ['added' => [['Jose Reyes', 'Staff']], 'removed' => [['Ana Cruz', 'Co-Leader']], 'roles' => [[$maria->name, 'Leader', 'Co-Leader']]],
        ], $log->properties);
        $this->assertSame(
            'Changed the title from “Solar Dryer Study” to “Solar Dryer Study, Phase 2” · Changed the research type from Thesis to Capstone · Added Jose Reyes as Staff to the proponents · Removed Co-Leader Ana Cruz from the proponents · Changed '.$maria->name.' from Leader to Co-Leader',
            $log->note(),
        );
    }

    public function test_an_abstract_edit_is_only_named(): void
    {
        $project = $this->project('Solar Dryer Study');
        $project->proponents()->create(['user_id' => $project->user->id, 'study' => 1, 'role' => 'Leader']);

        $this->actingAs($project->user);

        Livewire::test('pages::submissions.create', ['submission' => $project])
            ->set('abstract', 'A new abstract.')
            ->set('designation', 'Phase 1')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['changed' => ['abstract', 'designation']], ActivityLog::sole()->properties);
        $this->assertSame('Updated the abstract and designation', ActivityLog::sole()->note());
    }

    public function test_only_the_research_office_moves_a_projects_dates_and_the_log_shows_it(): void
    {
        $project = $this->project('Solar Dryer Study');
        $oldEnd = $project->target_date->toDateString();
        $newEnd = today()->addYears(2)->toDateString();

        $this->actingAs($this->admin);

        Livewire::test('pages::submissions.index')
            ->call('editDates', $project->id)
            ->assertSet('target_date', $oldEnd)
            ->set('target_date', $newEnd)
            ->call('saveDates')
            ->assertHasNoErrors();

        $this->assertSame($newEnd, $project->fresh()->target_date->toDateString());
        $this->assertSame('Submitted', $project->fresh()->status, 'Moving dates is not a review.');
        $this->assertFalse(ActivityLog::where('action', 'submission.reviewed')->exists());

        $moved = ActivityLog::firstWhere('action', 'submission.dates_changed');
        $this->assertSame('changed a project’s dates', $moved->summary());
        $this->assertSame(['values' => ['target_date' => [$oldEnd, $newEnd]]], $moved->properties);
        $this->assertSame('Research Office', $moved->actor_name);
        $this->assertStringStartsWith('Changed the completion date from', $moved->note());

        $this->actingAs($project->user);
        Livewire::test('pages::submissions.index')->call('editDates', $project->id)->assertForbidden();
    }

    public function test_each_review_keeps_its_own_remarks(): void
    {
        $project = $this->project('Solar Dryer Study');

        $this->actingAs($this->admin);

        Livewire::test('pages::submissions.index')
            ->call('review', $project->id)->set('status', 'Submitted')->set('remarks', 'Add the budget table.')->call('saveReview')
            ->call('review', $project->id)->set('status', 'Concept')->set('remarks', 'Approved.')->call('saveReview')
            ->assertHasNoErrors();

        $this->assertSame(['Approved.', 'Add the budget table.'], ActivityLog::latest('id')->get()->map->remarks()->all());

        $this->get(route('activity-log.index'))->assertSeeInOrder(['Remarks: Approved.', 'Remarks: Add the budget table.']);
    }

    public function test_manage_users_links_to_each_members_activity(): void
    {
        $maria = User::factory()->create();

        $this->actingAs($this->admin);

        $this->get(route('users.index'))->assertSee(route('activity-log.index', ['user' => $maria->id]), escape: false);
    }

    private function project(string $title): Submission
    {
        $maria = User::factory()->create(['department_id' => Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies'])->id]);

        return $maria->submissions()->create([
            'research_type_id' => ResearchType::create(['name' => 'Thesis'])->id,
            'category_id' => Category::create(['name' => 'Computing'])->id,
            'department_id' => $maria->department_id,
            'title' => $title,
            'abstract' => 'An abstract.',
            'start_date' => today()->toDateString(),
            'target_date' => today()->addYear()->toDateString(),
            'status' => 'Submitted',
            // Every project starts with its concept proposal, which a review can accept.
            'concept_path' => 'submissions/concept.pdf',
        ]);
    }
}
