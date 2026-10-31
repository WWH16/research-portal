<?php

use App\Exceptions\DocumentStorageUnavailable;
use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AnnouncementPosted;
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

        // The list runs newest first, ties by id, so this many come before it.
        $before = $this->others()
            ->where(fn (Builder $query) => $query->where('created_at', '>', $announcement->created_at)
                ->orWhere(fn (Builder $query) => $query->where('created_at', $announcement->created_at)->where('id', '>', $announcement->id)))
            ->count();

        $this->setPage(intdiv($before, self::PER_PAGE) + 1);
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

    /** Everything else, newest first: notices and memos without a deadline, and schedules that have closed. */
    #[Computed]
    public function announcements(): LengthAwarePaginator
    {
        $announcements = $this->others()->with('poster')->latest()->orderByDesc('id')->paginate(self::PER_PAGE);

        // A page past the end, as once its last announcement was deleted, shows the last page that has any.
        if ($announcements->isEmpty() && $announcements->currentPage() > 1 && $announcements->total() > 0) {
            $this->setPage($announcements->lastPage());

            return $this->announcements();
        }

        return $announcements;
    }

    /**
     * @return Builder<Announcement>
     */
    private function others(): Builder
    {
        return Announcement::where(fn (Builder $query) => $query->whereNull('due_on')->orWhereDate('due_on', '<', today()));
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

    /**
     * The announcements whose notices the member hasn't read. A notice names its announcement in the fragment of its
     * link (AnnouncementPosted::url()).
     *
     * @return list<int>
     */
    private function unseen(): array
    {
        return Auth::user()->unreadNotifications()->where('type', AnnouncementPosted::class)->get()
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
        $read = Auth::user()->unreadNotifications()->where('type', AnnouncementPosted::class)
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
            'description.required' => __('Enter what the announcement says.'),
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

        // Faculty hear of a new announcement once it has saved, in the portal only; an edit tells no one again.
        if (! $this->editingId) {
            Notification::send(User::where('role', 'faculty')->whereNotNull('approved_at')->get(), new AnnouncementPosted($announcement));
        }

        Flux::modal('announcement-form')->close();
        Flux::toast(variant: 'success', text: $this->editingId ? __('Announcement updated.') : __('Announcement posted.'));

        $this->reset('editingId', 'title', 'description', 'dueOn', 'memo', 'removeMemo');
        unset($this->upcoming, $this->announcements, $this->editing);
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
        unset($this->upcoming, $this->announcements, $this->deleting);
    }
}; ?>

