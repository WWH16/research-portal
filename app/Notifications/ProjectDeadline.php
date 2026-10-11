<?php

namespace App\Notifications;

use App\Models\Submission;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Reminds a project's people that its target date is near, or has passed without a terminal report.
 */
class ProjectDeadline extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    /** A mail server that is down for a moment gets two more tries, a minute apart. */
    public int $tries = 3;

    public int $backoff = 60;

    /**
     * @param  int  $days  Days until the target date; negative once it has passed.
     */
    public function __construct(
        public readonly Submission $submission,
        public readonly int $days,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        // An address the member hasn't confirmed gets no mail; the notice still waits in the portal.
        return $notifiable->hasVerifiedEmail() ? ['database', 'mail'] : ['database'];
    }

    /**
     * Brackets in a title, name or remark are escaped, so typed markdown can't turn into a link in the email.
     */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->days > 0 ? __('Target date in :days days', ['days' => $this->days]) : __('Project overdue'))
            ->line(addcslashes($this->message(), '\[]'))
            ->action(__('Open project'), $this->url());
    }

    /**
     * @return array{submission_id: int, message: string, url: string}
     */
    public function toArray(User $notifiable): array
    {
        return ['submission_id' => $this->submission->id, 'message' => $this->message(), 'url' => $this->url()];
    }

    private function message(): string
    {
        $replace = ['days' => abs($this->days), 'title' => $this->submission->displayTitle(), 'date' => $this->submission->target_date?->format('F j, Y')];

        return $this->days > 0
            ? trans_choice('{1} Due in 1 day: :title. Upload the terminal report by :date.|[2,*] Due in :days days: :title. Upload the terminal report by :date.', $this->days, $replace)
            : trans_choice('{1} Overdue by 1 day: :title. The terminal report was due :date.|[2,*] Overdue by :days days: :title. The terminal report was due :date.', abs($this->days), $replace);
    }

    private function url(): string
    {
        return route('drive.show', $this->submission);
    }
}
