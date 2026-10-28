<?php

namespace Tests\Feature;

use App\Exceptions\DocumentStorageUnavailable;
use App\Models\GoogleDriveConnection;
use App\Services\DocumentStorage;
use App\Services\GoogleDrive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
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

    public function test_without_a_storage_account_documents_stay_on_the_server_disk(): void
    {
        Storage::fake('submissions');

        $path = app(DocumentStorage::class)->store($this->pdf());

        $this->assertStringStartsWith('submissions/', $path);
        Storage::disk('submissions')->assertExists($path);
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

    public function test_paths_from_before_google_drive_open_from_the_server_disk(): void
    {
        Storage::fake('submissions');
        Storage::disk('submissions')->put('submissions/old.pdf', 'old');
        GoogleDriveConnection::factory()->create();

        [$disk, $location] = app(DocumentStorage::class)->locate('submissions/old.pdf');

        $this->assertSame('submissions/old.pdf', $location);
        $this->assertSame('old', $disk->get($location));
    }
}
