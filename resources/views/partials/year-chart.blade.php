{{--
    Columns of citing papers per year, with hover and focus tooltips and a table view: the single-series
    sibling of monthly-chart. Expects: $years (year => count, in order, from Citation::perYear()) and $since,
    the first year asked for, so the chart can say when older years were cut off. $heading is optional: leave
    it out when the section around the chart already names it. $level sets the heading level; it defaults to
    2, so pass 3 when the chart sits under another h2. Pass $compact for an unboxed short chart, only as wide
    as its years need, with a baseline and no scale.
--}}
@php
    $cited = array_sum($years);
    $peak = max(1, max($years ?: [0]));
    // One unit of headroom over the peak, at least 5, then the smallest step of 1, 2 or 5 times a power of ten
    // that covers it in 5 steps. So low counts tick at every whole number (0 to 5 for a peak of 4) and high ones
    // at round numbers (0 to 150 by 50 for a peak of 130), and the tallest bar never touches the top.
    $top = max($peak + 1, 5);
    $magnitude = 10 ** floor(log10(ceil($top / 5)));
    $step = collect([1, 2, 5, 10])->map(fn ($factor) => $factor * $magnitude)->first(fn ($value) => $value * 5 >= $top);
    $axisMax = (int) (ceil($top / $step) * $step);
    $ticks = range($axisMax, 0, $step);
    $columns = count($years);
    $first = array_key_first($years);
    $compact ??= false;
    $plot = $compact ? '' : 'h-48';
    // With no scale to read, bar height alone shows size: 1rem per citation up to $floor citations, after which
    // the tallest bar stays at $floor rem and the rest scale to it. So a single citation draws a short bar. The plot
    // ends at the tallest bar, with no empty band above it, so that bar's count always sits the same distance
    // below the row above. Bars are sized in rem, not percent, so the hover area can reach above the plot.
    $floor = 5;
    $remPerCitation = $floor / max($peak, $floor);
    $plotHeight = $compact ? 'height: '.round($peak * $remPerCitation, 3).'rem' : null;
    $barHeight = fn (int $count) => $compact ? round($count * $remPerCitation, 3).'rem' : ($count / $axisMax * 100).'%';
    // About 3.5rem per year, never narrower than 12rem or wider than the row, so two or three years don't sit
    // as slivers on a wide empty axis.
    $width = $compact ? 'width: min(100%, max(12rem, '.(3.5 * $columns).'rem))' : null;
@endphp

{{-- A container, so the year labels thin out by the card's own width: it sits in a narrow side column on some pages.
     Compact drops the card, the scale and the gridlines: the baseline alone carries the bars. --}}
<flux:card class="@container {{ $compact ? 'border-0! p-0 bg-transparent' : '' }}" :style="$width" data-test="year-chart">
    @isset ($heading)
        <flux:heading :level="$level ?? 2">{{ $heading }}</flux:heading>
    @endisset
    {{-- Compact keeps only the cut-off note: its year labels already show the range --}}
    @if ($since < $first || ! $compact)
        {{-- In compact, the gap under the note matches the gap between the page's rows --}}
        <flux:text @class(['mt-1' => isset($heading), 'mb-3' => $compact]) data-test="chart-note">
            {{ $since < $first
                ? __('Showing :from to :to. Earlier citations count toward the total.', ['from' => $first, 'to' => now()->year])
                : __('By year cited, :from to :to.', ['from' => $first, 'to' => now()->year]) }}
        </flux:text>
    @endif

    @if ($cited === 0)
        {{-- Older citations can still count toward the total, so a cut-off chart says which years are empty --}}
        <flux:text class="mt-6">{{ $since < $first ? __('No citations from :from to :to.', ['from' => $first, 'to' => now()->year]) : __('No citations yet.') }}</flux:text>
    @else
        <div class="{{ $compact ? '' : 'mt-6' }} flex gap-3">
            @unless ($compact)
                <div class="flex {{ $plot }} flex-col justify-between text-end text-xs tabular-nums text-zinc-500" aria-hidden="true">
                    @foreach ($ticks as $tick)
                        <span class="-my-2">{{ $tick }}</span>
                    @endforeach
                </div>
            @endunless

            <div class="min-w-0 flex-1">
                <div class="relative {{ $plot }}" @if ($plotHeight) style="{{ $plotHeight }}" @endif>
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
                                        class="relative w-3.5 rounded-t-sm bg-isu-green-400 transition-colors group-hover:bg-isu-green-600 pointer-coarse:group-focus:bg-isu-green-600"
                                        style="height: {{ $barHeight($count) }}"
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
                {{-- Compact has no scale, so its years are the bars' only context and read at the size of the details above --}}
                <ol class="mt-2 grid gap-1 text-center tabular-nums {{ $compact ? 'text-sm text-zinc-900' : 'text-xs text-zinc-500' }}" style="grid-template-columns: repeat({{ $columns }}, minmax(0, 1fr))" aria-hidden="true">
                    @foreach ($years as $year => $count)
                        <li class="{{ $columns > 8 && ($columns - 1 - $loop->index) % 2 ? '@max-md:invisible' : '' }}">{{ $year }}</li>
                    @endforeach
                </ol>
            </div>
        </div>

        {{-- The same numbers without hovering, for screen readers and anyone who wants exact values --}}
        <div x-data="{ open: false }" class="mt-4">
            <flux:button size="sm" icon="table-cells" class="max-sm:h-11" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-expanded="false">
                <span x-text="open ? @js(__('Hide table')) : @js(__('View as table'))">{{ __('View as table') }}</span>
            </flux:button>
            <div x-show="open" x-cloak @unless (isset($heading)) role="group" aria-label="{{ __('Citations per year') }}" @endunless>
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
            </div>
        </div>
    @endif
</flux:card>
