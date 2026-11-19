<?php

use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Notifications\AnnouncementPosted;
use App\Notifications\AnnouncementUpdated;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * Schedules of submissions and memos from the Research Office. Every member reads them; admins post and edit them on
 * the announcement form page, and delete them here. A memo file is kept on the portal's Google Drive, like project
 * documents.
 */
new #[Title('Announcements')] class extends Component {
    use WithPagination;

    /** How many of the other announcements one page shows. */
    private const PER_PAGE = 10;

    public ?int $deletingId = null;

    /** The announcement a notification linked to was deleted before the member followed the link. */
    #[Locked]
    public bool $gone = false;

    /**
     * Announcements the member hadn't seen when the page opened: their notices were still unread. Each keeps its New
     * mark for the whole visit, even once seen() has read its notice.
     *
     * @var list<int>
     */
    #[Locked]
    public array $fresh = [];

    /**
     * A notification links here with ?show=<id>. Open the page of the list that holds that announcement, so the link
     * still lands on it once newer ones have pushed it past the first page. Open schedules are all on the first page.
     */
    public function mount(): void
    {
        $this->fresh = $this->unseen();

        if (! $id = request()->integer('show')) {
            return;
        }

        $announcement = Announcement::find($id);

        if (! $announcement) {
            $this->gone = true;

            return;
        }

        if ($announcement->due_on?->gte(today())) {
            return;
        }

        // A notice's list runs newest posted first, a closed schedule's latest deadline first, ties by id, so this many
        // come before it.
        [$list, $column, $pageName] = $announcement->due_on ? [$this->past(), 'due_on', 'closed'] : [$this->notices(), 'created_at', 'page'];
        $before = $list
            ->where(fn (Builder $query) => $query->where($column, '>', $announcement->{$column})
                ->orWhere(fn (Builder $query) => $query->where($column, $announcement->{$column})->where('id', '>', $announcement->id)))
            ->count();

        $this->setPage(intdiv($before, self::PER_PAGE) + 1, $pageName);
    }

    /**
     * Schedules still open, soonest first. All of them show above the list, so none is missed on a later page.
     *
     * @return Collection<int, Announcement>
     */
    #[Computed]
    public function upcoming(): Collection
    {
        return Announcement::with('poster')->whereDate('due_on', '>=', today())->orderBy('due_on')->orderBy('id')->get();
    }

    /** Notices and memos without a deadline, newest first. */
    #[Computed]
    public function announcements(): LengthAwarePaginator
    {
        return $this->paginated($this->notices()->latest()->orderByDesc('id'), 'page');
    }

    /** Schedules whose deadline has passed, the latest deadline first, kept below the rest as a record. */
    #[Computed]
    public function closed(): LengthAwarePaginator
    {
        return $this->paginated($this->past()->orderByDesc('due_on')->orderByDesc('id'), 'closed');
    }

    /**
     * @param  Builder<Announcement>  $query
     */
    private function paginated(Builder $query, string $pageName): LengthAwarePaginator
    {
        $list = $query->with('poster')->paginate(self::PER_PAGE, pageName: $pageName);

        // A page past the end, as once its last announcement was deleted, shows the last page that has any.
        if ($list->isEmpty() && $list->currentPage() > 1 && $list->total() > 0) {
            $this->setPage($list->lastPage(), $pageName);

            return $this->paginated($query, $pageName);
        }

        return $list;
    }

    /**
     * @return Builder<Announcement>
     */
    private function notices(): Builder
    {
        return Announcement::whereNull('due_on');
    }

    /**
     * @return Builder<Announcement>
     */
    private function past(): Builder
    {
        return Announcement::whereDate('due_on', '<', today());
    }

    #[Computed]
    public function deleting(): ?Announcement
    {
        return $this->deletingId ? Announcement::find($this->deletingId) : null;
    }

    /** The notices that tell a member of an announcement: its post, and each edit after it. */
    private const NOTICES = [AnnouncementPosted::class, AnnouncementUpdated::class];

    /**
     * The announcements whose notices the member hasn't read. A notice names its announcement in the fragment of its
     * link (AnnouncementPosted::url()).
     *
     * @return list<int>
     */
    private function unseen(): array
    {
        return Auth::user()->unreadNotifications()->whereIn('type', self::NOTICES)->get()
            ->map(fn ($notice) => (int) Str::afterLast($notice->data['url'] ?? '', '#announcement-'))
            ->filter()->values()->all();
    }

    /** Redraws when the sidebar's open-work check sees the announcements' version move, marking new arrivals New. */
    #[On('open-work-changed')]
    public function openWorkChanged(array $changed): void
    {
        if (! in_array('announcements', $changed, true)) {
            $this->skipRender();

            return;
        }

        $this->fresh = [...$this->fresh, ...$this->unseen()];
    }

    /**
     * A new announcement has been on the member's screen for a moment: read its notice, so the bell stops counting it.
     * Each is read on its own as the member reaches it, never all at once when the page opens. Nothing on the page
     * changes, so it isn't drawn again.
     */
    #[Renderless]
    public function seen(int $id): void
    {
        $read = Auth::user()->unreadNotifications()->whereIn('type', self::NOTICES)
            ->where('data->url', 'like', '%#announcement-'.$id)
            ->update(['read_at' => now()]);

        if ($read) {
            $this->dispatch('open-work-stale');
        }
    }

    public function confirmDelete(int $id): void
    {
        Gate::authorize('delete', Announcement::findOrFail($id));

        $this->deletingId = $id;
        unset($this->deleting);

        Flux::modal('announcement-delete')->show();
    }

    public function delete(): void
    {
        $announcement = Announcement::findOrFail($this->deletingId);
        Gate::authorize('delete', $announcement);

        DB::transaction(function () use ($announcement) {
            if ($announcement->attachment_path) {
                $announcement->replaceMemo(null);
            }

            $announcement->delete();
            ActivityLog::record('announcement.deleted', $announcement);
        });

        Flux::modal('announcement-delete')->close();
        Flux::toast(variant: 'success', text: __('Announcement deleted.'));

        $this->reset('deletingId');
        unset($this->upcoming, $this->announcements, $this->closed, $this->deleting);
    }
}; ?>

