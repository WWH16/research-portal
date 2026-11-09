<?php

namespace App\Console\Commands;

use App\Models\Submission;
use App\Services\DocumentStorage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Files documents uploaded before project folders existed into those folders. It changes the saved paths of the database
 * it runs against, so run it only where that database belongs with the Google account, which is production. Safe to run
 * again: a document already in a folder is left alone.
 */
#[Signature('drive:organize {--force : Move the files instead of only listing them}')]
#[Description('Move older project documents from the flat submissions folder into college and project folders')]
class OrganizeDriveFolders extends Command
{
    public function handle(DocumentStorage $storage): int
    {
        $force = (bool) $this->option('force');
        $moved = 0;
        $failed = 0;

        Submission::with('department')->lazyById()->each(function (Submission $project) use ($storage, $force, &$moved, &$failed) {
            // Moving a file isn't editing the project, so its "last updated" time stays.
            $project->timestamps = false;
            $folder = $project->driveFolder() ?? Submission::newDriveFolder($project->department->code, $project->title);

            foreach (array_keys(Submission::DOCUMENTS) as $stage) {
                $column = $stage.'_path';
                $from = $project->{$column};

                if ($from === null || str_starts_with(Str::after($from, '/'), Submission::DRIVE_ROOT.'/')) {
                    continue;
                }

                $to = rtrim(Str::before($from, '/')."/{$folder}/".Submission::driveDocumentName($stage).'.'.pathinfo($from, PATHINFO_EXTENSION), '.');
                $this->line("{$from} -> {$to}");

                if (! $force) {
                    $moved++;

                    continue;
                }

                try {
                    // The path changes first, inside the transaction, so a move Google refuses leaves the row as it was.
                    DB::transaction(function () use ($project, $storage, $column, $from, $to) {
                        $project->forceFill([$column => $to])->save();
                        $storage->move($from, $to);
                    });
                    $moved++;
                } catch (Throwable $e) {
                    report($e);
                    $project->{$column} = $from;
                    $failed++;
                    $this->error("Not moved: {$from}");
                }
            }
        });

        $this->info($force ? "Moved: {$moved}. Failed: {$failed}." : "To move: {$moved}. Run again with --force to move them.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
