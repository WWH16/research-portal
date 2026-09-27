{{--
    Stacked columns of proposals filed per month, split by current status, with hover and focus
    tooltips and a table view. Expects: $monthly, $bars, $tile, $heading.
--}}
@php
    $peak = max(1, max(array_column($monthly, 'total')));
    // Round the axis up to a clean number (1, 2, 5, 10, 20, 50...) so the ticks read easily.
    $magnitude = 10 ** floor(log10($peak));
    $axisMax = collect([1, 2, 5, 10])->map(fn ($step) => $step * $magnitude)->first(fn ($value) => $value >= $peak);
    $ticks = $axisMax >= 2 ? [$axisMax, $axisMax / 2, 0] : [$axisMax, 0];
    $filedThisYear = array_sum(array_column($monthly, 'total'));
@endphp

<section class="{{ $tile }} lg:col-span-12" data-test="monthly-chart">
    <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-3">
        <div>
            <flux:heading level="2">{{ $heading }}</flux:heading>
            <flux:text class="mt-1">{{ __('Last 12 months, by current status.') }}</flux:text>
        </div>

        <ul class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-zinc-600">
            @foreach ($bars as $status => $class)
                <li class="flex items-center gap-2">
                    <span class="h-2.5 w-3 rounded-sm {{ $class }}" aria-hidden="true"></span>{{ __($status) }}
                </li>
            @endforeach
        </ul>
    </div>

    @if ($filedThisYear === 0)
        <flux:text class="mt-6">{{ __('No proposals in the last 12 months.') }}</flux:text>
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

                    <ol class="relative grid h-full grid-cols-12 gap-1">
                        @foreach ($monthly as $index => $month)
                            @php
                                $label = $month['month']->format('F Y');
                                $summary = collect($month['counts'])->map(fn ($count, $status) => $count.' '.__($status))->join(', ');
                                $tooltipSide = match (true) { $index < 2 => 'left-0', $index > 9 => 'right-0', default => 'left-1/2 -translate-x-1/2' };
                            @endphp
                            {{-- The whole column is the hover and focus target, not just the painted bar --}}
                            <li
                                class="group relative flex h-full items-end justify-center rounded outline-none focus-visible:bg-zinc-100"
                                tabindex="0"
                                aria-label="{{ trans_choice('{0} :month: no proposals|{1} :month: one proposal, :summary|[2,*] :month: :count proposals, :summary', $month['total'], ['month' => $label, 'summary' => $summary]) }}"
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

                                <div class="pointer-events-none absolute bottom-full z-10 mb-2 hidden w-max rounded-lg border border-line bg-surface px-3 py-2 text-xs shadow-lg shadow-zinc-900/10 group-hover:block group-focus-visible:block {{ $tooltipSide }}" aria-hidden="true">
                                    <p class="text-zinc-500">{{ $label }}</p>
                                    <p class="font-semibold text-zinc-900">{{ trans_choice('{0} No proposals|{1} One proposal|[2,*] :count proposals', $month['total']) }}</p>
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
                <ol class="mt-2 grid grid-cols-12 gap-1 text-center text-xs text-zinc-500" aria-hidden="true">
                    @foreach ($monthly as $index => $month)
                        <li class="{{ $index % 2 ? 'max-sm:invisible' : '' }}">
                            {{ $month['month']->format('M') }}
                            @if ($index === 0 || $month['month']->month === 1)
                                <span class="block text-zinc-400">{{ $month['month']->format('Y') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>

        {{-- The same numbers without hovering, for screen readers and anyone who wants exact values --}}
        <details class="mt-4 text-sm">
            <summary class="cursor-pointer text-zinc-600 hover:text-zinc-900">{{ __('Show as table') }}</summary>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full">
                    <thead class="text-zinc-500">
                        <tr>
                            <th class="py-1.5 pe-4 text-start font-medium">{{ __('Month') }}</th>
                            @foreach (\App\Models\Submission::STATUSES as $status)
                                <th class="py-1.5 pe-4 text-end font-medium">{{ __($status) }}</th>
                            @endforeach
                            <th class="py-1.5 text-end font-medium">{{ __('Total') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line tabular-nums text-zinc-800">
                        @foreach ($monthly as $month)
                            <tr>
                                <td class="py-1.5 pe-4">{{ $month['month']->format('M Y') }}</td>
                                @foreach ($month['counts'] as $count)
                                    <td class="py-1.5 pe-4 text-end">{{ $count }}</td>
                                @endforeach
                                <td class="py-1.5 text-end font-medium">{{ $month['total'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif
</section>
