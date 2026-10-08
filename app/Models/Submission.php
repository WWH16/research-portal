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
 * uploads each document on the same record as the project moves along. The status follows the
 * furthest document uploaded. The Research Office reviews only the concept proposal, passing it
 * or returning it for revision, and never sets the status.
 *
 * @property int $year
 * @property CarbonInterface|null $start_date
 * @property CarbonInterface|null $target_date
 * @property CarbonInterface|null $terminal_uploaded_at
 * @property bool $awaiting_review
 * @property bool $concept_passed
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
    'concept_passed',
    'remarks',
])]
class Submission extends Model
{
    /** @var array<string, Carbon>|null Each stored document's time, read once per request by uploadTimes(). */
    private ?array $uploadTimes = null;

    /** The stages a project moves through as its documents are uploaded, in order. */
    public const STATUSES = ['Concept', 'Detailed', 'Completed'];

    /** The year the portal went live. Pickers and the dashboard start here; nothing earlier is tracked. */
    public const FIRST_YEAR = 2026;

    /** Stages that count as "still at proposal stage" in the yearly summary. */
    public const PROPOSAL_STAGES = ['Concept', 'Detailed'];

    /** Flux badge colour for each status, shared by every list that shows one. */
    public const STATUS_COLORS = ['Concept' => 'sky', 'Detailed' => 'amber', 'Completed' => 'green'];

    /** The documents a project collects, keyed by the prefix of the column that holds each one. */
    public const DOCUMENTS = ['concept' => 'Concept proposal', 'detailed' => 'Detailed proposal', 'terminal' => 'Terminal report'];

    /** The document whose upload moves a project into each stage. */
    public const STAGE_DOCUMENTS = ['Concept' => 'concept', 'Detailed' => 'detailed', 'Completed' => 'terminal'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'target_date' => 'date',
            'terminal_uploaded_at' => 'datetime',
            'awaiting_review' => 'boolean',
            'concept_passed' => 'boolean',
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
     * Whether the terminal report came in by the target date. Null until both dates exist.
     */
    public function completedOnTime(): ?bool
    {
        return $this->terminal_uploaded_at && $this->target_date
            ? $this->terminal_uploaded_at->lte($this->target_date->endOfDay())
            : null;
    }

    /**
     * The documents proponents can upload now, as DOCUMENTS keys: every one already on file, so it can be
     * replaced, plus the next one. A new project starts with the concept proposal, the detailed proposal
     * opens once the Research Office passes the concept proposal, and the terminal report once the
     * detailed proposal is uploaded.
     *
     * @return list<string>
     */
    public function uploadableStages(): array
    {
        return array_keys(array_filter([
            'concept' => true,
            'detailed' => $this->concept_passed || $this->detailed_path,
            'terminal' => (bool) $this->detailed_path,
        ]));
    }

    /**
     * Where the concept proposal's review stands: waiting for the Research Office, passed, or returned for revision.
     *
     * @return 'pending'|'passed'|'returned'
     */
    public function conceptReview(): string
    {
        return match (true) {
            $this->awaiting_review => 'pending',
            $this->concept_passed => 'passed',
            default => 'returned',
        };
    }

    /**
     * The document to upload next, as a DOCUMENTS key, or null once the terminal report is in.
     */
    public function nextDocument(): ?string
    {
        return collect(array_keys(self::DOCUMENTS))->first(fn (string $stage) => ! $this->{$stage.'_path'});
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
     * When each document on disk was uploaded, keyed by stage. A page can ask for these more than once,
     * so the disk is read once per request; on a cloud disk each read is
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
     * Store a document for one stage, replacing the earlier file so only one version exists, and move
     * the status to the furthest document on file. A concept proposal goes on the Research Office's
     * review list; the other documents are filed without review. Inside a transaction, the
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
        $this->status = collect(self::STAGE_DOCUMENTS)->filter(fn (string $document) => $this->{$document.'_path'})->keys()->last();

        // A concept proposal goes for review until one passes; a passed one is replaced without another review.
        if ($stage === 'concept' && ! $this->concept_passed) {
            $this->awaiting_review = true;
        }

        // The first terminal report finishes the project; a later correction never moves when it finished.
        if ($stage === 'terminal') {
            $this->terminal_uploaded_at ??= now();
        }

        $this->save();

        if ($old) {
            DB::afterCommit(fn () => Storage::disk('submissions')->delete($old));
        }
    }
}
