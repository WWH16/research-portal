<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * A research project. The researcher enters it once, lists its proponents per study, and
 * uploads each document on the same record as the project moves along. Only the Research
 * Office moves the status, after reviewing the latest upload.
 *
 * @property int $year
 * @property CarbonInterface|null $start_date
 * @property CarbonInterface|null $target_date
 * @property CarbonInterface|null $terminal_uploaded_at
 * @property bool $awaiting_review
 * @property bool $uploads_reopened
 */
#[Fillable([
    'user_id',
    'research_type_id',
    'category_id',
    'department_id',
    'title',
    'year',
    'start_date',
    'target_date',
    'abstract',
    'designation',
    'concept_path',
    'detailed_path',
    'terminal_path',
    'terminal_uploaded_at',
    'status',
    'awaiting_review',
    'uploads_reopened',
    'remarks',
])]
class Submission extends Model
{
    /** @var array<string, Carbon>|null Each stored document's time, read once per request by uploadTimes(). */
    private ?array $uploadTimes = null;

    /** The stages the Research Office moves a project through, in order. */
    public const STATUSES = ['Submitted', 'Concept', 'Detailed', 'Completed'];

    /** The year the portal went live. Pickers and the dashboard start here; nothing earlier is tracked. */
    public const FIRST_YEAR = 2026;

    /** Stages that count as "still at proposal stage" in the yearly summary. */
    public const PROPOSAL_STAGES = ['Concept', 'Detailed'];

    /** Flux badge colour for each status, shared by every list that shows one. */
    public const STATUS_COLORS = ['Submitted' => 'zinc', 'Concept' => 'sky', 'Detailed' => 'amber', 'Completed' => 'green'];

    /** The documents a project collects, keyed by the prefix of the column that holds each one. */
    public const DOCUMENTS = ['concept' => 'Concept proposal', 'detailed' => 'Detailed proposal', 'terminal' => 'Terminal report'];

