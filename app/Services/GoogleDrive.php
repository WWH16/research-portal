<?php

namespace App\Services;

use Google\Client;
use Google\Service\Drive;

/**
 * The Google API calls the portal makes itself: everything else goes through the "google" disk.
 * A class, not static helpers, so tests can swap it for a mock and never reach Google.
 */
class GoogleDrive
{
    /** Only files and folders the portal creates; never the rest of the account's Drive. */
    public const SCOPE = Drive::DRIVE_FILE;

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
     * Tell Google to forget the portal's access, so a disconnected token is useless even if it leaked.
     */
    public function revoke(string $refreshToken): void
    {
        $this->client()->revokeToken($refreshToken);
    }
}
