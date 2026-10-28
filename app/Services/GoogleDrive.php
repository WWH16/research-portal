<?php

namespace App\Services;

use App\Models\GoogleDriveConnection;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem;
use Masbug\Flysystem\GoogleDriveAdapter;

/**
 * The Google API calls the portal makes itself: everything else goes through the "google" disk.
 * A class, not static helpers, so tests can swap it for a mock and never reach Google.
 */
class GoogleDrive
{
    /** Only files and folders the portal creates; never the rest of the account's Drive. */
    public const SCOPE = Drive::DRIVE_FILE;

    /** The folder each storage account keeps the documents in, at the top of its My Drive. */
    public const FOLDER = 'Faculty Research Portal';

    /**
     * A client for the portal's Google app. With a refresh token it signs in as the connected account,
     * fetching an access token on its first request rather than here.
     */
    public function client(?string $refreshToken = null): Client
    {
        $client = new Client;
        $client->setClientId((string) config('services.google.client_id'));
        $client->setClientSecret((string) config('services.google.client_secret'));
        $client->setScopes([self::SCOPE]);

        if ($refreshToken !== null) {
            // Expired on purpose, so the first request swaps the refresh token for a fresh access token.
            $client->setAccessToken(['access_token' => '', 'refresh_token' => $refreshToken, 'created' => 0, 'expires_in' => 0]);
        }

        return $client;
    }

    /**
     * The "Faculty Research Portal" folder of a connected account, as a disk. The folder is given by ID: as a root path the
     * adapter would look it up by name over the network. Always private: the adapter shares a "public" file with
     * anyone who has its link.
     */
    public function disk(GoogleDriveConnection $account): FilesystemAdapter
    {
        $config = ['visibility' => 'private'];
        $adapter = new GoogleDriveAdapter(new Drive($this->client($account->refresh_token)), options: ['sharedFolderId' => $account->folder_id]);

        return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
    }

    /**
     * Make the "Faculty Research Portal" folder documents are stored in. New Drive items are shared with no one,
     * and the portal never adds a permission, so the folder stays Restricted.
     */
    public function createFolder(string $refreshToken): string
    {
        $folder = new DriveFile(['name' => self::FOLDER, 'mimeType' => 'application/vnd.google-apps.folder']);

        return (new Drive($this->client($refreshToken)))->files->create($folder, ['fields' => 'id'])->id;
    }

    /**
     * Tell Google to forget the portal's access, so a disconnected token is useless even if it leaked.
     */
    public function revoke(string $refreshToken): void
    {
        $this->client()->revokeToken($refreshToken);
    }
}
