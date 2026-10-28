<?php

use App\Models\Submission;
use App\Notifications\ProjectDeadline;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

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

// On one server only, so a second app instance never sends every reminder twice.
Schedule::command('projects:remind')->dailyAt('07:00')->onOneServer();

// Copies every document a project points to from the old disk to the submissions disk, under the same path, so the
// saved paths keep working after the switch to Google Drive. Safe to run again: files already there are skipped.
Artisan::command('submissions:move-to-drive {--from=submissions-bucket : The disk the documents are on now}', function () {
    $from = Storage::disk((string) $this->option('from'));
    $to = Storage::disk('submissions');
    [$copied, $skipped, $missing] = [0, 0, []];

    Submission::query()->each(function (Submission $submission) use ($from, $to, &$copied, &$skipped, &$missing) {
        foreach (array_keys(Submission::DOCUMENTS) as $stage) {
            $path = $submission->{$stage.'_path'};

            if (! $path) {
                continue;
            }

            if ($to->exists($path)) {
                $skipped++;
            } elseif (! $from->exists($path) || ! $to->writeStream($path, $from->readStream($path)) || ! $to->exists($path)) {
                $missing[] = $path;
            } else {
                $copied++;
            }
        }
    });

    $this->info("Copied {$copied}, already there {$skipped}, missing ".count($missing).'.');

    foreach ($missing as $path) {
        $this->warn("Missing: {$path}");
    }

    return $missing === [] ? 0 : 1;
})->purpose('Copy project documents to the submissions disk, such as Google Drive');
