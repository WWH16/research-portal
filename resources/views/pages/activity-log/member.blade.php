{{-- Who did it. A member's name is a quiet link that narrows the log to them; the tooltip says so on hover and focus. --}}
<div class="flex min-w-0 items-center gap-2">
    <flux:avatar size="xs" :src="$log->user?->profileImageUrl()" :name="$log->actor_name ?? __('Deleted account')" class="shrink-0" />
    @if ($log->user_id)
        <flux:tooltip :content="__('Show only :name’s activity', ['name' => $log->actor_name])" class="min-w-0">
            <flux:link as="button" :accent="false" wire:click="$set('user', {{ $log->user_id }})" class="block max-w-full truncate text-start font-medium underline-offset-2! max-sm:min-h-11">{{ $log->actor_name }}</flux:link>
        </flux:tooltip>
    @else
        <span class="truncate font-medium text-zinc-800">{{ $log->actor_name ?? __('Deleted account') }}</span>
    @endif
</div>
