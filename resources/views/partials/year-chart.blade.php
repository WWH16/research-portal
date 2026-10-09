{{--
    Columns of citing papers per year, with hover and focus tooltips and a table view: the single-series
    sibling of monthly-chart. Expects: $years (year => count, in order, from Citation::perYear()), $heading,
    and $since, the first year asked for, so the chart can say when older years were cut off. $level sets
    the heading level; it defaults to 2, so pass 3 when the chart sits under another h2. Pass $compact for an
    unboxed short chart, only as wide as its years need, with a baseline and no scale.
--}}
@php
    $cited = array_sum($years);
    $peak = max(1, max($years ?: [0]));
    // Round the axis up to a clean number (1, 2, 4, 6, 8, 10, 20, 40...) whose half is whole, so no tick reads 2.5.
    $magnitude = 10 ** floor(log10($peak));
    $axisMax = collect([1, 2, 4, 6, 8, 10])->map(fn ($step) => $step * $magnitude)->first(fn ($value) => $value >= $peak);
    $ticks = $axisMax >= 2 ? [$axisMax, $axisMax / 2, 0] : [$axisMax, 0];
    $columns = count($years);
    $first = array_key_first($years);
    $compact ??= false;
    $plot = $compact ? 'h-32' : 'h-48';
    // With no scale to read, the tallest bar reaches the top instead of stopping short under empty space.
    $axisMax = $compact ? $peak : $axisMax;
    // About 2.75rem per year, never narrower than the heading or wider than the row.
    $width = $compact ? 'width: min(100%, max(12rem, '.(2.75 * $columns).'rem))' : null;
@endphp

{{-- A container, so the year labels thin out by the card's own width: it sits in a narrow side column on some pages.
     Compact drops the card, the scale and the gridlines: the baseline alone carries the bars. --}}
<flux:card class="@container {{ $compact ? 'border-0! p-0 bg-transparent' : '' }}" :style="$width" data-test="year-chart">
    <flux:heading :level="$level ?? 2">{{ $heading }}</flux:heading>
    {{-- Compact keeps only the cut-off note: its year labels already show the range --}}
    @if ($since < $first || ! $compact)
        <flux:text class="mt-1">
            {{ $since < $first
                ? __('Showing :from to :to. Earlier citations count toward the total.', ['from' => $first, 'to' => now()->year])
                : __('By year cited, :from to :to.', ['from' => $first, 'to' => now()->year]) }}
        </flux:text>
    @endif

    @if ($cited === 0)
        {{-- Older citations can still count toward the total, so a cut-off chart says which years are empty --}}
        <flux:text class="mt-6">{{ $since < $first ? __('No citations from :from to :to.', ['from' => $first, 'to' => now()->year]) : __('No citations yet.') }}</flux:text>
    @else
        <div class="{{ $compact ? 'mt-2' : 'mt-6' }} flex gap-3">
            @unless ($compact)
                <div class="flex {{ $plot }} flex-col justify-between text-end text-xs tabular-nums text-zinc-500" aria-hidden="true">
                    @foreach ($ticks as $tick)
                        <span class="-my-2">{{ $tick }}</span>
                    @endforeach
                </div>
            @endunless

            <div class="min-w-0 flex-1">
                <div class="relative {{ $plot }}">
                    @foreach ($compact ? [0] : $ticks as $tick)
                        <div class="absolute inset-x-0 border-t {{ $tick ? 'border-line' : 'border-zinc-300' }}" style="bottom: {{ $tick / $axisMax * 100 }}%" aria-hidden="true"></div>
                    @endforeach

                    {{-- Hidden from screen readers: the table below carries the same numbers once.
                         The pointer's spot is kept in --x and --y, so the tooltip rides beside it instead of on a tall bar's top. --}}
                    <ol
                        class="relative grid h-full gap-1"
                        style="grid-template-columns: repeat({{ $columns }}, minmax(0, 1fr))"
                        aria-hidden="true"
                        x-data="{ follow(e) { const r = e.currentTarget.getBoundingClientRect(); e.currentTarget.style.setProperty('--x', e.clientX - r.left + 'px'); e.currentTarget.style.setProperty('--y', e.clientY - r.top + 'px') } }"
                        x-on:pointermove="follow($event)"
                        x-on:pointerdown="follow($event)"
                    >
                        @foreach ($years as $year => $count)
                            {{-- The whole column is the hover and tap target. Out of the tab order: keyboard users get the table below --}}
                            <li class="group flex h-full items-end justify-center outline-none" tabindex="-1">
                                @if ($count > 0)
                                    <div
                                        class="w-2.5 rounded-t-sm bg-isu-green-400 transition-colors group-hover:bg-isu-green-600 pointer-coarse:group-focus:bg-isu-green-600"
                                        style="height: {{ $count / $axisMax * 100 }}%"
                                    ></div>
                                @endif

                                {{-- Beside the pointer, on the side facing the middle so it never leaves the card --}}
                                <div class="pointer-events-none absolute top-(--y) left-(--x) z-10 hidden w-max -translate-y-1/2 rounded-lg border border-line bg-surface px-3 py-2 text-xs group-hover:block pointer-coarse:group-focus:block {{ $loop->index < $columns / 2 ? 'ml-4' : '-ml-4 -translate-x-full' }}">
                                    <p class="text-zinc-500">{{ $year }}</p>
                                    <p class="font-semibold text-zinc-900">{{ trans_choice('{0} No citations|{1} 1 citation|[2,*] :count citations', $count) }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>

                {{-- Every other year hides in a narrow card once there are more than 8, so the labels never collide --}}
                <ol class="mt-2 grid gap-1 text-center text-xs tabular-nums text-zinc-500" style="grid-template-columns: repeat({{ $columns }}, minmax(0, 1fr))" aria-hidden="true">
                    @foreach ($years as $year => $count)
                        <li class="{{ $columns > 8 && ($columns - 1 - $loop->index) % 2 ? '@max-md:invisible' : '' }}">{{ $year }}</li>
                    @endforeach
                </ol>
            </div>
        </div>

        {{-- The same numbers without hovering, for screen readers and anyone who wants exact values --}}
        <details class="mt-2 text-sm">
            <summary class="cursor-pointer py-2 text-zinc-600 hover:text-zinc-900">{{ __('Show as table') }}</summary>
            <flux:table class="mt-3 tabular-nums">
                <flux:table.columns>
                    <flux:table.column>{{ __('Year') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Citations') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach (array_reverse($years, true) as $year => $count)
                        <flux:table.row>
                            <flux:table.cell>{{ $year }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $count }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </details>
    @endif
</flux:card>
