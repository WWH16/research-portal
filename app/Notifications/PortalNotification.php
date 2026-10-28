<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * What every portal notice shares: queued, kept in the portal, emailed to confirmed addresses, one sentence and one link.
 */
abstract class PortalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

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
        // An address the member hasn't confirmed gets no mail; the notice still waits in the portal.
        return $notifiable->hasVerifiedEmail() ? ['database', 'mail'] : ['database'];
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
