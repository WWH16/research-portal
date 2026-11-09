<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells a member the Research Office approved their sign-up. The email says they can sign in; the notice waiting
 * in the portal, read once they have, welcomes them and points to the first step.
 */
class AccountApproved extends PortalNotification
{
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your account has been approved'))
            ->line(__('The Research Office has **approved** your Faculty Research Portal account for :email.', ['email' => self::bold($notifiable->email)]))
            ->line(__('You may now sign in to submit research proposals, upload the documents for each stage, and follow the progress of your projects.'))
            ->line(__('To begin, sign in and **submit your concept proposal** under Submissions. The Research Office will review it, and you will be notified of its decision.'))
            ->action(__('Sign in'), route('login'));
    }

    protected function message(): string
    {
        return __('The Research Office has approved your account. To begin, submit your concept proposal; the Research Office will review it and notify you of its decision here. You may also add your mobile number and profile photo under My profile.');
    }

    protected function url(): string
    {
        return route('submissions.create');
    }
}
