{{-- The two public policy pages, linked from the sign-in screens and from each other. --}}
<nav {{ $attributes->class('flex items-center gap-4') }} aria-label="{{ __('Policies') }}">
    <flux:link :href="route('privacy')" variant="subtle">{{ __('Privacy Policy') }}</flux:link>
    <flux:link :href="route('terms')" variant="subtle">{{ __('Terms of Service') }}</flux:link>
</nav>