<section class="mx-auto w-full max-w-5xl">
    <x-page-title :subtitle="__('Schedules of submissions and memos from the Research Office.')">
        {{ __('Announcements') }}

        <x-slot:actions>
            @can('create', App\Models\Announcement::class)
                <flux:button variant="primary" icon="plus" :href="route('announcements.create')" wire:navigate class="max-sm:h-11" data-test="post-announcement">{{ __('Post announcement') }}</flux:button>
            @endcan
        </x-slot:actions>
    </x-page-title>

    {{-- What the page holds and how much, each a jump to its section, so a phone that opens on the deadlines still tells
         the member the general announcements are further down. Nothing is hidden, unlike tabs. Only when there is more
         than one section to jump between. --}}
    @php($labels = ['upcoming' => __('Upcoming deadlines'), 'other' => __('General announcements'), 'closed' => __('Past deadlines')])
    @php($jumps = array_filter(['upcoming' => $this->upcoming->count(), 'other' => $this->announcements->total(), 'closed' => $this->closed->total()]))
    @if (count($jumps) > 1)
        {{-- On a phone the jumps wrap rather than scroll, so none sits out of sight --}}
        <flux:navbar class="-mx-3 mt-2 flex-wrap" :aria-label="__('Sections')" data-test="section-jumps">
            @foreach ($jumps as $section => $count)
                <flux:navbar.item href="#announcements-{{ $section }}" :badge="$count" class="shrink-0 max-sm:h-11">{{ $labels[$section] }}</flux:navbar.item>
            @endforeach
        </flux:navbar>
    @endif

    @if ($gone)
        <flux:callout variant="secondary" icon="information-circle" class="mt-6" :text="__('The Research Office has removed the announcement you opened.')" data-test="announcement-gone" />
    @endif

    {{-- A stream, as in Google Classroom, which faculty already use: on a wide screen the open deadlines are listed beside
         it under Due soon, each a jump to its post; on a phone, and with nothing due, the stream has the width alone.
         Open schedules come first, as their own section, so a deadline is never buried under newer memos; closed ones
         last, as a record, so they don't crowd the notices. Each section shows only when it has any. With no
         announcements at all, the one list still shows, saying so, so the page keeps its shape. --}}
    <div @class(['mt-6', 'lg:grid lg:grid-cols-[13rem_minmax(0,1fr)] lg:items-start lg:gap-6' => $this->upcoming->isNotEmpty()])>
        @if ($this->upcoming->isNotEmpty())
            {{-- Pinned while the stream scrolls, and never taller than the screen, so a long list scrolls on its own
                 rather than hiding its last deadlines --}}
            <aside class="hidden rounded-xl border border-line p-4 lg:sticky lg:top-6 lg:block lg:max-h-[calc(100dvh-3rem)] lg:overflow-y-auto lg:[scrollbar-width:thin]" aria-labelledby="due-soon-heading" data-test="due-soon">
                <flux:heading level="2" id="due-soon-heading" class="text-base!">{{ __('Due soon') }}</flux:heading>
                <ul class="mt-3 flex flex-col gap-3">
                    @foreach ($this->upcoming as $announcement)
                        <li>
                            <flux:link href="#announcement-{{ $announcement->id }}" variant="ghost" x-on:click="$dispatch('light-announcement', {{ $announcement->id }})" class="text-sm font-medium wrap-anywhere text-zinc-900!">{{ $announcement->title }}</flux:link>
                            <flux:text size="sm" @class(['mt-0.5', 'text-red-700!' => $announcement->due_on->lte(today()->addDay())])>{{ $announcement->deadline() }}</flux:text>
                        </li>
                    @endforeach
                </ul>
            </aside>
        @endif

        <div class="flex min-w-0 flex-col gap-8">
            @foreach (['upcoming' => $this->upcoming, 'other' => $this->announcements, 'closed' => $this->closed] as $section => $list)
                @continue($list->isEmpty() && ! ($section === 'other' && $this->upcoming->isEmpty() && $this->closed->isEmpty()))

                <section wire:key="section-{{ $section }}" aria-labelledby="announcements-{{ $section }}" data-test="{{ $section }}-announcements">
                    <flux:heading size="lg" level="2" id="announcements-{{ $section }}" class="scroll-mt-6 text-xl! leading-tight!">{{ $labels[$section] }}</flux:heading>

                    <ul class="mt-4 flex flex-col gap-4">
                        @forelse ($list as $announcement)
                            @php($urgent = $section === 'upcoming' && $announcement->due_on->lte(today()->addDay()))
                            {{-- A post, as in Classroom: who posted it and when, with its actions at the corner; then the title,
                                 its deadline, the text, and the memo at the foot. A closed schedule's card sits on the canvas
                                 grey, as Classroom quiets work that is done. A notification or a Due soon entry links here
                                 by id; the gold wash, spread past the edges by an outline of the same colour, shows which
                                 announcement it meant, and fades once the member clicks anywhere but this card. A click in Due
                                 soon leaves it to that list, which lights the post it jumps to. A new one is read once the
                                 member has taken it in: with a memo, when they open the memo, since that is the notice itself;
                                 without one, when its title has been on screen for a moment. read() asks the server once, and
                                 only for a new one. --}}
                            @php($isNew = in_array($announcement->id, $fresh))
                            <li
                                wire:key="announcement-{{ $announcement->id }}"
                                id="announcement-{{ $announcement->id }}"
                                x-data="{ unread: @js($isNew), lit: location.hash === '#announcement-{{ $announcement->id }}', read() { if (this.unread) { this.unread = false; this.$wire.seen({{ $announcement->id }}) } } }"
                                x-on:click.outside="$event.target.closest('[data-test=due-soon]') || (lit = false)"
                                x-on:light-announcement.window="lit = $event.detail === {{ $announcement->id }}"
                                x-bind:class="lit && 'bg-highlight-soft! outline-4 outline-highlight-soft'"
                                @class(['scroll-mt-6 rounded-xl border border-line p-4 transition-colors motion-reduce:transition-none sm:p-6', 'bg-canvas' => $section === 'closed'])
                                data-test="announcement"
                            >
                                {{-- Who posted it over when, as in Classroom. An edit is said beside the post, with its own date,
                                     so a changed deadline or memo is never taken for the original. The picture repeats the
                                     name beside it, so it is hidden from screen readers. --}}
                                <div class="flex items-center gap-3">
                                    <flux:avatar size="sm" circle :src="$announcement->poster->profileImageUrl()" :name="$announcement->poster->displayName()" class="shrink-0" aria-hidden="true" />
                                    <div class="min-w-0 flex-1">
                                        <flux:text class="truncate font-medium text-zinc-800!">{{ $announcement->poster->displayName() }}</flux:text>
                                        <flux:text size="sm">
                                            {{ $announcement->updated_at->gt($announcement->created_at)
                                                ? __('Posted on :date, and edited on :edited', ['date' => $announcement->created_at->format('F j, Y'), 'edited' => $announcement->updated_at->format('F j, Y')])
                                                : __('Posted on :date', ['date' => $announcement->created_at->format('F j, Y')]) }}
                                        </flux:text>
                                    </div>

                                    @can('update', $announcement)
                                        <flux:dropdown position="bottom" align="end">
                                            {{-- 44px on a phone, a comfortable thumb target --}}
                                            <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" class="-me-2 max-sm:size-11" :tooltip="__('Open actions')" :aria-label="__('Open actions for :title', ['title' => $announcement->title])" />
                                            <flux:menu>
                                                <flux:menu.item icon="pencil-square" :href="route('announcements.edit', $announcement)" wire:navigate>{{ __('Edit') }}</flux:menu.item>
                                                <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $announcement->id }})">{{ __('Delete') }}</flux:menu.item>
                                            </flux:menu>
                                        </flux:dropdown>
                                    @endcan
                                </div>

                                <div class="mt-4 min-w-0" @if ($isNew && ! $announcement->attachment_path) x-data="{ timer: null }" x-intersect:enter="timer = setTimeout(() => document.hidden || read(), 1500)" x-intersect:leave="clearTimeout(timer)" @endif>
                                    {{-- After the title, so a screen reader hears it first; the mark goes once it is read. The title
                                         is in ink and a step bolder than the text, not the portal's green heading colour, so the
                                         green on a post is left to mark an open deadline. --}}
                                    <flux:heading size="lg" level="3" class="text-lg! leading-tight! font-semibold! text-balance wrap-anywhere text-zinc-900!">
                                        {{ $announcement->title }}
                                        {{-- Under the pointer, the mark says what clears it --}}
                                        @if ($isNew)
                                            <flux:tooltip x-show="unread" :content="__('The New mark clears once the announcement has been on your screen for a moment, or once you open its memo.')">
                                                <flux:badge size="sm" class="ms-1 bg-isu-gold-100! align-middle text-isu-gold-800!" data-test="new-badge">{{ __('New') }}</flux:badge>
                                            </flux:tooltip>
                                        @endif
                                    </flux:heading>

                                    @if ($deadline = $announcement->deadline())
                                        {{-- The icon sits on the first line, so it stays put when a phone wraps the sentence --}}
                                        <p @class([
                                            'mt-1 flex items-start gap-2 text-sm font-medium text-pretty',
                                            'text-isu-green-800' => $section === 'upcoming' && ! $urgent,
                                            'font-semibold text-red-700' => $urgent,
                                            'text-zinc-500' => $section !== 'upcoming',
                                        ]) data-test="deadline">
                                            <flux:icon :icon="$urgent ? 'exclamation-circle' : 'calendar-days'" variant="micro" class="mt-0.5 shrink-0" aria-hidden="true" />
                                            {{ $deadline }}
                                        </p>
                                    @endif
                                </div>

                                {{-- The text takes the card's full width. A long announcement shows its first six lines with
                                     Show more for the rest, so one long post doesn't push the others screens away; the button
                                     shows only when the text runs past them. That is measured once, on load, so a window resized
                                     later keeps its first answer. --}}
                                <div x-data="{ more: false, long: false }">
                                    <flux:text size="lg" x-init="long = $el.scrollHeight > $el.clientHeight" x-bind:class="{ 'line-clamp-6': ! more }" class="mt-2 line-clamp-6 whitespace-pre-line wrap-anywhere text-zinc-800">{{ $announcement->description }}</flux:text>
                                    <flux:button x-show="long" x-cloak size="sm" variant="ghost" inset="left" icon:trailing="chevron-down" icon-trailing:class="in-aria-expanded:rotate-180" class="mt-1 max-sm:h-11" x-on:click="more = ! more" x-bind:aria-expanded="more" aria-expanded="false">
                                        <span x-text="more ? @js(__('Show less')) : @js(__('Show more'))">{{ __('Show more') }}</span>
                                    </flux:button>
                                </div>

                                {{-- The memo at the foot of the post, set off by a rule, as Classroom shows an attachment --}}
                                @if ($announcement->attachment_path)
                                    <x-memo :announcement="$announcement" class="mt-4 border-t border-line pt-4" x-on:memo-opened="read()" />
                                @endif
                            </li>
                        @empty
                            {{-- The header already says what belongs here, so this says what happens next: for faculty the
                                 notice they will get, for an admin the one action that fills it --}}
                            <li data-test="no-announcements">
                                <x-empty-state icon="megaphone" :level="3" :heading="__('No announcements yet.')" :text="auth()->user()->can('create', App\Models\Announcement::class) ? __('Faculty and staff get a notification when one is posted.') : __('You will get a notification when the Research Office posts one.')">
                                    @can('create', App\Models\Announcement::class)
                                        <flux:button icon="plus" :href="route('announcements.create')" wire:navigate class="max-sm:h-11">{{ __('Post the first announcement') }}</flux:button>
                                    @endcan
                                </x-empty-state>
                            </li>
                        @endforelse
                    </ul>

                    @if ($section !== 'upcoming' && $list->hasPages())
                        <flux:pagination :paginator="$list" class="mt-4" />
                    @endif
                </section>
            @endforeach
        </div>
    </div>

    <x-memo-viewer />

    @can('create', App\Models\Announcement::class)
        {{-- Closing forgets the announcement without a request, so later actions don't reload it --}}
        <flux:modal name="announcement-delete" wire:close="$set('deletingId', null, false)" class="w-full sm:w-96" aria-labelledby="announcement-delete-heading">
            @if ($this->deleting)
                <div class="flex flex-col gap-6">
                    <div>
                        <flux:heading size="lg" id="announcement-delete-heading" class="wrap-anywhere">{{ __('Delete “:title”?', ['title' => $this->deleting->title]) }}</flux:heading>
                        <flux:text class="mt-2">
                            {{ $this->deleting->attachment_path
                                ? __('Hidden from faculty and staff. Memo deleted from Google Drive.')
                                : __('Hidden from faculty and staff.') }}
                        </flux:text>
                    </div>

                    <div class="flex justify-end gap-2">
                        {{-- Cancel takes the focus as the dialog opens, so Enter never deletes by accident --}}
                        <flux:modal.close>
                            <flux:button variant="ghost" class="max-sm:h-11" autofocus>{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="danger" class="max-sm:h-11" wire:click="delete" data-test="confirm-delete-announcement">{{ __('Delete announcement') }}</flux:button>
                    </div>
                </div>
            @endif
        </flux:modal>
    @endcan
</section>
