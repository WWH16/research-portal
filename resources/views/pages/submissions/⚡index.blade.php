<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Submissions')] class extends Component {
    #[Computed]
    public function submissions(): Collection
    {
        return Auth::user()->submissions()
            ->with(['researchType:id,name', 'category:id,name'])
            ->latest()
            ->get();
    }
}; ?>

<section class="mx-auto w-full max-w-4xl">
    <header class="flex items-center gap-4 border-b border-line pb-6">
        <img src="{{ asset('images/isu_logo.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
        <flux:heading size="xl" level="1">{{ __('Submissions') }}</flux:heading>
        <flux:spacer />
        <flux:button :href="route('submissions.create')" variant="primary" icon="plus" wire:navigate>
            {{ __('New') }}
        </flux:button>
    </header>

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" class="mt-6" :heading="session('status')" />
    @endif

    @if ($this->submissions->isEmpty())
        <flux:text class="mt-8">{{ __('No submissions yet.') }}</flux:text>
    @else
        <flux:table class="mt-6">
            <flux:table.columns>
                <flux:table.column>{{ __('Title') }}</flux:table.column>
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column>{{ __('Category') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Submitted') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->submissions as $submission)
                    <flux:table.row :key="$submission->id">
                        <flux:table.cell variant="strong" class="max-w-xs truncate">{{ $submission->title }}</flux:table.cell>
                        <flux:table.cell>{{ $submission->researchType->name }}</flux:table.cell>
                        <flux:table.cell>{{ $submission->category->name }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" inset="top bottom" :color="match ($submission->status) { 'OK' => 'green', 'For Revision' => 'amber', default => 'zinc' }">
                                {{ $submission->status }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $submission->created_at->format('M j, Y') }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</section>
