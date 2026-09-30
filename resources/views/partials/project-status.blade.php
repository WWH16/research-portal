{{--
    A project's status badge, plus "Awaiting review" and "Delayed" when they apply.
    Expects: $submission; optional $class for the wrapper, such as flex-row-reverse to keep the stage badge at the right edge.
--}}
<div class="flex flex-wrap gap-1 {{ $class ?? '' }}">
    <flux:badge size="sm" inset="top bottom" :color="\App\Models\Submission::STATUS_COLORS[$submission->status] ?? 'zinc'">{{ __($submission->status) }}</flux:badge>
    @if ($submission->awaiting_review)
        <flux:badge size="sm" inset="top bottom" color="blue">{{ __('Awaiting review') }}</flux:badge>
    @endif
    @if ($submission->isDelayed())
        <flux:badge size="sm" inset="top bottom" color="red">{{ __('Delayed') }}</flux:badge>
    @endif
</div>
