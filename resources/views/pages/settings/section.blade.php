@props(['heading', 'description' => null, 'footer' => null])

<section {{ $attributes->class('rounded-xl border border-line bg-surface') }}>
    <div class="p-5 sm:p-6">
        <flux:heading level="2">{{ $heading }}</flux:heading>
        @if ($description)
            <flux:text class="mt-1">{{ $description }}</flux:text>
        @endif

        <div class="mt-6">
            {{ $slot }}
        </div>
    </div>

    @if ($footer)
        <div class="flex items-center justify-end gap-3 rounded-b-xl border-t border-line bg-canvas px-5 py-3 sm:px-6">
            {{ $footer }}
        </div>
    @endif
</section>
