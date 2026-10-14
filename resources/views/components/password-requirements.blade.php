@props([
    'password',
])

{{-- The password requirements as a checklist that ticks off while the user types.
     `password` is an Alpine expression for the typed value: an x-model variable on plain forms, a $wire property in Livewire.
     The list comes from Password::default(), so it shows the full rules in production and only the length elsewhere.
     Each check mirrors the regex Laravel's Password rule runs, so the list and the server agree. --}}
@php
    $rules = \Illuminate\Validation\Rules\Password::default()->appliedRules();

    // A null value would throw on the spread and match /\pL/ as the text "null".
    $value = "({$password} ?? '')";

    $checks = array_filter([
        __('At least :min characters', ['min' => $rules['min']]) => "[...{$value}].length >= {$rules['min']}",
        __('Uppercase and lowercase letters') => $rules['mixedCase'] ? "/\\p{Lu}/u.test({$value}) && /\\p{Ll}/u.test({$value})" : null,
        __('At least one letter') => $rules['letters'] && ! $rules['mixedCase'] ? "/\\pL/u.test({$value})" : null,
        __('At least one number') => $rules['numbers'] ? "/\\pN/u.test({$value})" : null,
        __('At least one symbol, like ! or #') => $rules['symbols'] ? "/\\p{Z}|\\p{S}|\\p{P}/u.test({$value})" : null,
    ]);
@endphp

{{-- Each icon sits in a box one line tall, so it stays beside the first line when the text wraps. --}}
<ul {{ $attributes->class('mt-3 space-y-1.5 text-sm') }} data-test="password-requirements">
    @foreach ($checks as $label => $check)
        <li
            class="flex items-start gap-1.5"
            x-data="{ get met() { return {{ $check }} } }"
            x-bind:class="met ? 'font-medium text-isu-green-700' : 'text-zinc-500'"
        >
            <span class="flex h-5 w-4 shrink-0 items-center justify-center">
                <flux:icon.check-circle variant="mini" class="size-4" x-show="met" x-cloak />
                <span class="m-px size-3.5 rounded-full border-[1.5px] border-current" x-show="! met"></span>
            </span>
            <span>
                <span class="sr-only" x-text="met ? @js(__('Done:')) : @js(__('Not yet:'))"></span>
                {{ $label }}
            </span>
        </li>
    @endforeach

    {{-- The browser can't check this one, so it stays a plain note; the server checks it on submit. --}}
    @if ($rules['uncompromised'])
        <li class="flex items-start gap-1.5 text-zinc-500">
            <span class="flex h-5 w-4 shrink-0 items-center justify-center">
                <flux:icon.information-circle variant="mini" class="size-4" />
            </span>
            <span>{{ __('Not in a known data breach (checked on submit)') }}</span>
        </li>
    @endif
</ul>
