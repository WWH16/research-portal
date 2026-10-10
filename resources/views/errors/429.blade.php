@php
    // The throttle middleware says how long the pause lasts; fall back to a minute if it doesn't.
    $seconds = (int) ($exception->getHeaders()['Retry-After'] ?? 60);
@endphp

{{-- Most often reached after 5 failed sign-ins in a minute, so it sits in the sign-in layout. Other throttled auth steps
     (resending the verification link, two-factor codes, passkeys) land here too, so the copy doesn't name sign-in. --}}
<x-layouts::auth :title="__('Too many attempts')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Too many attempts')" :description="__('To protect your account, wait a moment before trying again.')" />

        {{-- Counts down in place, then says it's ready. Not a live region, so screen readers aren't told every second. --}}
        <flux:text
            variant="strong"
            x-data="{ left: {{ $seconds }} }"
            x-init="const timer = setInterval(() => { if (--left <= 0) clearInterval(timer) }, 1000)"
            x-text="left > 0
                ? {{ Js::from(__('Try again in')) }} + ' ' + Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0') + '.'
                : {{ Js::from(__('You can try again now.')) }}"
        >{{ __('Try again in a moment.') }}</flux:text>

        @guest
            <div class="flex flex-col gap-3">
                <flux:button variant="primary" class="w-full" :href="route('login')" wire:navigate>
                    {{ __('Back to sign in') }}
                </flux:button>

                <flux:button variant="ghost" class="w-full" :href="route('password.request')" wire:navigate>
                    {{ __('Forgot your password?') }}
                </flux:button>
            </div>
        @else
            <flux:button variant="primary" class="w-full" :href="url()->previous()">
                {{ __('Go back') }}
            </flux:button>
        @endguest

        <flux:text class="text-center">
            {{ __('Still locked out?') }}
            <flux:link :href="'mailto:'.config('mail.from.address')">{{ __('Contact the Research Office') }}</flux:link>
        </flux:text>
    </div>
</x-layouts::auth>
