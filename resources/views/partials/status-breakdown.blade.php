{{--
    One bar split by status, plus a legend with counts. The legend carries identity and values,
    so the bar itself is decorative for screen readers.
    Expects: $counts (status => count), $bars (status => background class), $linked (bool).
--}}
@php($total = array_sum($counts))

<div class="flex h-2.5 gap-0.5 overflow-hidden rounded-full" aria-hidden="true">
    @foreach ($counts as $status => $count)
        @if ($count > 0)
            <div class="{{ $bars[$status] }}" style="width: {{ $count / $total * 100 }}%"></div>
        @endif
    @endforeach
</div>

<ul class="mt-4 grid gap-2 text-sm">
    @foreach ($counts as $status => $count)
        <li>
            @if ($linked)
                <a href="{{ route('submissions.index', ['status' => $status]) }}" wire:navigate class="flex items-center gap-2 hover:underline">
            @else
                <div class="flex items-center gap-2">
            @endif
                    <span class="h-2.5 w-3 shrink-0 rounded-sm {{ $bars[$status] }}" aria-hidden="true"></span>
                    <span class="flex-1 text-zinc-800">{{ __($status) }}</span>
                    <span class="tabular-nums text-zinc-600">{{ $count }}</span>
            @if ($linked)
                </a>
            @else
                </div>
            @endif
        </li>
    @endforeach
</ul>
