{{--
    A project's status badge, plus "Awaiting review" and "Delayed" when they apply.
    Expects: $submission; optional $class for the wrapper, such as flex-row-reverse to keep the stage badge at the right edge;
    optional $timing to flag completed projects that finished late (judged on the terminal report's upload date) or
    have no terminal report at all, and to say how many days a delayed project is overdue instead of just "Delayed".
    On time is the norm, so it gets no badge: an unmarked completed project finished on time.
--}}
<div class="flex flex-wrap gap-1 {{ $class ?? '' }}">
    <flux:badge size="sm" inset="top bottom" :color="\App\Models\Submission::STATUS_COLORS[$submission->status] ?? 'zinc'">{{ __($submission->status) }}</flux:badge>
    @if ($submission->awaiting_review)
        <flux:badge size="sm" inset="top bottom" color="blue">{{ __('Awaiting review') }}</flux:badge>
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
        @if (! $submission->terminal_path)
            <flux:badge size="sm" inset="top bottom" color="amber" :title="__('Marked Completed, but no terminal report was uploaded')">{{ __('No terminal report') }}</flux:badge>
        @elseif ($submission->completedOnTime() === false)
            <flux:badge
                size="sm"
                inset="top bottom"
                color="orange"
                :title="__('Terminal report uploaded :uploaded; completion date :target', ['uploaded' => $submission->terminal_uploaded_at->format('M j, Y'), 'target' => $submission->target_date->format('M j, Y')])"
            >{{ __('Late') }}</flux:badge>
        @endif
    @endif
</div>
