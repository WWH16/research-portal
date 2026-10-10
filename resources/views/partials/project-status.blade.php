{{--
    A project's status: one stage badge, with where its concept proposal review stands and "Delayed" on their own lines
    underneath, so the stage never reads as part of a combined state. A passed concept proposal is the norm, so it gets
    no line, like finishing on time.
    Expects: $submission; optional $class for the wrapper, such as items-end to keep the badge at the right edge;
    optional $timing to flag completed projects that finished late (judged on the terminal report's upload date),
    and to say how many days a delayed project is overdue instead of just "Delayed"; optional $delay = false to leave
    "Delayed" out where the list already shows the overdue days beside the due date.
    On time is the norm, so it gets no line: an unmarked completed project finished on time.
--}}
@php($line = 'flex items-center gap-1 whitespace-nowrap text-xs font-medium')
<div class="flex flex-col items-start gap-1 {{ $class ?? '' }}">
    <flux:badge size="sm" inset="top bottom" :color="\App\Models\Submission::STATUS_COLORS[$submission->status] ?? 'zinc'">{{ __($submission->status) }}</flux:badge>
    @if ($submission->awaiting_review)
        <span class="{{ $line }} text-amber-800" title="{{ __('The concept proposal is waiting for the Research Office to review') }}">
            <flux:icon.clock variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
            {{ __('Awaiting review') }}
        </span>
    @elseif (! $submission->concept_passed)
        <span class="{{ $line }} text-rose-700" title="{{ __('The Research Office returned the concept proposal for revision') }}">
            <flux:icon.arrow-uturn-left variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
            {{ __('Needs revision') }}
        </span>
    @endif
    @if (($delay ?? true) && $submission->isDelayed())
        <span class="{{ $line }} text-red-700" title="{{ __('Completion date :date', ['date' => $submission->target_date->format('M j, Y')]) }}">
            <flux:icon.exclamation-triangle variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
            @if ($timing ?? false)
                {{ trans_choice('{1} 1 day overdue|[2,*] :count days overdue', (int) $submission->target_date->diffInDays(today())) }}
            @else
                {{ __('Delayed') }}
            @endif
        </span>
    @endif
    {{-- Finishing late needs a terminal report, so only a completed project can get here --}}
    @if (($timing ?? false) && $submission->completedOnTime() === false)
        <span
            class="{{ $line }} text-orange-700"
            title="{{ __('Terminal report uploaded :uploaded; completion date :target', ['uploaded' => $submission->terminal_uploaded_at->format('M j, Y'), 'target' => $submission->target_date->format('M j, Y')]) }}"
        >
            <flux:icon.flag variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
            {{ __('Late') }}
        </span>
    @endif
</div>
