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
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
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

    /** What an image memo shows, in words, for members who can't see it. */
    public string $memoAlt = '';

    public ?int $deletingId = null;

    /** The announcement a notification linked to was deleted before the member followed the link. */
    #[Locked]
    public bool $gone = false;

    /**
     * A notification links here with ?show=<id>. Open the page of the list that holds that announcement, so the link
     * still lands on it once newer ones have pushed it past the first page. Open schedules are all on the first page.
     */
    public function mount(): void
    {
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

    public function create(): void
    {
        Gate::authorize('create', Announcement::class);

        $this->reset('editingId', 'title', 'description', 'dueOn', 'memo', 'removeMemo', 'memoAlt');
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
        $this->memoAlt = (string) $announcement->attachment_alt;
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
        $this->memoAlt = trim($this->memoAlt);

        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            // A new schedule can't open already closed; an edit may keep, or correct, a deadline that has passed.
            'dueOn' => array_filter(['nullable', 'date_format:Y-m-d', $this->editingId ? null : 'after_or_equal:today']),
            'memo' => ['nullable', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png,webp', 'max:'.\App\Services\DocumentStorage::maxUploadMb() * 1024],
            'memoAlt' => ['nullable', 'string', 'max:1000'],
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
                }

                // Only an image needs words in its place; any other memo, or none, keeps none.
                $announcement->attachment_alt = $announcement->memoPreview() === 'image' ? ($this->memoAlt ?: null) : null;

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

        $this->reset('editingId', 'title', 'description', 'dueOn', 'memo', 'removeMemo', 'memoAlt');
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

    @if ($this->upcoming->isEmpty() && $this->announcements->isEmpty())
        <div class="mt-8 flex flex-wrap items-center justify-between gap-4 rounded-lg bg-zinc-50 p-6" data-test="no-announcements">
            <flux:text>{{ __('No announcements yet.') }}</flux:text>
            @can('create', App\Models\Announcement::class)
                <flux:button icon="plus" wire:click="create" class="max-sm:h-11">{{ __('Post announcement') }}</flux:button>
            @endcan
        </div>
    @endif

    {{-- Open schedules first, as their own section, so a deadline is never buried under newer memos --}}
    @foreach (array_filter(['upcoming' => $this->upcoming, 'other' => $this->announcements], fn ($list) => $list->isNotEmpty()) as $section => $list)
        <section wire:key="section-{{ $section }}" class="mt-8" aria-labelledby="announcements-{{ $section }}" data-test="{{ $section }}-announcements">
            {{-- A step above the announcement titles and under the page title, with a rule under it --}}
            <flux:heading size="lg" level="2" id="announcements-{{ $section }}" class="border-b border-line pb-2 text-lg!">
                {{ $section === 'upcoming' ? __('Upcoming deadlines') : ($this->upcoming->isEmpty() ? __('All announcements') : __('Other announcements')) }}
            </flux:heading>

            <ul class="mt-3 divide-y divide-line">
                @foreach ($list as $announcement)
                    @php($urgent = $section === 'upcoming' && $announcement->due_on->lte(today()->addDay()))
                    {{-- An agenda: a date leads each row, so the list reads by date at a glance. An open schedule shows its
                         deadline on a tile, green while open and red when due today or tomorrow. Everything else, a closed
                         schedule included, shows the day it was posted, quietly, so the dates run in the list's own order; the
                         "Closed on" sentence keeps the old deadline. The sentences below carry the same dates for screen
                         readers, so the tile is hidden from them. A notification links here by id; the gold wash, spread past
                         the edges by an outline of the same colour, shows which announcement it meant. --}}
                    <li wire:key="announcement-{{ $announcement->id }}" id="announcement-{{ $announcement->id }}" class="grid scroll-mt-6 grid-cols-[auto_minmax(0,1fr)] gap-x-4 py-6 target:rounded-sm target:bg-highlight-soft target:outline-8 target:outline-highlight-soft sm:gap-x-6" data-test="announcement">
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
                                <div class="min-w-0">
                                    <flux:heading size="lg" level="3" class="text-balance wrap-anywhere">{{ $announcement->title }}</flux:heading>

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

                            {{-- An image memo is the notice itself, so it shows on the page, its file name under it; a click opens it at
                                 full size --}}
                            @if ($preview === 'image')
                                <figure class="mt-4 w-fit max-w-full">
                                    <a href="{{ $announcement->memoUrl() }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-lg border border-line focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent" data-test="memo-image">
                                        <img src="{{ $announcement->memoPreviewUrl() }}" alt="{{ $announcement->attachment_alt ? __(':description (opens the memo in a new tab)', ['description' => $announcement->attachment_alt]) : __('Memo: :name (opens in a new tab)', ['name' => $announcement->attachment_name]) }}" loading="lazy" decoding="async" class="max-h-96 w-auto" />
                                    </a>
                                    <figcaption class="mt-2 truncate text-sm text-zinc-600">{{ $announcement->attachment_name }}</figcaption>
                                </figure>
                            @elseif ($announcement->attachment_path)
                                {{-- A PDF or Word memo is a file row: what it is, then the one thing to do with it. On a phone the button
                                     drops under the name rather than cutting the name short. --}}
                                <div class="mt-4 flex max-w-prose flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border border-line py-2 ps-3 pe-2" data-test="memo-file">
                                    <flux:icon :icon="$preview === 'pdf' ? 'document-text' : 'document'" variant="mini" class="shrink-0 text-zinc-500" aria-hidden="true" />
                                    <flux:text class="min-w-0 grow basis-40 truncate text-zinc-800" :title="$announcement->attachment_name">{{ $announcement->attachment_name }}</flux:text>
                                    @if ($preview)
                                        {{-- 44px tall on a phone, like the actions button, for the thumb --}}
                                        <flux:button size="sm" icon="eye" class="max-sm:h-11" x-on:click="$dispatch('memo-preview', {{ Js::from(['name' => $announcement->attachment_name, 'url' => $announcement->memoUrl(), 'kind' => $preview]) }})" :aria-label="__('Preview memo: :name', ['name' => $announcement->attachment_name])" data-test="preview-memo">
                                            {{ __('Preview memo') }}
                                        </flux:button>
                                    @else
                                        {{-- An old .doc can't be shown in a browser, so it downloads --}}
                                        <flux:button size="sm" icon="arrow-down-tray" class="max-sm:h-11" :href="$announcement->memoUrl()" download :aria-label="__('Download memo: :name', ['name' => $announcement->attachment_name])" data-test="download-memo">
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
                @endforeach
            </ul>

            @if ($section === 'other' && $this->announcements->hasPages())
                <flux:pagination :paginator="$this->announcements" class="mt-4" />
            @endif
        </section>
    @endforeach

    {{-- One viewer for every PDF and Word memo; the Preview memo buttons fill it, so opening one asks nothing of the server
         but the file itself --}}
    <flux:modal name="memo-preview" class="w-full max-w-4xl" aria-labelledby="memo-preview-heading" data-test="memo-preview">
        <div x-data="memoPreview" x-on:memo-preview.window="show($event.detail)" x-on:resize.window.debounce="fitPages()" class="flex flex-col gap-4">
            <flux:heading size="lg" id="memo-preview-heading" class="pe-8 wrap-anywhere" x-text="name"></flux:heading>

            <iframe x-show="kind === 'pdf'" x-bind:src="kind === 'pdf' ? url : 'about:blank'" x-bind:title="name" class="h-[70vh] w-full rounded-md border border-line"></iframe>

            {{-- As tall as the pages, up to 70% of the screen, so a page scaled down on a phone has no empty ground under it --}}
            <div x-show="kind === 'docx'" class="max-h-[70vh] min-h-32 overflow-auto rounded-md border border-line bg-zinc-100" data-memo-pages>
                <flux:text x-show="loading" class="p-6" role="status">{{ __('Loading the memo…') }}</flux:text>
                <flux:text x-show="failed" x-cloak class="p-6" role="alert">{{ __('The memo could not be shown here. Download it to read it.') }}</flux:text>
                <div x-ref="pages"></div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:text size="sm" x-show="kind === 'pdf'">{{ __('Not showing? Your browser may not preview PDFs here. Open it in a new tab.') }}</flux:text>
                <flux:spacer />
                <flux:button href="#" x-bind:href="url" target="_blank" rel="noopener" icon="arrow-top-right-on-square" x-show="kind === 'pdf'">{{ __('Open in a new tab') }}</flux:button>
                <flux:button href="#" x-bind:href="url" download icon="arrow-down-tray" x-show="kind === 'docx'">{{ __('Download') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    @can('create', App\Models\Announcement::class)
        {{-- Livewire sets no loading state while a file is still uploading, so the form counts uploads and holds Save until
             they finish, as the project form does --}}
        <flux:modal name="announcement-form" class="w-full max-w-lg" aria-labelledby="announcement-form-heading">
            <form
                wire:submit="save"
                class="flex flex-col gap-6"
                x-data="{ uploading: 0 }"
                x-on:livewire-upload-start="uploading++"
                x-on:livewire-upload-finish="uploading--"
                x-on:livewire-upload-error="uploading--"
                x-on:livewire-upload-cancel="uploading--"
                x-on:submit.capture="uploading && ($event.preventDefault(), $event.stopImmediatePropagation())"
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

                    <flux:text x-show="uploading" x-cloak size="sm" class="mt-2">{{ __('Uploading… Save waits until the file is in.') }}</flux:text>
                </div>

                {{-- An image memo is often a poster with the notice on it, so its words are asked for here and read out in
                     its place; a PDF or Word file carries its own text --}}
                @if ($memo ? str_starts_with((string) $memo->getMimeType(), 'image/') : $this->editing?->memoPreview() === 'image' && ! $removeMemo)
                    <flux:textarea
                        wire:model="memoAlt"
                        :label="__('Image description')"
                        :description="__('For members who use a screen reader, type the text on the image or describe what it shows. Optional.')"
                        rows="3"
                        maxlength="1000"
                        data-test="memo-alt"
                    />
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
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
