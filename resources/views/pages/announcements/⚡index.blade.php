<?php

use App\Exceptions\DocumentStorageUnavailable;
use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AnnouncementPosted;
use App\Notifications\AnnouncementUpdated;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/*
 * Schedules of submissions and memos from the Research Office. Every member reads them; admins post, edit and delete
 * them. A memo file is kept on the portal's Google Drive, like project documents.
 */
new #[Title('Announcements')] class extends Component {
    use WithFileUploads, WithPagination;

    /** How many of the other announcements one page shows. */
    private const PER_PAGE = 10;

    public ?int $editingId = null;

    public string $title = '';

    public string $description = '';

    /** The submission deadline as Y-m-d, or empty for an announcement that isn't a schedule. */
    public string $dueOn = '';

    /** A new memo file, in place of the current one. */
    public ?UploadedFile $memo = null;

    /** Take the current memo off without putting another in its place. */
    public bool $removeMemo = false;

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
    public function editing(): ?Announcement
    {
        return $this->editingId ? Announcement::find($this->editingId) : null;
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

    public function create(): void
    {
        Gate::authorize('create', Announcement::class);

        $this->reset('editingId', 'title', 'description', 'dueOn', 'memo', 'removeMemo');
        $this->resetValidation();

        Flux::modal('announcement-form')->show();
    }

    public function edit(int $id): void
    {
        $announcement = Announcement::findOrFail($id);
        Gate::authorize('update', $announcement);

        $this->reset('memo', 'removeMemo');
        $this->editingId = $announcement->id;
        $this->title = $announcement->title;
        $this->description = $announcement->description;
        $this->dueOn = $announcement->due_on?->format('Y-m-d') ?? '';
        $this->resetValidation();
        unset($this->editing);

        // On a slow server a member can press Delete before this answers; the dialog opened last is the only one open.
        Flux::modal('announcement-delete')->close();
        Flux::modal('announcement-form')->show();
    }

    public function save(): void
    {
        $announcement = $this->editingId ? Announcement::findOrFail($this->editingId) : new Announcement(['posted_by' => Auth::id()]);
        Gate::authorize($announcement->exists ? 'update' : 'create', $announcement->exists ? $announcement : Announcement::class);

        $this->title = trim($this->title);
        $this->description = trim($this->description);

        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            // A new schedule can't open already closed; an edit may keep, or correct, a deadline that has passed.
            'dueOn' => array_filter(['nullable', 'date_format:Y-m-d', $this->editingId ? null : 'after_or_equal:today']),
            'memo' => ['nullable', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png,webp', 'max:'.\App\Services\DocumentStorage::maxUploadMb() * 1024],
        ], [
            'title.required' => __('Enter a title.'),
            'description.required' => __('Enter the message.'),
            'dueOn.date_format' => __('Enter the deadline as a full date.'),
            'dueOn.after_or_equal' => __('The deadline can’t be in the past. Enter today or a later date.'),
            'memo.mimes' => __('The memo must be a PDF, a Word file or an image (JPG, PNG or WebP).'),
            'memo.max' => __('The memo must be :size MB or less.', ['size' => \App\Services\DocumentStorage::maxUploadMb()]),
        ]);

        $announcement->fill(['title' => $this->title, 'description' => $this->description, 'due_on' => $this->dueOn ?: null]);

        try {
            DB::transaction(function () use ($announcement) {
                if ($this->memo || ($this->removeMemo && $announcement->attachment_path)) {
                    $announcement->replaceMemo($this->memo);
                    // A description given before the form stopped asking for one goes with the memo it described.
                    $announcement->attachment_alt = null;
                }

                $announcement->save();

                // An edit that changed nothing is left out of the log.
                if ($announcement->wasRecentlyCreated || $announcement->wasChanged()) {
                    ActivityLog::record($announcement->wasRecentlyCreated ? 'announcement.posted' : 'announcement.updated', $announcement);
                }
            });
        } catch (DocumentStorageUnavailable) {
            // Nothing saved, so the admin can try again once Google Drive is back.
            $this->addError('memo', __('Document storage is unavailable right now, so the memo could not be saved. Try again later.'));

            return;
        }

        // Faculty hear of a new announcement once it has saved, and of every edit to one, in the portal only. An edit
        // that changed nothing tells no one.
        if ($announcement->wasRecentlyCreated || $announcement->wasChanged()) {
            Notification::send(
                User::faculty()->whereNotNull('approved_at')->get(),
                $announcement->wasRecentlyCreated ? new AnnouncementPosted($announcement) : new AnnouncementUpdated($announcement),
            );
        }

        Flux::modal('announcement-form')->close();
        Flux::toast(variant: 'success', text: $this->editingId ? __('Announcement updated.') : __('Announcement posted.'));

        $this->reset('editingId', 'title', 'description', 'dueOn', 'memo', 'removeMemo');
        unset($this->upcoming, $this->announcements, $this->closed, $this->editing);
    }

    public function confirmDelete(int $id): void
    {
        Gate::authorize('delete', Announcement::findOrFail($id));

        $this->deletingId = $id;
        unset($this->deleting);

        Flux::modal('announcement-form')->close();
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
                <flux:button variant="primary" icon="plus" wire:click="create" class="max-sm:h-11" data-test="post-announcement">{{ __('Post announcement') }}</flux:button>
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
                    <flux:heading size="lg" level="2" id="announcements-{{ $section }}" class="scroll-mt-6 text-xl!">{{ $labels[$section] }}</flux:heading>

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
                                @class(['scroll-mt-6 rounded-xl border border-line p-4 transition-colors motion-reduce:transition-none sm:p-5', 'bg-canvas' => $section === 'closed'])
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
                                            <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" class="-me-2 max-sm:size-11" :aria-label="__('Actions for :title', ['title' => $announcement->title])" />
                                            <flux:menu>
                                                <flux:menu.item icon="pencil-square" wire:click="edit({{ $announcement->id }})">{{ __('Edit') }}</flux:menu.item>
                                                <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $announcement->id }})">{{ __('Delete') }}</flux:menu.item>
                                            </flux:menu>
                                        </flux:dropdown>
                                    @endcan
                                </div>

                                <div class="mt-4 min-w-0" @if ($isNew && ! $announcement->attachment_path) x-data="{ timer: null }" x-intersect:enter="timer = setTimeout(() => document.hidden || read(), 1500)" x-intersect:leave="clearTimeout(timer)" @endif>
                                    {{-- After the title, so a screen reader hears it first; the mark goes once it is read. The title
                                         is in ink and a step bolder than the text, not the portal's green heading colour, so the
                                         green on a post is left to mark an open deadline. --}}
                                    <flux:heading size="lg" level="3" class="text-lg! font-semibold! text-balance wrap-anywhere text-zinc-900!">
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
                                            'mt-1 flex items-start gap-1.5 text-sm font-medium text-pretty',
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
                                    <flux:button x-show="long" x-cloak size="sm" variant="ghost" inset="left" icon:trailing="chevron-down" icon-trailing:class="in-aria-expanded:rotate-180" class="mt-1" x-on:click="more = ! more" x-bind:aria-expanded="more" aria-expanded="false">
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
                                <x-empty-state icon="megaphone" :level="3" :heading="__('No announcements yet.')" :text="auth()->user()->can('create', App\Models\Announcement::class) ? __('Faculty get a notification when one is posted.') : __('You will get a notification when the Research Office posts one.')">
                                    @can('create', App\Models\Announcement::class)
                                        <flux:button icon="plus" wire:click="create" class="max-sm:h-11">{{ __('Post the first announcement') }}</flux:button>
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

    {{-- A memo opens on its own: the memo alone on a dark ground, as large as the screen allows, with nothing beside it.
         Its file actions float at the top edge, Close at the corner, and the zoom controls at the bottom edge. A click
         beside the memo or Escape closes it too. The variant is bare, and its size and ground are forced past the phone
         rule for dialogs in app.css. --}}
    <flux:modal name="memo-viewer" variant="bare" class="m-0 size-full max-h-none! max-w-none! bg-black/85! p-0!" aria-labelledby="memo-viewer-heading" data-test="memo-viewer">
        <div x-data="memoViewer(@js(__('Page :number of :total')))" x-on:memo-viewer.window="show($event.detail)" x-on:keydown.window="key($event)" x-on:resize.window.debounce="measure()" x-on:pointerup.window="release()" x-on:pointercancel.window="release()" class="relative size-full">
            <h2 id="memo-viewer-heading" class="sr-only" x-text="name"></h2>

            {{-- Fitted, a photo is whole at any size: a tall poster is scaled to the screen's height, a wide one to its
                 width. A PDF or Word memo's pages are fitted to the screen's width and scroll down. Zoomed, the memo grows
                 past the screen and this frame scrolls; a mouse drags a photo, a finger or trackpad scrolls either. The
                 auto margins centre a fitted memo and let a zoomed one scroll to every edge. The frame keeps clear of the
                 buttons above and below, so they never cover the top or foot of a fitted photo. wire:ignore keeps a
                 Livewire refresh of the page from clearing pages already drawn. --}}
            <div x-ref="stage" x-on:click.self="$flux.modal('memo-viewer').close()" x-on:wheel="wheel($event)" class="flex size-full overflow-auto py-16 [scrollbar-width:none]">
                {{-- Laid out while hidden, so the pages can be measured the moment they are drawn --}}
                <div x-ref="pages" x-show="kind !== 'image'" x-bind:class="{ invisible: ! loaded }" x-bind:style="pagesStyle" wire:ignore class="m-auto w-max shrink-0" data-memo-pages></div>
                <img
                    x-ref="photo"
                    x-show="kind === 'image' && loaded"
                    x-bind:src="kind === 'image' ? url : null"
                    x-bind:alt="alt"
                    x-bind:style="photoStyle"
                    x-bind:class="zoom > 1 ? 'cursor-grab active:cursor-grabbing' : 'max-h-full max-w-full'"
                    x-on:load="loaded = true; measure()"
                    x-on:error="failed = true"
                    x-on:pointerdown="grab($event)"
                    x-on:pointermove="pan($event)"
                    draggable="false"
                    class="m-auto shrink-0 touch-manipulation object-contain select-none"
                />
            </div>

            {{-- Google Drive can take a second or more to send the memo, and may not send it at all --}}
            <flux:text x-show="! loaded && ! failed" role="status" class="pointer-events-none absolute inset-0 m-auto size-fit text-zinc-200">{{ __('Loading the memo…') }}</flux:text>
            <flux:text x-show="failed" x-cloak role="alert" class="pointer-events-none absolute inset-0 m-auto size-fit px-6 text-center text-zinc-200">{{ __('The memo could not be loaded. Close the viewer and try again later.') }}</flux:text>

            {{-- Zoom, only when the member asks: out, the share of the memo's real size now shown (a click fits it back to
                 the screen), and in. A press steps to the next ten percent; holding a button then goes on a point at a time.
                 A press from the keyboard (detail 0) steps once. Also Ctrl or ⌘ with the scroll wheel or a trackpad pinch,
                 and the + − 0 keys. A click on the memo itself does nothing. --}}
            {{-- On a white ground, so pages scrolling under it never show through a disabled button --}}
            <div class="absolute bottom-3 start-1/2 -translate-x-1/2 rounded-lg bg-white">
                {{-- No tooltips here: they would sit between the group and its buttons and break its joined edges --}}
                <flux:button.group>
                    <flux:button icon="magnifying-glass-minus" class="max-sm:size-11" x-on:pointerdown="hold(-1)" x-on:click="$event.detail || nudge(-1)" x-bind:disabled="zoom <= 1" :aria-label="__('Zoom out')" data-test="zoom-out" />
                    <flux:button class="w-18 tabular-nums max-sm:h-11" x-on:click="zoomBy(1 / zoom)" x-bind:disabled="zoom <= 1" :title="__('Fit to screen')">
                        <span class="sr-only">{{ __('Fit to screen, now') }}</span>
                        <span x-text="percent"></span>
                    </flux:button>
                    <flux:button icon="magnifying-glass-plus" class="max-sm:size-11" x-on:pointerdown="hold(1)" x-on:click="$event.detail || nudge(1)" x-bind:disabled="! width || zoom >= maxZoom" :aria-label="__('Zoom in')" data-test="zoom-in" />
                </flux:button.group>
            </div>

            <div class="absolute end-3 top-3 flex gap-2">
                <flux:tooltip :content="__('Copy image')" x-show="canCopy && kind === 'image'">
                    <flux:button icon="clipboard-document" class="max-sm:size-11" x-bind:disabled="! file" x-on:click="copyImage({{ Js::from(__('Image copied.')) }}, {{ Js::from(__('The image could not be copied. Download it instead.')) }})" :aria-label="__('Copy image')" data-test="copy-image" />
                </flux:tooltip>
                <flux:tooltip :content="__('Download')">
                    <flux:button icon="arrow-down-tray" class="max-sm:size-11" href="#" x-bind:href="url" download :aria-label="__('Download')" />
                </flux:tooltip>
                <flux:tooltip :content="__('Share')">
                    <flux:button icon="share" class="max-sm:size-11" x-on:click="share({{ Js::from(__('Link to the announcement copied.')) }}, {{ Js::from(__('The announcement could not be shared. Download the memo instead.')) }})" :aria-label="__('Share')" data-test="share-memo" />
                </flux:tooltip>
                <flux:tooltip :content="__('Close')">
                    <flux:modal.close>
                        <flux:button icon="x-mark" class="max-sm:size-11" :aria-label="__('Close')" />
                    </flux:modal.close>
                </flux:tooltip>
            </div>
        </div>
    </flux:modal>

    @can('create', App\Models\Announcement::class)
        {{-- Livewire sets no loading state while a file is still uploading, so the form counts uploads and holds Save until
             they finish, as the project form does.
             While the memo uploads and while the post saves, the dialog stays open: the close button, Escape and a click
             outside all wait with Cancel, which is disabled for both. Closing during the save wouldn't stop the post, which
             would still go out and tell every faculty member, and an error such as Google Drive being down would land out
             of sight. Saving a memo to Google Drive can take ten seconds. An upload that stalls can be stopped, so the dialog
             is never stuck. Livewire doesn't mark a wire:submit form while it saves, so the lock reads Cancel instead. --}}
        <flux:modal name="announcement-form" class="w-full max-w-lg [scrollbar-width:thin] [&:has([data-cancel]:disabled)>div>[data-flux-modal-close]]:opacity-40" aria-labelledby="announcement-form-heading">
            <form
                wire:submit="save"
                class="flex flex-col gap-6"
                x-data="{
                    uploading: 0,
                    holdOpen(event) {
                        const dialog = this.$root.closest('dialog');

                        if (this.$refs.cancel.disabled && (event.key === 'Escape' || event.target === dialog || dialog.contains(event.target.closest('[data-flux-modal-close]')))) {
                            event.preventDefault();
                            event.stopPropagation();
                        }
                    },
                }"
                x-on:livewire-upload-start="uploading++"
                x-on:livewire-upload-finish="uploading--"
                x-on:livewire-upload-error="uploading--"
                x-on:livewire-upload-cancel="uploading--"
                x-on:submit.capture="uploading && ($event.preventDefault(), $event.stopImmediatePropagation())"
                x-on:click.window.capture="holdOpen($event)"
                x-on:keydown.escape.window="holdOpen($event)"
            >
                <flux:heading size="lg" id="announcement-form-heading">{{ $editingId ? __('Edit announcement') : __('Post announcement') }}</flux:heading>

                <flux:input wire:model="title" :label="__('Title')" maxlength="255" required :placeholder="__('Schedule of Submission of Concept Proposals')" />

                <flux:textarea wire:model="description" :label="__('Message')" rows="5" maxlength="5000" required />

                <flux:input
                    wire:model="dueOn"
                    type="date"
                    :min="$editingId ? null : today()->format('Y-m-d')"
                    :label="__('Submission deadline')"
                    :description="__('Only for a schedule of submissions. The announcement stays under Upcoming deadlines until this date, then moves to Past deadlines.')"
                />

                <div>
                    <flux:input
                        wire:model="memo"
                        type="file"
                        accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/jpeg,image/png,image/webp"
                        :label="__('Memo')"
                        :description="__('Optional. A PDF, Word file or image (JPG, PNG or WebP), up to :size MB. An older .doc file can be downloaded but not shown in the portal.', ['size' => \App\Services\DocumentStorage::maxUploadMb()])"
                    />

                    @if ($this->editing?->attachment_name)
                        <div class="mt-3 flex flex-col gap-2">
                            @if ($memo)
                                <flux:text size="sm" class="wrap-anywhere">{{ __('Replaces :name.', ['name' => $this->editing->attachment_name]) }}</flux:text>
                            @else
                                {{-- The current memo, as a file row like the page's, so the admin checks it without leaving the
                                     form: Open full screen opens the page's own viewer above the form, with its zoom --}}
                                <x-memo :announcement="$this->editing" :label="__('Current memo: :name', ['name' => $this->editing->attachment_name])" :fold="false" />

                                <flux:checkbox wire:model.live="removeMemo" :label="__('Remove the memo')" />
                            @endif
                        </div>
                    @endif

                    {{-- A chosen image shows before it is posted, so a wrong picture is caught here --}}
                    @if ($memo && str_starts_with((string) $memo->getMimeType(), 'image/') && $memo->isPreviewable())
                        <img src="{{ $memo->temporaryUrl() }}" alt="{{ __('The chosen memo image') }}" class="mt-3 max-h-48 w-auto rounded-md border border-line" />
                    @endif

                    <div x-show="uploading" x-cloak class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1" data-test="uploading-status">
                        <flux:text size="sm" role="status">{{ __('Uploading the memo… Wait for it to finish before you save or close.') }}</flux:text>
                        <flux:button size="xs" variant="ghost" x-on:click="$wire.cancelUpload('memo')">{{ __('Stop upload') }}</flux:button>
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2">
                    {{-- Says why Cancel has gone quiet, and that the dialog closes by itself --}}
                    <flux:text size="sm" wire:loading wire:target="save" role="status" class="me-auto" data-test="posting-status">
                        {{ $editingId ? __('Saving… This window closes when it is done.') : __('Posting… This window closes when it is done.') }}
                    </flux:text>
                    <flux:modal.close>
                        <flux:button variant="ghost" wire:loading.attr="disabled" wire:target="save" x-bind:disabled="uploading > 0" x-ref="cancel" data-cancel>{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="save-announcement">
                        {{ $editingId ? __('Save changes') : __('Post announcement') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>

        {{-- Closing forgets the announcement without a request, so later actions don't reload it --}}
        <flux:modal name="announcement-delete" wire:close="$set('deletingId', null, false)" class="w-full sm:w-96" aria-labelledby="announcement-delete-heading">
            @if ($this->deleting)
                <div class="flex flex-col gap-6">
                    <div>
                        <flux:heading size="lg" id="announcement-delete-heading" class="wrap-anywhere">{{ __('Delete “:title”?', ['title' => $this->deleting->title]) }}</flux:heading>
                        <flux:text class="mt-2">
                            {{ $this->deleting->attachment_path
                                ? __('Faculty will no longer see this announcement, and its memo will be removed from Google Drive.')
                                : __('Faculty will no longer see this announcement.') }}
                        </flux:text>
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="danger" wire:click="delete" data-test="confirm-delete-announcement">{{ __('Delete') }}</flux:button>
                    </div>
                </div>
            @endif
        </flux:modal>
    @endcan
</section>
