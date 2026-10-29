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

    protected function url(): string
    {
        return route('google-drive.index');
    }
}
