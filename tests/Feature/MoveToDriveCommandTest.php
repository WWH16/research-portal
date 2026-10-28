<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MoveToDriveCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('submissions');
        Storage::fake('old-bucket');

        $owner = User::factory()->create();
        $owner->submissions()->create([
            'category_id' => Category::firstOrCreate(['name' => 'Computing'])->id,
            'department_id' => Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies'])->id,
            'title' => 'Stored Study',
            'abstract' => 'An abstract.',
            'start_date' => now(),
            'target_date' => now()->addYear(),
            'concept_path' => 'submissions/concept.pdf',
            'detailed_path' => 'submissions/detailed.docx',
        ]);
    }

    public function test_it_copies_every_document_path_to_the_submissions_disk(): void
    {
        Storage::disk('old-bucket')->put('submissions/concept.pdf', 'concept');
        Storage::disk('old-bucket')->put('submissions/detailed.docx', 'detailed');
        Storage::disk('old-bucket')->put('submissions/orphan.pdf', 'no project points here');

        $this->artisan('submissions:move-to-drive', ['--from' => 'old-bucket'])
            ->expectsOutput('Copied 2, already there 0, missing 0.')
            ->assertSuccessful();

        $this->assertSame('concept', Storage::disk('submissions')->get('submissions/concept.pdf'));
        $this->assertSame('detailed', Storage::disk('submissions')->get('submissions/detailed.docx'));
        $this->assertFalse(Storage::disk('submissions')->exists('submissions/orphan.pdf'));
    }

    public function test_a_second_run_copies_nothing(): void
    {
        Storage::disk('old-bucket')->put('submissions/concept.pdf', 'concept');
        Storage::disk('old-bucket')->put('submissions/detailed.docx', 'detailed');

        $this->artisan('submissions:move-to-drive', ['--from' => 'old-bucket'])->assertSuccessful();
        $this->artisan('submissions:move-to-drive', ['--from' => 'old-bucket'])
            ->expectsOutput('Copied 0, already there 2, missing 0.')
            ->assertSuccessful();
    }

    public function test_it_needs_a_disk_to_copy_from(): void
    {
        $this->artisan('submissions:move-to-drive', ['--from' => ''])
            ->expectsOutput('Name the disk to copy from with --from.')
            ->assertFailed();
    }

    public function test_it_reports_files_missing_from_the_source(): void
    {
        Storage::disk('old-bucket')->put('submissions/concept.pdf', 'concept');

        $this->artisan('submissions:move-to-drive', ['--from' => 'old-bucket'])
            ->expectsOutput('Copied 1, already there 0, missing 1.')
            ->expectsOutput('Missing: submissions/detailed.docx')
            ->assertFailed();
    }
}
