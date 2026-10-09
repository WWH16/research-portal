<?php

use App\Models\Citation;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * A faculty member's publications profile: their papers with each one's cited-by count, the total, and
 * citations per year across every paper. The route lets in only the member and the Research Office.
 */
new #[Title('Publications')] class extends Component {
    public User $user;

    public function mount(User $user): void
    {
        // Admins record no publications, so only faculty accounts have a profile.
        abort_if($user->isAdmin(), 404);

        // A deleted publication comes back here with a confirmation.
        if ($message = session('status')) {
            Flux::toast(variant: 'success', text: $message);
        }

        $this->user = $user->load('department:id,code');
    }

    #[Computed]
    public function publications(): Collection
    {
        return $this->user->publications()->withCount('citations')->orderByDesc('published_on')->orderBy('title')->get();
    }
}; ?>

@php
    $mine = auth()->user()->is($user);
    $first = $this->publications->min(fn ($publication) => $publication->published_on->year);
@endphp

<section class="mx-auto w-full max-w-4xl">
    <header class="grid gap-x-6 gap-y-4 border-b border-line pb-6 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
        @if ($mine)
            <flux:heading size="xl" level="1" class="sm:row-start-1">{{ __('My Publications') }}</flux:heading>
        @else
            <flux:breadcrumbs class="min-w-0 flex-wrap gap-y-1">
                <flux:breadcrumbs.item :href="route('faculty.index')" wire:navigate>{{ __('Faculty Publications') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ $user->name }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
        @endif

        <div class="flex min-w-0 items-center gap-4 sm:col-span-2 sm:row-start-2">
            <flux:avatar size="lg" :src="$user->profileImageUrl()" :name="$user->name" :initials="$user->initials()" />
            <div class="min-w-0">
                @if ($mine)
                    <p class="truncate font-medium text-zinc-800">{{ $user->name }}</p>
                @else
                    <flux:heading size="xl" level="1" class="truncate">{{ $user->name }}</flux:heading>
                @endif
                <flux:text class="tabular-nums">{{ collect([$user->department?->code ?? __('No college'), $user->researcher_id])->filter()->join(' · ') }}</flux:text>
            </div>
        </div>

        @if ($mine)
            <div class="flex gap-2 sm:col-start-2 sm:row-start-1">
                <flux:button :href="route('publications.create')" variant="primary" icon="plus" wire:navigate class="max-sm:h-11 max-sm:flex-1" data-test="add-publication-button">{{ __('Add publication') }}</flux:button>
            </div>
        @endif
    </header>

    <dl class="mt-6 grid grid-cols-2 gap-4" data-test="profile-totals">
        <div class="rounded-xl border border-line bg-surface p-5">
            <dt class="text-sm text-zinc-600">{{ __('Publications') }}</dt>
            <dd class="mt-2 text-3xl font-semibold tabular-nums text-zinc-900" data-test="publication-total">{{ number_format($this->publications->count()) }}</dd>
        </div>
        <div class="rounded-xl border border-line bg-surface p-5">
            <dt class="text-sm text-zinc-600">{{ __('Total citations') }}</dt>
            <dd class="mt-2 text-3xl font-semibold tabular-nums text-zinc-900" data-test="citation-total">{{ number_format($this->publications->sum('citations_count')) }}</dd>
            <dd class="mt-1 text-sm text-zinc-500">{{ __('Times other papers cited yours') }}</dd>
        </div>
    </dl>

    @if ($first)
        <div class="mt-6">
            @include('partials.year-chart', ['years' => Citation::perYear(Citation::whereIn('publication_id', $this->publications->modelKeys()), $first), 'since' => $first, 'heading' => __('Citations per year')])
        </div>
    @endif

    <section class="mt-10" aria-labelledby="publications-heading">
        <flux:heading level="2" id="publications-heading">{{ __('Publications') }}</flux:heading>

        @if ($this->publications->isEmpty())
            <div class="mt-3 rounded-xl border border-dashed border-line px-6 py-12 text-center" data-test="no-publications">
                <flux:heading>{{ __('No publications yet') }}</flux:heading>
                @if ($mine)
                    <flux:text class="mt-2">{{ __('Add each paper you’ve published, then the papers that cite it.') }}</flux:text>
                    <flux:button :href="route('publications.create')" variant="primary" icon="plus" size="sm" wire:navigate class="mt-4">{{ __('Add publication') }}</flux:button>
                @endif
            </div>
        @else
            <flux:table class="mt-3 [&_td]:align-top" data-test="publications">
                <flux:table.columns>
                    <flux:table.column>{{ __('Title') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Year') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Cited by') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->publications as $publication)
                        <flux:table.row :key="$publication->id">
                            <flux:table.cell class="min-w-56 whitespace-normal">
                                <a href="{{ route('publications.show', $publication) }}" wire:navigate class="font-medium text-zinc-800 wrap-anywhere hover:underline">{{ $publication->title }}</a>
                                <p class="mt-0.5 text-zinc-500 wrap-anywhere">{{ $publication->authors }}</p>
                                <p class="text-zinc-500 wrap-anywhere">{{ $publication->journal }}</p>
                            </flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $publication->published_on->year }}</flux:table.cell>
                            <flux:table.cell align="end" variant="strong" class="tabular-nums">{{ number_format($publication->citations_count) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </section>
</section>
