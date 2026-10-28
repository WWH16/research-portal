<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\GoogleDriveConnection;
use App\Models\Submission;
use App\Services\GoogleDrive;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;

/**
 * Where Google sends the admin back after they allow access. Saves the connection the "google" disk
 * stores documents with, as long as the account is allowed to hold them.
 */
class GoogleDriveCallbackController
{
    public function __invoke(Request $request, GoogleDrive $drive): RedirectResponse
    {
        // Choosing Cancel on Google's screen comes back with ?error=access_denied and no code to trade for a token.
        if ($request->has('error')) {
            return $this->refuse(__('Google sign-in was cancelled, so nothing changed.'));
        }

        try {
            // Stateful on purpose: Socialite checks the state it put in the session, so a forged callback fails here.
            /** @var GoogleUser $google */
            $google = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            return $this->refuse(__('The Google sign-in expired. Click Connect again.'));
        }

        $email = strtolower((string) $google->getEmail());
        $domain = strtolower((string) config('services.google.drive_domain'));

        // Google's hd hint only filters its account picker; the address is checked here.
        if ($domain !== '' && ! str_ends_with($email, '@'.$domain)) {
            return $this->refuse(__('Use a Google account ending in @:domain.', ['domain' => $domain]));
        }

        if (! $google->refreshToken) {
            return $this->refuse(__('Google didn’t give the portal lasting access. Click Connect again and allow access.'));
        }

        $current = GoogleDriveConnection::first();
        $sameAccount = $current?->email === $email;

        // drive.file only reaches files this account's connection created, so a second account would lose every document.
        if ($current && ! $sameAccount && $this->documentsExist()) {
            return $this->refuse(__('Documents are stored on :email’s Drive. Connect that account again.', ['email' => $current->email]));
        }

        $folderId = $sameAccount ? $current->folder_id : $drive->createFolder($google->refreshToken);

        DB::transaction(function () use ($email, $google, $folderId) {
            GoogleDriveConnection::query()->delete();
            $connection = GoogleDriveConnection::create([
                'email' => $email,
                'refresh_token' => $google->refreshToken,
                'folder_id' => $folderId,
                'connected_by' => Auth::id(),
            ]);
            ActivityLog::record('drive.connected', $connection);
        });

        // An unused account the portal is leaving keeps no access.
        if ($current && ! $sameAccount) {
            rescue(fn () => $drive->revoke($current->refresh_token), report: false);
        }

        return redirect()->route('google-drive.index')->with('status', __('Google Drive connected as :email.', ['email' => $email]));
    }

    private function documentsExist(): bool
    {
        return Submission::where(function (Builder $query) {
            foreach (array_keys(Submission::DOCUMENTS) as $stage) {
                $query->orWhereNotNull($stage.'_path');
            }
        })->exists();
    }

    private function refuse(string $message): RedirectResponse
    {
        return redirect()->route('google-drive.index')->with('drive-error', $message);
    }
}
