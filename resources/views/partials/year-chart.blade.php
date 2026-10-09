{{--
    Columns of citing papers per year, with hover and focus tooltips and a table view: the single-series
    sibling of monthly-chart. Expects: $years (year => count, in order, from Citation::perYear()), $heading,
    and $since, the first year asked for, so the chart can say when older years were cut off.
--}}
@php
    $cited = array_sum($years);
    $peak = max(1, max($years ?: [0]));
    // Round the axis up to a clean number (1, 2, 5, 10, 20, 50...) so the ticks read easily.
    $magnitude = 10 ** floor(log10($peak));
    $axisMax = collect([1, 2, 5, 10])->map(fn ($step) => $step * $magnitude)->first(fn ($value) => $value >= $peak);
    $ticks = $axisMax >= 2 ? [$axisMax, $axisMax / 2, 0] : [$axisMax, 0];
    $columns = count($years);
    $first = array_key_first($years);
@endphp

{{-- A container, so the year labels thin out by the card's own width: it sits in a narrow side column on some pages --}}
<flux:card class="@container" data-test="year-chart">
    <flux:heading level="2">{{ $heading }}</flux:heading>
    <flux:text class="mt-1">
        {{ $since < $first
            ? __('Showing :from to :to. Earlier citations count toward the total.', ['from' => $first, 'to' => now()->year])
            : __('By year cited, :from to :to.', ['from' => $first, 'to' => now()->year]) }}
    </flux:text>

    @if ($cited === 0)
        {{-- Older citations can still count toward the total, so a cut-off chart says which years are empty --}}
        <flux:text class="mt-6">{{ $since < $first ? __('No citations from :from to :to.', ['from' => $first, 'to' => now()->year]) : __('No citations yet.') }}</flux:text>
    @else
        <div class="mt-6 flex gap-3">
            <div class="flex h-48 flex-col justify-between text-end text-xs tabular-nums text-zinc-500" aria-hidden="true">
                @foreach ($ticks as $tick)
                    <span class="-my-2">{{ $tick }}</span>
                @endforeach
            </div>

            <div class="min-w-0 flex-1">
                <div class="relative h-48">
                    @foreach ($ticks as $tick)
                        <div class="absolute inset-x-0 border-t border-line" style="bottom: {{ $tick / $axisMax * 100 }}%" aria-hidden="true"></div>
                    @endforeach

                    <ol class="relative grid h-full gap-1" style="grid-template-columns: repeat({{ $columns }}, minmax(0, 1fr))">
                        @foreach ($years as $year => $count)
                            @php($tooltipSide = match (true) { $loop->index < 2 => 'left-0', $loop->index > $columns - 3 => 'right-0', default => 'left-1/2 -translate-x-1/2' })
                            {{-- The whole column is the hover and tap target. Out of the tab order: keyboard users get the table below --}}
                            <li
                                class="group relative flex h-full items-end justify-center outline-none"
                                tabindex="-1"
                                aria-label="{{ trans_choice('{0} :year: no citations|{1} :year: 1 citation|[2,*] :year: :count citations', $count, ['year' => $year]) }}"
                            >
                                @if ($count > 0)
                                    <div
                                        class="w-2.5 rounded-t-sm bg-isu-green-600 transition group-hover:brightness-110"
                                        style="height: {{ $count / $axisMax * 100 }}%"
                                        aria-hidden="true"
                                    ></div>
                                @endif

                                <div class="pointer-events-none absolute bottom-full z-10 mb-2 hidden w-max rounded-lg border border-line bg-surface px-3 py-2 text-xs shadow-lg shadow-zinc-900/10 group-hover:block pointer-coarse:group-focus:block {{ $tooltipSide }}" aria-hidden="true">
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
        <details class="mt-4 text-sm">
            <summary class="cursor-pointer text-zinc-600 hover:text-zinc-900">{{ __('Show as table') }}</summary>
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
