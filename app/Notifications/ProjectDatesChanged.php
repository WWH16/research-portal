<?php

namespace App\Notifications;

use App\Models\Submission;

/**
 * Tells a project's people that the Research Office moved its dates, since the deadline reminders move with them.
 */
class ProjectDatesChanged extends PortalNotification
{
    protected const string SUBJECT = 'Project dates changed';

    protected const ?string EMAIL_KIND = 'dates';

    public function __construct(
        public readonly Submission $submission,
    ) {}

    protected function message(): string
    {
        return __('The Research Office has changed the dates of “:title”. The project now starts on :start, and its completion date is :due.', [
            'title' => $this->submission->displayTitle(),
            'start' => $this->submission->start_date->format('F j, Y'),
            'due' => $this->submission->target_date->format('F j, Y'),
        ]);
    }

    protected function mailLines(): array
    {
        return [
            __('The Research Office has changed the dates of :title.', ['title' => self::bold('“'.$this->submission->displayTitle().'”')]),
            __('The project now starts on :start and is due on :due.', [
                'start' => self::bold($this->submission->start_date->format('F j, Y')),
                'due' => self::bold($this->submission->target_date->format('F j, Y')),
            ]),
            __('The completion date reminders for this project now follow the new due date, and the terminal report is due on or before it.'),
        ];
    }

    protected function url(): string
    {
        return route('drive.show', $this->submission);
    }
}
