<div class="mx-auto w-full max-w-4xl">
    {{-- The tabs' own line runs under them, so the title needs none --}}
    <x-page-title :divider="false" :subtitle="$subheading ?? null">{{ $heading ?? '' }}</x-page-title>

    <div class="border-b border-line lg:mt-4">
        <flux:navbar class="settings-tabs -mb-px" scrollable aria-label="{{ __('My Profile') }}">
            <flux:navbar.item :href="route('profile.edit')" :current="request()->routeIs('profile.edit')" wire:navigate>{{ __('My Profile') }}</flux:navbar.item>
            <flux:navbar.item :href="route('security.edit')" :current="request()->routeIs('security.edit')" wire:navigate>{{ __('Security') }}</flux:navbar.item>
        </flux:navbar>
    </div>

    <div class="mt-8 space-y-6">
        {{ $slot }}
    </div>
</div>
