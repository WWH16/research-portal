{{--
    A College column header that filters its own table: shows the chosen code (in green when a filter is on),
    lists "All colleges" and every college code, and optionally "No college".
    Usage: <x-college-filter model="college" :value="$college" with-none />
--}}
@props(['model', 'value' => '', 'withNone' => false])

@php($label = match ($value) { '' => __('College'), 'none' => __('No college'), default => $value })

<flux:dropdown position="bottom" align="start">
    <flux:button
        variant="ghost"
        size="sm"
        inset="top bottom"
        icon:trailing="chevron-down"
        class="-ms-2 {{ $value !== '' ? 'text-isu-green-700!' : '' }}"
        :aria-label="$value !== '' ? __('College, filtered to :code. Change filter', ['code' => $label]) : __('College. Filter by college')"
    >
        {{ $label }}
    </flux:button>

    <flux:menu class="max-h-80 min-w-40 overflow-y-auto">
        <flux:menu.radio.group wire:model.live="{{ $model }}">
            <flux:menu.radio value="">{{ __('All colleges') }}</flux:menu.radio>
            @if ($withNone)
                <flux:menu.radio value="none">{{ __('No college') }}</flux:menu.radio>
            @endif
            <flux:menu.separator />
            @foreach (\App\Models\Department::orderBy('code')->pluck('code') as $code)
                <flux:menu.radio :value="$code">{{ $code }}</flux:menu.radio>
            @endforeach
        </flux:menu.radio.group>
    </flux:menu>
</flux:dropdown>
