<?php

namespace App\Notifications;

use App\Models\Submission;
use App\Models\User;

/**
 * Tells the Research Office a project is complete, the first time its terminal report comes in, so it can record
 * whether the project was presented in-house. Portal only: it is a record to act on, not news to chase.
 */
class ProjectCompleted extends PortalNotification
{
    public function __construct(
        public readonly Submission $submission,
        public readonly string $uploader,
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
        $late = (int) $this->submission->target_date->copy()->startOfDay()->diffInDays($this->submission->terminal_uploaded_at->copy()->startOfDay());
        $replace = [
            'title' => $this->submission->displayTitle(),
            'name' => $this->uploader,
            'date' => $this->submission->terminal_uploaded_at->format('F j, Y'),
            'due' => $this->submission->target_date->format('F j, Y'),
            'days' => $late,
        ];

        return $this->submission->completedOnTime() === false
            ? trans_choice('{1} “:title” is complete: :name uploaded the terminal report on :date, 1 day after its completion date of :due. Record whether it was presented in-house under Submissions.|[2,*] “:title” is complete: :name uploaded the terminal report on :date, :days days after its completion date of :due. Record whether it was presented in-house under Submissions.', $late, $replace)
            : __('“:title” is complete: :name uploaded the terminal report on :date, on time. Record whether it was presented in-house under Submissions.', $replace);
    }

    protected function url(): string
    {
        return route('submissions.index', ['status' => 'not-presented']);
    }
}
