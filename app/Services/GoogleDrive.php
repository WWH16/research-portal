<?php

namespace App\Services;

use App\Exceptions\DocumentStorageUnavailable;
use App\Models\GoogleDriveConnection;
use App\Models\User;
use App\Notifications\DriveFull;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use GuzzleHttp\Middleware;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use League\Flysystem\Filesystem;
use Masbug\Flysystem\GoogleDriveAdapter;
use Psr\Http\Message\ResponseInterface;

/**
 * The Google API calls the portal makes itself: everything else goes through the "google" disk.
 * A class, not static helpers, so tests can swap it for a mock and never reach Google.
 */
#[Scoped]
class GoogleDrive
{
    /** Only files and folders the portal creates; never the rest of the account's Drive. */
    public const SCOPE = Drive::DRIVE_FILE;

    /** The folder each storage account keeps the documents in, at the top of its My Drive. */
    public const FOLDER = 'Faculty Research Portal';

    /** @var array<int, FilesystemAdapter> Each account's disk, signed in once per request or job (the class is scoped). */
    private array $disks = [];

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
     * anyone who has its link. A page that opens several documents on one account signs in to Google once.
     */
    public function disk(GoogleDriveConnection $account): FilesystemAdapter
    {
        if (isset($this->disks[$account->id])) {
            return $this->disks[$account->id];
        }

        // Signed in here, not on the first file request: the adapter turns a refused token into "file not found",
        // which would show a missing document instead of storage being down, and hide a revoked account.
        $client = $this->client($account->refresh_token);
        $token = $client->fetchAccessTokenWithRefreshToken();

        if (isset($token['error'])) {
            throw new DocumentStorageUnavailable("Google refused Google Drive {$account->email}: {$token['error']}.");
        }

        // A school account's own limit is never reported, so a refused upload is how the Google Drive page learns
        // it's full. Read off the response: the adapter drops Google's error and only says the write failed.
        $client->getHttpClient()->getConfig('handler')->push(Middleware::mapResponse(function (ResponseInterface $response) use ($account) {
            // Only the first refusal tells the admins; the ones after it, until an upload goes through, stay quiet.
            if ($response->getStatusCode() === 403 && str_contains((string) $response->getBody(), 'storageQuotaExceeded')
                && Cache::add($account->fullKey(), now())) {
                Notification::send(User::where('role', 'admin')->get(), new DriveFull($account));
            }

            return $response;
        }));

        $config = ['visibility' => 'private'];
        $adapter = new GoogleDriveAdapter(new Drive($client), options: ['sharedFolderId' => $account->folder_id]);

        return $this->disks[$account->id] = new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
    }

    /**
     * How much of the account's Google storage is used, in bytes, against its limit (null on a plan without one):
     * in all, in Drive, and by the portal's own documents. The drive.file scope lists only the files the portal
     * made, so their sizes add up to the portal's share; Gmail and Photos are the rest outside Drive.
     *
     * A school or company account (Google Workspace) shares its organization's storage pool, and Google reports
     * the whole pool's use and limit, not the account's own. For those only the account's Drive use is real, so
     * that is the use, with no limit: Google doesn't tell the portal the account's own cap.
     *
     * @return array{used: int, limit: int|null, drive: int, portal: int, pooled: bool}
     */
    public function usage(GoogleDriveConnection $account): array
    {
        /** @var GoogleDriveAdapter $adapter */
        $adapter = $this->disk($account)->getAdapter();
        $service = $adapter->getService();
        $pooled = $this->pooled($service->getClient());
        // Fails with "File not found" once someone deleted the folder, so the page stops claiming all is well.
        $service->files->get((string) $account->folder_id, ['fields' => 'id']);
        $quota = $service->about->get(['fields' => 'storageQuota'])->getStorageQuota();

        $portal = 0;
        $page = null;
        do {
            $list = $service->files->listFiles(array_filter(['q' => 'trashed = false', 'fields' => 'nextPageToken, files(size)', 'pageSize' => 1000, 'pageToken' => $page]));
            foreach ($list->getFiles() as $file) {
                $portal += (int) $file->getSize();
            }
            $page = $list->getNextPageToken();
        } while ($page);

        return [
            'used' => (int) ($pooled ? $quota->getUsageInDrive() : $quota->getUsage()),
            'limit' => $pooled || $quota->getLimit() === null ? null : (int) $quota->getLimit(),
            'drive' => (int) $quota->getUsageInDrive(),
            'portal' => $portal,
            'pooled' => $pooled,
        ];
    }

    /**
     * Whether the account belongs to a Workspace organization: its ID token, sent with the access token, names the
     * organization (hd); a personal account's doesn't. The token came straight from Google's token endpoint over
     * TLS, so its claims are read without checking the signature, as OpenID Connect allows.
     */
    private function pooled(Client $client): bool
    {
        $payload = explode('.', $client->getAccessToken()['id_token'] ?? '')[1] ?? '';

        return isset(json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true)['hd']);
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
