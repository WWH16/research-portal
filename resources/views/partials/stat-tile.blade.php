{{--
    A dashboard stat tile: label, value, a short note, and optionally a status swatch or a
    12-month sparkline. The whole tile opens the list it counts. Label, value and note sit on the
    parent grid's rows (subgrid), so values and notes line up across a row even when a label wraps.
    Expects: $label, $value, $note, $href; optional $swatch (background class), $spark (list of ints).
--}}
<a href="{{ $href }}" wire:navigate class="group row-span-3 grid grid-rows-subgrid gap-y-0 rounded-xl border border-line bg-surface p-5 transition hover:border-zinc-300 hover:shadow-sm hover:shadow-zinc-900/5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
    <span class="flex items-center gap-2 self-start text-sm text-zinc-600">
        @isset($swatch)
            <span class="h-2.5 w-3 shrink-0 rounded-sm {{ $swatch }}" aria-hidden="true"></span>
        @endisset
        {{ $label }}
    </span>

    <span class="mt-2 flex items-end justify-between gap-4">
        <span class="text-3xl font-semibold text-zinc-900">{{ number_format($value) }}</span>

        @isset($spark)
            @php
                $peak = max(1, max($spark));
                $points = collect($spark)->map(fn ($count, $i) => round($i / (count($spark) - 1) * 96 + 2, 1).','.round(30 - $count / $peak * 26, 1));
                [$lastX, $lastY] = explode(',', $points->last());
            @endphp
            {{-- Trend of the last 12 months: the line in a quiet grey, this month marked in the accent --}}
            <svg viewBox="0 0 100 32" class="h-8 w-24 shrink-0 overflow-visible" aria-hidden="true">
                <polyline points="{{ $points->join(' ') }}" fill="none" stroke="var(--color-zinc-400)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                <circle cx="{{ $lastX }}" cy="{{ $lastY }}" r="3" fill="var(--color-isu-green-700)" stroke="var(--color-surface)" stroke-width="2" />
            </svg>
        @endisset
    </span>

    <span class="mt-1 text-sm text-zinc-500 group-hover:text-zinc-700">{{ $note }}</span>
</a>
