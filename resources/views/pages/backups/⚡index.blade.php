<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Backup and Restore')] class extends Component {
    //
}; ?>

<section class="mx-auto w-full max-w-4xl">
    <header class="flex items-center gap-4 border-b border-line pb-6">
        <img src="{{ asset('images/isu_logo.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
        <div class="min-w-0">
            <flux:heading size="xl" level="1">{{ __('Backup and Restore') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Create backups of portal data and restore from a previous backup.') }}</flux:text>
        </div>
    </header>
</section>
