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
        $announcements = Announcement::with('poster')
            ->where(fn (Builder $query) => $query->whereNull('due_on')->orWhereDate('due_on', '<', today()))
            ->latest()->orderByDesc('id')->paginate(10);

        // A page past the end, as once its last announcement was deleted, shows the last page that has any.
        if ($announcements->isEmpty() && $announcements->currentPage() > 1 && $announcements->total() > 0) {
            $this->setPage($announcements->lastPage());

            return $this->announcements();
        }

        return $announcements;
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
            'dueOn' => ['nullable', 'date_format:Y-m-d'],
            'memo' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:'.\App\Services\DocumentStorage::maxUploadMb() * 1024],
        ], [
            'title.required' => __('Enter a title.'),
            'description.required' => __('Enter what the announcement says.'),
            'dueOn.date_format' => __('Enter the deadline as a full date.'),
            'memo.mimes' => __('The memo must be a PDF or Word file.'),
            'memo.max' => __('The memo must be :size MB or less.', ['size' => \App\Services\DocumentStorage::maxUploadMb()]),
        ]);

        $announcement->fill(['title' => $this->title, 'description' => $this->description, 'due_on' => $this->dueOn ?: null]);

        try {
            DB::transaction(function () use ($announcement) {
                if ($this->memo || ($this->removeMemo && $announcement->attachment_path)) {
                    $announcement->replaceMemo($this->memo);
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
            <flux:button variant="primary" icon="plus" wire:click="create" data-test="post-announcement">{{ __('Post announcement') }}</flux:button>
        @endcan
    </header>

    @if ($this->upcoming->isEmpty() && $this->announcements->isEmpty())
        <flux:text class="mt-8">{{ __('No announcements yet.') }}</flux:text>
    @endif

    {{-- Open schedules first, as their own section, so a deadline is never buried under newer memos --}}
    @foreach (array_filter(['upcoming' => $this->upcoming, 'other' => $this->announcements], fn ($list) => $list->isNotEmpty()) as $section => $list)
        <section wire:key="section-{{ $section }}" class="mt-8" aria-labelledby="announcements-{{ $section }}" data-test="{{ $section }}-announcements">
            <flux:heading level="2" id="announcements-{{ $section }}" class="text-zinc-600!">
                {{ $section === 'upcoming' ? __('Upcoming deadlines') : ($this->upcoming->isEmpty() ? __('All announcements') : __('Other announcements')) }}
            </flux:heading>

            <ul class="mt-2 divide-y divide-line">
                @foreach ($list as $announcement)
                    <li wire:key="announcement-{{ $announcement->id }}" class="py-6" data-test="announcement">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <flux:heading size="lg" level="3" class="wrap-anywhere">{{ $announcement->title }}</flux:heading>

                                {{-- The deadline is what a schedule is for, so it sits under the title: green while open, muted once closed --}}
                                @if ($deadline = $announcement->deadline())
                                    <p @class([
                                        'mt-1 flex items-center gap-1.5 text-sm font-medium',
                                        'text-isu-green-800' => $section === 'upcoming',
                                        'text-zinc-500' => $section !== 'upcoming',
                                    ]) data-test="deadline">
                                        <flux:icon.calendar-days variant="micro" class="shrink-0" aria-hidden="true" />
                                        {{ $deadline }}
                                    </p>
                                @endif
                            </div>

                            @can('update', $announcement)
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" :aria-label="__('Actions for :title', ['title' => $announcement->title])" />
                                    <flux:menu>
                                        <flux:menu.item icon="pencil-square" wire:click="edit({{ $announcement->id }})">{{ __('Edit') }}</flux:menu.item>
                                        <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $announcement->id }})">{{ __('Delete') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            @endcan
                        </div>

                        <flux:text class="mt-3 max-w-prose whitespace-pre-line wrap-anywhere text-zinc-800">{{ $announcement->description }}</flux:text>

                        <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2">
                            @if ($announcement->attachment_path)
                                <flux:button size="sm" icon="document-text" :href="route('announcements.memo', $announcement)" target="_blank" rel="noopener" :aria-label="__('Open memo: :name', ['name' => $announcement->attachment_name])" data-test="open-memo">
                                    {{ __('Open memo') }}
                                </flux:button>
                            @endif
                            <flux:text size="sm">
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

    @can('create', App\Models\Announcement::class)
        {{-- Livewire sets no loading state while a file is still uploading, so the form counts uploads and holds Save until
             they finish, as the project form does --}}
        <flux:modal name="announcement-form" class="w-full max-w-lg">
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
                <flux:heading size="lg">{{ $editingId ? __('Edit announcement') : __('Post announcement') }}</flux:heading>

                <flux:input wire:model="title" :label="__('Title')" maxlength="255" required :placeholder="__('Schedule of Submission of Concept Proposals')" />

                <flux:textarea wire:model="description" :label="__('Announcement')" rows="5" maxlength="5000" required />

                <flux:input
                    wire:model="dueOn"
                    type="date"
                    :label="__('Submission deadline')"
                    :description="__('For a schedule of submissions. Leave it empty for anything else.')"
                />

                <div>
                    <flux:input
                        wire:model="memo"
                        type="file"
                        accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                        :label="__('Memo')"
                        :description="__('PDF or Word, up to :size MB. Optional.', ['size' => \App\Services\DocumentStorage::maxUploadMb()])"
                    />

                    @if ($this->editing?->attachment_name)
                        <div class="mt-3 flex flex-col gap-2">
                            <flux:text size="sm" class="wrap-anywhere">
                                {{ $memo ? __('Replaces :name.', ['name' => $this->editing->attachment_name]) : __('Current memo: :name', ['name' => $this->editing->attachment_name]) }}
                            </flux:text>
                            @unless ($memo)
                                <flux:checkbox wire:model="removeMemo" :label="__('Remove the memo')" />
                            @endunless
                        </div>
                    @endif

                    <flux:text x-show="uploading" x-cloak size="sm" class="mt-2">{{ __('Uploading… Save waits until the file is in.') }}</flux:text>
                </div>

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