    /** The document whose acceptance moves a project into each stage after Submitted. */
    public const STAGE_DOCUMENTS = ['Concept' => 'concept', 'Detailed' => 'detailed', 'Completed' => 'terminal'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'target_date' => 'date',
            'terminal_uploaded_at' => 'datetime',
            'awaiting_review' => 'boolean',
            'uploads_reopened' => 'boolean',
        ];
    }

    /**
     * File a new project under the current year unless the Research Office set another one.
     */
    protected static function booted(): void
    {
        static::creating(function (Submission $submission): void {
            $submission->year ??= now()->year;
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return HasMany<Proponent, $this>
     */
    public function proponents(): HasMany
    {
        return $this->hasMany(Proponent::class);
    }

    /**
     * Every year with a project since the portal went live, plus this one, newest first, for year pickers.
     *
     * @return Collection<int, int>
     */
    public static function years(): Collection
    {
        return static::where('year', '>=', self::FIRST_YEAR)->distinct()->pluck('year')->push(now()->year)->unique()->sortDesc()->values();
    }

    /**
     * Projects the member filed or is listed on as a proponent.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInvolving(Builder $query, User $user): void
    {
        $query->where(fn ($query) => $query
            ->where('user_id', $user->id)
            ->orWhereHas('proponents', fn ($query) => $query->where('user_id', $user->id)));
    }

    /**
     * Projects past their target date with no terminal report uploaded yet.
     *
     * @param  Builder<self>  $query
     */
    public function scopeDelayed(Builder $query): void
    {
        $query->whereDate('target_date', '<', today())->whereNull('terminal_uploaded_at');
    }

    public function involves(User $user): bool
    {
        return $this->user_id === $user->id || $this->proponents()->where('user_id', $user->id)->exists();
    }

    public function isDelayed(): bool
    {
        return $this->target_date?->lt(today()) === true && $this->terminal_uploaded_at === null;
    }

    /**
     * Whether the terminal report came in by the target date. Judged on the upload date,
     * so a late review never counts against the researcher. Null until both dates exist.
     */
    public function completedOnTime(): ?bool
    {
        return $this->terminal_uploaded_at && $this->target_date
            ? $this->terminal_uploaded_at->lte($this->target_date->endOfDay())
            : null;
    }

    /**
     * Whether proponents can upload a document. A Completed project is closed unless the Research Office
     * reopened it for a corrected upload.
     */
    public function acceptsUploads(): bool
    {
        return $this->status !== 'Completed' || $this->uploads_reopened;
    }

    /**
     * The documents proponents can upload now, as DOCUMENTS keys. Each opens once the project reaches the
     * stage before it, so nothing is uploaded ahead of an unreviewed document; a new project starts with
     * the concept proposal. A Completed project takes none until the Research Office reopens it.
     *
     * @return list<string>
     */
    public function uploadableStages(): array
    {
        return $this->acceptsUploads() ? array_slice(array_keys(self::DOCUMENTS), 0, $this->stageIndex() + 1) : [];
    }

    /**
     * The statuses the Research Office can set in a review: back to any earlier one to correct a mistake,
     * or one stage forward once that stage's document is on file. Nothing further ahead.
     *
     * @return list<string>
     */
    public function reviewableStatuses(): array
    {
        $next = $this->stageIndex() + 1;
        $document = self::STAGE_DOCUMENTS[self::STATUSES[$next] ?? ''] ?? null;

        return array_slice(self::STATUSES, 0, $document && $this->{$document.'_path'} ? $next + 1 : $next);
    }

    /**
     * Where the project is in STATUSES, counting from 0. A status outside the list, such as one left
     * from before the stages existed, counts as Submitted.
     */
    public function stageIndex(): int
    {
        return (int) array_search($this->status, self::STATUSES, true);
    }

    /**
     * The stage the project moves to next and where its document stands: "returned" when the document was
     * reviewed but the stage didn't move, "missing" when it hasn't been uploaded. Null once the project is
     * Completed. An upload still waiting for review is documentUnderReview()'s to report.
     *
     * @return array{stage: string, document: string, state: 'returned'|'missing'}|null
     */
    public function nextStep(): ?array
    {
        $stage = self::STATUSES[$this->stageIndex() + 1] ?? '';
        $document = self::STAGE_DOCUMENTS[$stage] ?? null;

        if ($document === null) {
            return null;
        }

        return ['stage' => $stage, 'document' => $document, 'state' => $this->{$document.'_path'} ? 'returned' : 'missing'];
    }

    /**
     * The stage of the upload waiting for the Research Office, or null when nothing waits. Read from the
     * stored files' times; when a file is gone from the disk, the furthest stage on file is the best guess.
     */
    public function documentUnderReview(): ?string
    {
        if ($this->awaiting_review !== true) {
            return null;
        }

        return $this->latestUpload()['stage']
            ?? collect(array_keys(self::DOCUMENTS))->reverse()->first(fn (string $stage) => $this->{$stage.'_path'});
    }

    /**
     * What happened to the project, newest first, from the activity log. Accounts and colleges are logged
     * with their own ids in the same column, so only submission.* entries belong to the project.
     *
     * @return EloquentCollection<int, ActivityLog>
     */
    public function history(): EloquentCollection
    {
        return ActivityLog::where('subject_id', $this->id)->where('action', 'like', 'submission.%')->latest('id')->get();
    }

    /**
     * When each document on disk was uploaded, keyed by stage. A page asks for these in several places (the
     * stage tracker, the document list), so the disk is read once per request; on a cloud disk each read is
     * a network call. A file missing from the disk is left out.
     *
     * @return array<string, Carbon>
     */
    public function uploadTimes(): array
    {
        return $this->uploadTimes ??= collect(array_keys(self::DOCUMENTS))
            ->filter(fn (string $stage) => $this->{$stage.'_path'} && Storage::disk('submissions')->exists($this->{$stage.'_path'}))
            ->mapWithKeys(fn (string $stage) => [$stage => Carbon::createFromTimestamp(Storage::disk('submissions')->lastModified($this->{$stage.'_path'}))])
            ->all();
    }

    /**
     * The most recently uploaded document, read from the stored files' times since a new upload
     * replaces the file. Null when no file is on disk.
     *
     * @return array{stage: string, at: Carbon}|null
     */
    public function latestUpload(): ?array
    {
        $times = $this->uploadTimes();
        arsort($times);

        return $times ? ['stage' => array_key_first($times), 'at' => reset($times)] : null;
    }

    /**
     * Store a document for one stage, replacing the earlier file so only one version exists,
     * and put the project back on the Research Office's review list. Inside a transaction, the
     * earlier file is deleted only once it commits, so a save that rolls back keeps it.
     */
    public function attachDocument(string $stage, UploadedFile $file): void
    {
        $old = $this->{$stage.'_path'};
        // The disk doesn't throw, so a failed write returns false; stop before saving that as the path.
        $new = $file->store('submissions', 'submissions') ?: throw new RuntimeException('The document could not be stored.');
        DB::afterRollBack(fn () => Storage::disk('submissions')->delete($new));

        $this->{$stage.'_path'} = $new;
        $this->uploadTimes = null;
        $this->awaiting_review = true;
        // A reopened Completed project takes one corrected upload, then closes again.
        $this->uploads_reopened = false;

        if ($stage === 'terminal') {
            $this->terminal_uploaded_at = now();
        }

        $this->save();

        if ($old) {
            DB::afterCommit(fn () => Storage::disk('submissions')->delete($old));
        }
    }
}
