<?php

namespace App\Notifications;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells a member their password changed, on the Security page, through a reset link, or by the Research Office on
 * Manage Users, so a change they didn't make doesn't go unnoticed. Email only: the member who changed it is
 * already looking at the portal. Never says the new password.
 */
class PasswordChanged extends PortalNotification
{
    /** When it changed, read when the notice is made, so a slow queue doesn't shift the time in the email. */
    public readonly CarbonInterface $changedAt;

    public function __construct(public readonly bool $byResearchOffice = false)
    {
        $this->changedAt = now();
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->hasVerifiedEmail() ? ['mail'] : [];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $replace = [
            'email' => self::bold($notifiable->email),
            'when' => self::bold(__(':date, at :time', ['date' => $this->changedAt->format('F j, Y'), 'time' => $this->changedAt->format('g:i A')])),
        ];

        $mail = (new MailMessage)->subject(__('Your Faculty Research Portal password was changed'));

        return $this->byResearchOffice
            ? $mail
                ->line(__('The Research Office has set a new password for your Faculty Research Portal account, :email, on :when.', $replace))
                ->line(__('Please sign in with the new password. If you did not expect this change, please contact the Research Office by replying to this email.'))
                ->action(__('Sign in'), route('login'))
            : $mail
                ->line(__('The password for your Faculty Research Portal account, :email, was changed on :when.', $replace))
                ->line(__('If you made this change, no further action is needed.'))
                ->line(__('If you did not make this change, please **reset your password** right away using the link below, and contact the Research Office by replying to this email.'))
                ->action(__('Reset password'), route('password.request'));
    }

    protected function message(): string
    {
        return __('Your password was changed. If you did not make this change, reset your password and contact the Research Office.');
    }
}
