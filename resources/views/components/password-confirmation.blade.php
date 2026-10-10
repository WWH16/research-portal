@props([
    'password',
    'confirmation',
])

{{-- The Confirm password field, with a live note on whether it matches the new password, so a typo shows up before submitting.
     `password` and `confirmation` are Alpine expressions for the two values: x-model variables on plain forms, $wire properties in Livewire.
     A match shows as soon as it happens; a mismatch waits until the field loses focus, so a half-typed password is not flagged. --}}
<flux:field x-data="{ left: false }" x-on:focusout="left = true">
    <flux:label>{{ __('Confirm password') }}</flux:label>

    <flux:input
        :attributes="$attributes"
        type="password"
        required
        autocomplete="new-password"
        passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
        viewable
    />

    {{-- x-if adds and removes the note, so screen readers announce it from the live region. --}}
    <div aria-live="polite">
        <template x-if="{{ $confirmation }} && {{ $confirmation }} === {{ $password }}">
            <p class="mt-3 flex items-center gap-1.5 text-sm font-medium text-isu-green-700">
                <flux:icon.check-circle variant="mini" class="size-4 shrink-0" />
                {{ __('Passwords match') }}
            </p>
        </template>

        <template x-if="left && {{ $confirmation }} && {{ $confirmation }} !== {{ $password }}">
            <flux:error :message="__('Passwords don\'t match')" />
        </template>
    </div>

    <flux:error name="password_confirmation" />
</flux:field>
