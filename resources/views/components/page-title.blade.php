@props(['subtitle', 'divider' => true, 'actions' => null])

{{-- The page's name beside the seal on wide screens, with a line on what the page holds under it and the page's own
     controls at the row's end. Phones and tablets keep the name and line for screen readers only, since their top bar
     already carries the seal, and show just the controls; app.css drops the next element's top margin there, so the
     content starts at the top. --}}
@php($hasActions = $actions?->hasActualContent())

<header data-page-title {{ $attributes->class(['flex flex-wrap items-center gap-3', 'lg:border-b lg:border-line lg:pb-4' => $divider, 'max-lg:mb-4' => $hasActions]) }}>
    {{-- The seal stands as tall as the text beside it and centred on it: the 32px name, plus the 4px gap and 20px line
         under it --}}
    <div class="flex min-w-0 items-center gap-4 max-lg:sr-only">
        <img src="{{ asset('images/isu_seal-128.png') }}" alt="" class="size-14 shrink-0 object-contain" />
        <div class="min-w-0">
            <flux:heading size="xl" level="1">{{ $slot }}</flux:heading>
            <flux:text class="mt-1">{{ $subtitle }}</flux:text>
        </div>
    </div>

    @if ($hasActions)
        <div {{ $actions->attributes->class('ms-auto flex flex-wrap items-center gap-2') }}>
            {{ $actions }}
        </div>
    @endif
</header>
