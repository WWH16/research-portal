{{--
    An empty list or page: an icon for what belongs here, a heading, the sentence that says what fills it, and the one
    action that does, when the member can take it. One look for every empty list in the portal, so an empty page reads
    the same wherever it is. Not for a panel inside a card; there a line of text says it (see the dashboard).
    Usage: <x-empty-state icon="tag" :heading="__('No categories yet')" :text="__('Add one so …')">
               <flux:button icon="plus" wire:click="create">{{ __('Add the first category') }}</flux:button>
           </x-empty-state>
--}}
@props(['icon', 'heading', 'text' => null, 'level' => 2])

<div {{ $attributes->class('flex flex-col items-center rounded-xl border border-dashed border-line px-6 py-12 text-center') }}>
    <span class="grid size-12 place-items-center rounded-full bg-zinc-100" aria-hidden="true">
        <flux:icon :icon="$icon" class="text-zinc-500" />
    </span>
    <flux:heading :level="$level" class="mt-4 text-base!">{{ $heading }}</flux:heading>
    @if ($text)
        <flux:text class="mt-1 max-w-sm">{{ $text }}</flux:text>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-5 flex flex-wrap justify-center gap-2">{{ $slot }}</div>
    @endif
</div>
