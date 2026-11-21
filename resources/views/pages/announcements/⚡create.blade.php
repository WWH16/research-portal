<?php

use App\Enums\Role;
use App\Exceptions\DocumentStorageUnavailable;
use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AnnouncementPosted;
use App\Notifications\AnnouncementUpdated;
use App\Services\DocumentStorage;
use Flux\Flux;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/*
 * Posting or editing an announcement, on its own page: writing one and putting a memo on Google Drive takes longer
 * than a dialog should hold. Each field is checked as the admin leaves it. A new announcement's text is kept in the
 * session as the admin leaves each field, so a refresh or a wrong click doesn't lose it; posting clears it.
 */
new #[Title('Announcement')] class extends Component {
    use WithFileUploads;

    private const DRAFT = 'announcement-draft';

    /** The announcement being edited, or none for a new one. Livewire keeps a model property from being swapped. */
    public ?Announcement $announcement = null;

    public string $title = '';

    public string $description = '';

    /** The submission deadline as Y-m-d, or empty for an announcement that isn't a schedule. */
    public string $dueOn = '';

    /** A new memo file, in place of the current one. */
    public ?UploadedFile $memo = null;

    /** Take the current memo off without putting another in its place. */
    public bool $removeMemo = false;

    /** The form opened on a draft kept from an earlier visit, so it says so and offers to throw it away. */
    #[Locked]
    public bool $restored = false;

    public function mount(?Announcement $announcement = null): void
    {
        if ($announcement?->exists) {
            Gate::authorize('update', $announcement);

            $this->announcement = $announcement;
            $this->fill(['title' => $announcement->title, 'description' => $announcement->description, 'dueOn' => $announcement->due_on?->format('Y-m-d') ?? '']);

            return;
        }

        Gate::authorize('create', Announcement::class);

        $draft = array_filter(session(self::DRAFT, []));
        $this->fill($draft);
        $this->restored = $draft !== [];
    }

    /** Checks a field as the admin leaves it, and keeps a new announcement's text as a draft. */
    public function updated(string $property): void
    {
        $this->validateOnly($property);

        if (! $this->announcement) {
            session([self::DRAFT => $this->only(['title', 'description', 'dueOn'])]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            // A new schedule can't open already closed; an edit may keep, or correct, a deadline that has passed.
            'dueOn' => array_filter(['nullable', 'date_format:Y-m-d', $this->announcement ? null : 'after_or_equal:today']),
            'memo' => ['nullable', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png,webp', 'max:'.DocumentStorage::maxUploadMb() * 1024],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'title.required' => __('Enter a title.'),
            'description.required' => __('Enter the message.'),
            'dueOn.date_format' => __('Enter the deadline as a full date.'),
            'dueOn.after_or_equal' => __('Enter today or a later date.'),
            'memo.mimes' => __('Use a PDF, a Word file or a JPG, PNG or WebP image.'),
            'memo.max' => __('Use a memo of :size MB or less.', ['size' => DocumentStorage::maxUploadMb()]),
        ];
    }

    public function save(): void
    {
        $announcement = $this->announcement ?? new Announcement(['posted_by' => Auth::id()]);
        Gate::authorize($announcement->exists ? 'update' : 'create', $announcement->exists ? $announcement : Announcement::class);

        $this->title = trim($this->title);
        $this->description = trim($this->description);

        $this->validate();

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
            $this->addError('memo', __('Memo not saved. Google Drive is unavailable. Try again later.'));

            return;
        }

        // Faculty hear of a new announcement once it has saved, and of every edit to one, in the portal only. An edit
        // that changed nothing tells no one.
        if ($announcement->wasRecentlyCreated || $announcement->wasChanged()) {
            Notification::send(
                // Everyone who does not post announcements hears of them: faculty and staff, once approved.
                User::whereNot('role', Role::Admin)->approved()->get(),
                $announcement->wasRecentlyCreated ? new AnnouncementPosted($announcement) : new AnnouncementUpdated($announcement),
            );
        }

        if ($announcement->wasRecentlyCreated) {
            session()->forget(self::DRAFT);
        }

        Flux::toast(variant: 'success', text: $announcement->wasRecentlyCreated ? __('Announcement posted.') : __('Announcement updated.'));

        // Back on the list, at the post itself, lit as a notification's link lights it
        $this->redirect(route('announcements.index', ['show' => $announcement->id]).'#announcement-'.$announcement->id, navigate: true);
    }

    /** Throws away a new announcement's draft and starts the form empty. */
    public function discardDraft(): void
    {
        session()->forget(self::DRAFT);
        $this->reset('title', 'description', 'dueOn', 'memo', 'restored');
        $this->resetValidation();
    }
}; ?>

{{-- Pattern 3, Form page: a narrow column under a breadcrumb back to the list, labels above the fields, Cancel and the
     one primary action at the foot --}}
