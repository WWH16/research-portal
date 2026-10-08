{{--
    What the Research Office asked to fix when it returned the concept proposal for revision.
    Remarks belong to a return only, so a passed concept proposal shows none. Expects: $submission.
--}}
@if ($submission->conceptReview() === 'returned')
    <flux:callout color="amber" icon="arrow-uturn-left" class="mt-6" :heading="__('Concept proposal returned for revision')" :text="$submission->remarks" data-test="concept-remarks" />
@endif
