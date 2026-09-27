<x-layouts::app :title="__('Dashboard')">
    <section class="mx-auto w-full max-w-4xl">
        <header class="flex items-center gap-4 border-b border-line pb-6">
            <img src="{{ asset('images/isu_seal.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
            <div class="min-w-0">
                <flux:heading size="xl" level="1">{{ __('Dashboard') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Welcome, :name.', ['name' => auth()->user()->name]) }}</flux:text>
            </div>
        </header>
    </section>
</x-layouts::app>
