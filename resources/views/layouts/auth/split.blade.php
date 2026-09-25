<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-surface antialiased">
        <div class="grid min-h-dvh lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
            {{-- Brand panel: the same seal green as the app sidebar, so signing in and working inside feel like one place. --}}
            <aside class="relative hidden flex-col justify-between overflow-hidden bg-isu-green-800 p-12 text-isu-green-50 lg:flex">
                <div class="flex items-center gap-3">
                    <x-app-logo-icon class="size-10 rounded-full bg-white p-px" />
                    <span class="text-base font-semibold text-white">{{ config('app.name') }}</span>
                </div>

                <div class="relative z-10 max-w-md">
                    <p class="text-4xl/tight font-semibold tracking-[-0.02em] text-balance text-white">
                        {{ __('Research proposals, reviews, and records in one place.') }}
                    </p>
                </div>

                <p class="text-sm text-isu-green-300">
                    &copy; {{ now()->year }} {{ __('Isabela State University') }}
                </p>

                {{-- The seal, centered and faded into the green, anchors the panel without competing with the text. --}}
                <img
                    src="{{ asset('images/isu_logo.png') }}"
                    alt=""
                    class="pointer-events-none absolute inset-0 m-auto size-[min(80%,30rem)] max-w-none opacity-[0.07] mix-blend-luminosity select-none"
                />
            </aside>

            <main class="flex items-center justify-center px-6 py-12 sm:px-10">
                <div class="flex w-full max-w-sm flex-col gap-8">
                    <div class="flex items-center gap-3 lg:hidden">
                        <x-app-logo-icon class="size-10" />
                        <span class="text-base font-semibold text-isu-green-800">{{ config('app.name') }}</span>
                    </div>

                    {{ $slot }}
                </div>
            </main>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
