{{--
    A dashboard stat tile: label, value, a short note, and optionally a status swatch. The whole
    tile opens the list it counts. Label, value and note sit on the parent grid's rows (subgrid),
    so values and notes line up across a row even when a label wraps.
    Expects: $label, $value, $note, $href; optional $swatch (background class).
--}}
<a href="{{ $href }}" wire:navigate class="group row-span-3 grid grid-rows-subgrid gap-y-0 rounded-xl border border-line bg-surface p-5 transition hover:border-zinc-300 hover:shadow-sm hover:shadow-zinc-900/5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
    <span class="flex items-center gap-2 self-start text-sm text-zinc-600">
        @isset($swatch)
            <span class="h-2.5 w-3 shrink-0 rounded-sm {{ $swatch }}" aria-hidden="true"></span>
        @endisset
        {{ $label }}
    </span>

    <span class="mt-2 text-2xl font-semibold text-zinc-900">{{ number_format($value) }}</span>

    <span class="mt-1 text-sm text-zinc-500 group-hover:text-zinc-700">{{ $note }}</span>
</a>