<section class="mx-auto w-full max-w-4xl">
    <header class="flex flex-wrap items-center gap-4 border-b border-line pb-6">
        <img src="{{ asset('images/isu_seal-128.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
        {{-- At least 14rem wide, so on a phone the button wraps below instead of squeezing the description --}}
        <div class="min-w-0 flex-1 basis-56">
            <flux:heading size="xl" level="1">{{ __('Announcements') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Schedules of submissions and memos from the Research Office.') }}</flux:text>
        </div>

        @can('create', App\Models\Announcement::class)
            <flux:button variant="primary" icon="plus" wire:click="create" class="max-sm:h-11" data-test="post-announcement">{{ __('Post announcement') }}</flux:button>
        @endcan
    </header>

    @if ($gone)
        <flux:callout variant="secondary" icon="information-circle" class="mt-6" :text="__('The announcement you followed is no longer on the portal. The Research Office has removed it.')" data-test="announcement-gone" />
    @endif

    {{-- Open schedules first, as their own section, so a deadline is never buried under newer memos. With no
         announcements at all, the one list still shows, saying so, so the page keeps its shape --}}
    @foreach (['upcoming' => $this->upcoming, 'other' => $this->announcements] as $section => $list)
        @continue($list->isEmpty() && ($section === 'upcoming' || $this->upcoming->isNotEmpty()))

        <section wire:key="section-{{ $section }}" class="mt-8" aria-labelledby="announcements-{{ $section }}" data-test="{{ $section }}-announcements">
            {{-- A step above the announcement titles and under the page title, with a rule under it --}}
            <flux:heading size="lg" level="2" id="announcements-{{ $section }}" class="border-b border-line pb-2 text-lg!">
                {{ $section === 'upcoming' ? __('Upcoming deadlines') : ($this->upcoming->isEmpty() ? __('All announcements') : __('Other announcements')) }}
            </flux:heading>

            <ul class="mt-3 divide-y divide-line">
                @forelse ($list as $announcement)
                    @php($urgent = $section === 'upcoming' && $announcement->due_on->lte(today()->addDay()))
                    {{-- An agenda: a date leads each row, so the list reads by date at a glance. An open schedule shows its
                         deadline on a tile, green while open and red when due today or tomorrow. Everything else, a closed
                         schedule included, shows the day it was posted, quietly, so the dates run in the list's own order; the
                         "Closed on" sentence keeps the old deadline. The sentences below carry the same dates for screen
                         readers, so the tile is hidden from them. A notification links here by id; the gold wash, spread past
                         the edges by an outline of the same colour, shows which announcement it meant.
                         A new one is read once the member has taken it in: with a memo, when they open the memo, since that is
                         the notice itself; without one, when its title has been on screen for a moment. read() asks the server
                         once, and only for a new one. --}}
                    @php($isNew = in_array($announcement->id, $fresh))
                    <li wire:key="announcement-{{ $announcement->id }}" id="announcement-{{ $announcement->id }}" x-data="{ unread: @js($isNew), read() { if (this.unread) { this.unread = false; this.$wire.seen({{ $announcement->id }}) } } }" class="grid scroll-mt-6 grid-cols-[auto_minmax(0,1fr)] gap-x-4 py-6 target:rounded-sm target:bg-highlight-soft target:outline-8 target:outline-highlight-soft sm:gap-x-6" data-test="announcement">
                        @php($day = $section === 'upcoming' ? $announcement->due_on : $announcement->created_at)
                        <div aria-hidden="true" @class([
                            'flex min-w-12 flex-col items-center self-start rounded-md px-1 py-2 tabular-nums leading-none sm:min-w-16',
                            'bg-isu-green-50 text-isu-green-800' => $section === 'upcoming' && ! $urgent,
                            'bg-red-50 text-red-700' => $urgent,
                            'text-zinc-500' => $section !== 'upcoming',
                        ]) data-test="date-tile">
                            <span @class(['font-semibold', 'text-xl sm:text-2xl' => $section === 'upcoming', 'text-lg' => $section !== 'upcoming'])>{{ $day->format('j') }}</span>
                            <span class="mt-1 text-xs font-medium">{{ $day->format('M') }}</span>
                        </div>

                        <div class="min-w-0">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0" @if ($isNew && ! $announcement->attachment_path) x-data="{ timer: null }" x-intersect:enter="timer = setTimeout(() => document.hidden || read(), 1500)" x-intersect:leave="clearTimeout(timer)" @endif>
                                    {{-- After the title, so a screen reader hears it first; hover says what reads it, and Read shows for the rest of the visit --}}
                                    <flux:heading size="lg" level="3" class="text-balance wrap-anywhere">
                                        {{ $announcement->title }}
                                        @if ($isNew)
                                            <flux:badge size="sm" x-show="unread" class="ms-1 bg-isu-gold-100! align-middle text-isu-gold-800!" :title="$announcement->attachment_path ? __('Unread. Opening the memo marks it as read.') : __('Unread. It is marked as read once it has been on your screen for a moment.')" data-test="new-badge">{{ __('New') }}</flux:badge>
                                            <flux:badge size="sm" icon="check" x-show="! unread" x-cloak class="ms-1 align-middle" data-test="read-badge">{{ __('Read') }}</flux:badge>
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

                                @can('update', $announcement)
                                    <flux:dropdown position="bottom" align="end">
                                        {{-- 44px on a phone, a comfortable thumb target --}}
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" class="max-sm:size-11" :aria-label="__('Actions for :title', ['title' => $announcement->title])" />
                                        <flux:menu>
                                            <flux:menu.item icon="pencil-square" wire:click="edit({{ $announcement->id }})">{{ __('Edit') }}</flux:menu.item>
                                            <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $announcement->id }})">{{ __('Delete') }}</flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                @endcan
                            </div>

                            <flux:text class="mt-3 max-w-prose whitespace-pre-line wrap-anywhere text-zinc-800">{{ $announcement->description }}</flux:text>

                            @php($preview = $announcement->attachment_path ? $announcement->memoPreview() : null)

                            {{-- Starts folded, and the image gets its address only when first unfolded, so nothing is fetched
                                 from Google Drive (about 100 KB and a second or more each) until the member asks --}}
                            @if ($preview === 'image')
                                @php($image = ['name' => $announcement->attachment_name, 'url' => $announcement->memoUrl(), 'alt' => $announcement->attachment_alt ?: __('Memo: :name', ['name' => $announcement->attachment_name]), 'link' => route('announcements.index', ['show' => $announcement->id]).'#announcement-'.$announcement->id])
                                <div x-data="{ open: false }" class="mt-4" data-test="memo-image">
                                    <div class="flex max-w-prose flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border border-line py-2 ps-3 pe-2">
                                        <flux:icon icon="photo" variant="mini" class="shrink-0 text-zinc-500" aria-hidden="true" />
                                        <flux:text class="min-w-0 grow basis-40 truncate text-zinc-800" :title="$announcement->attachment_name">{{ $announcement->attachment_name }}</flux:text>
                                        <div class="flex gap-2">
                                            <flux:button size="sm" variant="ghost" class="max-sm:h-11" x-on:click="open = ! open; open && read()" x-bind:aria-expanded="open" aria-expanded="false" aria-controls="memo-image-{{ $announcement->id }}" data-test="toggle-memo-image">
                                                <span x-text="open ? @js(__('Hide image')) : @js(__('Show image'))">{{ __('Show image') }}</span>
                                            </flux:button>
                                            <flux:button size="sm" icon="eye" class="max-sm:h-11" x-on:click="read(); $dispatch('memo-image', {{ Js::from($image) }})" :aria-label="__('Preview memo: :name', ['name' => $announcement->attachment_name])">
                                                {{ __('Preview memo') }}
                                            </flux:button>
                                        </div>
                                    </div>
                                    <div id="memo-image-{{ $announcement->id }}" x-show="open" x-collapse x-cloak class="pt-2">
                                        {{-- Under the pointer or keyboard focus the photo eases in a little, inside its own frame, as a hint
                                             that it opens larger. Members who ask for less motion get a still photo. --}}
                                        <button type="button" x-on:click="$dispatch('memo-image', {{ Js::from($image) }})" class="group block w-fit max-w-full cursor-zoom-in overflow-hidden rounded-lg border border-line focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
                                            <img x-init="$watch('open', () => $el.src = @js($announcement->memoPreviewUrl()))" alt="{{ $announcement->attachment_alt ? __(':description (opens the memo viewer)', ['description' => $announcement->attachment_alt]) : __('Memo: :name (opens the memo viewer)', ['name' => $announcement->attachment_name]) }}" decoding="async" class="max-h-96 w-auto motion-safe:transition-transform motion-safe:duration-500 motion-safe:ease-[cubic-bezier(0.16,1,0.3,1)] motion-safe:group-hover:scale-[1.04] motion-safe:group-focus-visible:scale-[1.04]" />
                                        </button>
                                    </div>
                                </div>
                            @elseif ($announcement->attachment_path)
                                {{-- A PDF or Word memo is a file row: what it is, then the one thing to do with it. On a phone the button
                                     drops under the name rather than cutting the name short. --}}
                                <div class="mt-4 flex max-w-prose flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border border-line py-2 ps-3 pe-2" data-test="memo-file">
                                    <flux:icon :icon="$preview === 'pdf' ? 'document-text' : 'document'" variant="mini" class="shrink-0 text-zinc-500" aria-hidden="true" />
                                    <flux:text class="min-w-0 grow basis-40 truncate text-zinc-800" :title="$announcement->attachment_name">{{ $announcement->attachment_name }}</flux:text>
                                    @if ($preview)
                                        {{-- 44px tall on a phone, like the actions button, for the thumb --}}
                                        <flux:button size="sm" icon="eye" class="max-sm:h-11" x-on:click="read(); $dispatch('memo-preview', {{ Js::from(['name' => $announcement->attachment_name, 'url' => $announcement->memoUrl(), 'kind' => $preview]) }})" :aria-label="__('Preview memo: :name', ['name' => $announcement->attachment_name])" data-test="preview-memo">
                                            {{ __('Preview memo') }}
                                        </flux:button>
                                    @else
                                        {{-- An old .doc can't be shown in a browser, so it downloads --}}
                                        <flux:button size="sm" icon="arrow-down-tray" class="max-sm:h-11" :href="$announcement->memoUrl()" download x-on:click="read()" :aria-label="__('Download memo: :name', ['name' => $announcement->attachment_name])" data-test="download-memo">
                                            {{ __('Download memo') }}
                                        </flux:button>
                                    @endif
                                </div>
                            @endif

                            <flux:text size="sm" class="mt-4">
                                {{ __('Posted by :name on :date', ['name' => $announcement->poster->displayName(), 'date' => $announcement->created_at->format('F j, Y')]) }}
                            </flux:text>
                        </div>
                    </li>
                @empty
                    <li class="py-6" data-test="no-announcements">
                        <flux:text>{{ __('No announcements yet.') }}</flux:text>
                    </li>
                @endforelse
            </ul>

            @if ($section === 'other' && $this->announcements->hasPages())
                <flux:pagination :paginator="$this->announcements" class="mt-4" />
            @endif
        </section>
    @endforeach

    {{-- An image memo opens on its own: the photo alone on a dark ground, as large as the screen allows, with nothing
         beside it. Its file actions float at the top edge, Close at the corner, and the zoom controls at the bottom edge.
         A click beside the photo or Escape closes it too. The variant is bare, and its size and ground are forced past
         the phone rule for dialogs in app.css. --}}
    <flux:modal name="memo-image" variant="bare" class="m-0 size-full max-h-none! max-w-none! bg-black/85! p-0!" aria-labelledby="memo-image-heading" data-test="memo-image-viewer">
        <div x-data="memoImage" x-on:memo-image.window="show($event.detail)" x-on:keydown.window="key($event)" x-on:resize.window.debounce="measure()" x-on:pointerup.window="release()" x-on:pointercancel.window="release()" class="relative size-full">
            <h2 id="memo-image-heading" class="sr-only" x-text="name"></h2>

            {{-- Fitted, the photo is whole at any size: a tall poster is scaled to the screen's height, a wide one to its
                 width. Zoomed, it grows past the screen and this frame scrolls; a mouse drags it, a finger or trackpad
                 scrolls it. The auto margins centre a fitted photo and let a zoomed one scroll to every edge. --}}
            <div x-ref="stage" x-on:click.self="$flux.modal('memo-image').close()" x-on:wheel="wheel($event)" class="flex size-full overflow-auto [scrollbar-width:none]">
                <img
                    x-ref="photo"
                    x-bind:src="url || null"
                    x-bind:alt="alt"
                    x-bind:style="photoStyle"
                    x-bind:class="zoom > 1 ? 'cursor-grab active:cursor-grabbing' : 'max-h-full max-w-full'"
                    x-on:load="measure()"
                    x-on:pointerdown="grab($event)"
                    x-on:pointermove="pan($event)"
                    draggable="false"
                    class="m-auto shrink-0 touch-manipulation object-contain select-none"
                />
            </div>

            {{-- Zoom, only when the member asks: out, the share of the photo's real size now shown (a click fits it back to
                 the screen), and in. A press steps to the next ten percent; holding a button then goes on a point at a time.
                 A press from the keyboard (detail 0) steps once. Also Ctrl or ⌘ with the scroll wheel or a trackpad pinch,
                 and the + − 0 keys. A click on the photo itself does nothing. --}}
            <div class="absolute bottom-3 start-1/2 -translate-x-1/2">
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
                <flux:tooltip :content="__('Copy image')" x-show="canCopy">
                    <flux:button icon="clipboard-document" class="max-sm:size-11" x-bind:disabled="! file" x-on:click="copyImage({{ Js::from(__('Image copied.')) }}, {{ Js::from(__('The image could not be copied. Download it instead.')) }})" :aria-label="__('Copy image')" data-test="copy-image" />
                </flux:tooltip>
                <flux:tooltip :content="__('Download')">
                    <flux:button icon="arrow-down-tray" class="max-sm:size-11" href="#" x-bind:href="url" download :aria-label="__('Download')" />
                </flux:tooltip>
                <flux:tooltip :content="__('Share')">
                    <flux:button icon="share" class="max-sm:size-11" x-on:click="share({{ Js::from(__('Link to the announcement copied.')) }}, {{ Js::from(__('The announcement could not be shared. Download the image instead.')) }})" :aria-label="__('Share')" data-test="share-image" />
                </flux:tooltip>
                <flux:tooltip :content="__('Close')">
                    <flux:modal.close>
                        <flux:button icon="x-mark" class="max-sm:size-11" :aria-label="__('Close')" />
                    </flux:modal.close>
                </flux:tooltip>
            </div>
        </div>
    </flux:modal>

    {{-- The viewer for PDF and Word memos; the Preview memo buttons fill it, so opening one asks nothing of the server but
         the file itself. It fills the screen and only the memo inside it scrolls, so there is one scrollbar, never one
         for the dialog and another for the memo. --}}
    <flux:modal name="memo-preview" class="w-full max-w-5xl" aria-labelledby="memo-preview-heading" data-test="memo-preview">
        <div x-data="memoPreview" x-on:memo-preview.window="show($event.detail)" x-on:resize.window.debounce="fitPages()" class="flex h-[calc(100dvh-6rem)] flex-col gap-4">
            {{-- The memo's name, then what can be done with it, above the memo as in a file viewer; the end is kept clear
                 for the dialog's close button --}}
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 pe-10">
                <flux:heading size="lg" id="memo-preview-heading" class="min-w-0 flex-1 basis-48 truncate" x-text="name" x-bind:title="name"></flux:heading>
                <div class="flex flex-wrap gap-2">
                    <flux:button size="sm" icon="arrow-down-tray" href="#" x-bind:href="url" download>{{ __('Download') }}</flux:button>
                    <flux:button size="sm" icon="arrow-top-right-on-square" href="#" x-bind:href="url" target="_blank" rel="noopener" x-show="kind === 'pdf'">{{ __('Open in a new tab') }}</flux:button>
                </div>
            </div>

            <div class="min-h-0 flex-1 overflow-hidden rounded-md border border-line bg-zinc-100">
                <iframe x-show="kind === 'pdf'" x-bind:src="kind === 'pdf' ? url : 'about:blank'" x-bind:title="name" class="size-full"></iframe>

                <div x-show="kind === 'docx'" class="h-full overflow-auto [scrollbar-width:thin]" data-memo-pages>
                    <flux:text x-show="loading" class="p-6" role="status">{{ __('Loading the memo…') }}</flux:text>
                    <flux:text x-show="failed" x-cloak class="p-6" role="alert">{{ __('The memo could not be shown here. Download it to read it.') }}</flux:text>
                    <div x-ref="pages"></div>
                </div>
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
        <flux:modal name="announcement-form" class="w-full max-w-lg [&:has([data-cancel]:disabled)>div>[data-flux-modal-close]]:opacity-40" aria-labelledby="announcement-form-heading">
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

                <flux:textarea wire:model="description" :label="__('Announcement')" rows="5" maxlength="5000" required />

                <flux:input
                    wire:model="dueOn"
                    type="date"
                    :min="$editingId ? null : today()->format('Y-m-d')"
                    :label="__('Submission deadline')"
                    :description="__('For a schedule of submissions. Leave it empty for anything else.')"
                />

                <div>
                    <flux:input
                        wire:model="memo"
                        type="file"
                        accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/jpeg,image/png,image/webp"
                        :label="__('Memo')"
                        :description="__('Optional. A PDF, Word file or image (JPG, PNG or WebP), up to :size MB. An old .doc file can only be downloaded, not previewed.', ['size' => \App\Services\DocumentStorage::maxUploadMb()])"
                    />

                    @if ($this->editing?->attachment_name)
                        <div class="mt-3 flex flex-col gap-2">
                            <flux:text size="sm" class="wrap-anywhere">
                                {{ $memo ? __('Replaces :name.', ['name' => $this->editing->attachment_name]) : __('Current memo: :name', ['name' => $this->editing->attachment_name]) }}
                            </flux:text>
                            @unless ($memo)
                                <flux:checkbox wire:model.live="removeMemo" :label="__('Remove the memo')" />
                            @endunless
                        </div>
                    @endif

                    {{-- A chosen image shows before it is posted, so a wrong picture is caught here --}}
                    @if ($memo && str_starts_with((string) $memo->getMimeType(), 'image/') && $memo->isPreviewable())
                        <img src="{{ $memo->temporaryUrl() }}" alt="{{ __('The chosen memo image') }}" class="mt-3 max-h-48 w-auto rounded-md border border-line" />
                    @endif

                    <div x-show="uploading" x-cloak class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1" data-test="uploading-status">
                        <flux:text size="sm" role="status">{{ __('Uploading the memo… Wait until it’s in before you save or close.') }}</flux:text>
                        <flux:button size="xs" variant="ghost" x-on:click="$wire.cancelUpload('memo')">{{ __('Stop upload') }}</flux:button>
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2">
                    {{-- Says why Cancel has gone quiet, and that the dialog closes by itself --}}
                    <flux:text size="sm" wire:loading wire:target="save" role="status" class="me-auto" data-test="posting-status">
                        {{ $editingId ? __('Saving… This closes once it’s done.') : __('Posting… This closes once it’s done.') }}
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
