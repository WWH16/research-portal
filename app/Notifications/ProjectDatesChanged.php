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
        return parent::toMail($notifiable)->subject(__('Project dates changed'));
    }

    protected function message(): string
    {
        return __('The Research Office has changed the dates of “:title”. The project now starts on :start and is due on :due.', [
            'title' => $this->submission->displayTitle(),
            'start' => $this->submission->start_date->format('F j, Y'),
            'due' => $this->submission->target_date->format('F j, Y'),
        ]);
    }

    protected function url(): string
    {
        return route('drive.show', $this->submission);
    }
}
