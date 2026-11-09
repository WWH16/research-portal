<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => __('Temporarily unavailable')])
    </head>
    <body class="min-h-screen bg-surface antialiased">
        <main class="flex min-h-svh items-center justify-center p-6 md:p-10">
            <div class="flex w-full max-w-md flex-col items-center text-center">
                <img src="{{ asset('images/isu_seal-128.png') }}" alt="{{ __('Isabela State University') }}" class="size-16 object-contain" />

                <flux:heading size="xl" level="1" class="mt-8 text-balance">{{ __('This isn’t available right now') }}</flux:heading>

                {{-- Says what is down, such as document storage, so the member knows it isn't their account --}}
                <flux:text class="mt-3 text-balance">
                    {{ $exception->getMessage() ?: __('The portal is busy for a moment. Try again shortly.') }}
                </flux:text>

                <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                    <flux:button :href="auth()->check() ? route('dashboard') : route('home')" variant="primary" icon="arrow-left">
                        {{ auth()->check() ? __('Return to dashboard') : __('Return to home') }}
                    </flux:button>
                </div>
            </div>
        </main>
    </body>
</html>
