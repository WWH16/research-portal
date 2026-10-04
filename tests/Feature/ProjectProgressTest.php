<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\ResearchType;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * How a project shows its way through the stages: the step row with what it needs next, and the
 * history of uploads and reviews on its Drive page.
 */
class ProjectProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $maria;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        Storage::fake('submissions');

        $department = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);
        $this->maria = User::factory()->create(['name' => 'Maria Santos', 'department_id' => $department->id]);
        $this->admin = User::factory()->create(['name' => 'Ana Admin', 'role' => 'admin']);
    }

    public function test_the_next_step_follows_the_status_and_the_documents(): void
    {
        $this->assertSame(
            ['stage' => 'Concept', 'document' => 'concept', 'state' => 'review'],
            $this->project(['status' => 'Submitted', 'awaiting_review' => true, 'concept_path' => 'submissions/concept.pdf'])->nextStep(),
        );
        $this->assertSame(
            ['stage' => 'Detailed', 'document' => 'detailed', 'state' => 'missing'],
            $this->project(['status' => 'Concept', 'awaiting_review' => false])->nextStep(),
        );
        $this->assertSame(
            ['stage' => 'Detailed', 'document' => 'detailed', 'state' => 'returned'],
            $this->project(['status' => 'Concept', 'awaiting_review' => false, 'detailed_path' => 'submissions/detailed.pdf'])->nextStep(),
        );
        $this->assertNull($this->project(['status' => 'Completed', 'awaiting_review' => false])->nextStep());
    }

    public function test_a_project_that_skips_ahead_names_the_upload_under_review(): void
    {
        Storage::disk('submissions')->put('submissions/detailed.pdf', '%PDF');

        $project = $this->project(['status' => 'Submitted', 'awaiting_review' => true, 'detailed_path' => 'submissions/detailed.pdf']);

        $this->assertSame(['stage' => 'Concept', 'document' => 'detailed', 'state' => 'review'], $project->nextStep());
    }

    public function test_a_status_outside_the_stages_counts_as_submitted(): void
    {
        $project = $this->project(['status' => 'Pending', 'awaiting_review' => false]);

        $this->assertSame(0, $project->stageIndex());
        $this->assertSame('Concept', $project->nextStep()['stage']);
    }

    private function project(array $attributes = []): Submission
    {
        $project = $this->maria->submissions()->create([
            'research_type_id' => ResearchType::firstOrCreate(['name' => 'Thesis'])->id,
            'category_id' => Category::firstOrCreate(['name' => 'Computing'])->id,
            'department_id' => $this->maria->department_id,
            'title' => 'Solar Dryer Study',
            'start_date' => now(),
            'target_date' => now()->addYear(),
            ...$attributes,
        ]);
        $project->proponents()->create(['user_id' => $this->maria->id, 'study' => 1, 'role' => 'Leader']);

        // Reload, so database defaults such as the status are on the model.
        return $project->fresh();
    }
}
