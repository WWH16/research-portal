<?php

namespace App\Notifications;

use App\Models\Submission;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells a project's people that the Research Office moved its dates, since the deadline reminders move with them.
 */
class ProjectDatesChanged extends PortalNotification
{
    protected const ?string EMAIL_KIND = 'dates';

    public function __construct(
        public readonly Submission $submission,
    ) {}

    public function toMail(User $notifiable): MailMessage
    {
        return parent::toMail($notifiable)->subject(__('New project dates'));
    }

    protected function message(): string
    {
        return __('New dates for :title: starts :start, due :due.', [
            'title' => $this->submission->displayTitle(),
            'start' => $this->submission->start_date->format('M j, Y'),
            'due' => $this->submission->target_date->format('M j, Y'),
        ]);
    }

    protected function url(): string
    {
        return route('drive.show', $this->submission);
    }
}
