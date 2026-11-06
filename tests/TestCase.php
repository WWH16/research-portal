<?php

namespace Tests;

use App\Models\GoogleDriveConnection;
use App\Services\DocumentStorage;
use App\Services\GoogleDrive;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Features;
use Mockery\MockInterface;

abstract class TestCase extends BaseTestCase
{
    /**
     * A connected Google Drive storage account whose Drive is the fake "drive" disk, so uploads and opens never
     * reach Google. The first account a test makes is number 1, so a stored path reads "drive-1/submissions/...",
     * and its place on the fake disk is the part after "drive-1/".
     */
    protected function fakeDrive(): FilesystemAdapter
    {
        GoogleDriveConnection::current() ?? GoogleDriveConnection::factory()->create();
        $disk = Storage::fake('drive');
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldReceive('disk')->andReturn($disk));

        return $disk;
    }

    /**
     * Upload a file to the fake Drive and return its path, for Submission::attachDocument().
     */
    protected function stored(UploadedFile $file): string
    {
        return app(DocumentStorage::class)->store($file);
    }

    /**
     * Run the action and fail if it takes more than the given number of database queries.
     */
    protected function assertQueriesAtMost(int $max, callable $action): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $action();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($max, count(DB::getQueryLog()));
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
