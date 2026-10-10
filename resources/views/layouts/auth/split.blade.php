<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-surface antialiased">
        <div class="grid min-h-dvh lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
            {{-- Brand panel: the same seal green as the app sidebar, so signing in and working inside feel like one place. --}}
            {{-- Everything in the panel shares one centered axis: brand, seal, and footer. --}}
            <aside class="hidden flex-col items-center justify-between gap-10 bg-isu-green-800 px-12 py-10 text-center text-isu-green-50 lg:flex">
                <div class="flex items-center gap-3">
                    <x-app-logo-icon class="size-9 rounded-full bg-white p-px" />
                    <span class="text-base font-semibold text-white">{{ config('app.name') }}</span>
                </div>

                {{-- The seal, faded into the green. It shows up to 256px wide here, so this is the full-size file;
                     every smaller spot uses isu_seal-128.png. Lazy, so phones, where this panel is hidden, never fetch it.
                     Hovering it brings back the seal's full colour. --}}
                <img
                    src="{{ asset('images/isu_seal.png') }}"
                    alt=""
                    loading="lazy"
                    class="size-[min(16rem,30vh)] object-contain opacity-20 mix-blend-luminosity transition-opacity duration-300 ease-out select-none hover:opacity-100 hover:mix-blend-normal motion-reduce:transition-none"
                />

                <p class="text-sm text-isu-green-300">
                    &copy; {{ now()->year }} {{ __('Isabela State University - Cauayan Campus') }}
                </p>
            </aside>

            <main class="flex items-center justify-center px-6 py-12 sm:px-10">
                <div class="flex w-full max-w-sm flex-col gap-8">
                    <div class="flex items-center gap-3 lg:hidden">
                        <x-app-logo-icon class="size-10" />
                        {{-- Phones hide the brand panel and its footer, so the campus is named here instead. --}}
                        <div class="leading-tight">
                            <span class="block text-base font-semibold text-isu-green-800">{{ config('app.name') }}</span>
                            <span class="block text-xs text-zinc-600">{{ __('ISU - Cauayan') }}</span>
                        </div>
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
