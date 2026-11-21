<?php

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Activity log')] class extends Component {
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
     * The newest entry when the log was opened. Pages count from it, so entries recorded while someone reads
     * don't push rows onto the next page; showNew() moves it up. Paging keeps it; a reload starts from the newest.
     */
    #[Url]
    public ?int $until = null;

    /** Set on the request that just read $until, when nothing can be newer yet; not kept between requests. */
    protected bool $pinnedNow = false;

    public function mount(): void
    {
        if ($this->until === null) {
            $this->until = ActivityLog::max('id');
            $this->pinnedNow = true;
        }
    }

    /**
     * Every property here is a filter, so any change starts again from the first page, with the latest entries.
     */
    public function updated(): void
    {
        $this->showNew();
    }

    /**
     * Start again from the first page, with entries recorded since the log was opened.
     */
    public function showNew(): void
    {
        $this->until = ActivityLog::max('id');
        $this->pinnedNow = true;
        $this->resetPage();
    }

    /**
     * Quick picks for the date range, each ending today, or "any" to drop the range.
     */
    public function preset(string $key): void
    {
        $this->showNew();

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
        $this->showNew();
    }

    /**
     * This page of entries, read in one query together with the entry just before and just after it, so a run
     * of sign-ins the page break cuts through says it continues. Pages have Previous and Next only: numbering
     * them would count the whole log on every load.
     *
     * @return array{0: Paginator, 1: ActivityLog|null, 2: ActivityLog|null}
     */
    #[Computed]
    public function page(): array
    {
        // Sign-ins fold into runs on the page, so a page holds more entries than it shows lines.
        $perPage = 100;
        // Asked of Laravel, as paginate() does: that's how Livewire learns to keep ?page= in the URL.
        $page = Paginator::resolveCurrentPage();
        $offset = ($page - 1) * $perPage;

        $rows = $this->filtered()->with('user:id,name,profile_image')
            ->skip(max(0, $offset - 1))
            ->take($perPage + ($offset > 0 ? 2 : 1))
            ->get();
        $before = $offset > 0 ? $rows->shift() : null;

        return [
            // Given one entry too many, the paginator knows there is a next page and keeps only $perPage.
            new Paginator($rows, $perPage, $page, ['path' => Paginator::resolveCurrentPath()]),
            $before,
            $rows->get($perPage),
        ];
    }

    #[Computed]
    public function logs(): Paginator
    {
        return $this->page[0];
    }

    /**
     * @return array{0: ActivityLog|null, 1: ActivityLog|null}
     */
    #[Computed]
    public function edges(): array
    {
        return [$this->page[1], $this->page[2]];
    }

    /**
     * Whether anything matching the filters was recorded since the log was opened.
     */
    #[Computed]
    public function hasNew(): bool
    {
        return ! $this->pinnedNow && $this->until !== null && $this->filtered(pinned: false)->where('id', '>', $this->until)->exists();
    }

    /**
     * Entries matching the filters, newest first, up to the entry the log was opened at unless unpinned.
     */
    private function filtered(bool $pinned = true): Builder
    {
        $search = trim($this->search);
        [$from, $to] = $this->range();

        return ActivityLog::query()
            ->when($pinned && $this->until !== null, fn ($query) => $query->where('id', '<=', $this->until))
            ->when($this->user, fn ($query) => $query->where('user_id', $this->user))
            ->when(isset(ActivityLog::GROUPS[$this->group]), fn ($query) => $query->where('action', 'like', $this->group.'.%'))
            ->when($from, fn ($query) => $query->where('created_at', '>=', $from->startOfDay()))
            ->when($to, fn ($query) => $query->where('created_at', '<=', $to->endOfDay()))
            ->when($search !== '', function ($query) use ($search) {
                $term = '%'.$search.'%';

                $query->where(fn ($query) => $query
                    ->where('actor_name', 'like', $term)
                    ->orWhere('subject_label', 'like', $term));
            })
            ->latest()
            ->latest('id');
    }

    /**
     * The date range as dates, new on each call. A half-typed, impossible or hand-edited date in the URL is ignored,
     * and a reversed range is swapped.
     *
     * @return array{0: \Illuminate\Support\Carbon|null, 1: \Illuminate\Support\Carbon|null}
     */
    public function range(): array
    {
        $date = function (string $value) {
            $parsed = rescue(fn () => Date::createFromFormat('!Y-m-d', $value), null, false);

            return $parsed && $parsed->format('Y-m-d') === $value ? $parsed : null;
        };
        [$from, $to] = [$date($this->from), $date($this->to)];

        return $from && $to && $from->greaterThan($to) ? [$to, $from] : [$from, $to];
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

<section class="mx-auto w-full max-w-5xl">
    <x-page-title :subtitle="__('Who did what in the portal, newest first.')">{{ __('Activity log') }}</x-page-title>

    {{-- Search, then one Filter button that opens every other filter in place. The panel stays open while its fields
         redraw the log, and what is set shows as chips under the toolbar, each one click to remove --}}
    <div class="mt-6" x-data="{ filters: false }">
        {{-- A grid, not flex: with a label, Flux wraps the input in a field that a flex-1 on the input never reaches --}}
        <div class="grid grid-cols-[minmax(0,1fr)_auto] items-end gap-3">
            <flux:input
                wire:model.live.debounce.300ms="search"
                icon="magnifying-glass"
                :label="__('Search names, titles or emails')"
                {{-- An example, and Flux hides the clear button only while a placeholder shows --}}
                :placeholder="__('e.g. Research Office')"
                clearable
                class:input="max-sm:h-11"
            />
            <flux:button icon="funnel" x-on:click="filters = ! filters" x-bind:aria-expanded="filters" aria-controls="activity-filters" class="max-sm:h-11">{{ __('Filter') }}</flux:button>
        </div>

        <div id="activity-filters" x-show="filters" x-cloak class="mt-3 grid gap-4 rounded-xl border border-line bg-zinc-50 p-4 lg:grid-cols-[13rem_minmax(0,26rem)_1fr]">
            <x-select wire:model.live="group" :label="__('Type of activity')" class="max-sm:h-11">
                <x-select.option value="">{{ __('All activity') }}</x-select.option>
                @foreach (ActivityLog::GROUPS as $key => $label)
                    <x-select.option :value="$key">{{ __($label) }}</x-select.option>
                @endforeach
            </x-select>

            {{-- Side by side once each has room for a written-out date ("September 30, 2026"); stacked on phones --}}
            <div class="grid min-w-0 gap-3 sm:grid-cols-2" data-test="date-range">
                <x-date-picker wire:model.live.debounce.500ms="from" :max="$to ?: null" :label="__('From')" class="min-w-0" class:input="max-sm:h-11" />
                <x-date-picker wire:model.live.debounce.500ms="to" :min="$from ?: null" :label="__('To')" class="min-w-0" class:input="max-sm:h-11" />
            </div>

            <div role="group" aria-labelledby="quick-ranges">
                <flux:label id="quick-ranges">{{ __('Quick range') }}</flux:label>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach (['today' => 'Today', 'week' => 'Last 7 days', 'month' => 'Last 30 days', 'any' => 'Any time'] as $key => $label)
                        <flux:button size="sm" wire:click="preset('{{ $key }}')" class="max-sm:h-11">{{ __($label) }}</flux:button>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Each set filter, keyed by the action that removes it --}}
        @php([$rangeFrom, $rangeTo] = $this->range())
        @php($chips = array_filter([
            "\$set('group', '')" => isset(ActivityLog::GROUPS[$group]) ? __('Type: :type', ['type' => __(ActivityLog::GROUPS[$group])]) : null,
            "preset('any')" => match (true) {
                $rangeFrom && $rangeTo => __('Dates: :from – :to', ['from' => $rangeFrom->format('M j, Y'), 'to' => $rangeTo->format('M j, Y')]),
                (bool) $rangeFrom => __('Dates: from :from', ['from' => $rangeFrom->format('M j, Y')]),
                (bool) $rangeTo => __('Dates: until :to', ['to' => $rangeTo->format('M j, Y')]),
                default => null,
            },
            "\$set('user', null)" => $user ? __('Member: :name', ['name' => $this->person?->name ?? __('a deleted account')]) : null,
        ]))
        <x-filter-chips :chips="$chips" />
    </div>

    {{-- The log stays as it was when opened, so new entries never shift the page being read; this says they exist --}}
    @if ($this->hasNew)
        <div class="mt-3 flex items-center gap-3 text-sm text-zinc-600" data-test="new-activity">
            {{ __('New activity since you opened the log.') }}
            <flux:button size="sm" wire:click="showNew" class="max-sm:h-11">{{ __('Show new activity') }}</flux:button>
        </div>
    @endif

    @if ($this->logs->isEmpty())
        @if ($this->logs->currentPage() > 1)
            <x-empty-state icon="queue-list" :heading="__('This page is past the end of the log')" class="mt-6">
                <flux:button variant="ghost" size="sm" wire:click="resetPage" class="max-sm:h-11">{{ __('Go to the first page') }}</flux:button>
            </x-empty-state>
        @elseif (trim($search) !== '' || $group || $from || $to || $user)
            <x-empty-state icon="funnel" :heading="__('No activity matches these filters')" :text="__('Try a different search or type of activity, or widen the date range.')" class="mt-6">
                <flux:button variant="ghost" size="sm" wire:click="clearFilters" class="max-sm:h-11">{{ __('Clear filters') }}</flux:button>
            </x-empty-state>
        @else
            <x-empty-state icon="clock" :heading="__('No activity recorded yet')" :text="__('Entries appear here as members sign in and make changes in the portal.')" class="mt-6" />
        @endif
    @else
        {{-- Runs open in place through Alpine, so expanding one costs no request and survives filter redraws --}}
        <flux:table container:class="mt-4 table-frame" x-data="{ open: {} }">
            <flux:table.columns>
                <flux:table.column class="w-36">{{ __('When') }}</flux:table.column>
                <flux:table.column class="w-48">{{ __('Member') }}</flux:table.column>
                <flux:table.column class="w-56">{{ __('Activity') }}</flux:table.column>
                <flux:table.column class="w-80">{{ __('Details') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                {{-- Three or more sign-ins and sign-outs in a row on the same day fold into one row, so the changes between them stand out --}}
                @foreach ($this->logs->chunkWhile(fn ($log, $key, $run) => ActivityLog::joins($run->last(), $log)) as $run)
                    {{-- Each day is named once above its entries, so every row keeps to one line --}}
                    @if ($run->first()->day() !== ($day ?? null))
                        @php($day = $run->first()->day())
                        <flux:table.row :key="'day-'.$run->first()->id">
                            <flux:table.cell colspan="4" class="bg-zinc-50 text-xs font-medium text-zinc-600" data-test="day">{{ $day }}</flux:table.cell>
                        </flux:table.row>
                    @endif

                    {{-- A run the page break cuts through still folds, and says where the rest of it is --}}
                    @php([$continued, $continues] = [$loop->first && ActivityLog::joins($this->edges[0], $run->first()), $loop->last && ActivityLog::joins($this->edges[1], $run->last())])
                    @if ($run->count() > 2 || $continued || $continues)
                        @php($runId = $run->first()->id)
                        <flux:table.row :key="'run-'.$runId">
                            <flux:table.cell class="tabular-nums text-zinc-800">{{ ActivityLog::runSpan($run) }}</flux:table.cell>
                            <flux:table.cell colspan="3">
                                {{-- A stock ghost button, left-aligned; a long summary wraps on a phone instead of running off --}}
                                <flux:button variant="ghost" size="sm" align="start" icon="chevron-right" icon:variant="micro" icon:class="transition-transform in-aria-expanded:rotate-90 motion-reduce:transition-none" x-on:click="open[{{ $runId }}] = ! open[{{ $runId }}]" x-bind:aria-expanded="open[{{ $runId }}] ? 'true' : 'false'" aria-expanded="false" class="-ms-2 h-auto! min-h-8 whitespace-normal! text-start max-sm:min-h-11">
                                    {{ ActivityLog::runSummary($run) }}
                                    @if ($continued)
                                        <span class="text-zinc-500">· {{ __('continued from the previous page') }}</span>
                                    @endif
                                    @if ($continues)
                                        <span class="text-zinc-500">· {{ __('continues on the next page') }}</span>
                                    @endif
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>

                        {{-- Indented to line up under the summary, past its chevron, so they read as the run's own rows --}}
                        @foreach ($run as $log)
                            <flux:table.row :key="$log->id" x-show="open[{{ $runId }}]" x-cloak data-stagger style="--i: {{ $loop->index }}">
                                <flux:table.cell class="tabular-nums">{{ $log->created_at->format('g:i A') }}</flux:table.cell>
                                <flux:table.cell class="ps-9">@include('pages.activity-log.member', ['log' => $log])</flux:table.cell>
                                <flux:table.cell>{{ Str::ucfirst($log->summary()) }}</flux:table.cell>
                                <flux:table.cell />
                            </flux:table.row>
                        @endforeach

                        @continue
                    @endif

                    {{-- A shorter run stays as plain rows: folding two saves one row at the cost of a click --}}
                    @foreach ($run as $log)
                        <flux:table.row :key="$log->id" :class="$log->isWarning() ? 'bg-highlight-soft' : ''">
                            <flux:table.cell class="tabular-nums text-zinc-800">{{ $log->created_at->format('g:i A') }}</flux:table.cell>

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
                                    <flux:tooltip :content="$subject" class="block min-w-0">
                                        <p class="truncate text-zinc-800">
                                            @if ($url = $log->subjectUrl())
                                                {{-- A box of its own, so on phones it can stand 44px tall and still end in an ellipsis; the
                                                     underline sits closer than Flux's, inside the one-line box that clips it --}}
                                                <flux:link :href="$url" wire:navigate class="inline-block max-w-full truncate align-top underline-offset-2! max-sm:min-w-11 max-sm:py-3">{{ $subject }}</flux:link>
                                            @else
                                                {{ $subject }}
                                            @endif
                                        </p>
                                    </flux:tooltip>
                                @endif

                                @php([$change, $note] = [$log->change(), $log->note()])
                                @if ($change || $note)
                                    <p class="whitespace-normal break-words text-sm text-zinc-500">
                                        @if ($change)
                                            {{ $change[0] }}
                                            <flux:icon.arrow-right variant="micro" class="inline size-3.5 align-[-3px]" />
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
                                    <flux:tooltip :content="$remarks" class="block min-w-0"><p class="mt-0.5 line-clamp-2 whitespace-normal wrap-anywhere text-sm text-zinc-600">{{ __('Remarks: :remarks', ['remarks' => $remarks]) }}</p></flux:tooltip>
                                @endif

                                @if (! $subject && ! $change && ! $note)
                                    <span class="text-zinc-400" aria-hidden="true">—</span>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                @endforeach
            </flux:table.rows>
        </flux:table>

        {{-- Named buttons, as Flux's arrows for a log with no page count have no names for screen readers. Newest
             first, so the previous page holds newer entries --}}
        <div class="mt-4 flex justify-end gap-2" x-on:click="$el.closest('section').scrollIntoView()">
            <flux:button size="sm" icon="chevron-left" wire:click="previousPage" :disabled="$this->logs->onFirstPage()" class="max-sm:h-11">{{ __('Show newer') }}</flux:button>
            <flux:button size="sm" icon:trailing="chevron-right" wire:click="nextPage" :disabled="! $this->logs->hasMorePages()" class="max-sm:h-11">{{ __('Show older') }}</flux:button>
        </div>
    @endif
</section>
