<?php

namespace App\Providers;

use App\Exceptions\DocumentStorageUnavailable;
use App\Models\GoogleDriveConnection;
use App\Services\GoogleDrive;
use Carbon\CarbonImmutable;
use Google\Service\Drive;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use League\Flysystem\Filesystem;
use Masbug\Flysystem\GoogleDriveAdapter;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerCsvResponse();
        $this->registerGoogleDriveDisk();
    }

    /**
     * The "google" disk driver: the connected account's "Research Portal" folder on Google Drive. Built from the
     * saved connection each time the disk is first used, so it signs in as whichever account an admin connected.
     */
    protected function registerGoogleDriveDisk(): void
    {
        Storage::extend('google', function (Application $app, array $config) {
            $connection = GoogleDriveConnection::first();

            if (! $connection?->isConnected()) {
                throw new DocumentStorageUnavailable('Google Drive is not connected.');
            }

            // The folder is given by ID; as a root path the adapter would look it up by name over the network.
            $adapter = new GoogleDriveAdapter(
                new Drive($app->make(GoogleDrive::class)->client($connection->refresh_token)),
                options: ['sharedFolderId' => $connection->folder_id],
            );
            // Always private: the adapter shares a "public" file with anyone who has its link.
            $config['visibility'] = 'private';

            return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });
    }

    /**
     * `response()->csv($filename, $header, $rows)`: a streamed CSV download that opens cleanly in Excel.
     * The byte order mark makes Excel read UTF-8, so names like Ñuñez keep their letters, and cells
     * starting with = + - @ get a leading apostrophe so Excel shows them as text instead of running
     * them as formulas (CSV injection), since titles and remarks are typed by users.
     */
    protected function registerCsvResponse(): void
    {
        Response::macro('csv', fn (string $filename, array $header, iterable $rows) => Response::streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w') ?: throw new RuntimeException('Cannot open the CSV output stream.');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, escape: '');

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($cell) => is_string($cell) && preg_match('/^[=+\-@\t\r]/', $cell) ? "'".$cell : $cell, $row), escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
