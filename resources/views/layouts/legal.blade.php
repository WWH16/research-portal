@props(['title', 'updated', 'summary'])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => $title])
    </head>
    {{-- Public reading pages: one calm column at a reading measure, the portal's green for headings and links, no app chrome. --}}
    <body class="min-h-screen bg-surface text-zinc-800 antialiased selection:bg-isu-green-100 selection:text-isu-green-900">
        <header class="border-b border-line">
            <div class="mx-auto flex max-w-3xl items-center justify-between gap-4 px-6 py-5">
                <a href="{{ route('home') }}" class="flex items-center gap-3 rounded-md focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-isu-green-700">
                    <x-app-logo-icon class="size-9" />
                    <span class="leading-tight">
                        <span class="block text-base font-semibold text-isu-green-800">{{ config('app.name') }}</span>
                        <span class="block text-xs text-zinc-600">{{ __('ISU - Cauayan') }}</span>
                    </span>
                </a>

                <flux:link :href="auth()->check() ? route('dashboard') : route('login')" class="text-sm">
                    {{ auth()->check() ? __('Back to the portal') : __('Sign in') }}
                </flux:link>
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-6 pt-14 pb-20 sm:pt-20">
            <h1 class="text-4xl font-semibold tracking-tight text-balance text-isu-green-900 sm:text-5xl">{{ $title }}</h1>
            <p class="mt-4 text-sm text-zinc-600">
                {{ __('Last updated :date', ['date' => $updated]) }} · {{ __('Research Office, Isabela State University - Cauayan Campus') }}
            </p>

            <p class="mt-10 max-w-[62ch] text-lg leading-8 text-pretty text-zinc-700">{{ $summary }}</p>

            {{-- Section rhythm: more air above each heading than below it, body at about 68 characters a line. --}}
            <article class="mt-4 max-w-[68ch] text-base leading-7 text-zinc-700
                [&_a]:font-medium [&_a]:text-isu-green-700 [&_a]:underline [&_a]:decoration-isu-green-300 [&_a]:underline-offset-4 [&_a:hover]:decoration-isu-green-700
                [&_h2]:mt-14 [&_h2]:mb-3 [&_h2]:scroll-mt-8 [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:text-balance [&_h2]:text-zinc-900
                [&_li]:pl-1 [&_p+p]:mt-4 [&_p+ul]:mt-3 [&_strong]:font-semibold [&_strong]:text-zinc-900
                [&_ul]:list-disc [&_ul]:space-y-2 [&_ul]:pl-5 [&_ul]:marker:text-isu-green-600 [&_ul+p]:mt-4">
                {{ $slot }}
            </article>
        </main>

        <footer class="border-t border-line">
            <div class="mx-auto flex max-w-3xl flex-col gap-1 px-6 py-6 text-xs text-zinc-600 sm:flex-row sm:items-center sm:justify-between">
                <p>&copy; {{ now()->year }} {{ __('Isabela State University - Cauayan Campus') }}</p>
                <x-legal-links />
            </div>
        </footer>
    </body>
</html>
