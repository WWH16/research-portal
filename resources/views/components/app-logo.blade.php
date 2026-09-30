@props([
    'sidebar' => false,
])

@if($sidebar)
    {{-- The sidebar spells out the university under the portal name instead of the bare "ISU". --}}
    <flux:sidebar.brand {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center">
            <x-app-logo-icon class="size-8" />
        </x-slot>

        <x-slot name="name">
            <span class="block truncate">{{ __('Research Portal') }}</span>
            <span class="block truncate text-xs font-normal text-isu-green-200">{{ __('Isabela State University') }}</span>
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center">
            <x-app-logo-icon class="size-8" />
        </x-slot>
    </flux:brand>
@endif
