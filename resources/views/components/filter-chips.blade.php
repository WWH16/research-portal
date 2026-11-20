{{--
    The filters a list has set, each a chip that removes it in one click, then Clear all, which calls the page's
    clearFilters(). Keyed by the Livewire action that removes each one; shows nothing while no filter is set.
    Usage: <x-filter-chips :chips="["\$set('group', '')" => __('Type: Sign-ins')]" />
--}}
@props(['chips'])

@if ($chips)
    <div class="mt-3 flex flex-wrap items-center gap-2" data-test="active-filters">
        @foreach ($chips as $remove => $chip)
            <flux:button size="sm" variant="filled" icon:trailing="x-mark" wire:click="{{ $remove }}" :aria-label="__('Remove filter: :filter', ['filter' => $chip])" class="max-sm:h-11">{{ $chip }}</flux:button>
        @endforeach
        <flux:button size="sm" variant="ghost" wire:click="clearFilters" class="max-sm:h-11">{{ __('Clear all') }}</flux:button>
    </div>
@endif
