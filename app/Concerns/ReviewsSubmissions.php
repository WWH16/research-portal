<?php

namespace App\Concerns;

use App\Models\ActivityLog;
use App\Models\Submission;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;

/**
 * The concept proposal review panel, shared by Reviews and the Research Drive project page so a review opens over
 * the page it started from. Pair it with the partials.review-panel flyout and a reviewSaved() method.
 */
trait ReviewsSubmissions
{
    public ?int $reviewingId = null;

    public string $remarks = '';

    /** The concept proposal's file when the review panel showed it, so a save never clears an upload the reviewer hasn't seen. */
    #[Locked]
    public ?string $reviewedConcept = null;

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

        // Each upload gets its own decision, so a pass never carries the remarks of an earlier return.
        $this->remarks = '';
        $this->reviewedConcept = $submission->concept_path;
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
     * Pass the concept proposal, which opens the detailed proposal, or return it for revision with remarks
     * saying what to fix. Either way the project leaves the review list. The review never touches the
     * status, which follows the documents the proponents upload.
     *
     * @param  'passed'|'returned'  $decision
     */
    public function saveReview(string $decision): void
    {
        $this->authorize('review', Submission::class);

        $this->remarks = trim($this->remarks);

        // A cleared-up retry, such as passing once the remarks are gone, must not keep the last attempt's error.
        $this->resetValidation();

        $validated = Validator::make(['decision' => $decision, 'remarks' => $this->remarks], [
            'decision' => ['required', Rule::in(['passed', 'returned'])],
            // Remarks only go with a return. A pass carrying remarks is refused, so typed remarks are never dropped unseen.
            'remarks' => ['prohibited_if:decision,passed', 'exclude_unless:decision,returned', 'required', 'string', 'max:2000'],
        ], [
            'remarks.prohibited_if' => __('You wrote remarks. Return it for revision, or clear them to pass.'),
            'remarks.required' => __('Say what to fix, so the proponents know what to change.'),
        ])->validate();

        // The review and its log entry save together, so a failed log write never leaves a review the log missed.
        DB::transaction(function () use ($validated) {
            // Locked until the review saves, so an upload can't slip in between the check and the save.
            $submission = Submission::lockForUpdate()->findOrFail($this->reviewingId);

            // A decided concept stays decided; only a new upload puts it back up for review.
            if (! $submission->awaiting_review) {
                throw ValidationException::withMessages(['review' => __('Nothing to review. The concept proposal was already decided.')]);
            }

            // A new concept proposal came in after the panel opened. Show it instead of clearing it unseen.
            if ($submission->concept_path !== $this->reviewedConcept) {
                $this->reviewedConcept = $submission->concept_path;

                throw ValidationException::withMessages(['review' => __('A new concept proposal came in while this was open. Check it, then save the review again.')]);
            }

            $submission->update([
                'remarks' => $validated['remarks'] ?? null,
                'awaiting_review' => false,
                'concept_passed' => $validated['decision'] === 'passed',
            ]);

            // The project keeps only the latest remarks, so the log holds each review's own copy.
            ActivityLog::record('submission.reviewed', $submission, array_filter(['decision' => $validated['decision'], 'remarks' => $submission->remarks]));
        });

        // The sidebar count lives in the layout, outside this component, so send it the new queue size.
        $toReview = Submission::where('awaiting_review', true)->count();
        $this->dispatch('review-queue-changed', toReview: $toReview, label: Submission::reviewQueueLabel($toReview));

        $this->reviewSaved();
    }
}