<section class="mx-auto w-full max-w-2xl">
    <header class="grid gap-4 border-b border-line pb-6">
        <flux:breadcrumbs class="min-w-0 flex-wrap gap-y-1 [&_a]:relative max-sm:[&_a]:before:absolute max-sm:[&_a]:before:-inset-x-1 max-sm:[&_a]:before:-inset-y-3">
            <flux:breadcrumbs.item :href="route('announcements.index')" wire:navigate>{{ __('Announcements') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $announcement ? __('Edit') : __('New announcement') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>
        <flux:heading level="1" class="sr-only">{{ $announcement ? __('Edit announcement') : __('Post announcement') }}</flux:heading>
    </header>

    @if ($restored)
        <flux:callout variant="secondary" icon="document-text" class="mt-6" data-test="draft-restored">
            <flux:callout.text>{{ __('Draft restored.') }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button size="sm" variant="ghost" wire:click="discardDraft" class="max-sm:h-11">{{ __('Discard draft') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    {{-- Livewire sets no loading state while a file is still uploading, so the form counts uploads and holds Save until
         they finish, as the project form does. A field's error goes the moment the admin types in it to fix it, rather
         than waiting for them to leave it again. --}}
    <form
        wire:submit="save"
        x-data="{
            uploading: 0,
            progress: 0,
            clearError(field) {
                field.closest('[data-flux-field]')?.querySelector('[data-flux-error]')?.replaceChildren();
                field.removeAttribute('data-invalid');
                field.removeAttribute('aria-invalid');
            },
        }"
        x-on:input="clearError($event.target)"
        x-on:livewire-upload-start="uploading++; progress = 0"
        x-on:livewire-upload-progress="progress = $event.detail.progress"
        x-on:livewire-upload-finish="uploading--"
        x-on:livewire-upload-error="uploading--"
        x-on:livewire-upload-cancel="uploading--"
        x-on:submit.capture="uploading && ($event.preventDefault(), $event.stopImmediatePropagation())"
        class="mt-8 flex flex-col gap-6"
    >
        <flux:input wire:model.live.blur="title" :label="__('Title')" maxlength="255" required :autofocus="! $announcement" :placeholder="__('e.g. Schedule of Submission of Concept Proposals')" class:input="max-sm:h-11" />

        <flux:textarea wire:model.live.blur="description" :label="__('Message')" rows="8" maxlength="5000" required />

        <flux:input
            wire:model.live.blur="dueOn"
            type="date"
            :min="$announcement ? null : today()->format('Y-m-d')"
            :label="__('Submission deadline')"
            :badge="__('Optional')"
            :description="__('Schedules only. Moves to Past deadlines after this date.')"
            class:input="max-sm:h-11"
        />

        <div>
            <flux:input
                wire:model="memo"
                type="file"
                accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/jpeg,image/png,image/webp"
                :label="__('Memo')"
                class="max-sm:[&_[data-flux-button]]:h-11"
                :badge="__('Optional')"
                :description="__('PDF, Word, JPG, PNG or WebP, up to :size MB. Old .doc files download only.', ['size' => \App\Services\DocumentStorage::maxUploadMb()])"
            />

            @if ($announcement?->attachment_name)
                <div class="mt-3 flex flex-col gap-2">
                    @if ($memo)
                        <flux:text size="sm" class="wrap-anywhere">{{ __('Replaces :name.', ['name' => $announcement->attachment_name]) }}</flux:text>
                    @else
                        <x-memo :announcement="$announcement" :label="__('Current memo: :name', ['name' => $announcement->attachment_name])" :fold="false" />

                        {{-- Spelled out, so the label can stand 44px tall on a phone and the whole row takes the tap --}}
                        <flux:field variant="inline" class="max-sm:items-center">
                            <flux:checkbox wire:model.live="removeMemo" />
                            <flux:label class="max-sm:min-h-11">{{ __('Remove the memo') }}</flux:label>
                        </flux:field>
                    @endif
                </div>
            @endif

            {{-- A chosen image shows before it is posted, so a wrong picture is caught here --}}
            @if ($memo && str_starts_with((string) $memo->getMimeType(), 'image/') && $memo->isPreviewable())
                <img src="{{ $memo->temporaryUrl() }}" alt="{{ __('The chosen memo image') }}" class="mt-3 max-h-48 w-auto rounded-md border border-line" />
            @endif

            <div x-show="uploading" x-cloak class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1" data-test="uploading-status">
                <flux:text size="sm" role="status" class="tabular-nums">{{ __('Uploading memo…') }} <span x-text="progress + '%'"></span></flux:text>
                <flux:progress x-model="progress" :aria-label="__('Upload progress')" class="w-40" />
                <flux:button size="xs" variant="ghost" class="max-sm:h-11" x-on:click="$wire.cancelUpload('memo')">{{ __('Stop upload') }}</flux:button>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line pt-6">
            <flux:text size="sm" wire:loading.delay wire:target="save" role="status" class="me-auto" data-test="posting-status">
                {{ $announcement ? __('Saving…') : __('Posting…') }}
            </flux:text>
            <flux:button :href="route('announcements.index')" variant="ghost" class="max-sm:h-11" wire:navigate>{{ __('Cancel') }}</flux:button>
            <flux:button type="submit" variant="primary" class="max-sm:h-11" data-test="save-announcement">
                {{ $announcement ? __('Save changes') : __('Post announcement') }}
            </flux:button>
        </div>
    </form>

</section>
