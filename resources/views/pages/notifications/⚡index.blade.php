<?php

use App\Notifications\AccountApproved;
use App\Notifications\AnnouncementPosted;
use App\Notifications\AnnouncementUpdated;
use App\Notifications\ConceptReviewed;
use App\Notifications\DriveDisconnected;
use App\Notifications\DriveFull;
use App\Notifications\EmailFailed;
use App\Notifications\ProjectCompleted;
use App\Notifications\ProjectDatesChanged;
use App\Notifications\ProjectDeadline;
use App\Notifications\ProjectLate;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * The signed-in member's notifications, newest first. Opening one reads it in full, like a message, and marks it
 * read; its button then goes where it points. Nothing is deleted unless the member chooses to, and a delete can be
 * undone for a few seconds.
 */
new #[Title('Notifications')] class extends Component {
    use WithPagination;

    /** What each kind of notice is called, what its button says when it opens, and its icon in the list. */
    private const KINDS = [
        AccountApproved::class => ['Welcome', 'Start a proposal', 'check-badge'],
        AnnouncementPosted::class => ['New announcement', 'Open Announcements', 'megaphone'],
        AnnouncementUpdated::class => ['Announcement updated', 'Open Announcements', 'megaphone'],
        ConceptReviewed::class => ['Concept proposal decision', 'Open the project', 'document-check'],
        ProjectDeadline::class => ['Completion date reminder', 'Open the project', 'calendar-days'],
        ProjectDatesChanged::class => ['Project dates changed', 'Open the project', 'calendar'],
        ProjectCompleted::class => ['Project completed', 'Open Submissions', 'flag'],
        ProjectLate::class => ['Project running late', 'Open Submissions', 'clock'],
        DriveFull::class => ['Google Drive is full', 'Open Google Drive', 'circle-stack'],
        DriveDisconnected::class => ['Google Drive is disconnected', 'Open Google Drive', 'exclamation-triangle'],
        EmailFailed::class => ['Email not sent', 'Open the dashboard', 'envelope'],
    ];

    /** Where the last deleted notices wait in the cache while Undo is offered; null once it's gone. */
    public ?string $undo = null;

    /** How many notices the last delete removed, for the Undo line. */
    public int $deleted = 0;

    /** The notice open in the message panel. */
    #[Locked]
    public ?string $readingId = null;

    #[Computed]
    public function notifications(): LengthAwarePaginator
    {
        return Auth::user()->notifications()->paginate(20);
    }

    #[Computed]
    public function reading(): ?DatabaseNotification
    {
        return $this->readingId ? Auth::user()->notifications()->find($this->readingId) : null;
    }

    /**
     * A notice's heading, button wording and icon; a kind no longer sent reads as a plain notice.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public function kind(DatabaseNotification $notice): array
    {
        return self::KINDS[$notice->type] ?? ['Notification', 'Open', 'bell'];
    }

    /**
     * Open a notice in full, like a message, and mark it read. Only the member's own are searched, so any other id is a 404.
     */
    public function read(string $id): void
    {
        $notice = Auth::user()->notifications()->findOrFail($id);
        $this->readingId = $id;

        if (! $notice->read_at) {
            $notice->markAsRead();
            $this->dispatch('open-work-stale');
        }

        Flux::modal('notice')->show();
    }

    #[Computed]
    public function readCount(): int
    {
        return Auth::user()->readNotifications()->count();
    }

    /**
     * Mark one notice unread, or read again. Only the member's own are searched, so any other id is a 404.
     */
    public function toggleRead(string $id): void
    {
        $notice = Auth::user()->notifications()->findOrFail($id);
        $notice->read_at ? $notice->markAsUnread() : $notice->markAsRead();

        // The sidebar counts again at once, so the bell matches without a reload.
        $this->dispatch('open-work-stale');
    }

    /**
     * The sidebar found the unread count moved, as when a new notice came in: show it. Other counts leave the list be.
     *
     * @param  list<string>  $changed
     */
    #[On('open-work-changed')]
    public function openWorkChanged(array $changed): void
    {
        if (! in_array('unread', $changed, true)) {
            $this->skipRender();
        }
    }

    public function delete(string $id): void
    {
        Auth::user()->notifications()->findOrFail($id);

        $this->deleteOfferingUndo(Auth::user()->notifications()->whereKey($id));
        Flux::modal('notice')->close();
    }

    public function deleteAllRead(): void
    {
        $this->deleteOfferingUndo(Auth::user()->readNotifications());

        Flux::modal('delete-read')->close();
    }

    /**
     * Put the last deleted notices back exactly as they were: same text, link, read state and time.
     */
    public function undoDelete(): void
    {
        $saved = $this->undo ? Cache::pull("notifications.undo.{$this->undo}") : null;

        if ($saved && $saved['user'] === Auth::id()) {
            DatabaseNotification::insert($saved['rows']);
        }

        $this->reset('undo', 'deleted');
        $this->dispatch('open-work-stale');
    }

    /**
     * Delete the notices, keeping their rows in the cache a little longer than Undo shows, so nothing new goes in
     * the database for it.
     */
    private function deleteOfferingUndo(MorphMany $notices): void
    {
        $rows = $notices->get()->map->getRawOriginal()->all();
        DatabaseNotification::whereKey(array_column($rows, 'id'))->delete();

        $this->undo = (string) Str::uuid();
        $this->deleted = count($rows);
        Cache::put("notifications.undo.{$this->undo}", ['user' => Auth::id(), 'rows' => $rows], now()->addSeconds(15));
        $this->dispatch('open-work-stale');
    }

    /**
     * Mark one notification read and open what it is about. Only the member's own are searched, so any other id is a 404.
     */
    public function open(string $id): void
    {
        $notification = Auth::user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        // Only the path is followed, on the address the member is using: the saved link names the address the portal
        // had when it was sent, which may not be this one, and it never leads off the portal.
        $url = $notification->data['url'];
        $query = parse_url($url, PHP_URL_QUERY);
        $fragment = parse_url($url, PHP_URL_FRAGMENT);

        // A link to one item on a page loads in full, so the browser scrolls to it and marks it as the target.
        $this->redirect((parse_url($url, PHP_URL_PATH) ?: '/').($query ? "?{$query}" : '').($fragment ? "#{$fragment}" : ''), navigate: ! $fragment);
    }

    /**
     * Mark every unread notification read. The page reloads, so the sidebar count in the layout drops too.
     */
    public function markAllRead(): void
    {
        Auth::user()->unreadNotifications()->update(['read_at' => now()]);

        $this->redirectRoute('notifications.index', navigate: true);
    }
}; ?>

