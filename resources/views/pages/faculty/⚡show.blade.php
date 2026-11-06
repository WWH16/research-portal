<?php

use App\Models\Citation;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * A faculty member's publications profile: their papers with each one's cited-by count, the total, and
 * citations per year across every paper. The route lets in only the member and the Research Office.
 */
new class extends Component {
    public User $user;

    /** How many papers the list shows; Show more adds the next 20. */
    public int $shown = 20;

    /** Cited by or Year, as on a Google Scholar profile. */
    #[Url(as: 'sort', except: 'cited')]
    public string $sortBy = 'cited';

    #[Url(as: 'direction', except: 'desc')]
    public string $sortDirection = 'desc';

    public function mount(User $user): void
    {
        // Only faculty record publications, so only faculty accounts have a profile.
        abort_unless($user->isFaculty(), 404);

        // A deleted publication comes back here with a confirmation.
        if ($message = session('status')) {
            Flux::toast(variant: 'success', text: $message);
        }

        $this->user = $user->load('department:id,name');
    }

    #[Computed]
    public function publications(): Collection
    {
        $query = $this->user->publications()->withCount('citations');
        $direction = $this->sortDirection === 'asc' ? 'asc' : 'desc';

        // By year, newest first; otherwise most-cited first, ties going to the newer paper. Anything else in the address sorts by citations.
        $this->sortBy === 'year'
            ? $query->orderBy('published_on', $direction)
            : $query->orderBy('citations_count', $direction)->orderByDesc('published_on');

        return $query->orderBy('title')->limit($this->shown)->get();
    }

    /**
     * Either column opens with the most cited or the newest; a second click on the same column flips it.
     */
    public function sort(string $column): void
    {
        $this->sortDirection = $this->sortBy === $column && $this->sortDirection === 'desc' ? 'asc' : 'desc';
        $this->sortBy = $column;
    }

    public function showMore(): void
    {
        $this->shown += 20;
    }

    /**
     * Every paper's id, not just this page's, so the totals and the chart cover them all.
     */
    #[Computed]
    public function paperIds(): Collection
    {
        return $this->user->publications()->pluck('publications.id');
    }

    // Named per member, so an admin with several profiles open can tell the tabs apart.
    public function render()
    {
        $mine = $this->user->is(auth()->user());

        return $this->view(['mine' => $mine])->title($mine ? __('My Publications') : __(':name’s publications', ['name' => $this->user->name]));
    }
}; ?>

@php
    $earliest = $user->publications()->min('published_on');
    $first = $earliest ? (int) substr($earliest, 0, 4) : null;
    $cited = $first ? Citation::whereIn('publication_id', $this->paperIds)->count() : 0;
@endphp

