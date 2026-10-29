<?php

namespace App\Services;

use App\Exceptions\DocumentStorageUnavailable;
use App\Models\GoogleDriveConnection;
use App\Models\User;
use App\Notifications\DriveDisconnected;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use League\Flysystem\FileAttributes;
use Masbug\Flysystem\GoogleDriveAdapter;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
     * Save an uploaded document or profile photo in a folder of the storage account and return the path to keep.
     */
    public function store(UploadedFile $file, string $folder = 'submissions'): string
    {
        $account = GoogleDriveConnection::current() ?? throw new DocumentStorageUnavailable('No Google Drive storage account is saved.');

        try {
            $location = $this->driveOf($account)->putFile($folder, $file);
        } catch (Throwable $e) {
            $this->markIfUnreachable($account, $e);

            throw new DocumentStorageUnavailable(previous: $e);
        }

        // The disk answers false, not an error, when Google refuses the upload; GoogleDrive::disk() marks a full account then.
        $location ?: throw new DocumentStorageUnavailable('The document could not be stored.');
        Cache::forget($account->fullKey());

        // Known now, so no page ever has to ask Google when this was uploaded.
        $path = "drive-{$account->id}/{$location}";
        Cache::forever(self::uploadedAtKey($path), now()->timestamp);

        return $path;
    }

    /**
     * When a stored document was uploaded. A saved path never changes, so the time is asked of Google at most once
     * and kept; a new upload records it as it saves.
     */
    public function uploadedAt(string $path): Carbon
    {
        return Carbon::createFromTimestamp(Cache::rememberForever(self::uploadedAtKey($path), function () use ($path) {
            [$disk, $location] = $this->locate($path);

            return $disk->lastModified($location);
        }));
    }

    private static function uploadedAtKey(string $path): string
    {
        return 'drive-uploaded.'.sha1($path);
    }

    /**
     * Remove a replaced or rolled-back file from whichever account holds it. An account that can't be reached
     * leaves the file where it is rather than failing the save.
     */
    public function delete(string $path): void
    {
        Cache::forget(self::fileIdKey($path));
        Cache::forget(self::uploadedAtKey($path));

        rescue(function () use ($path) {
            [$disk, $location] = $this->locate($path);
            $disk->delete($location);
        });
    }

    /**
     * Where the cache keeps a stored document's Google file ID, once it has been opened. Losing it only means the
     * next open finds the file by name again.
     */
    public static function fileIdKey(string $path): string
    {
        return 'drive-file.'.sha1($path);
    }

    /**
     * Stream a stored document to the browser, or null when it isn't there. Finding a file by its name in Drive
     * takes several requests, so the first open remembers its Google file ID and later opens download it straight
     * away. An ID that stopped working, as for a file someone moved, is looked up by name again.
     */
    public function open(string $path, string $name): ?StreamedResponse
    {
        [$disk, $location] = $this->locate($path);
        $adapter = $disk->getAdapter();

        // Only Google Drive needs the shortcut; any other disk, such as the tests' fake, answers from the path.
        if (! $adapter instanceof GoogleDriveAdapter) {
            return $disk->exists($location) ? $disk->response($location, $name) : null;
        }

        $files = $adapter->getService()->files;
        $id = Cache::get(self::fileIdKey($path));
        $media = $id ? rescue(fn () => $files->get($id, ['alt' => 'media']), report: false) : null;

        if (! $media instanceof ResponseInterface) {
            $found = $adapter->getMetadata($location);

            if (! $found instanceof FileAttributes) {
                return null;
            }

            Cache::forever(self::fileIdKey($path), $id = $found->extraMetadata()['id']);
            $media = $files->get($id, ['alt' => 'media']);
        }

        $body = $media->getBody();

        return response()->stream(function () use ($body) {
            while (! $body->eof()) {
                echo $body->read(1024 * 1024);
            }
        }, 200, array_filter([
            'Content-Type' => $media->getHeaderLine('Content-Type') ?: 'application/octet-stream',
            'Content-Length' => $media->getHeaderLine('Content-Length') ?: null,
            'Content-Disposition' => HeaderUtils::makeDisposition('inline', $name, str_replace('%', '', Str::ascii($name))),
        ]));
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
                $this->disconnect($account, ['refresh_token' => null]);

                return true;
            }

            if (str_contains($cause->getMessage(), "File not found: {$account->folder_id}.")) {
                $this->disconnect($account, ['refresh_token' => null, 'folder_id' => null]);

                return true;
            }
        }

        return false;
    }

    /**
     * Mark the account not connected. Only the first failure tells the admins: once marked, the portal doesn't ask
     * Google again until someone connects it, which starts the next incident afresh.
     *
     * @param  array<string, null>  $forget
     */
    private function disconnect(GoogleDriveConnection $account, array $forget): void
    {
        $wasConnected = $account->isConnected();
        $account->update($forget);

        if ($wasConnected) {
            Notification::send(User::where('role', 'admin')->get(), new DriveDisconnected($account));
        }
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
