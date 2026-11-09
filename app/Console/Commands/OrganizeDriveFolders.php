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
#[Signature('drive:organize {--project= : Only this project ID} {--force : Move the files instead of only listing them}')]
#[Description('Move older project documents from the flat submissions folder into college and project folders')]
class OrganizeDriveFolders extends Command
{
    public function handle(DocumentStorage $storage): int
    {
        $force = (bool) $this->option('force');

        if ($force && $this->databaseIsLocal()) {
            $this->error('Refusing to move files for a local database. Run this from Laravel Cloud.');

            return self::FAILURE;
        }

        $moved = 0;
        $failed = 0;

        Submission::with('department')->when($this->option('project'), fn ($query, $id) => $query->whereKey($id))->lazyById()->each(function (Submission $project) use ($storage, $force, &$moved, &$failed) {
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
                    $moved += (int) DB::transaction(function () use ($project, $storage, $column, $from, $to) {
                        // Read again under a lock, as the project form does: a member may have replaced it since this run read the row.
                        $fresh = Submission::lockForUpdate()->findOrFail($project->id);

                        if ($fresh->{$column} !== $from) {
                            return false;
                        }

                        // Moving a file isn't editing the project, so its "last updated" time stays. The path changes
                        // first, inside the transaction, so a move Google refuses leaves the row as it was.
                        $fresh->timestamps = false;
                        $fresh->forceFill([$column => $to])->save();
                        $storage->move($from, $to);

                        return true;
                    });
                } catch (Throwable $e) {
                    report($e);
                    $failed++;
                    $this->error("Not moved: {$from}");
                }
            }
        });

        $this->info($force ? "Moved: {$moved}. Failed: {$failed}." : "To move: {$moved}. Run again with --force to move them.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Whether this run's database is on this computer. The Google account can be shared with production, so moving files
     * for a local database would change where they are while production keeps the old paths.
     */
    private function databaseIsLocal(): bool
    {
        $host = config('database.connections.'.config('database.default').'.host');

        return blank($host) || in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    }
}
