{{--
    Stacked columns of projects filed per month, split by current status, with hover and focus
    tooltips and a table view. Expects: $monthly (one entry per month, up to 24), $bars, $heading,
    and $period, how the months are described ("2026", "the last 12 months").
--}}
@php
    $peak = max(1, max(array_column($monthly, 'total')));
    // Round the axis up to a clean number (1, 2, 4, 6, 8, 10, 20, 40...) whose half is whole, so no tick reads 2.5.
    $magnitude = 10 ** floor(log10($peak));
    $axisMax = collect([1, 2, 4, 6, 8, 10])->map(fn ($step) => $step * $magnitude)->first(fn ($value) => $value >= $peak);
    $ticks = $axisMax >= 2 ? [$axisMax, $axisMax / 2, 0] : [$axisMax, 0];
    $filed = array_sum(array_column($monthly, 'total'));
    $columns = count($monthly);
    // Label every month up to 12 columns; past that, every other one, so the labels never collide.
    $labelEvery = (int) ceil($columns / 12);
@endphp

<flux:card class="lg:col-span-12" data-test="monthly-chart">
    <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-3">
        <div>
            <flux:heading level="2">{{ $heading }}</flux:heading>
            <flux:text class="mt-1">{{ __(':period, by current status.', ['period' => ucfirst($period)]) }}</flux:text>
        </div>

        <ul class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-zinc-600">
            @foreach ($bars as $status => $class)
                <li class="flex items-center gap-2">
                    <span class="h-2.5 w-3 rounded-sm {{ $class }}" aria-hidden="true"></span>{{ __($status) }}
                </li>
            @endforeach
        </ul>
    </div>

    @if ($filed === 0)
        <flux:text class="mt-6">{{ __('No projects in :period.', ['period' => $period]) }}</flux:text>
    @else
        <div class="mt-6 flex gap-3">
            <div class="flex h-56 flex-col justify-between text-end text-xs tabular-nums text-zinc-500" aria-hidden="true">
                @foreach ($ticks as $tick)
                    <span class="-my-2">{{ $tick }}</span>
                @endforeach
            </div>

            <div class="min-w-0 flex-1">
                <div class="relative h-56">
                    @foreach ($ticks as $tick)
                        <div class="absolute inset-x-0 border-t border-line" style="bottom: {{ $tick / $axisMax * 100 }}%" aria-hidden="true"></div>
                    @endforeach

                    <ol class="relative grid h-full gap-1" style="grid-template-columns: repeat({{ $columns }}, minmax(0, 1fr))">
                        @foreach ($monthly as $index => $month)
                            @php
                                $label = $month['month']->format('F Y');
                                $summary = collect($month['counts'])->map(fn ($count, $status) => $count.' '.__($status))->join(', ');
                                $tooltipSide = match (true) { $index < 2 => 'left-0', $index > $columns - 3 => 'right-0', default => 'left-1/2 -translate-x-1/2' };
                            @endphp
                            {{-- The whole column is the hover and focus target, not just the painted bar --}}
                            <li
                                class="group relative flex h-full items-end justify-center rounded outline-none focus-visible:bg-zinc-100"
                                tabindex="0"
                                aria-label="{{ trans_choice('{0} :month: no projects|{1} :month: one project, :summary|[2,*] :month: :count projects, :summary', $month['total'], ['month' => $label, 'summary' => $summary]) }}"
                            >
                                @if ($month['total'] > 0)
                                    <div
                                        class="flex w-full max-w-6 flex-col-reverse gap-0.5 overflow-hidden rounded-t transition group-hover:brightness-110 group-focus-visible:ring-2 group-focus-visible:ring-accent group-focus-visible:ring-offset-2"
                                        style="height: {{ $month['total'] / $axisMax * 100 }}%"
                                        aria-hidden="true"
                                    >
                                        @foreach ($month['counts'] as $status => $count)
                                            @if ($count > 0)
                                                <div class="{{ $bars[$status] }}" style="flex-grow: {{ $count }}"></div>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif

                                <div class="pointer-events-none absolute bottom-full z-10 mb-2 hidden w-max rounded-lg border border-line bg-surface px-3 py-2 text-xs shadow-lg shadow-zinc-900/10 group-hover:block group-focus:block {{ $tooltipSide }}" aria-hidden="true">
                                    <p class="text-zinc-500">{{ $label }}</p>
                                    <p class="font-semibold text-zinc-900">{{ trans_choice('{0} No projects|{1} One project|[2,*] :count projects', $month['total']) }}</p>
                                    @if ($month['total'] > 0)
                                        <ul class="mt-1.5 grid gap-1">
                                            @foreach ($month['counts'] as $status => $count)
                                                <li class="flex items-center gap-2">
                                                    <span class="h-0.5 w-3 rounded-full {{ $bars[$status] }}"></span>
                                                    <span class="font-semibold tabular-nums text-zinc-900">{{ $count }}</span>
                                                    <span class="text-zinc-500">{{ __($status) }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>

                {{-- Month labels; every other one hides on phones so they never collide --}}
                <ol class="mt-2 grid gap-1 text-center text-xs text-zinc-500" style="grid-template-columns: repeat({{ $columns }}, minmax(0, 1fr))" aria-hidden="true">
                    @php($yearShown = null)
                    @foreach ($monthly as $index => $month)
                        @php($labelled = $index % $labelEvery === 0)
                        <li class="{{ $labelled ? '' : 'invisible' }} {{ ($index / $labelEvery) % 2 ? 'max-sm:invisible' : '' }}">
                            {{ $month['month']->format('M') }}
                            {{-- The year sits under the first labelled month of each year, so a year change is always marked --}}
                            @if ($labelled && $month['month']->year !== $yearShown)
                                <span class="block text-zinc-500">{{ $month['month']->format('Y') }}</span>
                                @php($yearShown = $month['month']->year)
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>

        {{-- The same numbers without hovering, for screen readers and anyone who wants exact values --}}
        <details class="group mt-4 text-sm">
            <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 h-8 rounded-lg border border-line px-3 font-medium text-zinc-700 hover:bg-canvas hover:text-zinc-900 focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 max-sm:h-11 [&::-webkit-details-marker]:hidden">
                {{-- Turns down when open, like the browser's disclosure triangle, not a select's chevron --}}
                <flux:icon.chevron-right variant="micro" class="text-zinc-500 motion-safe:transition-transform group-open:rotate-90" />
                <span class="group-open:hidden">{{ __('View as table') }}</span><span class="hidden group-open:inline">{{ __('Hide table') }}</span>
            </summary>
            <flux:table class="mt-3 tabular-nums">
                <flux:table.columns>
                    <flux:table.column>{{ __('Month') }}</flux:table.column>
                    @foreach (\App\Models\Submission::STATUSES as $status)
                        <flux:table.column align="end">{{ __($status) }}</flux:table.column>
                    @endforeach
                    <flux:table.column align="end">{{ __('Total') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($monthly as $month)
                        <flux:table.row>
                            <flux:table.cell>{{ $month['month']->format('M Y') }}</flux:table.cell>
                            @foreach ($month['counts'] as $count)
                                <flux:table.cell align="end">{{ $count }}</flux:table.cell>
                            @endforeach
                            <flux:table.cell align="end" variant="strong">{{ $month['total'] }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </details>
    @endif
</flux:card>
