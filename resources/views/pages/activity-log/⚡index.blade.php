<?php

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Activity Log')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    /** An ActivityLog::GROUPS key. */
    #[Url(except: '')]
    public string $group = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    /** One member's activity; Manage Users links here with ?user=. */
    #[Url]
    public ?int $user = null;

    /**
     * Every property here is a filter, so any change starts again from the first page.
     */
    public function updated(): void
    {
        $this->resetPage();
    }

    /**
     * Quick picks for the date range, each ending today, or "any" to drop the range.
     */
    public function preset(string $key): void
    {
        $this->resetPage();

        if ($key === 'any') {
            $this->reset('from', 'to');

            return;
        }

        $this->from = today()->subDays(match ($key) {
            'week' => 6,
            'month' => 29,
            default => 0,
        })->toDateString();
        $this->to = today()->toDateString();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'group', 'from', 'to', 'user');
        $this->resetPage();
    }

    #[Computed]
    public function logs(): LengthAwarePaginator
    {
        // Sign-ins fold into runs on the page, so a page holds more entries than it shows lines.
        return $this->filtered()->with('user:id,name,profile_image')->paginate(100);
    }

    /**
     * The entries just before and after this page, so a run of sign-ins the page break cuts through
     * says it continues. Null at either end of the log.
     *
     * @return array{0: ActivityLog|null, 1: ActivityLog|null}
     */
    #[Computed]
    public function edges(): array
    {
        $logs = $this->logs;

        return [
            $logs->isNotEmpty() && $logs->firstItem() > 1 ? $this->filtered()->skip($logs->firstItem() - 2)->first() : null,
            $logs->hasMorePages() ? $this->filtered()->skip($logs->lastItem())->first() : null,
        ];
    }

    /**
     * Entries matching the filters, newest first.
     */
    private function filtered(): Builder
    {
        $search = trim($this->search);
        // A half-typed, impossible or hand-edited date in the URL is ignored, and a reversed range is swapped.
        $date = function (string $value) {
            $parsed = rescue(fn () => Date::createFromFormat('!Y-m-d', $value), null, false);

            return $parsed && $parsed->format('Y-m-d') === $value ? $parsed : null;
        };
        [$from, $to] = [$date($this->from), $date($this->to)];

        if ($from && $to && $from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return ActivityLog::query()
            ->when($this->user, fn ($query) => $query->where('user_id', $this->user))
            ->when(isset(ActivityLog::GROUPS[$this->group]), fn ($query) => $query->where('action', 'like', $this->group.'.%'))
            ->when($from, fn ($query) => $query->where('created_at', '>=', $from->startOfDay()))
            ->when($to, fn ($query) => $query->where('created_at', '<=', $to->endOfDay()))
            ->when($search !== '', function ($query) use ($search) {
                $term = '%'.$search.'%';

                $query->where(fn ($query) => $query
                    ->where('actor_name', 'like', $term)
                    ->orWhere('subject_label', 'like', $term)
                    // Saved lowercase; JSON values compare case-sensitively on MySQL.
                    ->orWhere('properties->email', 'like', Str::lower($term)));
            })
            ->latest()
            ->latest('id');
    }

    /**
     * The member the log is narrowed to, or null when their account has since been deleted.
     */
    #[Computed]
    public function person(): ?User
    {
        return $this->user ? User::find($this->user, ['id', 'name']) : null;
    }
}; ?>

