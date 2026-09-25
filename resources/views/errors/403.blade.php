<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => __('Access denied')])
    </head>
    <body class="min-h-screen bg-surface antialiased">
        <main class="flex min-h-svh items-center justify-center p-6 md:p-10">
            <div class="flex w-full max-w-md flex-col items-center text-center">
                <img src="{{ asset('images/isu_seal.png') }}" alt="{{ __('Isabela State University') }}" class="size-16 object-contain" />

                <flux:heading size="xl" level="1" class="mt-8 text-balance">{{ __('You don’t have access to this page') }}</flux:heading>

                <flux:text class="mt-3 text-balance">
                    {{ $exception->getMessage() ?: __('Your account doesn’t have permission to view it.') }}
                    {{ __('If you think you should have access, contact the Research Office.') }}
                </flux:text>

                <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                    <flux:button :href="auth()->check() ? route('dashboard') : route('home')" variant="primary" icon="arrow-left">
                        {{ auth()->check() ? __('Back to dashboard') : __('Back to home') }}
                    </flux:button>
                </div>
            </div>
        </main>
    </body>
</html>
