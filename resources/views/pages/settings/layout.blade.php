<div class="mx-auto w-full max-w-4xl">
    <header class="flex items-center gap-4">
        <img src="{{ asset('images/isu_seal-128.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
        <div class="min-w-0 flex-1">
            <flux:heading size="xl" level="1">{{ $heading ?? '' }}</flux:heading>
            <flux:text class="mt-1">{{ $subheading ?? '' }}</flux:text>
        </div>
    </header>

    <div class="mt-4 border-b border-line">
        <flux:navbar class="settings-tabs -mb-px" scrollable aria-label="{{ __('My Profile') }}">
            <flux:navbar.item :href="route('profile.edit')" :current="request()->routeIs('profile.edit')" wire:navigate>{{ __('My Profile') }}</flux:navbar.item>
            <flux:navbar.item :href="route('security.edit')" :current="request()->routeIs('security.edit')" wire:navigate>{{ __('Security') }}</flux:navbar.item>
        </flux:navbar>
    </div>

    <div class="mt-8 space-y-6">
        {{ $slot }}
    </div>
</div>
