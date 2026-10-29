<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\ConceptAwaitingReview;
use App\Notifications\SignupAwaitingApproval;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Notices name people short since names in them were shortened; this rewrites the ones sent before, once, so the
 * Notifications page reads the same throughout. Only the name changes: read state, link and time stay.
 */
#[Signature('notifications:tidy-names {--dry-run : List the changes without saving them}')]
#[Description('Shorten the names in notices sent before names were written short')]
class TidyNotificationNames extends Command
{
    /** Where each kind of notice names someone: the name is the middle group. */
    private const NAMES = [
        ConceptAwaitingReview::class => '/(, from )(.+)(\.)$/u',
        SignupAwaitingApproval::class => '/(approval: )(.+?)( \()/u',
    ];

    public function handle(): int
    {
        // Only a member who still exists can be matched; a name that matches no one is left as it is.
        $short = User::all(['id', 'name'])->mapWithKeys(fn (User $user) => [$user->name => $user->shortName()]);
        $changed = 0;

        DatabaseNotification::whereIn('type', array_keys(self::NAMES))->each(function (DatabaseNotification $notice) use ($short, &$changed) {
            $message = $notice->data['message'];
            $tidied = preg_replace_callback(self::NAMES[$notice->type], fn (array $match) => $match[1].($short[$match[2]] ?? $match[2]).$match[3], $message);

            if ($tidied === $message) {
                return;
            }

            $changed++;
            $this->line("{$message}\n  → {$tidied}");

            if (! $this->option('dry-run')) {
                $notice->update(['data' => ['message' => $tidied] + $notice->data]);
            }
        });

        if ($this->option('dry-run')) {
            $this->info(trans_choice('{0} No notices need shortening.|{1} 1 notice would be shortened.|[2,*] :count notices would be shortened.', $changed));
            $this->warn('Nothing was saved: this was a dry run.');
        } else {
            $this->info(trans_choice('{0} No notices need shortening.|{1} 1 notice shortened.|[2,*] :count notices shortened.', $changed));
        }

        return self::SUCCESS;
    }
}
