<?php

namespace App\Notifications;

use App\Models\GoogleDriveConnection;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells the Research Office that a storage account stopped answering (access removed, password changed, or its
 * folder deleted), so uploads and opens fail until someone connects it again.
 */
class DriveDisconnected extends PortalNotification
{
    public function __construct(
        public readonly GoogleDriveConnection $account,
    ) {}

    public function toMail(User $notifiable): MailMessage
    {
        return parent::toMail($notifiable)->subject(__('Google Drive is disconnected'));
    }

    protected function message(): string
    {
        return __('Google Drive :email is disconnected, so documents can’t be uploaded or opened. Connect it again on System → Google Drive.', ['email' => $this->account->email]);
    }

    protected function url(): string
    {
        return route('google-drive.index');
    }
}
