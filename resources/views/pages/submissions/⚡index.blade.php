<?php

use App\Models\Submission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Submissions')] class extends Component {
    /**
     * Admins monitor every proposal in the portal; faculty see only their own.
     */
    #[Computed]
    public function monitoring(): bool
    {
        return Auth::user()->isAdmin();
    }

    #[Computed]
    public function submissions(): Collection
    {
        $query = $this->monitoring
            ? Submission::query()->with(['user:id,name', 'department:id,code,name'])
            : Auth::user()->submissions();

        return $query
            ->with(['researchType:id,name', 'category:id,name'])
            ->latest()
            ->get();
    }
}; ?>

<section class="mx-auto w-full {{ $this->monitoring ? 'max-w-5xl' : 'max-w-4xl' }}">
    <header class="flex items-center gap-4 border-b border-line pb-6">
        <img src="{{ asset('images/isu_seal.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
        <div class="min-w-0 flex-1">
            <flux:heading size="xl" level="1">{{ __('Submissions') }}</flux:heading>
            <flux:text class="mt-1">
                {{ $this->monitoring ? __('Every proposal filed across the portal.') : __('Proposals you have submitted.') }}
            </flux:text>
        </div>

        @unless ($this->monitoring)
            <flux:button :href="route('submissions.create')" variant="primary" icon="plus" class="shrink-0" wire:navigate>
                {{ __('New') }}
            </flux:button>
        @endunless
    </header>

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" class="mt-6" :heading="session('status')" />
    @endif

    @if ($this->submissions->isEmpty())
        <flux:text class="mt-8">
            {{ $this->monitoring ? __('No proposals have been submitted yet.') : __('No submissions yet.') }}
        </flux:text>
    @else
        <flux:table class="mt-6">
            <flux:table.columns>
                <flux:table.column>{{ __('Title') }}</flux:table.column>
                @if ($this->monitoring)
                    <flux:table.column>{{ __('Department') }}</flux:table.column>
                @endif
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column>{{ __('Category') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Submitted') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->submissions as $submission)
                    <flux:table.row :key="$submission->id">
                        <flux:table.cell class="max-w-xs">
                            <div class="truncate font-medium text-zinc-800">{{ $submission->title }}</div>
                            @if ($this->monitoring)
                                <div class="truncate text-zinc-500">{{ $submission->user->name }}</div>
                            @endif
                        </flux:table.cell>
                        @if ($this->monitoring)
                            <flux:table.cell>
                                <span title="{{ $submission->department->name }}">{{ $submission->department->code }}</span>
                            </flux:table.cell>
                        @endif
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
