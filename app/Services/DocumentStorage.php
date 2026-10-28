<?php

namespace App\Services;

use App\Exceptions\DocumentStorageUnavailable;
use App\Models\GoogleDriveConnection;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Where project documents live. Once an admin saves a Google Drive storage account, new documents go to it and
 * their saved path starts with "drive-{account id}/", so each one keeps opening from the account that holds it
 * after the storage account changes. Paths without that prefix are on the server disk, as before Google Drive.
 */
class DocumentStorage
{
    private const DRIVE_PATH = '#^drive-(\d+)/(.+)$#';

    public function __construct(private GoogleDrive $drive) {}

    /**
     * Save an uploaded document and return the path to store on the project.
     */
    public function store(UploadedFile $file): string
    {
        $account = GoogleDriveConnection::current();

        if (! $account) {
            return $file->store('submissions', 'submissions') ?: throw new DocumentStorageUnavailable('The document could not be stored.');
        }

        $location = $this->driveOf($account)->putFile('submissions', $file) ?: throw new DocumentStorageUnavailable('The document could not be stored.');

        return "drive-{$account->id}/{$location}";
    }

    /**
     * The disk holding a stored path, and the path on that disk.
     *
     * @return array{FilesystemAdapter, string}
     */
    public function locate(string $path): array
    {
        if (! preg_match(self::DRIVE_PATH, $path, $match)) {
            return [Storage::disk('submissions'), $path];
        }

        $account = GoogleDriveConnection::find((int) $match[1]) ?? throw new DocumentStorageUnavailable('The Google account holding this document is gone.');

        return [$this->driveOf($account), $match[2]];
    }

    private function driveOf(GoogleDriveConnection $account): FilesystemAdapter
    {
        if (! $account->isConnected()) {
            throw new DocumentStorageUnavailable("Google Drive {$account->email} is not connected.");
        }

        return $this->drive->disk($account);
    }
}
