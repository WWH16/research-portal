<?php

use App\Models\Submission;
use App\Notifications\ProjectDeadline;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Deletes activity log entries past ActivityLog::KEEP_MONTHS, except each project's history.
Schedule::command('model:prune')->daily();

// Reminds each open project's people 30 and 7 days before its target date, then the day after it passes and weekly until the terminal report is in.
Artisan::command('projects:remind', function () {
    // ponytail: stateless, so a day the scheduler misses is a reminder skipped; record sent milestones if that ever matters.
    // ponytail: loads every open project and filters in PHP; compute the matching target dates in SQL if projects reach the thousands.
    Submission::whereNotNull('target_date')->whereNull('terminal_uploaded_at')->each(function (Submission $submission) {
        $days = (int) today()->diffInDays($submission->target_date, false);

        if (in_array($days, [30, 7], true) || ($days <= -1 && (-$days - 1) % 7 === 0)) {
            Notification::send($submission->people(), new ProjectDeadline($submission, $days));
        }
    });
})->purpose('Remind proponents of near and passed target dates');

Schedule::command('projects:remind')->dailyAt('07:00');
