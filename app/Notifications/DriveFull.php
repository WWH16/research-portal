<?php

namespace App\Notifications;

use App\Models\GoogleDriveConnection;

/**
 * Tells the Research Office that Google refused an upload because the storage account is full, so every upload
 * fails until someone frees space or changes the account. Emailed too: the Google Drive page only shows it to
 * whoever opens it.
 */
class DriveFull extends PortalNotification
{
    protected const string SUBJECT = 'Google Drive is full';

    public function __construct(
        public readonly GoogleDriveConnection $account,
    ) {}

    protected function message(): string
    {
        return __('Google Drive :email is full, so documents can’t be uploaded. Free up space in it, or change the storage account on System → Google Drive.', ['email' => $this->account->email]);
    }

    protected function mailLines(): array
    {
        return [
            __('The Google Drive account :email is **full**, so documents can’t be uploaded to the portal.', ['email' => self::bold($this->account->email)]),
            __('Every upload will fail until there is space again. Please **free up space** in this account, or change the storage account on System → Google Drive.'),
        ];
    }

    protected function url(): string
    {
        return route('google-drive.index');
    }
}
