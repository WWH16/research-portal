{{--
    A project's status badge, plus "Awaiting review" and "Delayed" when they apply.
    Expects: $submission.
--}}
<div class="flex flex-wrap gap-1">
    <flux:badge size="sm" inset="top bottom" :color="\App\Models\Submission::STATUS_COLORS[$submission->status] ?? 'zinc'">{{ __($submission->status) }}</flux:badge>
    @if ($submission->awaiting_review)
        <flux:badge size="sm" inset="top bottom" color="blue">{{ __('Awaiting review') }}</flux:badge>
    @endif
    @if ($submission->isDelayed())
        <flux:badge size="sm" inset="top bottom" color="red">{{ __('Delayed') }}</flux:badge>
    @endif
</div>
