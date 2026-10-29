<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells someone the Research Office turned their sign-up down. Email only, since they can't use the portal,
 * and only to a confirmed address: an unconfirmed one may be mistyped or fake, and every email counts
 * against the daily sending limit.
 */
class AccountRejected extends PortalNotification
{
    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->hasVerifiedEmail() ? ['mail'] : [];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your portal sign-up was not approved'))
            ->line(__('Your sign-up for the Faculty Research Portal with :email was **not approved** by the Research Office.', ['email' => self::bold($notifiable->email)]))
            ->line(__('If you think this is a mistake, please contact the Research Office by replying to this email.'));
    }

    protected function message(): string
    {
        return __('Your sign-up for the Faculty Research Portal was not approved. Contact the Research Office if you think this is a mistake.');
    }
}
