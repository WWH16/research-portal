<?php

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * The signed-in member's notifications, newest first. Opening one marks it read and goes where it points.
 */
new #[Title('Notifications')] class extends Component {
    use WithPagination;

    #[Computed]
    public function notifications(): LengthAwarePaginator
    {
        return Auth::user()->notifications()->paginate(20);
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
    <header class="flex items-center justify-between gap-4 border-b border-line pb-6">
        <div class="min-w-0">
            <flux:heading size="xl" level="1">{{ __('Notifications') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Reviews, concept proposals and target dates that need your attention.') }}</flux:text>
        </div>

        @if (auth()->user()->unreadNotifications()->exists())
            <flux:button wire:click="markAllRead" data-test="mark-all-read">{{ __('Mark all read') }}</flux:button>
        @endif
    </header>

    @if ($this->notifications->isEmpty())
        <flux:text class="mt-8">{{ __('No notifications yet.') }}</flux:text>
    @else
        <ul class="divide-y divide-line">
            @foreach ($this->notifications as $notification)
                <li wire:key="{{ $notification->id }}" class="flex items-center justify-between gap-4 py-4">
                    <div class="min-w-0">
                        <flux:text @class(['font-semibold text-zinc-900' => ! $notification->read_at])>
                            @unless ($notification->read_at)
                                <span class="sr-only">{{ __('Unread:') }}</span>
                            @endunless
                            {{ $notification->data['message'] }}
                        </flux:text>
                        <flux:text size="sm" class="mt-1">{{ $notification->created_at->diffForHumans() }}</flux:text>
                    </div>

                    <flux:button size="sm" wire:click="open('{{ $notification->id }}')" aria-label="{{ __('Open: :message', ['message' => $notification->data['message']]) }}">{{ __('Open') }}</flux:button>
                </li>
            @endforeach
        </ul>

        <flux:pagination :paginator="$this->notifications" class="mt-4" />
    @endif
</section>
