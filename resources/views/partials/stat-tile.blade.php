{{--
    A dashboard stat tile: label, value, a short note, and optionally a status swatch. The whole
    tile opens the list it counts. Label, value and note sit on the parent grid's rows (subgrid),
    so values and notes line up across a row even when a label wraps. Tiles are cells of one strip:
    the parent draws the border and a 1px gap over a line-coloured fill, which shows as the hairlines.
    Expects: $label, $value, $note, $href; optional $swatch, a background class or a list of them for a tile that
    counts several stages. With $alert true, the tile is a problem to act on: a pale red fill and a red number. With $group, a click opens that group in the dashboard's faculty-list panel instead of
    loading the page again; $href stays for opening it in a new tab. An included view sees the dashboard's own empty
    $group property too, so only a filled $group counts.
--}}
<a
    href="{{ $href }}"
    @if (filled($group ?? null))
        wire:click.prevent="$set('group', '{{ $group }}')"
        wire:island="faculty-list"
        aria-haspopup="dialog"
        x-bind:aria-current="$wire.group === '{{ $group }}' ? 'true' : null"
    @else
        wire:navigate
    @endif
    class="group row-span-3 grid grid-rows-subgrid gap-y-0 {{ ($alert ?? false) ? 'bg-red-50 hover:bg-red-100' : 'bg-surface hover:bg-zinc-50' }} p-4 transition focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-accent sm:p-5 aria-[current=true]:bg-isu-green-50 aria-[current=true]:shadow-[inset_0_0_0_2px_var(--color-accent)] data-loading:cursor-progress data-loading:opacity-70"
>
    <span class="flex items-center gap-2 self-start text-sm text-zinc-600">
        @isset($swatch)
            <span class="flex shrink-0 gap-0.5" aria-hidden="true">
                @foreach ((array) $swatch as $class)
                    <span class="h-2.5 w-3 rounded-sm {{ $class }}"></span>
                @endforeach
            </span>
        @endisset
        {{ $label }}
    </span>

    <span class="mt-2 text-3xl font-semibold tabular-nums {{ ($alert ?? false) ? 'text-red-700' : 'text-zinc-900' }}">{{ number_format($value) }}</span>

    {{-- zinc-600, so the note keeps 4.5:1 on the Delayed tile's red tint too --}}
    <span class="mt-1 text-sm text-zinc-600 group-hover:text-zinc-700">{{ $note }}</span>
</a>
