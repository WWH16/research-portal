<?php

namespace App\Models;

use App\Services\DocumentStorage;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A notice from the Research Office: a schedule of submissions when it has a deadline, a memo when it has a file, or
 * both. The file sits on the portal's Google Drive, like project documents.
 *
 * @property Carbon|null $due_on
 * @property string|null $attachment_path
 * @property string|null $attachment_name
 */
#[Fillable(['posted_by', 'title', 'description', 'due_on'])]
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['due_on' => 'date'];
    }

    /**
     * The deadline in a full sentence, such as "Due on November 15, 2026, in 17 days.", or null without one.
     */
    public function deadline(): ?string
    {
        if (! $this->due_on) {
            return null;
        }

        $date = $this->due_on->format('F j, Y');
        $days = (int) today()->diffInDays($this->due_on, false);

        return match (true) {
            $days < 0 => __('Closed on :date.', ['date' => $date]),
            $days === 0 => __('Due today, :date.', ['date' => $date]),
            $days === 1 => __('Due tomorrow, :date.', ['date' => $date]),
            default => __('Due on :date, in :days days.', ['date' => $date, 'days' => $days]),
        };
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * Put $file in place of the memo, or take the memo off with null; the caller saves. Inside a transaction, a new
     * file that never gets saved leaves Drive again, and the old one leaves only once the save commits.
     */
    public function replaceMemo(?UploadedFile $file): void
    {
        $storage = app(DocumentStorage::class);
        $old = $this->attachment_path;

        if ($file) {
            $new = $storage->store($file, 'announcements');
            DB::afterRollBack(fn () => $storage->delete($new));
        }

        $this->attachment_path = $new ?? null;
        $this->attachment_name = $file?->getClientOriginalName();

        if ($old) {
            DB::afterCommit(fn () => $storage->delete($old));
        }
    }
}
