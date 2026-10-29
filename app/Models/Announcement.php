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
use Symfony\Component\HttpFoundation\Response;

/**
 * A notice from the Research Office: a schedule of submissions when it has a deadline, a memo when it has a file, or
 * both. The file sits on the portal's Google Drive, like project documents.
 *
 * @property Carbon|null $due_on
 * @property string|null $attachment_path
 * @property string|null $attachment_name
 * @property string|null $attachment_alt
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
     * How the memo previews in the portal: an image shows on the page, a PDF opens in the browser's own viewer, and a
     * .docx is drawn by docx-preview. An old .doc, or no memo, has no preview and is only downloaded.
     *
     * @return 'image'|'pdf'|'docx'|null
     */
    public function memoPreview(): ?string
    {
        return match (strtolower(pathinfo((string) $this->attachment_name, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg', 'png', 'webp' => 'image',
            'pdf' => 'pdf',
            'docx' => 'docx',
            default => null,
        };
    }

    /**
     * The memo's link. It names the stored file's version, so a browser may keep the file: a replaced memo gets a new
     * path, and with it a new link.
     */
    public function memoUrl(): string
    {
        return route('announcements.memo', [$this, 'v' => $this->memoVersion()]);
    }

    /** An image memo's smaller copy, for showing it on the page; the same versioning as memoUrl(). */
    public function memoPreviewUrl(): string
    {
        return route('announcements.memo.preview', [$this, 'v' => $this->memoVersion()]);
    }

    public function memoVersion(): string
    {
        return substr(sha1((string) $this->attachment_path), 0, 12);
    }

    /**
     * A link naming the current file's version always means the same file, so the member's own browser keeps it for a
     * week instead of fetching it from Google Drive on every visit. An old link still opens the memo, uncached.
     */
    public function keepIfCurrent(Response $response, string $version): Response
    {
        if ($version === $this->memoVersion()) {
            $response->headers->set('Cache-Control', 'private, max-age=604800');
        }

        return $response;
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