<section class="mx-auto w-full max-w-4xl">
    <header class="flex items-center gap-4 border-b border-line pb-6">
        <img src="{{ asset('images/isu_seal.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
        <div class="min-w-0">
            <flux:heading size="xl" level="1">{{ __('Activity Log') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Who did what in the portal, newest first.') }}</flux:text>
        </div>
    </header>

    <div class="mt-6 flex flex-col gap-3 lg:flex-row lg:items-center">
        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            :placeholder="__('Search names, project titles, or emails')"
            :aria-label="__('Search activity')"
            clearable
            class="lg:flex-1"
        />

        <flux:select wire:model.live="group" :aria-label="__('Filter by type of activity')" class="lg:max-w-52">
            <flux:select.option value="">{{ __('All activity') }}</flux:select.option>
            @foreach (ActivityLog::GROUPS as $key => $label)
                <flux:select.option :value="$key">{{ __($label) }}</flux:select.option>
            @endforeach
        </flux:select>

        <div class="flex items-center gap-2" data-test="date-range">
            <flux:input type="date" wire:model.live.debounce.500ms="from" :max="$to ?: null" :aria-label="__('From')" class="min-w-0 flex-1 sm:w-40 sm:flex-none" />
            <span class="text-sm text-zinc-500" aria-hidden="true">–</span>
            <flux:input type="date" wire:model.live.debounce.500ms="to" :min="$from ?: null" :aria-label="__('To')" class="min-w-0 flex-1 sm:w-40 sm:flex-none" />

            <flux:dropdown position="bottom" align="end">
                <flux:button icon="calendar-days" :aria-label="__('Quick date ranges')" :tooltip="__('Quick date ranges')" />
                <flux:menu>
                    <flux:menu.item wire:click="preset('today')">{{ __('Today') }}</flux:menu.item>
                    <flux:menu.item wire:click="preset('week')">{{ __('Last 7 days') }}</flux:menu.item>
                    <flux:menu.item wire:click="preset('month')">{{ __('Last 30 days') }}</flux:menu.item>
                    <flux:menu.separator />
                    <flux:menu.item wire:click="preset('any')">{{ __('Any time') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    @if ($user)
        <div class="mt-3 flex items-center gap-2 text-sm text-zinc-600" data-test="person-filter">
            {{ __('Showing activity for') }}
            <flux:badge size="sm">
                {{ $this->person?->name ?? __('a deleted account') }}
                <flux:badge.close wire:click="$set('user', null)" :aria-label="__('Show everyone')" />
            </flux:badge>
        </div>
    @endif

    @if ($this->logs->isEmpty())
        <div class="mt-6 rounded-xl border border-dashed border-line px-6 py-12 text-center">
            @if ($this->logs->currentPage() > 1)
                <flux:heading>{{ __('This page is past the end of the log') }}</flux:heading>
                <flux:button variant="ghost" size="sm" class="mt-4" wire:click="resetPage">{{ __('Go to the first page') }}</flux:button>
            @elseif (trim($search) !== '' || $group || $from || $to || $user)
                <flux:heading>{{ __('No activity matches these filters') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Try another name or a wider date range.') }}</flux:text>
                <flux:button variant="ghost" size="sm" class="mt-4" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
            @else
                <flux:heading>{{ __('No activity recorded yet') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Entries appear here as members sign in, submit or review projects, and change accounts or filing options.') }}</flux:text>
            @endif
        </div>
    @else
        {{-- Runs open in place through Alpine, so expanding one costs no request and survives filter redraws --}}
        <flux:table class="mt-4" :paginate="$this->logs" x-data="{ open: {} }">
            <flux:table.columns>
                <flux:table.column class="w-36">{{ __('When') }}</flux:table.column>
                <flux:table.column class="w-48">{{ __('Member') }}</flux:table.column>
                <flux:table.column class="w-56">{{ __('Activity') }}</flux:table.column>
                <flux:table.column class="w-80">{{ __('Details') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                {{-- Two or more sign-ins and sign-outs in a row on the same day fold into one row, so the changes between them stand out --}}
                @foreach ($this->logs->chunkWhile(fn ($log, $key, $run) => ActivityLog::joins($run->last(), $log)) as $run)
                    {{-- A run the page break cuts through still folds, and says where the rest of it is --}}
                    @php([$continued, $continues] = [$loop->first && ActivityLog::joins($this->edges[0], $run->first()), $loop->last && ActivityLog::joins($this->edges[1], $run->last())])
                    @if ($run->count() > 1 || $continued || $continues)
                        @php($runId = $run->first()->id)
                        <flux:table.row :key="'run-'.$runId">
                            <flux:table.cell>
                                <div class="tabular-nums text-zinc-800">{{ ActivityLog::runSpan($run) }}</div>
                                <div class="text-xs text-zinc-500">{{ $run->first()->day() }}</div>
                            </flux:table.cell>
                            <flux:table.cell colspan="3">
                                <button type="button" x-on:click="open[{{ $runId }}] = ! open[{{ $runId }}]" x-bind:aria-expanded="open[{{ $runId }}] ? 'true' : 'false'" aria-expanded="false" class="flex items-center gap-2 text-zinc-600 hover:text-zinc-800">
                                    <flux:icon.chevron-right variant="micro" class="transition-transform motion-reduce:transition-none" x-bind:class="open[{{ $runId }}] && 'rotate-90'" />
                                    {{ ActivityLog::runSummary($run) }}
                                    @if ($continued)
                                        <span class="text-zinc-500">· {{ __('continued from the previous page') }}</span>
                                    @endif
                                    @if ($continues)
                                        <span class="text-zinc-500">· {{ __('continues on the next page') }}</span>
                                    @endif
                                </button>
                            </flux:table.cell>
                        </flux:table.row>

                        @foreach ($run as $log)
                            <flux:table.row :key="$log->id" class="bg-zinc-50" x-show="open[{{ $runId }}]" x-cloak>
                                <flux:table.cell class="tabular-nums">{{ $log->created_at->format('g:i A') }}</flux:table.cell>
                                <flux:table.cell>@include('pages.activity-log.member', ['log' => $log])</flux:table.cell>
                                <flux:table.cell>{{ Str::ucfirst($log->summary()) }}</flux:table.cell>
                                <flux:table.cell />
                            </flux:table.row>
                        @endforeach

                        @continue
                    @endif

                    @php($log = $run->first())
                    <flux:table.row :key="$log->id" :class="$log->isWarning() ? 'bg-highlight-soft' : ''">
                        <flux:table.cell>
                            <div class="tabular-nums text-zinc-800">{{ $log->created_at->format('g:i A') }}</div>
                            <div class="text-xs text-zinc-500">{{ $log->day() }}</div>
                        </flux:table.cell>

                        <flux:table.cell>@include('pages.activity-log.member', ['log' => $log])</flux:table.cell>

                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                <span class="truncate text-zinc-800">{{ Str::ucfirst($log->summary()) }}</span>
                                {{-- Names why the row is tinted, so the flag doesn't rest on colour alone --}}
                                @if ($log->isWarning())
                                    <flux:badge size="sm" color="amber" inset="top bottom" class="shrink-0">{{ __('Security') }}</flux:badge>
                                @endif
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($subject = $log->subject())
                                <p class="truncate text-zinc-800" title="{{ $subject }}">
                                    @if ($url = $log->subjectUrl())
                                        <a href="{{ $url }}" wire:navigate>{{ $subject }}</a>
                                    @else
                                        {{ $subject }}
                                    @endif
                                </p>
                            @endif

                            @php([$change, $note] = [$log->change(), $log->note()])
                            @if ($change || $note)
                                <p class="whitespace-normal break-words text-xs text-zinc-500">
                                    @if ($change)
                                        {{ $change[0] }}
                                        <flux:icon.arrow-right variant="micro" class="inline size-3 align-[-2px]" />
                                        <span class="sr-only">{{ __('to') }}</span>
                                        <span class="font-medium text-zinc-700">{{ $change[1] }}</span>
                                        @if ($note)
                                            <span aria-hidden="true">·</span>
                                        @endif
                                    @endif
                                    {{ $note }}
                                </p>
                            @endif

                            @if ($remarks = $log->remarks())
                                <p class="mt-0.5 line-clamp-2 whitespace-normal break-words text-xs text-zinc-600" title="{{ $remarks }}">{{ __('Remarks: :remarks', ['remarks' => $remarks]) }}</p>
                            @endif

                            @if (! $subject && ! $change && ! $note)
                                <span class="text-zinc-400" aria-hidden="true">—</span>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</section>