<section class="mx-auto w-full max-w-4xl">
    <header class="flex flex-wrap items-center gap-4 border-b border-line pb-6">
        <img src="{{ asset('images/isu_seal-128.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
        <div class="min-w-0 flex-1">
            <flux:heading size="xl" level="1">{{ __('Notifications') }}</flux:heading>
        </div>

        {{-- On phones the buttons take a row of their own, so the title never squeezes beside one --}}
        <div class="flex w-full flex-wrap gap-2 sm:w-auto">
            @if (auth()->user()->unreadNotifications()->exists())
                <flux:button wire:click="markAllRead" data-test="mark-all-read" class="max-sm:h-11">{{ __('Mark all as read') }}</flux:button>
            @endif
            @if ($this->readCount)
                <flux:modal.trigger name="delete-read">
                    <flux:button icon="trash" data-test="delete-read" class="max-sm:h-11">{{ __('Delete all read') }}</flux:button>
                </flux:modal.trigger>
            @endif
        </div>
    </header>

    {{-- Offered for 10 seconds; the deleted notices wait a little longer in the cache, so a late click still works --}}
    @if ($undo)
        <flux:callout inline variant="secondary" wire:key="undo-{{ $undo }}" x-init="setTimeout(() => $wire.set('undo', null), 10000)" role="status" class="mt-4" :text="trans_choice('{1} Notification deleted.|[2,*] :count notifications deleted.', $deleted)" data-test="undo-delete">
            <x-slot name="actions">
                <flux:button size="sm" wire:click="undoDelete">{{ __('Undo') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    @if ($this->notifications->isEmpty())
        {{-- What arrives here differs by role: faculty hear about their projects, admins about finished projects and storage --}}
        <x-empty-state
            icon="bell"
            :heading="__('No notifications yet.')"
            :text="auth()->user()->isAdmin()
                ? __('Completed and late projects, and problems with Google Drive, will appear here.')
                : __('Concept proposal decisions, completion date reminders and Research Office announcements will appear here.')"
            class="mt-8"
            data-test="no-notifications"
        />
    @else
        @php
            // Grouped by day, newest first, as inboxes do; a later page simply starts in an older group.
            $groups = $this->notifications->getCollection()->groupBy(fn ($notice) => match (true) {
                $notice->created_at->isToday() => __('Today'),
                $notice->created_at->isYesterday() => __('Yesterday'),
                $notice->created_at->greaterThan(now()->subWeek()) => __('Last 7 days'),
                default => __('Earlier'),
            });
        @endphp

        @foreach ($groups as $label => $notices)
            <h2 @class(['text-sm font-medium text-zinc-600', 'mt-6' => $loop->first, 'mt-8' => ! $loop->first])>{{ $label }}</h2>

            {{-- Inbox row: the kind as subject, the message under it; unread in pastel with the gold dot ("Unread:" for
                 screen readers). The whole row opens the notice; its menu sits above, beside the subject only, so the
                 message runs the full width --}}
            <ul class="mt-2 divide-y divide-line rounded-xl border border-line bg-surface">
                @foreach ($notices as $notification)
                    @php([$kind, , $icon] = $this->kind($notification))
                    <li wire:key="{{ $notification->id }}" @class(['relative flex items-start gap-3 px-4 py-3 first:rounded-t-xl last:rounded-b-xl', 'hover:bg-zinc-50' => $notification->read_at, 'bg-pastel hover:bg-pastel-hover' => ! $notification->read_at])>
                        <flux:icon :icon="$icon" @class(['mt-0.5 size-5 shrink-0', 'text-zinc-400' => $notification->read_at, 'text-isu-green-700' => ! $notification->read_at]) />

                        <div class="min-w-0 flex-1">
                            <flux:link as="button" variant="subtle" wire:click="read('{{ $notification->id }}')" class="block text-start no-underline! after:absolute after:inset-0 after:rounded-[inherit] focus-visible:outline-none focus-visible:after:outline-2 focus-visible:after:-outline-offset-2 focus-visible:after:outline-accent">
                                @unless ($notification->read_at)
                                    <span class="sr-only">{{ __('Unread:') }}</span>
                                @endunless
                                <span @class(['block pe-16 text-sm', 'font-medium text-zinc-800' => $notification->read_at, 'font-semibold text-zinc-900' => ! $notification->read_at])>{{ __($kind) }}</span>
                                <span class="mt-0.5 block text-sm text-zinc-600 wrap-anywhere">{{ $notification->data['message'] }}</span>
                            </flux:link>
                            <p class="mt-1 text-xs text-zinc-600">{{ $notification->created_at->diffForHumans() }}</p>
                        </div>

                        <div class="absolute end-2 top-2 z-10 flex items-center gap-2">
                            @unless ($notification->read_at)
                                <span class="size-2 rounded-full bg-isu-gold-700" data-test="unread-dot" aria-hidden="true"></span>
                            @endunless
                            <flux:dropdown position="bottom" align="end">
                                <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Actions for: :message', ['message' => $notification->data['message']])" class="max-sm:size-11" />
                                <flux:menu>
                                    <flux:menu.item :icon="$notification->read_at ? 'envelope' : 'envelope-open'" wire:click="toggleRead('{{ $notification->id }}')" class="max-sm:min-h-11">
                                        {{ $notification->read_at ? __('Mark as unread') : __('Mark as read') }}
                                    </flux:menu.item>
                                    <flux:menu.item icon="trash" variant="danger" wire:click="delete('{{ $notification->id }}')" class="max-sm:min-h-11">{{ __('Delete') }}</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endforeach

        @if ($this->notifications->hasPages())
            <flux:pagination :paginator="$this->notifications" class="mt-4" />
        @endif
    @endif

    {{-- The open notice, read in full like a message; its main button goes where the notice points --}}
    <flux:modal name="notice" class="w-full max-w-lg" data-test="notice-panel">
        @if ($notice = $this->reading)
            @php([$heading, $action] = $this->kind($notice))
            <flux:heading size="lg">{{ __($heading) }}</flux:heading>
            <flux:text size="sm" class="mt-1">{{ $notice->created_at->format('F j, Y, g:i A') }}</flux:text>
            <flux:text class="mt-4 whitespace-pre-line wrap-anywhere text-zinc-800">{{ $notice->data['message'] }}</flux:text>

            <div class="mt-6 flex flex-wrap items-center gap-2">
                <flux:button variant="ghost" size="sm" icon="envelope" wire:click="toggleRead('{{ $notice->id }}')" x-on:click="$flux.modal('notice').close()" class="max-sm:h-11">{{ __('Mark as unread') }}</flux:button>
                <flux:button variant="ghost" size="sm" icon="trash" wire:click="delete('{{ $notice->id }}')" class="max-sm:h-11">{{ __('Delete') }}</flux:button>
                <flux:spacer />
                <flux:button variant="primary" wire:click="open('{{ $notice->id }}')" class="max-sm:h-11">{{ __($action) }}</flux:button>
            </div>
        @endif
    </flux:modal>

    <flux:modal name="delete-read" class="w-full max-w-md">
        <flux:heading size="lg">{{ trans_choice('{1} Delete 1 read notification?|[2,*] Delete :count read notifications?', $this->readCount) }}</flux:heading>
        <flux:text class="mt-2">{{ __('Unread notifications are kept. You can undo this for a few seconds.') }}</flux:text>
        <div class="mt-6 flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="danger" wire:click="deleteAllRead" data-test="confirm-delete-read">{{ __('Delete') }}</flux:button>
        </div>
    </flux:modal>
</section>