<section class="mx-auto w-full max-w-6xl">
    <header class="grid gap-x-6 gap-y-4 border-b border-line pb-6 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
        @if ($mine)
            {{-- For screen readers only: the sidebar already marks the page, and the member's name leads it --}}
            <flux:heading level="1" class="sr-only">{{ __('My Publications') }}</flux:heading>

            {{-- On phones it comes before the summary rather than under it. An empty profile leaves it to the empty
                 list's own button, so the page offers one, not two. --}}
            @if ($first)
                <flux:button :href="route('publications.create')" variant="primary" icon="plus" wire:navigate class="max-sm:h-11 sm:col-start-2 sm:row-start-1" data-test="add-publication-button">{{ __('Add publication') }}</flux:button>
            @endif
        @else
            <flux:breadcrumbs class="min-w-0 flex-wrap gap-y-1">
                <flux:breadcrumbs.item :href="route('faculty.index')" wire:navigate>{{ __('Faculty Publications') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ $user->name }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
        @endif

        {{-- The member and their totals read as one summary row: under the breadcrumb on a colleague's page, beside
             the Add button on the member's own --}}
        <div @class(['flex flex-wrap items-center gap-x-8 gap-y-4', 'sm:col-span-2 sm:row-start-2' => ! $mine])>
            <div class="flex min-w-0 items-center gap-5 sm:gap-6">
                {{-- The page is the member's record of their work, so their portrait leads it. Hidden from
                     screen readers: the name beside it already says whose it is. --}}
                <flux:avatar
                    aria-hidden="true"
                    circle
                    :src="$user->profileImageUrl()"
                    :name="$user->name"
                    :initials="$user->initials()"
                    class="size-24 text-3xl sm:size-32 sm:text-4xl"
                />
                {{-- A long name wraps rather than cutting off beside the portrait on phones --}}
                <div class="min-w-0">
                    @if ($mine)
                        <p class="text-2xl font-semibold text-balance text-zinc-900">{{ $user->name }}</p>
                    @else
                        <flux:heading size="xl" level="1" class="text-balance">{{ $user->name }}</flux:heading>
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
                        <dt class="flex items-center gap-1.5 text-sm text-zinc-600"><flux:icon.document-text variant="micro" class="text-isu-green-600" />{{ __('Publications') }}</dt>
                        <dd class="text-2xl font-semibold tabular-nums text-zinc-900" data-test="publication-total">{{ number_format($this->paperIds->count()) }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-sm text-zinc-600"><flux:icon.link variant="micro" class="text-isu-green-600" />{{ __('Total citations') }}</dt>
                        <dd class="text-2xl font-semibold tabular-nums text-zinc-900" data-test="citation-total">{{ number_format($cited) }}</dd>
                    </div>
                </dl>
            @endif
        </div>
    </header>

    {{-- The list leads; on wide screens the chart rides beside it and stays in view while a long list scrolls.
         The list's panel ends with its last paper, so one or two papers don't sit in a mostly empty box. Papers
         nobody has cited yet get no chart: the total of 0 already says so, as on the publication page. --}}
    <div @class(['mt-6 grid items-start gap-8', 'xl:grid-cols-[minmax(0,1fr)_20rem]' => $cited])>
        <section aria-label="{{ __('Publications') }}">
            @if ($this->publications->isEmpty())
                <x-empty-state icon="book-open" :heading="__('No publications yet')" :text="$mine ? __('Add each paper you’ve published, then the papers that cite it.') : __('The publications this faculty member records will appear here.')" data-test="no-publications">
                    @if ($mine)
                        <flux:button :href="route('publications.create')" variant="primary" icon="plus" class="max-sm:h-11" wire:navigate>{{ __('Add publication') }}</flux:button>
                    @endif
                </x-empty-state>
            @else
                {{-- The table bleeds to the panel's edges, so a hovered row lights up its full width --}}
                <div class="rounded-xl border border-line bg-surface px-(--flux-bleed) pt-3 pb-2 [--flux-bleed:--spacing(4)] sm:[--flux-bleed:--spacing(6)]">
                    {{-- Each sortable header says which way it sorts, for screen readers as well as by its arrow. Anything
                         but Year sorts by citations, so Cited by shows as sorted then. --}}
                    @php($ariaDirection = $sortDirection === 'asc' ? 'ascending' : 'descending')
                    <flux:table bleed class="[&_td]:align-top" data-test="publications">
                        <flux:table.columns>
                            <flux:table.column>{{ __('Title') }}</flux:table.column>
                            <flux:table.column align="end" sortable :sorted="$sortBy !== 'year'" :direction="$sortDirection" :aria-sort="$sortBy !== 'year' ? $ariaDirection : 'none'" wire:click="sort('cited')">{{ __('Cited by') }}</flux:table.column>
                            <flux:table.column align="end" sortable :sorted="$sortBy === 'year'" :direction="$sortDirection" :aria-sort="$sortBy === 'year' ? $ariaDirection : 'none'" wire:click="sort('year')">{{ __('Year') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($this->publications as $publication)
                                {{-- The title link stretches over the whole row, so the row is one big tap target.
                                     Keyboard focus shades the row the same as hover. --}}
                                <flux:table.row :key="$publication->id" class="relative hover:bg-canvas has-[a:focus-visible]:bg-canvas">
                                    {{-- No floor on phones, so the title wraps and the count and year stay on screen --}}
                                    <flux:table.cell class="whitespace-normal sm:min-w-56">
                                        <a href="{{ route('publications.show', $mine ? $publication : [$publication, 'author' => $user->id]) }}" wire:navigate class="font-semibold text-zinc-800 wrap-anywhere after:absolute after:inset-0 hover:underline">{{ $publication->title }}</a>
                                        <p class="mt-1 text-zinc-500 wrap-anywhere">{{ $publication->shortAuthors() }}</p>
                                        <p class="text-zinc-500 italic wrap-anywhere">{{ $publication->journal }}</p>
                                    </flux:table.cell>
                                    <flux:table.cell align="end" variant="strong" class="tabular-nums">{{ number_format($publication->citations_count) }}</flux:table.cell>
                                    <flux:table.cell align="end" class="tabular-nums">{{ $publication->published_on->year }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>

                    @if ($this->paperIds->count() > $this->publications->count())
                        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line py-3">
                            <flux:text class="tabular-nums" aria-live="polite">{{ __('Showing :shown of :total', ['shown' => number_format($this->publications->count()), 'total' => number_format($this->paperIds->count())]) }}</flux:text>
                            {{-- Focus moves to the first newly shown paper, so keyboard users carry on reading there instead of
                                 landing back at the top when this button disappears --}}
                            <flux:button size="sm" icon="chevron-down" class="max-sm:h-11 max-sm:w-full" data-test="show-more-publications"
                                wire:loading.attr="disabled" wire:target="showMore"
                                x-on:click="$wire.showMore().then(() => document.querySelectorAll('[data-test=publications] a')[{{ $this->publications->count() }}]?.focus())">{{ __('Show more') }}</flux:button>
                        </div>
                    @endif
                </div>
            @endif
        </section>

        @if ($cited)
            <aside class="xl:sticky xl:top-8 xl:self-start">
                @include('partials.year-chart', ['years' => Citation::perYear(Citation::whereIn('publication_id', $this->paperIds), $first), 'since' => $first, 'heading' => __('Citations per year')])
            </aside>
        @endif
    </div>
</section>
