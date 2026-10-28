<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\GoogleDriveConnection;
use App\Services\GoogleDrive;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;

/**
 * Where Google sends the admin back after they allow access. Connects the storage account saved on the
 * Google Drive page, and only that account, so every admin's Connect lands on the same Drive.
 */
class GoogleDriveCallbackController
{
    public function __invoke(Request $request, GoogleDrive $drive): RedirectResponse
    {
        // The account Connect was started for: the current one, or an earlier one being reconnected.
        $target = $request->session()->pull('google-drive.connecting');

        // Choosing Cancel on Google's screen comes back with ?error=access_denied and no code to trade for a token.
        if ($request->has('error')) {
            return $this->refuse(__('Google sign-in was cancelled, so nothing changed.'));
        }

        $connection = is_int($target) ? GoogleDriveConnection::find($target) : GoogleDriveConnection::current();

        if (! $connection) {
            return $this->refuse(__('Save the Google account that stores documents first.'));
        }

        try {
            // Stateful on purpose: Socialite checks the state it put in the session, so a forged callback fails here.
            /** @var GoogleUser $google */
            $google = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            return $this->refuse(__('The Google sign-in expired. Click Connect again.'));
        }

        $email = strtolower((string) $google->getEmail());

        // The browser often offers a personal account first; only the saved storage account may connect.
        if ($email !== $connection->email) {
            return $this->refuse(__('Sign in as :expected to connect. You signed in as :actual.', ['expected' => $connection->email, 'actual' => $email]));
        }

        if (! $google->refreshToken) {
            return $this->refuse(__('Google didn’t give the portal lasting access. Click Connect again and allow access.'));
        }

        DB::transaction(function () use ($connection, $google, $drive) {
            $connection->update([
                'refresh_token' => $google->refreshToken,
                // Reconnecting keeps the folder the documents are already in.
                'folder_id' => $connection->folder_id ?? $drive->createFolder($google->refreshToken),
                'connected_by' => Auth::id(),
            ]);
            ActivityLog::record('drive.connected', $connection);
        });

        return redirect()->route('google-drive.index')->with('status', __('Google Drive connected as :email.', ['email' => $email]));
    }

    private function refuse(string $message): RedirectResponse
    {
        return redirect()->route('google-drive.index')->with('drive-error', $message);
    }
}
