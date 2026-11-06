<?php

use App\Models\Submission;
use App\Models\User;
use App\Notifications\ProjectDeadline;
use App\Notifications\ProjectLate;
use App\Services\Heartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Deletes activity log entries past ActivityLog::KEEP_MONTHS, except each project's history.
Schedule::command('model:prune')->daily();

// Deletes password reset tokens past their expiry, which would otherwise stay in the table for good.
Schedule::command('auth:clear-resets')->daily();

// Bell notifications and failed email records would otherwise stay for good, so each is kept for 12 months.
Artisan::command('notifications:prune', function () {
    DatabaseNotification::where('created_at', '<', now()->subMonths(12))->delete();
})->purpose('Delete notifications older than 12 months');
Schedule::command('notifications:prune')->daily();
Schedule::command('queue:prune-failed', ['--hours' => 365 * 24])->daily();

// Lets the sidebar warn admins when the scheduler or the queue worker has stopped.
Schedule::call(fn () => Heartbeat::beat())->everyFiveMinutes();

// Reminds each open project's people 30 and 7 days before its target date, then the day after it passes and weekly until the terminal report is in.
Artisan::command('projects:remind', function () {
    // ponytail: stateless, so a day the scheduler misses is a reminder skipped; record sent milestones if that ever matters.
    // ponytail: loads every open project and filters in PHP; compute the matching target dates in SQL if projects reach the thousands.
    Submission::whereNotNull('target_date')->whereNull('terminal_uploaded_at')->each(function (Submission $submission) {
        $days = (int) today()->diffInDays($submission->target_date, false);

        if (in_array($days, [30, 7], true) || ($days <= -1 && (-$days - 1) % 7 === 0)) {
            Notification::send($submission->people(), new ProjectDeadline($submission, $days));
        }

        // The Research Office, admins and staff, hears once, the day after a project runs late; the weekly reminders after that stay with its people.
        if ($days === -1) {
            Notification::send(User::researchOffice()->get(), new ProjectLate($submission));
        }
    });
})->purpose('Remind proponents of near and passed target dates');

// On one server only, so a second app instance never sends every reminder twice.
Schedule::command('projects:remind')->dailyAt('07:00')->onOneServer();
