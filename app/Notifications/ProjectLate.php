<?php

namespace App\Notifications;

use App\Models\Submission;

/**
 * Tells the Research Office, the day after, that a project passed its completion date without a terminal report.
 * Once per project: the weekly overdue reminders after that go to its proponents only. Portal only.
 */
class ProjectLate extends PortalNotification
{
    protected const bool EMAILED = false;

    public function __construct(
        public readonly Submission $submission,
    ) {}

    protected function message(): string
    {
        return __('“:title”, filed by :name, passed its completion date of :date without a terminal report. Its proponents have been reminded.', [
            'title' => $this->submission->displayTitle(),
            'name' => $this->submission->user->displayName(),
            'date' => $this->submission->target_date->format('F j, Y'),
        ]);
    }

    protected function url(): string
    {
        return route('submissions.index', ['status' => 'delayed']);
    }
}
