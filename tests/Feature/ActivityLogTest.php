<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Department;
use App\Models\ResearchType;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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

    public function test_sign_ins_are_recorded_and_failed_attempts_are_not(): void
    {
        $maria = User::factory()->create(['name' => 'Maria Santos', 'email' => 'maria@isu.edu.ph']);

        $this->post(route('login.store'), ['email' => 'maria@isu.edu.ph', 'password' => 'wrong-password']);
        $this->post(route('login.store'), ['email' => 'maria@isu.edu.ph', 'password' => 'password']);
        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => 'nobody@isu.edu.ph', 'password' => 'password']);

        $this->assertSame(['auth.login', 'auth.logout'], ActivityLog::orderBy('id')->pluck('action')->all());
        $this->assertTrue(ActivityLog::where('user_id', $maria->id)->where('actor_name', 'Maria Santos')->where('action', 'auth.login')->exists());
    }

    public function test_a_review_is_recorded_as_a_concept_proposal_review(): void
    {
        $project = $this->project('Solar Dryer Study');

        $this->actingAs($this->admin);

        Livewire::test('pages::reviews.index')
            ->call('review', $project->id)
            ->set('remarks', 'Add the budget table.')
            ->call('saveReview', 'returned')
            ->assertHasNoErrors();

        $log = ActivityLog::sole();
        $this->assertSame('submission.reviewed', $log->action);
        $this->assertSame(['decision' => 'returned', 'remarks' => 'Add the budget table.'], $log->properties, 'A review never records a status.');
        $this->assertTrue($log->subject_id === $project->id && $log->subject_label === 'Solar Dryer Study');

        $this->get(route('activity-log.index'))
            ->assertOk()
            ->assertSeeInOrder(['Research Office', 'Returned a concept proposal for revision', 'Solar Dryer Study', 'Remarks: Add the budget table.'])
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

    public function test_runs_of_three_or_more_sign_ins_fold_into_one_line_around_changes(): void
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

        // Newest first: the later run of three folds; the earlier run of two stays as two plain rows,
        // since folding it would save one row at the cost of a click.
        $page = $this->get(route('activity-log.index'))
            ->assertSeeInOrder(['Research Office, Jose Reyes +1 other · 3 sign-ins', 'Added a category', 'Signed out', 'Signed in'])
            ->getContent();

        $this->assertSame(1, substr_count($page, 'aria-expanded="false"'));
    }

    public function test_a_folded_run_names_who_signed_in_and_out(): void
    {
        $maria = User::factory()->create(['name' => 'Maria Santos']);
        $jose = User::factory()->create(['name' => 'Jose Reyes']);
        $login = fn (User $user) => ActivityLog::record('auth.login', user: $user);
        $logout = fn (User $user) => ActivityLog::record('auth.logout', user: $user);

        $this->assertSame('Maria Santos · 2 sign-ins, 1 sign-out', ActivityLog::runSummary(collect([$login($maria), $logout($maria), $login($maria)])));
        $this->assertSame('Jose Reyes and Maria Santos · 2 sign-ins, 1 sign-out', ActivityLog::runSummary(collect([$login($jose), $logout($maria), $login($maria)])));
        $this->assertSame('Research Office, Jose Reyes +2 others · 4 sign-ins', ActivityLog::runSummary(collect([
            $login($this->admin), $login($jose), $login($maria), $login(User::factory()->create(['name' => 'Ana Cruz'])),
        ])));
    }

    public function test_each_day_is_named_once_above_its_entries(): void
    {
        $this->travelTo(now()->subDay());
        ActivityLog::record('filing.created', Category::create(['name' => 'Computing']), ['kind' => 'category'], $this->admin);
        ActivityLog::record('filing.created', Category::create(['name' => 'Education']), ['kind' => 'category'], $this->admin);
        $this->travelBack();
        $this->freezeTime();
        ActivityLog::record('filing.created', Category::create(['name' => 'Nursing']), ['kind' => 'category'], $this->admin);

        $this->actingAs($this->admin);

        $page = $this->get(route('activity-log.index'))
            ->assertSeeInOrder(['data-test="day"', 'Today', 'Nursing', 'data-test="day"', 'Yesterday', 'Education', 'Computing'], escape: false)
            ->getContent();

        $this->assertSame(2, substr_count($page, 'data-test="day"'));
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
        $review = ActivityLog::record('submission.reviewed', $project, [], $this->admin);
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

    public function test_new_activity_does_not_shift_the_pages_being_read(): void
    {
        foreach (range(1, 150) as $i) {
            ActivityLog::record('filing.created', Category::create(['name' => "Category {$i}"]), ['kind' => 'category'], $this->admin);
        }
        $firstOnPageTwo = ActivityLog::query()->orderByDesc('id')->skip(100)->value('id');

        $this->actingAs($this->admin);
        $component = Livewire::test('pages::activity-log.index')->assertDontSee('New activity since you opened the log');

        foreach (range(1, 3) as $i) {
            ActivityLog::record('auth.login', user: $this->admin);
        }

        $component->call('nextPage')->assertSee('New activity since you opened the log');
        $this->assertSame($firstOnPageTwo, $component->instance()->logs->first()->id);

        $component->call('showNew')
            ->assertDontSee('New activity since you opened the log')
            ->assertSet('paginators.page', 1);
        $this->assertSame(ActivityLog::max('id'), $component->instance()->logs->first()->id);
    }

    public function test_a_page_reads_the_log_in_one_query_without_counting_it(): void
    {
        foreach (range(1, 250) as $i) {
            ActivityLog::record('auth.login', user: $this->admin);
        }
        $reads = 0;
        DB::listen(function ($query) use (&$reads) {
            $reads += str_contains($query->sql, 'from "activity_logs"') ? 1 : 0;
        });

        $this->actingAs($this->admin)
            ->get(route('activity-log.index', ['page' => 2]))
            ->assertSee('continued from the previous page')
            ->assertSee('continues on the next page');

        // The page with its neighbours, and whether anything is newer than the page was opened at.
        $this->assertSame(2, $reads);
    }

    public function test_a_run_cut_by_the_page_break_says_where_the_rest_is(): void
    {
        foreach (range(1, 103) as $i) {
            ActivityLog::record('auth.login', user: $this->admin);
        }

        $this->actingAs($this->admin);

        $this->get(route('activity-log.index'))
            ->assertSee('Research Office · 100 sign-ins')
            ->assertSee('continues on the next page');
        $this->get(route('activity-log.index', ['page' => 2]))
            ->assertSee('Research Office · 3 sign-ins')
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
            ->set('category_id', Category::create(['name' => 'Agriculture'])->id)
            ->call('removeProponent', 1)
            ->set('proponents.1', ['study' => 1, 'user_id' => User::factory()->create(['name' => 'Jose Reyes'])->id, 'role' => 'Staff'])
            ->call('save')
            ->assertHasNoErrors();

        $log = ActivityLog::sole();
        $this->assertSame([
            'values' => [
                'title' => ['Solar Dryer Study', 'Solar Dryer Study, Phase 2'],
                'category_id' => ['Computing', 'Agriculture'],
            ],
            'proponents' => ['added' => [['Jose Reyes', 'Staff']], 'removed' => [['Ana Cruz', 'Co-Leader']], 'roles' => [[$maria->name, 'Leader', 'Co-Leader']]],
        ], $log->properties);
        $this->assertSame(
            'Changed the title from “Solar Dryer Study” to “Solar Dryer Study, Phase 2” · Changed the category from Computing to Agriculture · Added Jose Reyes as Staff to the proponents · Removed Co-Leader Ana Cruz from the proponents · Changed '.$maria->name.' from Leader to Co-Leader',
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

    public function test_an_upload_over_an_earlier_file_reads_as_replaced(): void
    {
        $this->fakeDrive();
        $project = $this->project('Solar Dryer Study');
        $project->proponents()->create(['user_id' => $project->user->id, 'study' => 1, 'role' => 'Leader']);

        $this->actingAs($project->user);

        Livewire::test('pages::submissions.create', ['submission' => $project])
            ->set('documents.concept', UploadedFile::fake()->create('concept-v2.pdf', 100, 'application/pdf'))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['documents' => ['concept'], 'replaced' => ['concept']], ActivityLog::sole()->properties);
        $this->assertSame('Replaced the concept proposal', ActivityLog::sole()->note());
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
        $this->assertSame('Concept', $project->fresh()->status, 'Moving dates is not a review.');
        $this->assertFalse(ActivityLog::where('action', 'submission.reviewed')->exists());

        $moved = ActivityLog::firstWhere('action', 'submission.dates_changed');
        $this->assertSame('changed a project’s dates', $moved->summary());
        $this->assertSame(['values' => ['target_date' => [$oldEnd, $newEnd]]], $moved->properties);
        $this->assertSame('Research Office', $moved->actor_name);
        $this->assertStringStartsWith('Changed the completion date from', $moved->note());

        $this->actingAs($project->user);
        Livewire::test('pages::submissions.index')->call('editDates', $project->id)->assertForbidden();
    }

    public function test_saving_unchanged_dates_logs_nothing_and_says_so(): void
    {
        $project = $this->project('Solar Dryer Study');

        $this->actingAs($this->admin);

        Livewire::test('pages::submissions.index')
            ->call('editDates', $project->id)
            ->call('saveDates')
            ->assertHasNoErrors()
            ->assertDispatched('toast-show', fn ($event, $params) => $params['slots']['text'] === 'No changes to save.');

        $this->assertFalse(ActivityLog::where('action', 'submission.dates_changed')->exists());
    }

    public function test_a_completion_date_before_the_start_reads_plainly(): void
    {
        $project = $this->project('Solar Dryer Study');

        $this->actingAs($this->admin);

        Livewire::test('pages::submissions.index')
            ->call('editDates', $project->id)
            ->set('target_date', $project->start_date->toDateString())
            ->call('saveDates')
            ->assertHasErrors(['target_date' => 'The completion date must be after the starting date.']);
    }

    public function test_a_completed_projects_dates_are_locked(): void
    {
        $project = $this->project('Solar Dryer Study');
        $project->update(['status' => 'Completed']);

        $this->actingAs($this->admin);

        Livewire::test('pages::submissions.index')
            ->assertDontSeeHtml('data-test="change-dates-button"')
            ->set('datingId', $project->id)
            ->set('start_date', today()->toDateString())
            ->set('target_date', today()->addYear()->toDateString())
            ->call('saveDates')
            ->assertForbidden();
    }

    public function test_each_review_keeps_its_own_remarks(): void
    {
        $project = $this->project('Solar Dryer Study');

        $this->actingAs($this->admin);

        $panel = Livewire::test('pages::reviews.index')
            ->call('review', $project->id)->set('remarks', 'Add the budget table.')->call('saveReview', 'returned');

        // A corrected concept proposal puts it back up for review.
        $project->update(['awaiting_review' => true]);
        $panel->call('review', $project->id)->set('remarks', 'Fixed now.')->call('saveReview', 'returned')
            ->assertHasNoErrors();

        $this->assertSame(['Fixed now.', 'Add the budget table.'], ActivityLog::latest('id')->get()->map->remarks()->all());

        $this->get(route('activity-log.index'))->assertSeeInOrder(['Remarks: Fixed now.', 'Remarks: Add the budget table.']);
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
            // Every project starts with its concept proposal, the one document the Research Office reviews.
            'concept_path' => 'drive-1/submissions/concept.pdf',
        ]);
    }
}
