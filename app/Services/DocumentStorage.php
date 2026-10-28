<?php

namespace App\Services;

use App\Exceptions\DocumentStorageUnavailable;
use App\Models\GoogleDriveConnection;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Where project documents live: the Google Drive storage account an admin saved. A saved path starts with
 * "drive-{account id}/", so each document keeps opening from the account that holds it after the storage account
 * changes. With no account saved, uploads are refused rather than kept anywhere else.
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
        $account = GoogleDriveConnection::current() ?? throw new DocumentStorageUnavailable('No Google Drive storage account is saved.');

        try {
            $location = $this->driveOf($account)->putFile('submissions', $file);
        } catch (Throwable $e) {
            $this->markIfUnreachable($account, $e);

            throw new DocumentStorageUnavailable(previous: $e);
        }

        return $location ? "drive-{$account->id}/{$location}" : throw new DocumentStorageUnavailable('The document could not be stored.');
    }

    /**
     * Learn from a failed open or read: when Google says the account's access is gone, mark the account not
     * connected, so the Google Drive page shows it and offers Connect instead of claiming all is well.
     */
    public function noticeFailure(string $path, Throwable $e): void
    {
        if (preg_match(self::DRIVE_PATH, $path, $match) && ($account = GoogleDriveConnection::find((int) $match[1]))) {
            $this->markIfUnreachable($account, $e);
        }
    }

    /**
     * Google answers "invalid_grant" once the account's password changed, someone removed the portal's access in
     * their Google Account, or the token went unused for six months. It answers "File not found" with the folder's
     * ID once someone deleted the folder; the folder ID is forgotten too, so Connect makes a new one. Either way
     * the account is marked not connected. Other failures, such as a timeout or one missing document, pass.
     */
    public function markIfUnreachable(GoogleDriveConnection $account, Throwable $e): bool
    {
        for ($cause = $e; $cause; $cause = $cause->getPrevious()) {
            if (str_contains($cause->getMessage(), 'invalid_grant')) {
                $account->update(['refresh_token' => null]);

                return true;
            }

            if (str_contains($cause->getMessage(), "File not found: {$account->folder_id}.")) {
                $account->update(['refresh_token' => null, 'folder_id' => null]);

                return true;
            }
        }

        return false;
    }

    /**
     * The disk holding a stored path, and the path on that disk.
     *
     * @return array{FilesystemAdapter, string}
     */
    public function locate(string $path): array
    {
        if (! preg_match(self::DRIVE_PATH, $path, $match) || ! ($account = GoogleDriveConnection::find((int) $match[1]))) {
            throw new DocumentStorageUnavailable('The Google account holding this document is gone.');
        }

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
