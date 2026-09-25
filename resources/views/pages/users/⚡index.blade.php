<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Manage Users')] class extends Component {
    //
}; ?>

<section class="mx-auto w-full max-w-4xl">
    <header class="flex items-center gap-4 border-b border-line pb-6">
        <img src="{{ asset('images/isu_logo.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
        <div class="min-w-0">
            <flux:heading size="xl" level="1">{{ __('Manage Users') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Accounts, roles, and department assignments for portal members.') }}</flux:text>
        </div>
    </header>
</section>
