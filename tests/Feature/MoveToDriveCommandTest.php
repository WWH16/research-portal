<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\GoogleDriveConnection;
use App\Models\Submission;
use App\Models\User;
use App\Services\GoogleDrive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class MoveToDriveCommandTest extends TestCase
{
    use RefreshDatabase;

    private Submission $project;

    private GoogleDriveConnection $account;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('old-bucket');
        $drive = Storage::fake('drive');
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldReceive('disk')->andReturn($drive));
        $this->account = GoogleDriveConnection::factory()->create();

        $this->project = User::factory()->create()->submissions()->create([
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

    public function test_it_copies_older_documents_to_the_storage_account_and_saves_their_new_paths(): void
    {
        Storage::disk('old-bucket')->put('submissions/concept.pdf', 'concept');
        Storage::disk('old-bucket')->put('submissions/detailed.docx', 'detailed');
        Storage::disk('old-bucket')->put('submissions/orphan.pdf', 'no project points here');

        $this->artisan('submissions:move-to-drive', ['--from' => 'old-bucket'])
            ->expectsOutput('Copied 2, already on Google Drive 0, missing 0.')
            ->assertSuccessful();

        $prefix = 'drive-'.$this->account->id.'/';
        $this->project->refresh();
        $this->assertSame($prefix.'submissions/concept.pdf', $this->project->concept_path);
        $this->assertSame($prefix.'submissions/detailed.docx', $this->project->detailed_path);
        $this->assertSame('concept', Storage::disk('drive')->get('submissions/concept.pdf'));
        $this->assertFalse(Storage::disk('drive')->exists('submissions/orphan.pdf'));
    }

    public function test_a_second_run_copies_nothing(): void
    {
        Storage::disk('old-bucket')->put('submissions/concept.pdf', 'concept');
        Storage::disk('old-bucket')->put('submissions/detailed.docx', 'detailed');

        $this->artisan('submissions:move-to-drive', ['--from' => 'old-bucket'])->assertSuccessful();
        $this->artisan('submissions:move-to-drive', ['--from' => 'old-bucket'])
            ->expectsOutput('Copied 0, already on Google Drive 2, missing 0.')
            ->assertSuccessful();
    }

    public function test_it_reports_files_missing_from_the_source_and_keeps_their_paths(): void
    {
        Storage::disk('old-bucket')->put('submissions/concept.pdf', 'concept');

        $this->artisan('submissions:move-to-drive', ['--from' => 'old-bucket'])
            ->expectsOutput('Copied 1, already on Google Drive 0, missing 1.')
            ->expectsOutput('Missing: submissions/detailed.docx')
            ->assertFailed();

        $this->assertSame('submissions/detailed.docx', $this->project->refresh()->detailed_path);
    }

    public function test_it_needs_a_connected_storage_account(): void
    {
        $this->account->update(['refresh_token' => null]);

        $this->artisan('submissions:move-to-drive', ['--from' => 'old-bucket'])
            ->expectsOutput('Connect the Google Drive storage account first, on System → Google Drive.')
            ->assertFailed();
    }

    public function test_it_needs_a_disk_to_copy_from(): void
    {
        $this->artisan('submissions:move-to-drive', ['--from' => ''])
            ->expectsOutput('Name the disk to copy from with --from.')
            ->assertFailed();
    }
}
