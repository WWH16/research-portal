<?php

use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * The signed-in member's notifications, newest first. Opening one marks it read and goes where it points.
 * Nothing is deleted unless the member chooses to, and a delete can be undone for a few seconds.
 */
new #[Title('Notifications')] class extends Component {
    use WithPagination;

    /** Where the last deleted notices wait in the cache while Undo is offered; null once it's gone. */
    public ?string $undo = null;

    /** How many notices the last delete removed, for the Undo line. */
    public int $deleted = 0;

    #[Computed]
    public function notifications(): LengthAwarePaginator
    {
        return Auth::user()->notifications()->paginate(20);
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
    }

    public function delete(string $id): void
    {
        Auth::user()->notifications()->findOrFail($id);

        $this->deleteOfferingUndo(Auth::user()->notifications()->whereKey($id));
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
    }

    /**
     * Mark one notification read and open what it is about. Only the member's own are searched, so any other id is a 404.
     */
    public function open(string $id): void
    {
        $notification = Auth::user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        $this->redirect($notification->data['url'], navigate: true);
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
            <flux:text class="mt-1">{{ __('Reviews, concept proposals and target dates that need your attention.') }}</flux:text>
        </div>

        <div class="flex flex-wrap gap-2">
            @if (auth()->user()->unreadNotifications()->exists())
                <flux:button wire:click="markAllRead" data-test="mark-all-read">{{ __('Mark all read') }}</flux:button>
            @endif
            @if ($this->readCount)
                <flux:modal.trigger name="delete-read">
                    <flux:button icon="trash" data-test="delete-read">{{ __('Delete all read') }}</flux:button>
                </flux:modal.trigger>
            @endif
        </div>
    </header>

    {{-- Offered for 10 seconds; the deleted notices wait a little longer in the cache, so a late click still works --}}
    @if ($undo)
        <div wire:key="undo-{{ $undo }}" x-init="setTimeout(() => $wire.set('undo', null), 10000)" role="status" class="mt-4 flex items-center justify-between gap-3 rounded-lg bg-zinc-100 px-4 py-2" data-test="undo-delete">
            <flux:text>{{ trans_choice('{1} Notice deleted.|[2,*] :count notices deleted.', $deleted) }}</flux:text>
            <flux:button size="sm" wire:click="undoDelete">{{ __('Undo') }}</flux:button>
        </div>
    @endif

    @if ($this->notifications->isEmpty())
        <flux:text class="mt-8">{{ __('No notifications yet.') }}</flux:text>
    @else
        <ul class="divide-y divide-line">
            @foreach ($this->notifications as $notification)
                <li wire:key="{{ $notification->id }}" class="flex items-center justify-between gap-4 py-4">
                    {{-- A gold dot, as on the sidebar, marks what's unread; screen readers hear "Unread:" instead --}}
                    <div class="flex min-w-0 items-start gap-3">
                        <span @class(['mt-2 size-2 shrink-0 rounded-full', 'bg-isu-gold-700' => ! $notification->read_at]) @if (! $notification->read_at) data-test="unread-dot" @endif aria-hidden="true"></span>
                        <div class="min-w-0">
                            <flux:text @class(['font-semibold text-zinc-900' => ! $notification->read_at])>
                                @unless ($notification->read_at)
                                    <span class="sr-only">{{ __('Unread:') }}</span>
                                @endunless
                                {{ $notification->data['message'] }}
                            </flux:text>
                            <flux:text size="sm" class="mt-1">{{ $notification->created_at->diffForHumans() }}</flux:text>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-1">
                        <flux:button size="sm" wire:click="open('{{ $notification->id }}')" aria-label="{{ __('Open: :message', ['message' => $notification->data['message']]) }}">{{ __('Open') }}</flux:button>
                        <flux:dropdown position="bottom" align="end">
                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('More for: :message', ['message' => $notification->data['message']])" />
                            <flux:menu>
                                <flux:menu.item :icon="$notification->read_at ? 'envelope' : 'envelope-open'" wire:click="toggleRead('{{ $notification->id }}')">
                                    {{ $notification->read_at ? __('Mark as unread') : __('Mark as read') }}
                                </flux:menu.item>
                                <flux:menu.item icon="trash" variant="danger" wire:click="delete('{{ $notification->id }}')">{{ __('Delete') }}</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                </li>
            @endforeach
        </ul>

        @if ($this->notifications->hasPages())
            <flux:pagination :paginator="$this->notifications" class="mt-4" />
        @endif
    @endif

    <flux:modal name="delete-read" class="w-full max-w-md">
        <flux:heading size="lg">{{ trans_choice('{1} Delete 1 read notice?|[2,*] Delete :count read notices?', $this->readCount) }}</flux:heading>
        <flux:text class="mt-2">{{ __('Unread notices stay. You can undo this for a few seconds after.') }}</flux:text>
        <div class="mt-6 flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="danger" wire:click="deleteAllRead" data-test="confirm-delete-read">{{ __('Delete') }}</flux:button>
        </div>
    </flux:modal>
</section>
