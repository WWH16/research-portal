@props([
    'sidebar' => false,
])

@if($sidebar)
    {{-- The sidebar names the campus under the portal name. The portal name wraps instead of truncating, because
         it is wider than the mobile drawer's header, where the close button takes room. --}}
    <flux:sidebar.brand {{ $attributes->class('h-auto! min-h-10') }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center">
            <x-app-logo-icon class="size-8" />
        </x-slot>

        <x-slot name="name">
            <span class="block whitespace-normal">{{ __('Faculty Research Portal') }}</span>
            <span class="block truncate text-xs font-normal text-isu-green-200">{{ __('ISU - Cauayan') }}</span>
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center">
            <x-app-logo-icon class="size-8" />
        </x-slot>
    </flux:brand>
@endif
