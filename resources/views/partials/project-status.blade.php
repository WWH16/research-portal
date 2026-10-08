{{--
    A project's status badge, plus where its concept proposal review stands and "Delayed" when they apply.
    A passed concept proposal is the norm, so it gets no badge, like finishing on time.
    Expects: $submission; optional $class for the wrapper, such as flex-row-reverse to keep the stage badge at the right edge;
    optional $timing to flag completed projects that finished late (judged on the terminal report's upload date),
    and to say how many days a delayed project is overdue instead of just "Delayed".
    On time is the norm, so it gets no badge: an unmarked completed project finished on time.
--}}
<div class="flex flex-wrap gap-1 {{ $class ?? '' }}">
    <flux:badge size="sm" inset="top bottom" :color="\App\Models\Submission::STATUS_COLORS[$submission->status] ?? 'zinc'">{{ __($submission->status) }}</flux:badge>
    @if ($submission->awaiting_review)
        <flux:badge size="sm" inset="top bottom" color="blue" :title="__('The concept proposal is waiting for the Research Office to review')">{{ __('Concept to review') }}</flux:badge>
    @elseif (! $submission->concept_passed)
        <flux:badge size="sm" inset="top bottom" color="amber" :title="__('The Research Office returned the concept proposal for revision')">{{ __('Concept needs revision') }}</flux:badge>
    @endif
    @if ($submission->isDelayed())
        @if ($timing ?? false)
            @php($overdue = (int) $submission->target_date->diffInDays(today()))
            <flux:badge size="sm" inset="top bottom" color="red" :title="__('Completion date :date', ['date' => $submission->target_date->format('M j, Y')])">
                {{ trans_choice('{1} 1 day overdue|[2,*] :count days overdue', $overdue) }}
            </flux:badge>
        @else
            <flux:badge size="sm" inset="top bottom" color="red">{{ __('Delayed') }}</flux:badge>
        @endif
    @endif
    @if (($timing ?? false) && $submission->status === 'Completed')
        @if ($submission->completedOnTime() === false)
            <flux:badge
                size="sm"
                inset="top bottom"
                color="orange"
                :title="__('Terminal report uploaded :uploaded; completion date :target', ['uploaded' => $submission->terminal_uploaded_at->format('M j, Y'), 'target' => $submission->target_date->format('M j, Y')])"
            >{{ __('Late') }}</flux:badge>
        @endif
    @endif
</div>
