<?php

namespace App\Notifications;

use App\Models\Submission;
use App\Models\User;

/**
 * Tells the Research Office, the day after, that a project passed its completion date without a terminal report.
 * Once per project: the weekly overdue reminders after that go to its proponents only. Portal only.
 */
class ProjectLate extends PortalNotification
{
    public function __construct(
        public readonly Submission $submission,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

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
