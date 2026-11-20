{{--
    What the Research Office asked to fix when it returned the concept proposal for revision.
    Remarks belong to a return only, so a passed concept proposal shows none. Expects: $submission.
--}}
@if ($submission->conceptReview() === 'returned')
    {{-- The pastel set, as it asks for a fix rather than warns; Flux's amber text fell under 4.5:1 on its own fill --}}
    <flux:callout icon="arrow-uturn-left" class="pastel mt-6 wrap-anywhere" :heading="__('Concept proposal returned for revision')" :text="$submission->remarks" data-test="concept-remarks" />
@endif
