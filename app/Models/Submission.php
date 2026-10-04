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
    'remarks',
])]
class Submission extends Model
{
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
     * @return BelongsTo<ResearchType, $this>
     */
    public function researchType(): BelongsTo
    {
        return $this->belongsTo(ResearchType::class);
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
     * Where the project is in STATUSES, counting from 0. A status outside the list, such as one left
     * from before the stages existed, counts as Submitted.
     */
    public function stageIndex(): int
    {
        return (int) array_search($this->status, self::STATUSES, true);
    }

    /**
     * The stage the project moves to next and where its document stands: "review" while an upload waits
     * for the Research Office, "returned" when the document was reviewed but the stage didn't move, and
     * "missing" when it hasn't been uploaded. Null once the project is Completed.
     *
     * @return array{stage: string, document: string, state: 'review'|'returned'|'missing'}|null
     */
    public function nextStep(): ?array
    {
        $stage = self::STATUSES[$this->stageIndex() + 1] ?? null;

        if ($stage === null) {
            return null;
        }

        $document = self::STAGE_DOCUMENTS[$stage];

        return match (true) {
            // The upload under review can be a later stage's document, when a project skips ahead.
            $this->awaiting_review === true => ['stage' => $stage, 'document' => $this->latestUpload()['stage'] ?? $document, 'state' => 'review'],
            (bool) $this->{$document.'_path'} => ['stage' => $stage, 'document' => $document, 'state' => 'returned'],
            default => ['stage' => $stage, 'document' => $document, 'state' => 'missing'],
        };
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
     * The most recently uploaded document, read from the stored files' times since a new upload
     * replaces the file. Null when no file is on disk.
     *
     * @return array{stage: string, at: Carbon}|null
     */
    public function latestUpload(): ?array
    {
        return collect(array_keys(self::DOCUMENTS))
            ->filter(fn (string $stage) => $this->{$stage.'_path'} && Storage::disk('submissions')->exists($this->{$stage.'_path'}))
            ->map(fn (string $stage) => ['stage' => $stage, 'at' => Carbon::createFromTimestamp(Storage::disk('submissions')->lastModified($this->{$stage.'_path'}))])
            ->sortByDesc('at')
            ->first();
    }

    /**
     * Store a document for one stage, replacing the earlier file so only one version exists,
     * and put the project back on the Research Office's review list. Inside a transaction, the
     * earlier file is deleted only once it commits, so a save that rolls back keeps it.
     */
    public function attachDocument(string $stage, UploadedFile $file): void
    {
        $old = $this->{$stage.'_path'};
        $new = $file->store('submissions', 'submissions');
        DB::afterRollBack(fn () => Storage::disk('submissions')->delete($new));

        $this->{$stage.'_path'} = $new;
        $this->awaiting_review = true;

        if ($stage === 'terminal') {
            $this->terminal_uploaded_at = now();
        }

        $this->save();

        if ($old) {
            DB::afterCommit(fn () => Storage::disk('submissions')->delete($old));
        }
    }
}
