<x-layouts::app.sidebar :title="$title ?? null">
    {{-- min-w-0 lets the main column shrink to the screen, so a wide table scrolls inside itself instead of pushing the whole page sideways --}}
    {{-- Flux draws the content area as a plain div; the role lets screen readers jump past the sidebar to it --}}
    <flux:main class="min-w-0" role="main">
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
