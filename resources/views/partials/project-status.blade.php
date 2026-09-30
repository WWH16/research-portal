{{--
    A project's status badge, plus "Awaiting review" and "Delayed" when they apply.
    Expects: $submission; optional $class for the wrapper, such as flex-row-reverse to keep the stage badge at the right edge;
    optional $timing to add "On time" or "Late" on completed projects, judged on the terminal report's upload date,
    and to say how many days a delayed project is overdue instead of just "Delayed".
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
    @if (($timing ?? false) && $submission->status === 'Completed' && ($onTime = $submission->completedOnTime()) !== null)
        <flux:badge
            size="sm"
            inset="top bottom"
            :color="$onTime ? 'teal' : 'orange'"
            :title="__('Terminal report uploaded :uploaded; completion date :target', ['uploaded' => $submission->terminal_uploaded_at->format('M j, Y'), 'target' => $submission->target_date->format('M j, Y')])"
        >{{ $onTime ? __('On time') : __('Late') }}</flux:badge>
    @endif
</div>
