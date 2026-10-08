<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SidebarBadgesTest extends TestCase
{
    use RefreshDatabase;

    private User $maria;

    protected function setUp(): void
    {
        parent::setUp();

        $department = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);
        $this->maria = User::factory()->create(['name' => 'Maria Santos', 'department_id' => $department->id]);
    }

    private function project(string $title, array $attributes = [], ?User $owner = null): Submission
    {
        $owner ??= $this->maria;
        $project = $owner->submissions()->create([
            'category_id' => Category::firstOrCreate(['name' => 'Computing'])->id,
            'department_id' => $this->maria->department_id,
            'title' => $title,
            'abstract' => 'An abstract.',
            'start_date' => now(),
            'target_date' => now()->addYear(),
            'concept_path' => 'submissions/concept.pdf',
        ]);
        $project->proponents()->create(['user_id' => $owner->id, 'study' => 1, 'role' => 'Leader']);
        $project->forceFill($attributes)->save();

        return $project->refresh();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_admins_see_the_review_count_with_a_spoken_label(): void
    {
        $this->project('First Proposal');
        $this->project('Second Proposal');
        $this->project('Decided Proposal', ['awaiting_review' => false, 'concept_passed' => true]);

        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertSeeHtml('title="2 concept proposals to review"')
            ->assertSeeHtml('<span class="sr-only" x-show="toReview" x-text="reviewLabel">2 concept proposals to review</span>')
            ->assertSeeHtml('data-test="sidebar-toggle-dot"');
    }

    public function test_the_review_badge_and_dot_hide_when_nothing_waits(): void
    {
        $this->project('Decided Proposal', ['awaiting_review' => false, 'concept_passed' => true]);

        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertDontSeeHtml('data-flux-navlist-badge>')
            ->assertSeeHtml('<span class="sr-only" x-show="toReview" x-text="reviewLabel"></span>')
            ->assertSeeHtml('style="display: none;" aria-hidden="true"');
    }

    public function test_the_review_badge_caps_at_99_and_keeps_the_exact_count_in_its_title(): void
    {
        $project = $this->project('Proposal');
        foreach (range(2, 100) as $ignored) {
            $project->replicate()->save();
        }

        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertSeeHtml('title="100 concept proposals to review"')
            ->assertSeeHtml('data-flux-navlist-badge>99+</span>')
            ->assertDontSeeHtml('data-flux-navlist-badge>100</span>');
    }

    public function test_the_concept_reviews_item_opens_the_review_queue(): void
    {
        $this->project('First Proposal');

        $html = $this->actingAs($this->admin())->get(route('dashboard'))->getContent();

        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('reviews.index'), '#').'"[^
]*data-test="reviews-nav"#', $html);
    }

    public function test_a_saved_review_sends_the_sidebar_the_new_count(): void
    {
        $project = $this->project('First Proposal');
        $this->project('Second Proposal');
        $this->actingAs($this->admin());

        Livewire::withQueryParams(['review' => $project->id])
            ->test('pages::reviews.index')
            ->call('saveReview', 'passed')
            ->assertDispatched('review-queue-changed', toReview: 1, label: '1 concept proposal to review');
    }

    public function test_faculty_see_their_projects_that_need_revision_and_the_item_opens_them(): void
    {
        $this->project('Returned Proposal', ['awaiting_review' => false, 'concept_passed' => false]);
        $this->project('Waiting Proposal');
        $this->project('Passed Proposal', ['awaiting_review' => false, 'concept_passed' => true]);
        $this->project('Someone Else Returned', ['awaiting_review' => false, 'concept_passed' => false], User::factory()->create());

        $html = $this->actingAs($this->maria)
            ->get(route('dashboard'))
            ->assertSeeHtml('data-tone="revision"')
            ->assertSeeHtml('title="1 project needs revision"')
            ->assertSeeHtml('<span class="sr-only">1 project needs revision</span>')
            ->assertDontSee('concept proposal to review')
            ->getContent();

        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('submissions.index', ['status' => 'revision']), '#').'"[^
]*data-test="submissions-nav"#', $html);
    }

    public function test_faculty_with_nothing_to_revise_get_the_plain_submissions_link(): void
    {
        $this->project('Passed Proposal', ['awaiting_review' => false, 'concept_passed' => true]);

        $html = $this->actingAs($this->maria)
            ->get(route('dashboard'))
            ->assertDontSeeHtml('data-flux-navlist-badge>')
            ->getContent();

        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('submissions.index'), '#').'"[^
]*data-test="submissions-nav"#', $html);
    }

    public function test_admins_get_no_revision_badge(): void
    {
        $this->project('Returned Proposal', ['awaiting_review' => false, 'concept_passed' => false]);

        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertDontSeeHtml('data-flux-navlist-badge>');
    }
}
