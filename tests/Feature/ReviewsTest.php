<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReviewsTest extends TestCase
{
    use RefreshDatabase;

    private User $maria;

    protected function setUp(): void
    {
        parent::setUp();

        $department = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);
        $this->maria = User::factory()->create(['name' => 'Maria Santos', 'department_id' => $department->id]);
    }

    private function project(string $title, array $attributes = []): Submission
    {
        $project = $this->maria->submissions()->create([
            'category_id' => Category::firstOrCreate(['name' => 'Computing'])->id,
            'department_id' => $this->maria->department_id,
            'title' => $title,
            'abstract' => 'An abstract.',
            'start_date' => now(),
            'target_date' => now()->addYear(),
            'concept_path' => 'drive-1/submissions/concept.pdf',
        ]);
        $project->proponents()->create(['user_id' => $this->maria->id, 'study' => 1, 'role' => 'Leader']);
        $project->forceFill($attributes)->save();

        return $project->refresh();
    }

    public function test_admins_see_only_waiting_concept_proposals_longest_waiting_first(): void
    {
        $this->project('Newer Proposal', ['updated_at' => now()->subDay()]);
        $this->project('Older Proposal', ['updated_at' => now()->subWeek()]);
        $this->project('Decided Proposal', ['awaiting_review' => false, 'concept_passed' => true]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('reviews.index'))
            ->assertOk()
            ->assertSeeInOrder(['Older Proposal', 'Newer Proposal'])
            ->assertDontSee('Decided Proposal');
    }

    public function test_each_row_names_its_project_for_screen_readers(): void
    {
        $this->project('Solar Dryer Study');

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('reviews.index'))
            ->assertSeeHtml('aria-label="Review Solar Dryer Study"')
            ->assertSeeHtml('aria-label="Concept proposal for Solar Dryer Study, opens in a new tab"');
    }

    public function test_rows_read_like_the_submissions_list(): void
    {
        // Same title, names and pager as the Submissions list shows for the same project.
        $this->maria->update(['name' => 'MARIA DELA PAZ SANTOS']);
        $this->project('Solar_Dryer_Study');

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('reviews.index'))
            ->assertSee('title="Solar Dryer Study"', escape: false)
            ->assertDontSee('Solar_Dryer_Study')
            ->assertSee('MDP Santos')
            ->assertDontSee('MARIA DELA PAZ SANTOS')
            ->assertDontSee('Showing 1 to');
    }

    public function test_the_list_redraws_when_the_live_count_finds_a_new_proposal(): void
    {
        $this->project('First Proposal');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $page = Livewire::test('pages::reviews.index')->assertSee('First Proposal');

        $this->project('Newly Uploaded Proposal');

        $page->dispatch('open-work-changed', changed: ['toReview'])->assertSee('Newly Uploaded Proposal');
    }

    public function test_the_save_buttons_loading_state_does_not_read_the_choice_before_the_panel_has_it(): void
    {
        $project = $this->project('Solar Dryer Study');
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::test('pages::reviews.index')
            ->call('review', $project->id)
            ->assertSeeHtml('wire:target="saveReview"');
    }

    public function test_faculty_see_no_reviews_link(): void
    {
        $this->actingAs($this->maria)->get(route('dashboard'))->assertDontSeeHtml('data-test="reviews-nav"');
    }

    public function test_submissions_list_no_longer_offers_a_review(): void
    {
        $this->project('Solar Dryer Study');

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('submissions.index'))
            ->assertSee('Solar Dryer Study')
            ->assertDontSeeHtml('data-test="review-submission-button"');
    }

    public function test_a_decided_project_leaves_the_queue(): void
    {
        $project = $this->project('Solar Dryer Study');
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::withQueryParams(['review' => $project->id])
            ->test('pages::reviews.index')
            ->assertSet('reviewingId', $project->id)
            ->call('saveReview', 'passed')
            ->assertHasNoErrors()
            ->assertSee('No concept proposals are waiting for review.');
    }

    public function test_deciding_the_only_row_on_the_last_page_steps_back(): void
    {
        foreach (range(1, 16) as $number) {
            $this->project("Proposal {$number}", ['updated_at' => now()->subMinutes(100 - $number)]);
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $component = Livewire::test('pages::reviews.index')->call('nextPage');
        $last = $component->instance()->queue->sole();

        $component->call('review', $last->id)
            ->call('saveReview', 'passed')
            ->assertHasNoErrors()
            ->assertSet('paginators.page', 1);
    }
}
