{{--
    A dashboard stat tile: label, value, a short note, and optionally a status swatch. The whole
    tile opens the list it counts. Label, value and note sit on the parent grid's rows (subgrid),
    so values and notes line up across a row even when a label wraps.
    Expects: $label, $value, $note, $href; optional $swatch, a background class or a list of them for a tile that
    counts several stages. With $group, a click opens that group in the dashboard's faculty-list island instead of
    loading the page again; $href stays for opening it in a new tab.
--}}
<a
    href="{{ $href }}"
    @isset($group)
        wire:click.prevent="$set('group', '{{ $group }}')"
        wire:island="faculty-list"
        x-bind:aria-current="$wire.group === '{{ $group }}' ? 'true' : null"
    @else
        wire:navigate
    @endisset
    class="group row-span-3 grid grid-rows-subgrid gap-y-0 rounded-xl border border-line bg-surface p-5 transition hover:border-zinc-300 hover:shadow-sm hover:shadow-zinc-900/5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent aria-[current=true]:border-accent aria-[current=true]:shadow-[inset_0_0_0_1px_var(--color-accent)] data-loading:cursor-progress data-loading:opacity-70"
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

    <span class="mt-2 text-2xl font-semibold text-zinc-900">{{ number_format($value) }}</span>

    <span class="mt-1 text-sm text-zinc-500 group-hover:text-zinc-700">{{ $note }}</span>
</a>
