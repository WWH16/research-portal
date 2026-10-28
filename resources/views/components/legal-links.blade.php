{{-- The two public policy pages: fine print, one step below the page's smallest secondary line. Vertical padding gives each
     link a comfortable tap target without making the row look any bigger. --}}
<nav {{ $attributes->class('flex flex-wrap items-center gap-x-2 text-xs text-zinc-600') }} aria-label="{{ __('Policies') }}">
    <flux:link :href="route('privacy')" class="py-2 text-zinc-600">{{ __('Privacy Policy') }}</flux:link>
    <span aria-hidden="true">·</span>
    <flux:link :href="route('terms')" class="py-2 text-zinc-600">{{ __('Terms of Service') }}</flux:link>
</nav>
