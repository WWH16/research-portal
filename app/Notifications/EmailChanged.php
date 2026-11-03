<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells the old address that a member's email changed on My Profile, so a change they didn't make doesn't go
 * unnoticed: reset links now go to the new address. Sent on demand to the old address, which no longer belongs to
 * the account, so it is email only and never kept in the portal.
 */
class EmailChanged extends PortalNotification
{
    /** When it changed, read when the notice is made, so a slow queue doesn't shift the time in the email. */
    public readonly CarbonInterface $changedAt;

    public function __construct(public readonly string $from, public readonly string $to)
    {
        $this->changedAt = now();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your Faculty Research Portal email address was changed'))
            ->line(__('The email address for your Faculty Research Portal account was changed from :from to :to on :when.', [
                'from' => self::bold($this->from),
                'to' => self::bold($this->to),
                'when' => self::bold(__(':date, at :time', ['date' => $this->changedAt->format('F j, Y'), 'time' => $this->changedAt->format('g:i A')])),
            ]))
            ->line(__('If you made this change, no further action is needed.'))
            ->line(__('If you did not make this change, please contact the Research Office right away by replying to this email.'));
    }

    protected function message(): string
    {
        return __('Your email address was changed. If you did not make this change, contact the Research Office.');
    }
}
