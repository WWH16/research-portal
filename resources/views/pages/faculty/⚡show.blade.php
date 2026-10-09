<?php

use App\Models\Citation;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/*
 * A faculty member's publications profile: their papers with each one's cited-by count, the total, and
 * citations per year across every paper. The route lets in only the member and the Research Office.
 */
new class extends Component {
    public User $user;

    public function mount(User $user): void
    {
        // Admins record no publications, so only faculty accounts have a profile.
        abort_if($user->isAdmin(), 404);

        // A deleted publication comes back here with a confirmation.
        if ($message = session('status')) {
            Flux::toast(variant: 'success', text: $message);
        }

        $this->user = $user->load('department:id,name');
    }

    #[Computed]
    public function publications(): Collection
    {
        // Most-cited first; ties go to the newer paper.
        return $this->user->publications()->withCount('citations')->orderByDesc('citations_count')->orderByDesc('published_on')->orderBy('title')->get();
    }

    // Named per member, so an admin with several profiles open can tell the tabs apart.
    public function render()
    {
        $mine = $this->user->is(auth()->user());

        return $this->view(['mine' => $mine])->title($mine ? __('My Publications') : __(':name’s publications', ['name' => $this->user->name]));
    }
}; ?>

@php
    $first = $this->publications->min(fn ($publication) => $publication->published_on->year);
@endphp

<section class="mx-auto w-full max-w-6xl">
    <header class="grid gap-x-6 gap-y-4 border-b border-line pb-6 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
        @if ($mine)
            <flux:heading size="xl" level="1" class="sm:row-start-1">{{ __('My Publications') }}</flux:heading>

            {{-- Straight after the title, so on phones it comes before the summary rather than under it --}}
            <flux:button :href="route('publications.create')" variant="primary" icon="plus" wire:navigate class="max-sm:h-11 sm:col-start-2 sm:row-start-1" data-test="add-publication-button">{{ __('Add publication') }}</flux:button>
        @else
            <flux:breadcrumbs class="min-w-0 flex-wrap gap-y-1">
                <flux:breadcrumbs.item :href="route('faculty.index')" wire:navigate>{{ __('Faculty Publications') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ $user->name }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
        @endif

        {{-- The member and their totals read as one summary row under the title --}}
        <div class="flex flex-wrap items-center gap-x-8 gap-y-4 sm:col-span-2 sm:row-start-2">
            <div class="flex min-w-0 items-center gap-4">
                <flux:avatar size="lg" :src="$user->profileImageUrl()" :name="$user->name" :initials="$user->initials()" />
                <div class="min-w-0">
                    @if ($mine)
                        <p class="truncate font-medium text-zinc-800">{{ $user->name }}</p>
                    @else
                        <flux:heading size="xl" level="1" class="truncate">{{ $user->name }}</flux:heading>
                    @endif
                    {{-- The college's full name, so nobody has to decode the short code --}}
                    <flux:text>{{ $user->department?->name ?? __('No college') }}</flux:text>
                    @if ($user->researcher_id)
                        <flux:text class="tabular-nums">{{ __('Researcher ID: :id', ['id' => $user->researcher_id]) }}</flux:text>
                    @endif
                </div>
            </div>

            @if ($first)
                <dl class="flex shrink-0 gap-8 sm:border-s sm:border-line sm:ps-8" data-test="profile-totals">
                    <div>
                        <dt class="text-sm text-zinc-600">{{ __('Publications') }}</dt>
                        <dd class="text-2xl font-semibold tabular-nums text-zinc-900" data-test="publication-total">{{ number_format($this->publications->count()) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-zinc-600">{{ __('Total citations') }}</dt>
                        <dd class="text-2xl font-semibold tabular-nums text-zinc-900" data-test="citation-total">{{ number_format($this->publications->sum('citations_count')) }}</dd>
                    </div>
                </dl>
            @endif
        </div>
    </header>

    {{-- The list leads; on wide screens the chart rides beside it and stays in view while a long list scrolls.
         The list's panel stretches to the chart's height, so a short list still ends level with it. --}}
    <div @class(['mt-6 grid gap-8', 'xl:grid-cols-[minmax(0,1fr)_20rem]' => $first])>
        <section aria-label="{{ __('Publications') }}">
            @if ($this->publications->isEmpty())
                <div class="rounded-xl border border-dashed border-line px-6 py-12 text-center" data-test="no-publications">
                    <flux:heading>{{ __('No publications yet') }}</flux:heading>
                    @if ($mine)
                        <flux:text class="mt-2">{{ __('Add each paper you’ve published, then the papers that cite it.') }}</flux:text>
                        <flux:button :href="route('publications.create')" variant="primary" icon="plus" size="sm" wire:navigate class="mt-4">{{ __('Add publication') }}</flux:button>
                    @endif
                </div>
            @else
                {{-- The table bleeds to the panel's edges, so a hovered row lights up its full width --}}
                <div class="h-full rounded-xl border border-line bg-surface px-(--flux-bleed) py-2 [--flux-bleed:--spacing(4)] sm:[--flux-bleed:--spacing(6)]">
                    <flux:table bleed class="[&_td]:py-4 [&_td]:align-top" data-test="publications">
                        <flux:table.columns>
                            <flux:table.column>{{ __('Title') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Cited by') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Year') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($this->publications as $publication)
                                {{-- The title link stretches over the whole row, so the row is one big tap target --}}
                                <flux:table.row :key="$publication->id" class="relative hover:bg-canvas">
                                    {{-- No floor on phones, so the title wraps and the count and year stay on screen --}}
                                    <flux:table.cell class="whitespace-normal sm:min-w-56">
                                        <a href="{{ route('publications.show', $publication) }}" wire:navigate class="font-medium text-zinc-800 wrap-anywhere after:absolute after:inset-0 hover:underline">{{ $publication->title }}</a>
                                        <p class="mt-0.5 text-zinc-600 wrap-anywhere">{{ $publication->authors }}</p>
                                        <p class="text-zinc-600 wrap-anywhere">{{ $publication->journal }}</p>
                                    </flux:table.cell>
                                    <flux:table.cell align="end" variant="strong" class="tabular-nums">{{ number_format($publication->citations_count) }}</flux:table.cell>
                                    <flux:table.cell align="end" class="tabular-nums">{{ $publication->published_on->year }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif
        </section>

        @if ($first)
            <aside class="xl:sticky xl:top-8 xl:self-start">
                @include('partials.year-chart', ['years' => Citation::perYear(Citation::whereIn('publication_id', $this->publications->modelKeys()), $first), 'since' => $first, 'heading' => __('Citations per year')])
            </aside>
        @endif
    </div>
</section>
