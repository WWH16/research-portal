{{-- Who did it. --}}
<div class="flex min-w-0 items-center gap-2">
    <flux:avatar size="xs" :src="$log->user?->profileImageUrl()" :name="$log->actor_name ?? __('Deleted account')" class="shrink-0" />
    @if ($log->user_id)
        <button type="button" wire:click="$set('user', {{ $log->user_id }})" class="truncate font-medium text-zinc-800 hover:underline" title="{{ __('Show only :name’s activity', ['name' => $log->actor_name]) }}">{{ $log->actor_name }}</button>
    @else
        <span class="truncate font-medium text-zinc-800">{{ $log->actor_name ?? __('Deleted account') }}</span>
    @endif
</div>
