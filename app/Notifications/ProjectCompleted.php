<?php

namespace App\Notifications;

use App\Models\Submission;

/**
 * Tells the Research Office a project is complete, the first time its terminal report comes in. Portal only: it is
 * a record to act on, not news to chase.
 */
class ProjectCompleted extends PortalNotification
{
    protected const bool EMAILED = false;

    public function __construct(
        public readonly Submission $submission,
        public readonly string $uploader,
    ) {}

    protected function message(): string
    {
        $late = (int) $this->submission->target_date->copy()->startOfDay()->diffInDays($this->submission->terminal_uploaded_at->copy()->startOfDay());
        $replace = [
            'title' => $this->submission->displayTitle(),
            'name' => $this->uploader,
            'date' => $this->submission->terminal_uploaded_at->format('F j, Y'),
            'due' => $this->submission->target_date->format('F j, Y'),
            'days' => $late,
        ];

        return $this->submission->completedOnTime() === false
            ? trans_choice('{1} “:title” is complete: :name uploaded the terminal report on :date, 1 day after its completion date of :due.|[2,*] “:title” is complete: :name uploaded the terminal report on :date, :days days after its completion date of :due.', $late, $replace)
            : __('“:title” is complete: :name uploaded the terminal report on :date, on time.', $replace);
    }

    protected function url(): string
    {
        return route('submissions.index', ['status' => 'not-presented']);
    }
}
