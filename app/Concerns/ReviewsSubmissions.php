<?php

namespace App\Concerns;

use App\Models\ActivityLog;
use App\Models\Submission;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;

/**
 * The review panel, shared by Submissions and the Research Drive project page so a review opens over
 * the page it started from. Pair it with the partials.review-panel flyout and a reviewSaved() method.
 */
trait ReviewsSubmissions
{
    public ?int $reviewingId = null;

    public string $status = '';

    public string $remarks = '';

    /** Lets the proponents of a Completed project upload one corrected document. */
    public bool $reopenUploads = false;

    /** The project's files when the review panel showed them, so a save never clears an upload the reviewer hasn't seen. */
    #[Locked]
    public array $reviewedFiles = [];

    /** What the page does once a review saves, such as redrawing its list. */
    abstract protected function reviewSaved(): void;

    #[Computed]
    public function reviewing(): ?Submission
    {
        return $this->reviewingId
            ? Submission::with(['user:id,name', 'department:id,code', 'category:id,name', 'proponents.user:id,name,department_id', 'proponents.user.department:id,code'])->find($this->reviewingId)
            : null;
    }

    /**
     * Open the review panel for a project. Faculty can load these pages too, so every
     * review action checks for an admin on the server, not just in the markup.
     */
    public function review(int $id): void
    {
        $this->authorize('review', Submission::class);

        $this->reviewingId = $id;
        unset($this->reviewing);
        $submission = $this->reviewing ?? abort(404);

        $this->status = $submission->status;
        $this->remarks = (string) $submission->remarks;
        $this->reopenUploads = $submission->uploads_reopened;
        $this->reviewedFiles = $this->documentPaths($submission);
        $this->resetValidation();

        Flux::modal('review-submission')->show();
    }

    /**
     * Forget the project, so later filter and page changes don't reload it. Nothing on screen changes, so skip the render.
     */
    #[Renderless]
    public function closeReview(): void
    {
        $this->reviewingId = null;
    }

    /**
     * Save the review. Saving takes the project off the review list whether or not the status
     * moves, so a document found incomplete stays at its old status until a corrected upload.
     */
    public function saveReview(): void
    {
        $this->authorize('review', Submission::class);

        $this->remarks = trim($this->remarks);

        $validated = $this->validate([
            // Back to an earlier stage, or one stage forward once its document is on file; the panel locks the rest.
            'status' => ['required', Rule::in(Submission::findOrFail($this->reviewingId)->reviewableStatuses())],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ], [
            'status.in' => __('That stage isn’t open yet. A project moves forward one stage at a time, once its document is on file.'),
        ]);

        // The review and its log entry save together, so a failed log write never leaves a review the log missed.
        DB::transaction(function () use ($validated) {
            // Locked until the review saves, so an upload can't slip in between the check and the save.
            $submission = Submission::lockForUpdate()->findOrFail($this->reviewingId);

            // A document came in after the panel opened. Show it instead of clearing it unseen.
            if (($paths = $this->documentPaths($submission)) !== $this->reviewedFiles) {
                $this->reviewedFiles = $paths;

                throw ValidationException::withMessages(['review' => __('A new document came in while this was open. Check it, then save the review again.')]);
            }

            $submission->update([
                'status' => $validated['status'],
                'remarks' => $validated['remarks'] ?: null,
                'awaiting_review' => false,
                // Only a Completed project is closed to uploads, so the flag means nothing on any other status.
                'uploads_reopened' => $validated['status'] === 'Completed' && $this->reopenUploads,
            ]);

            // The project keeps only the latest remarks, so the log holds each review's own copy.
            ActivityLog::record('submission.reviewed', $submission, array_filter([
                'status' => [$submission->getPrevious()['status'] ?? $submission->status, $submission->status],
                'remarks' => $submission->remarks,
                'reopened' => $submission->wasChanged('uploads_reopened') && $submission->uploads_reopened,
            ]));
        });

        $this->reviewSaved();
    }

    /**
     * The stored file for each stage. A new upload always gets a new path, so a changed path means a new document.
     *
     * @return array<string, string|null>
     */
    private function documentPaths(Submission $submission): array
    {
        return $submission->only(array_map(fn (string $stage) => $stage.'_path', array_keys(Submission::DOCUMENTS)));
    }
}
