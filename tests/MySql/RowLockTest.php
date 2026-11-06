<?php

namespace Tests\MySql;

use App\Models\Category;
use App\Models\Department;
use App\Models\Publication;
use App\Models\Submission;
use App\Models\User;
use App\Services\DocumentStorage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * A second connection asks for the row while a save runs, which also means the rows must really commit: that's why
 * this truncates instead of rolling back.
 */
class RowLockTest extends TestCase
{
    use DatabaseTruncation;

    private User $maria;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.lock_probe' => config('database.connections.mysql')]);

        $this->maria = User::factory()->create(['name' => 'Maria Test', 'department_id' => Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies'])->id]);
        $this->actingAs($this->maria);
    }

    /**
     * Whether another save would have to wait for this row right now.
     */
    private function isLocked(string $table, int $id): bool
    {
        try {
            DB::connection('lock_probe')->transaction(fn ($db) => $db->table($table)->where('id', $id)->lock('for update nowait')->first());

            return false;
        } catch (QueryException $e) {
            // MySQL's answer when NOWAIT finds the row locked; anything else is a real failure.
            return $e->errorInfo[1] === 3572 ? true : throw $e;
        }
    }

    private function project(array $attributes = []): Submission
    {
        $project = $this->maria->submissions()->create([
            'category_id' => Category::create(['name' => 'Computing'])->id,
            'department_id' => $this->maria->department_id,
            'title' => 'Lock Test Project',
            'abstract' => 'An abstract.',
            'start_date' => now(),
            'target_date' => now()->addYear(),
            'concept_path' => 'drive-1/submissions/concept.pdf',
            ...$attributes,
        ]);
        $project->proponents()->create(['user_id' => $this->maria->id, 'study' => 1, 'role' => 'Leader']);

        return $project;
    }

    public function test_a_project_save_locks_the_row_but_its_upload_does_not(): void
    {
        $project = $this->project(['awaiting_review' => false, 'concept_passed' => true]);
        $lockedDuringUpload = null;
        $lockedDuringSave = null;

        $this->partialMock(DocumentStorage::class, function (MockInterface $mock) use (&$lockedDuringUpload, $project) {
            $mock->shouldReceive('delete');
            $mock->shouldReceive('store')->once()->andReturnUsing(function () use (&$lockedDuringUpload, $project) {
                $lockedDuringUpload = $this->isLocked('submissions', $project->id);

                return 'drive-1/submissions/new.pdf';
            });
        });
        // Only the first save: by the next, adding the proponents has locked the row by itself, lock or no lock.
        Submission::saving(function () use (&$lockedDuringSave, $project) {
            $lockedDuringSave ??= $this->isLocked('submissions', $project->id);
        });

        Livewire::test('pages::submissions.create', ['submission' => $project->fresh()])
            ->set('documents.concept', UploadedFile::fake()->create('concept-v2.pdf', 100, 'application/pdf'))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($lockedDuringUpload);
        $this->assertTrue($lockedDuringSave);
        $this->assertSame('drive-1/submissions/new.pdf', $project->fresh()->concept_path);
    }

    public function test_a_concept_review_locks_the_row(): void
    {
        $this->fakeDrive();
        $project = $this->project();
        $locked = null;
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Submission::saving(function () use (&$locked, $project) {
            $locked = $this->isLocked('submissions', $project->id);
        });

        Livewire::test('pages::reviews.index')
            ->call('review', $project->id)
            ->call('saveReview', 'passed')
            ->assertHasNoErrors();

        $this->assertTrue($locked);
    }

    public function test_a_paper_save_locks_the_row(): void
    {
        $paper = Publication::factory()->create(['authors' => 'Maria Test', 'published_on' => '2025-06-01']);
        $paper->faculty()->attach($this->maria->id);
        $locked = null;

        Publication::saving(function () use (&$locked, $paper) {
            $locked = $this->isLocked('publications', $paper->id);
        });

        Livewire::test('pages::publications.create', ['publication' => $paper])
            ->set('title', 'A new title')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($locked);
    }
}
