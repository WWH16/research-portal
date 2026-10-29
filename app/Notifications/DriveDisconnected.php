<?php

namespace App\Notifications;

use App\Models\GoogleDriveConnection;

/**
 * Tells the Research Office that a storage account stopped answering (access removed, password changed, or its
 * folder deleted), so uploads and opens fail until someone connects it again.
 */
class DriveDisconnected extends PortalNotification
{
    protected const string SUBJECT = 'Google Drive is disconnected';

    public function __construct(
        public readonly GoogleDriveConnection $account,
    ) {}

    protected function message(): string
    {
        return __('Google Drive :email is disconnected, so documents can’t be uploaded or opened. Connect it again on System → Google Drive.', ['email' => $this->account->email]);
    }

    protected function mailLines(): array
    {
        return [
            __('The Google Drive account :email is **disconnected**, so documents can’t be uploaded or opened in the portal.', ['email' => self::bold($this->account->email)]),
            __('This happens when the portal’s access to the account is removed, its password is changed, or its folder is deleted. Please **connect it again** on System → Google Drive.'),
        ];
    }

    protected function url(): string
    {
        return route('google-drive.index');
    }
}
