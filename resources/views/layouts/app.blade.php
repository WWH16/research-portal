<x-layouts::app.sidebar :title="$title ?? null">
    {{-- min-w-0 lets the main column shrink to the screen, so a wide table scrolls inside itself instead of pushing the whole page sideways --}}
    <flux:main class="min-w-0">
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
