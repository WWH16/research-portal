<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\GoogleDriveConnection;
use App\Models\Submission;
use App\Models\User;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Masbug\Flysystem\GoogleDriveAdapter;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GoogleDriveDiskTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
    }

    private function useDrive(): void
    {
        config(['filesystems.disks.submissions.driver' => 'google', 'services.google.client_id' => 'portal-client-id', 'services.google.client_secret' => 'portal-secret']);
        Storage::forgetDisk('submissions');
    }

    private function project(array $paths): Submission
    {
        $project = $this->owner->submissions()->create([
            'category_id' => Category::firstOrCreate(['name' => 'Computing'])->id,
            'department_id' => Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies'])->id,
            'title' => 'Stored Study',
            'abstract' => 'An abstract.',
            'start_date' => now(),
            'target_date' => now()->addYear(),
        ] + $paths);
        $project->proponents()->create(['user_id' => $this->owner->id, 'study' => 1, 'role' => 'Leader']);

        return $project;
    }

    public function test_the_google_disk_is_built_from_the_saved_connection(): void
    {
        GoogleDriveConnection::factory()->create(['folder_id' => 'folder-123', 'refresh_token' => 'saved-token']);
        $this->useDrive();

        $disk = Storage::disk('submissions');

        $this->assertInstanceOf(FilesystemAdapter::class, $disk);
        $this->assertInstanceOf(GoogleDriveAdapter::class, $disk->getAdapter());
        // Never "public": the adapter would share a public file with anyone who has the link.
        $this->assertSame('private', $disk->getConfig()['visibility']);
    }

    public function test_the_google_disk_refuses_without_a_connection(): void
    {
        $this->useDrive();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Google Drive is not connected.');

        Storage::disk('submissions');
    }

    public function test_the_google_disk_refuses_a_saved_account_that_is_not_connected(): void
    {
        GoogleDriveConnection::factory()->unconnected()->create();
        $this->useDrive();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Google Drive is not connected.');

        Storage::disk('submissions');
    }

    public function test_opening_a_document_while_storage_is_down_says_so(): void
    {
        $project = $this->project(['concept_path' => 'submissions/concept.pdf']);
        $this->useDrive();

        $this->actingAs($this->owner)
            ->get(route('submissions.document', [$project, 'concept']))
            ->assertStatus(503)
            ->assertSee('Document storage is unavailable right now.');
    }

    public function test_opening_a_document_after_google_refuses_the_token_says_storage_is_down(): void
    {
        $project = $this->project(['concept_path' => 'submissions/concept.pdf']);
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->andThrow(new GoogleServiceException('{"error": "invalid_grant"}', 401));
        Storage::shouldReceive('disk')->with('submissions')->andReturn($disk);

        $this->actingAs($this->owner)
            ->get(route('submissions.document', [$project, 'concept']))
            ->assertStatus(503)
            ->assertSee('Document storage is unavailable right now.');
    }

    public function test_documents_download_with_the_type_of_their_extension(): void
    {
        Storage::fake('submissions');
        Storage::disk('submissions')->put('submissions/concept.pdf', 'pdf');
        Storage::disk('submissions')->put('submissions/detailed.doc', 'doc');
        Storage::disk('submissions')->put('submissions/midyear.docx', 'docx');
        $project = $this->project([
            'concept_path' => 'submissions/concept.pdf',
            'detailed_path' => 'submissions/detailed.doc',
            'midyear_path' => 'submissions/midyear.docx',
        ]);

        $this->actingAs($this->owner);

        $this->get(route('submissions.document', [$project, 'concept']))->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('submissions.document', [$project, 'detailed']))->assertHeader('Content-Type', 'application/msword');
        $this->get(route('submissions.document', [$project, 'midyear']))->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }
}
