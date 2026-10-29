<?php

namespace App\Models;

use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
     * @return BelongsTo<User, $this>
     */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
