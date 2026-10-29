<?php

namespace App\Notifications;

use App\Models\GoogleDriveConnection;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells the Research Office that Google refused an upload because the storage account is full, so every upload
 * fails until someone frees space or changes the account. Emailed too: the Google Drive page only shows it to
 * whoever opens it.
 */
class DriveFull extends PortalNotification
{
    public function __construct(
        public readonly GoogleDriveConnection $account,
    ) {}

    public function toMail(User $notifiable): MailMessage
    {
        return parent::toMail($notifiable)->subject(__('Google Drive is full'));
    }

    protected function message(): string
    {
        return __('Google Drive :email is full, so documents can’t be uploaded. Free up space in it, or change the storage account.', ['email' => $this->account->email]);
    }

    protected function url(): string
    {
        return route('google-drive.index');
    }
}
