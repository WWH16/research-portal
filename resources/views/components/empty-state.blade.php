{{--
    An empty list or page: an icon for what belongs here, a heading, the sentence that says what fills it, and the one
    action that does, when the member can take it. One look for every empty list in the portal, so an empty page reads
    the same wherever it is. Inside a card, `inline` makes it one line with a small icon instead, so no box sits in a box;
    `good` colours that icon green when empty is good news.
    Usage: <x-empty-state icon="tag" :heading="__('No categories yet')" :text="__('Add one so …')">
               <flux:button icon="plus" wire:click="create">{{ __('Add the first category') }}</flux:button>
           </x-empty-state>
           <x-empty-state inline good icon="check-circle" :heading="__('Nothing needs your attention.')" />
--}}
@props(['icon', 'heading', 'text' => '', 'level' => 2, 'inline' => false, 'good' => false])

@if ($inline)
    <p {{ $attributes->class('flex items-start gap-2 text-sm text-zinc-600') }}>
        <flux:icon :icon="$icon" variant="mini" @class(['mt-0.5 shrink-0', 'text-isu-green-700' => $good, 'text-zinc-400' => ! $good]) aria-hidden="true" />
        {{ $heading }}
    </p>
@else
    <div {{ $attributes->class('flex flex-col items-center rounded-xl border border-dashed border-line px-6 py-12 text-center') }}>
        <span class="grid size-12 place-items-center rounded-full bg-zinc-100" aria-hidden="true">
            <flux:icon :icon="$icon" class="text-zinc-500" />
        </span>
        <flux:heading :level="$level" class="mt-4 text-base!">{{ $heading }}</flux:heading>
        <flux:text class="mt-1 max-w-sm">{{ $text }}</flux:text>
        @if ($slot->isNotEmpty())
            <div class="mt-5 flex flex-wrap justify-center gap-2">{{ $slot }}</div>
        @endif
    </div>
@endif
