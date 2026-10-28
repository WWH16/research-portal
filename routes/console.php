<?php

use App\Models\GoogleDriveConnection;
use App\Models\Submission;
use App\Notifications\ProjectDeadline;
use App\Services\GoogleDrive;
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

// Copies documents saved before Google Drive, from the server disk or bucket, to the current storage account, and
// saves each project's new path so the document opens from Drive. Safe to run again: documents already on Google
// Drive are skipped, and a document that can't be copied keeps its old path.
Artisan::command('submissions:move-to-drive {--from=submissions : The disk the older documents are on}', function () {
    $source = $this->option('from');

    if (! is_string($source) || $source === '') {
        $this->error('Name the disk to copy from with --from.');

        return 1;
    }

    $account = GoogleDriveConnection::current();

    if (! $account?->isConnected()) {
        $this->error('Connect the Google Drive storage account first, on System → Google Drive.');

        return 1;
    }

    $from = Storage::disk($source);
    $to = app(GoogleDrive::class)->disk($account);
    [$copied, $skipped, $missing] = [0, 0, []];

    Submission::query()->each(function (Submission $submission) use ($from, $to, $account, &$copied, &$skipped, &$missing) {
        foreach (array_keys(Submission::DOCUMENTS) as $stage) {
            $path = $submission->{$stage.'_path'};

            if (! $path) {
                continue;
            }

            if (str_starts_with($path, 'drive-')) {
                $skipped++;
            } elseif (! $from->exists($path) || ! $to->writeStream($path, $from->readStream($path))) {
                $missing[] = $path;
            } else {
                $submission->forceFill([$stage.'_path' => "drive-{$account->id}/{$path}"])->saveQuietly();
                $copied++;
            }
        }
    });

    $this->info("Copied {$copied}, already on Google Drive {$skipped}, missing ".count($missing).'.');

    foreach ($missing as $path) {
        $this->warn("Missing: {$path}");
    }

    return $missing === [] ? 0 : 1;
})->purpose('Copy older project documents to the Google Drive storage account');
