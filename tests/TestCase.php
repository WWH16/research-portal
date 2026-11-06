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
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests wipe their database, so stop before they can run on one that isn't named for testing, whatever the settings say.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        self::refuseRealDatabase($app['db']->getDatabaseName());

        return $app;
    }

    public static function refuseRealDatabase(string $database): void
    {
        if (! preg_match('/_test(ing)?$/', $database)) {
            throw new RuntimeException("Tests wipe their database, so they only run on one named *_test or *_testing, not {$database}.");
        }
    }

    /**
     * A connected Google Drive storage account whose Drive is the fake "drive" disk, so uploads and opens never
     * reach Google. The account is number 1, set by hand since MySQL's ids keep counting after a test's rollback, so a
     * stored path reads "drive-1/submissions/...", and its place on the fake disk is the part after "drive-1/".
     */
    protected function fakeDrive(): FilesystemAdapter
    {
        GoogleDriveConnection::current() ?? GoogleDriveConnection::factory()->create(['id' => 1]);
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
