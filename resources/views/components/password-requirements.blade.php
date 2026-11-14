@props([
    'password',
])

{{-- The password requirements as a small, quiet checklist that ticks off while the user types.
     `password` is an Alpine expression for the typed value: an x-model variable on plain forms, a $wire property in Livewire.
     The list comes from Password::default(), so it shows the full rules in production and only the length elsewhere.
     Each check mirrors the regex Laravel's Password rule runs, so the list and the server agree.
     Use the braced \p{…} form: JavaScript rejects PHP's \pL shorthand, which breaks the whole row.
     The data breach check is left out: the browser can't run it, and the server names it on submit if it fails. --}}
@php
    $rules = \Illuminate\Validation\Rules\Password::default()->appliedRules();

    // A null value would throw on the spread and match /\p{L}/ as the text "null".
    $value = "({$password} ?? '')";

    $checks = array_filter([
        __('At least :min characters', ['min' => $rules['min']]) => "[...{$value}].length >= {$rules['min']}",
        __('Uppercase and lowercase letters') => $rules['mixedCase'] ? "/\\p{Lu}/u.test({$value}) && /\\p{Ll}/u.test({$value})" : null,
        __('At least one letter') => $rules['letters'] && ! $rules['mixedCase'] ? "/\\p{L}/u.test({$value})" : null,
        __('At least one number') => $rules['numbers'] ? "/\\p{N}/u.test({$value})" : null,
        __('At least one symbol, like ! or #') => $rules['symbols'] ? "/\\p{Z}|\\p{S}|\\p{P}/u.test({$value})" : null,
    ]);
@endphp

{{-- Each mark sits in a box one line tall, so it stays beside the first line when the text wraps. --}}
<ul {{ $attributes->class('mt-2 text-xs leading-5 text-zinc-500') }}>
    @foreach ($checks as $label => $check)
        <li
            class="flex items-start gap-2"
            x-data="{ get met() { return {{ $check }} } }"
            x-bind:class="met && 'text-isu-green-700'"
        >
            <span class="flex h-5 w-3.5 shrink-0 items-center justify-center">
                <flux:icon.check variant="micro" class="size-3.5" x-show="met" x-cloak />
                <span class="size-1.5 rounded-full bg-current opacity-50" x-show="! met"></span>
            </span>
            <span>
                <span class="sr-only" aria-live="polite" x-text="met ? @js(__('Done:')) : @js(__('Not yet:'))"></span>
                {{ $label }}
            </span>
        </li>
    @endforeach
</ul>
