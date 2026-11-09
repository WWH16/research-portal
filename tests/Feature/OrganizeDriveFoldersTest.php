<?php

namespace Tests\Feature;

use App\Exceptions\DocumentStorageUnavailable;
use App\Models\Category;
use App\Models\Department;
use App\Models\Submission;
use App\Models\User;
use App\Services\DocumentStorage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class OrganizeDriveFoldersTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = '\([a-z0-9]{4}\)';

    private const FOLDER = 'Research Drive/CCS/Soil Study (m2p7)';

    private FilesystemAdapter $drive;

    private User $maria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->drive = $this->fakeDrive();
        $college = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);
        $this->maria = User::factory()->create(['department_id' => $college->id]);
    }

    private function project(array $attributes): Submission
    {
        return $this->maria->submissions()->create([
            'category_id' => Category::firstOrCreate(['name' => 'Computing'])->id,
            'department_id' => $this->maria->department_id,
            'title' => 'Soil Study',
            'abstract' => 'An abstract.',
            'start_date' => now(),
            'target_date' => now()->addYear(),
            ...$attributes,
        ]);
    }

    public function test_a_trial_run_lists_the_moves_and_changes_nothing(): void
    {
        $this->drive->put('submissions/concept.pdf', 'pdf');
        $project = $this->project(['concept_path' => 'drive-1/submissions/concept.pdf']);

        $this->artisan('drive:organize')
            ->expectsOutputToContain('drive-1/submissions/concept.pdf -> drive-1/Research Drive/CCS/Soil Study (')
            ->assertSuccessful();

        $this->assertSame('drive-1/submissions/concept.pdf', $project->fresh()->concept_path);
        $this->drive->assertExists('submissions/concept.pdf');
    }

    public function test_force_moves_each_projects_documents_into_one_folder(): void
    {
        $this->drive->put('submissions/concept.pdf', 'concept bytes');
        $this->drive->put('submissions/detailed.docx', 'detailed bytes');
        $project = $this->project(['concept_path' => 'drive-1/submissions/concept.pdf', 'detailed_path' => 'drive-1/submissions/detailed.docx']);

        $this->artisan('drive:organize', ['--force' => true])->assertSuccessful();

        $project->refresh();
        $this->assertMatchesRegularExpression('#^drive-1/Research Drive/CCS/Soil Study '.self::CODE.'/Concept proposal '.self::CODE.'\.pdf$#', $project->concept_path);
        $this->assertMatchesRegularExpression('#/Detailed proposal '.self::CODE.'\.docx$#', $project->detailed_path);
        $this->assertSame(Str::beforeLast($project->concept_path, '/'), Str::beforeLast($project->detailed_path, '/'));
        $this->assertSame('concept bytes', $this->drive->get(Str::after($project->concept_path, '/')));
        $this->drive->assertMissing('submissions/concept.pdf');
    }

    public function test_a_project_that_has_a_folder_keeps_using_it(): void
    {
        $this->drive->put(self::FOLDER.'/Concept proposal (a1b2).pdf', 'pdf');
        $this->drive->put('submissions/detailed.pdf', 'pdf');
        $project = $this->project(['concept_path' => 'drive-1/'.self::FOLDER.'/Concept proposal (a1b2).pdf', 'detailed_path' => 'drive-1/submissions/detailed.pdf']);

        $this->artisan('drive:organize', ['--force' => true])->assertSuccessful();

        $this->assertSame('drive-1/'.self::FOLDER.'/Concept proposal (a1b2).pdf', $project->fresh()->concept_path);
        $this->assertStringStartsWith('drive-1/'.self::FOLDER.'/Detailed proposal (', $project->fresh()->detailed_path);
    }

    public function test_running_it_twice_moves_nothing_more(): void
    {
        $this->drive->put('submissions/concept.pdf', 'pdf');
        $project = $this->project(['concept_path' => 'drive-1/submissions/concept.pdf']);
        $this->artisan('drive:organize', ['--force' => true])->assertSuccessful();
        $path = $project->fresh()->concept_path;

        $this->artisan('drive:organize', ['--force' => true])->assertSuccessful();

        $this->assertSame($path, $project->fresh()->concept_path);
        $this->assertCount(1, $this->drive->allFiles());
    }

    public function test_a_missing_file_is_reported_and_the_others_still_move(): void
    {
        Exceptions::fake();
        $gone = $this->project(['concept_path' => 'drive-1/submissions/gone.pdf']);
        $this->drive->put('submissions/here.pdf', 'pdf');
        $here = $this->project(['concept_path' => 'drive-1/submissions/here.pdf']);

        $this->artisan('drive:organize', ['--force' => true])->assertFailed();

        $this->assertSame('drive-1/submissions/gone.pdf', $gone->fresh()->concept_path);
        $this->assertStringStartsWith('drive-1/Research Drive/', $here->fresh()->concept_path);
        Exceptions::assertReportedCount(1);
    }

    public function test_a_move_that_fails_leaves_the_saved_path_as_it_was(): void
    {
        Exceptions::fake();
        $project = $this->project(['concept_path' => 'drive-1/submissions/concept.pdf']);
        $this->partialMock(DocumentStorage::class, fn (MockInterface $mock) => $mock->shouldReceive('move')->andThrow(new DocumentStorageUnavailable));

        $this->artisan('drive:organize', ['--force' => true])->assertFailed();

        $this->assertSame('drive-1/submissions/concept.pdf', $project->fresh()->concept_path);
    }

    public function test_moving_keeps_the_upload_time_and_the_last_updated_time(): void
    {
        $this->travelTo('2026-03-01 08:00:00');
        $uploadedAt = now()->timestamp;
        $project = $this->project(['concept_path' => $this->stored(UploadedFile::fake()->create('concept.pdf', 10, 'application/pdf'))]);

        $this->travelTo('2026-11-09 08:00:00');
        $this->artisan('drive:organize', ['--force' => true])->assertSuccessful();

        $project->refresh();
        $this->assertSame($uploadedAt, app(DocumentStorage::class)->uploadedAt($project->concept_path)->timestamp);
        $this->assertSame($uploadedAt, $project->updated_at->timestamp);
    }
}
