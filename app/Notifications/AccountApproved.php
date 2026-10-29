<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells a member the Research Office approved their sign-up, so they don't have to keep trying to sign in.
 */
class AccountApproved extends PortalNotification
{
    public function toMail(User $notifiable): MailMessage
    {
        return parent::toMail($notifiable)->subject(__('Your account is approved'));
    }

    protected function message(): string
    {
        return __('Your account is approved. You can now sign in to the Faculty Research Portal.');
    }
}
