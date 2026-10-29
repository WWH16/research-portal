<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * What every portal notice shares: queued, kept in the portal, emailed to confirmed addresses, one sentence and one link.
 */
abstract class PortalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    /** The User::EMAIL_KINDS switch this notice's email follows; null for notices that are always emailed. */
    protected const ?string EMAIL_KIND = null;

    /** False for a notice that stays in the portal and is never emailed. */
    protected const bool EMAILED = true;

    /** The email's subject, for a notice without its own toMail(). */
    protected const string SUBJECT = 'Faculty Research Portal';

    /** A mail server that is down for a moment gets two more tries, a minute apart. */
    public int $tries = 3;

    public int $backoff = 60;

    /** The sentence shown in the portal and in the email. */
    abstract protected function message(): string;

    /** Where the notice's Open link goes. */
    protected function url(): string
    {
        return route('dashboard');
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        // An address the member hasn't confirmed gets no mail, nor does a kind they turned off; the notice still waits in the portal.
        $emails = static::EMAILED && $notifiable->hasVerifiedEmail() && (static::EMAIL_KIND === null || $notifiable->wantsEmail(static::EMAIL_KIND));

        return $emails ? ['database', 'mail'] : ['database'];
    }

    /**
     * The portal copy is saved straight away, so the bell and the Notifications page never wait on a queue worker;
     * only the email goes through the queue, where a mail server that is down can be retried.
     *
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    /**
     * The email for a notice without its own: the same sentence and link as in the portal.
     */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__(static::SUBJECT))
            ->line(self::plain($this->message()))
            ->action(__('Open the portal'), $this->url());
    }

    /**
     * @return array{message: string, url: string}
     */
    public function toArray(User $notifiable): array
    {
        return ['message' => $this->message(), 'url' => $this->url()];
    }

    /**
     * Brackets in a title, name or remark are escaped, so typed markdown can't turn into a link in the email.
     */
    protected static function plain(string $text): string
    {
        return addcslashes($text, '\\[]');
    }
}
