<?php

namespace Tests\Feature;

use App\Exceptions\DocumentStorageUnavailable;
use App\Models\GoogleDriveConnection;
use App\Services\DocumentStorage;
use App\Services\GoogleDrive;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class DocumentStorageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * One fake Drive per storage account, kept for the whole test so a file stored on it can be read back.
     */
    private function fakeDrives(): void
    {
        $drives = [];
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldReceive('disk')
            ->andReturnUsing(function (GoogleDriveConnection $connection) use (&$drives) {
                return $drives[$connection->id] ??= Storage::fake('drive-'.$connection->id);
            }));
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->create('proposal.pdf', 100, 'application/pdf');
    }

    public function test_without_a_storage_account_an_upload_is_refused(): void
    {
        $this->expectException(DocumentStorageUnavailable::class);

        app(DocumentStorage::class)->store($this->pdf());
    }

    public function test_a_connected_storage_account_takes_new_documents_and_the_path_remembers_it(): void
    {
        $this->fakeDrives();
        $account = GoogleDriveConnection::factory()->create();

        $path = app(DocumentStorage::class)->store($this->pdf());

        $this->assertMatchesRegularExpression('#^drive-'.$account->id.'/submissions/[^/]+\.pdf$#', $path);
        [$disk, $location] = app(DocumentStorage::class)->locate($path);
        $this->assertSame(Storage::disk('drive-'.$account->id), $disk);
        $disk->assertExists($location);
    }

    public function test_a_saved_account_that_is_not_connected_refuses_uploads(): void
    {
        $this->fakeDrives();
        GoogleDriveConnection::factory()->unconnected()->create();

        $this->expectException(DocumentStorageUnavailable::class);

        app(DocumentStorage::class)->store($this->pdf());
    }

    public function test_documents_on_an_earlier_account_open_from_that_account(): void
    {
        $this->fakeDrives();
        $first = GoogleDriveConnection::factory()->create(['email' => 'email1@gmail.com']);
        $oldPath = app(DocumentStorage::class)->store($this->pdf());

        $first->update(['retired_at' => now()]);
        $second = GoogleDriveConnection::factory()->create(['email' => 'email2@gmail.com']);
        $newPath = app(DocumentStorage::class)->store($this->pdf());

        $this->assertStringStartsWith('drive-'.$first->id.'/', $oldPath);
        $this->assertStringStartsWith('drive-'.$second->id.'/', $newPath);
        [$oldDisk, $oldLocation] = app(DocumentStorage::class)->locate($oldPath);
        $oldDisk->assertExists($oldLocation);
        $this->assertSame(Storage::disk('drive-'.$first->id), $oldDisk);
    }

    public function test_a_document_whose_account_is_gone_or_disconnected_is_unavailable(): void
    {
        $this->fakeDrives();
        $disconnected = GoogleDriveConnection::factory()->unconnected()->create();

        foreach (['drive-999/submissions/a.pdf', 'drive-'.$disconnected->id.'/submissions/a.pdf'] as $path) {
            try {
                app(DocumentStorage::class)->locate($path);
                $this->fail("{$path} should be unavailable.");
            } catch (DocumentStorageUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * A Drive that fails every write with the given error, the way Google answers once access is revoked.
     */
    public function test_a_new_upload_knows_its_time_without_asking_google(): void
    {
        GoogleDriveConnection::factory()->create();
        $this->freezeTime();
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFile')->andReturn('submissions/proposal.pdf');
        $disk->shouldNotReceive('lastModified');
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldReceive('disk')->andReturn($disk));

        $storage = app(DocumentStorage::class);
        $path = $storage->store($this->pdf());

        $this->assertSame(now()->timestamp, $storage->uploadedAt($path)->timestamp);
    }

    public function test_an_older_document_asks_google_for_its_time_once(): void
    {
        $account = GoogleDriveConnection::factory()->create();
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('lastModified')->once()->with('submissions/old.pdf')->andReturn(1_790_000_000);
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldReceive('disk')->andReturn($disk));
        $path = "drive-{$account->id}/submissions/old.pdf";

        $storage = app(DocumentStorage::class);

        $this->assertSame(1_790_000_000, $storage->uploadedAt($path)->timestamp);
        $this->assertSame(1_790_000_000, $storage->uploadedAt($path)->timestamp);
    }

    private function failingDrive(string $error): void
    {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFile')->andThrow(new RuntimeException($error));
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldReceive('disk')->andReturn($disk));
    }

    public function test_an_upload_google_refuses_as_revoked_marks_the_account_not_connected(): void
    {
        $account = GoogleDriveConnection::factory()->create();
        $this->failingDrive('Client error: 400 Bad Request {"error": "invalid_grant", "error_description": "Token has been expired or revoked."}');

        try {
            app(DocumentStorage::class)->store($this->pdf());
            $this->fail('The upload should have failed.');
        } catch (DocumentStorageUnavailable) {
            $this->assertFalse($account->fresh()->isConnected());
        }
    }

    public function test_an_upload_into_a_folder_gone_from_google_drive_marks_the_account_not_connected(): void
    {
        $account = GoogleDriveConnection::factory()->create(['folder_id' => 'folder-123']);
        $this->failingDrive('{"error": {"code": 404, "message": "File not found: folder-123."}}');

        try {
            app(DocumentStorage::class)->store($this->pdf());
            $this->fail('The upload should have failed.');
        } catch (DocumentStorageUnavailable) {
            $this->assertFalse($account->fresh()->isConnected());
            // Connect then makes a new folder instead of reusing the gone one.
            $this->assertNull($account->fresh()->folder_id);
        }
    }

    public function test_a_missing_document_leaves_its_account_connected(): void
    {
        $account = GoogleDriveConnection::factory()->create(['folder_id' => 'folder-123']);

        app(DocumentStorage::class)->noticeFailure("drive-{$account->id}/submissions/a.pdf", new RuntimeException('File not found: file-456.'));

        $this->assertTrue($account->fresh()->isConnected());
        $this->assertSame('folder-123', $account->fresh()->folder_id);
    }

    public function test_a_full_account_stays_marked_full_until_an_upload_goes_through(): void
    {
        $account = GoogleDriveConnection::factory()->create();
        // GoogleDrive::disk() marked it on Google's refusal; the disk itself only answers false.
        Cache::forever($account->fullKey(), now());
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFile')->andReturnFalse();
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldReceive('disk')->andReturn($disk));

        try {
            app(DocumentStorage::class)->store($this->pdf());
            $this->fail('The upload should have failed.');
        } catch (DocumentStorageUnavailable) {
            $this->assertTrue(Cache::has($account->fullKey()));
            $this->assertTrue($account->fresh()->isConnected());
        }

        $this->fakeDrives();
        app(DocumentStorage::class)->store($this->pdf());

        $this->assertFalse(Cache::has($account->fullKey()));
    }

    public function test_other_google_failures_leave_the_account_connected(): void
    {
        $account = GoogleDriveConnection::factory()->create();
        $this->failingDrive('cURL error 28: Operation timed out');

        try {
            app(DocumentStorage::class)->store($this->pdf());
            $this->fail('The upload should have failed.');
        } catch (DocumentStorageUnavailable) {
            $this->assertTrue($account->fresh()->isConnected());
        }
    }

    public function test_a_revoked_earlier_account_is_marked_when_one_of_its_documents_fails_to_open(): void
    {
        $email1 = GoogleDriveConnection::factory()->create(['retired_at' => now()]);
        $current = GoogleDriveConnection::factory()->create(['email' => 'email2@gmail.com']);

        app(DocumentStorage::class)->noticeFailure("drive-{$email1->id}/submissions/a.pdf", new RuntimeException('wrapper', 0, new RuntimeException('{"error": "invalid_grant"}')));

        $this->assertFalse($email1->fresh()->isConnected());
        $this->assertTrue($current->fresh()->isConnected());
    }

    public function test_a_named_document_is_saved_in_its_folder_under_that_name(): void
    {
        $this->fakeDrives();
        $account = GoogleDriveConnection::factory()->create();

        $path = app(DocumentStorage::class)->store($this->pdf(), 'Research Drive/CCS/Soil Study (m2p7)', 'Concept proposal (a1b2)');

        $this->assertSame("drive-{$account->id}/Research Drive/CCS/Soil Study (m2p7)/Concept proposal (a1b2).pdf", $path);
        [$disk, $location] = app(DocumentStorage::class)->locate($path);
        $disk->assertExists($location);
    }

    public function test_a_named_document_google_refuses_is_reported_as_unavailable(): void
    {
        Exceptions::fake();
        GoogleDriveConnection::factory()->create();
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->andReturnFalse();
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldReceive('disk')->andReturn($disk));

        try {
            app(DocumentStorage::class)->store($this->pdf(), 'Research Drive/CCS/Soil Study (m2p7)', 'Concept proposal (a1b2)');
            $this->fail('The upload should have failed.');
        } catch (DocumentStorageUnavailable) {
            Exceptions::assertReported(DocumentStorageUnavailable::class);
        }
    }

    public function test_a_failed_upload_leaves_its_cause_in_the_log(): void
    {
        Exceptions::fake();
        GoogleDriveConnection::factory()->create();
        $this->failingDrive('Google is unavailable.');

        try {
            app(DocumentStorage::class)->store($this->pdf());
            $this->fail('The upload should have failed.');
        } catch (DocumentStorageUnavailable) {
            Exceptions::assertReported(RuntimeException::class);
        }
    }

    public function test_a_path_not_on_a_storage_account_is_unavailable(): void
    {
        GoogleDriveConnection::factory()->create();

        $this->expectException(DocumentStorageUnavailable::class);

        app(DocumentStorage::class)->locate('submissions/old.pdf');
    }
}
