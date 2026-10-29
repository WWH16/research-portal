<?php

namespace App\Notifications;

use App\Models\Submission;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Reminds a project's people that its target date is near, or has passed without a terminal report.
 */
class ProjectDeadline extends PortalNotification
{
    protected const ?string EMAIL_KIND = 'reminders';

    /**
     * @param  int  $days  Days until the target date; negative once it has passed.
     */
    public function __construct(
        public readonly Submission $submission,
        public readonly int $days,
    ) {}

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->days > 0 ? __('Completion date in :days days', ['days' => $this->days]) : __('Terminal report overdue'))
            ->line(self::plain($this->message()))
            ->action(__('Open project'), $this->url());
    }

    protected function message(): string
    {
        $replace = ['days' => abs($this->days), 'title' => $this->submission->displayTitle(), 'date' => $this->submission->target_date?->format('F j, Y')];

        // Written in full for the faculty who read it: the project, the date, and what to do.
        return $this->days > 0
            ? trans_choice('{1} The completion date of “:title” is in 1 day, on :date. Please upload the terminal report on or before that date.|[2,*] The completion date of “:title” is in :days days, on :date. Please upload the terminal report on or before that date.', $this->days, $replace)
            : trans_choice('{1} The completion date of “:title” was :date, 1 day ago, and the terminal report has not been uploaded yet. Please upload it as soon as you can.|[2,*] The completion date of “:title” was :date, :days days ago, and the terminal report has not been uploaded yet. Please upload it as soon as you can.', abs($this->days), $replace);
    }

    protected function url(): string
    {
        return route('drive.show', $this->submission);
    }
}
