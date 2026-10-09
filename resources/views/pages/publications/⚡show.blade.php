<?php

use App\Models\ActivityLog;
use App\Models\Citation;
use App\Models\Publication;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * One publication: its details, total citations, citations per year, and the citing papers. Its authors add
 * and remove citing papers here; the Research Office only reads, checking each entry through its link.
 */
new class extends Component {
    public Publication $publication;

    // The co-author whose page the Research Office came from, so the breadcrumb leads back there.
    #[Url]
    public ?int $author = null;

    public string $link = '';
    public ?int $year = null;

    /** How many citing papers the list shows; Show more adds the next 20. */
    public int $shown = 20;

    public function mount(Publication $publication): void
    {
        if ($message = session('status')) {
            Flux::toast(variant: 'success', text: $message);
        }

        $this->publication = $publication->load(['faculty:id,name', 'submission:id,title,year']);
        $this->year = now()->year;
    }

    public function addCitation(): void
    {
        $this->authorize('update', $this->publication);

        $this->link = Publication::normalizeLink($this->link);

        $validated = $this->validate([
            'link' => ['required', 'url:http,https', 'max:500', Rule::unique('citations')->where('publication_id', $this->publication->id)],
            'year' => ['required', 'integer', 'between:'.$this->publication->published_on->year.','.now()->year],
        ], [
            'link.unique' => __('This paper is already listed as citing this one.'),
        ], [
            'link' => __('citing paper’s DOI or link'),
            'year' => __('citing paper’s year'),
        ]);

        $this->publication->citations()->create($validated);
        ActivityLog::record('publication.citation_added', $this->publication, ['link' => $validated['link'], 'year' => $validated['year']]);

        $this->reset('link');
        Flux::toast(variant: 'success', text: __('Citing paper added.'));
    }

    public function removeCitation(int $id): void
    {
        $this->authorize('update', $this->publication);

        // Looked up through this publication, so another paper's citing paper can't be removed from here.
        $citation = $this->publication->citations()->findOrFail($id);
        $citation->delete();
        ActivityLog::record('publication.citation_removed', $this->publication, ['link' => $citation->link, 'year' => $citation->year]);

        Flux::modal('citation-remove')->close();
        Flux::toast(variant: 'success', text: __('Citing paper removed.'));
    }

    public function showMore(): void
    {
        $this->shown += 20;
    }

    // Named per paper, so several open publications can be told apart by their tabs.
    public function render()
    {
        return $this->view()->title(str($this->publication->title)->limit(60));
    }
}; ?>

@php
    $publication = $this->publication;
    $admin = auth()->user()->isAdmin();
    $canEdit = auth()->user()->can('update', $publication);
    $total = $publication->citations()->count();
    $citations = $publication->citations()->orderByDesc('year')->orderBy('link')->limit($this->shown)->get();
    $first = $publication->published_on->year;
    $owner = $admin ? ($publication->faculty->firstWhere('id', $this->author) ?? $publication->faculty->first()) : auth()->user();
@endphp

<div class="mx-auto w-full max-w-6xl">
    <header class="grid gap-x-6 gap-y-4 border-b border-line pb-6 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
        <flux:breadcrumbs class="min-w-0 flex-wrap gap-y-1">
            @if ($admin)
                <flux:breadcrumbs.item :href="route('faculty.index')" wire:navigate>{{ __('Faculty Publications') }}</flux:breadcrumbs.item>
                @if ($owner)
                    <flux:breadcrumbs.item :href="route('faculty.show', $owner)" wire:navigate>{{ $owner->name }}</flux:breadcrumbs.item>
                @endif
            @else
                <flux:breadcrumbs.item :href="route('faculty.show', $owner)" wire:navigate>{{ __('My Publications') }}</flux:breadcrumbs.item>
            @endif
            <flux:breadcrumbs.item>{{ str($publication->title)->limit(40) }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="min-w-0 sm:col-span-2 sm:row-start-2">
            <flux:heading size="xl" level="1" class="text-balance wrap-anywhere">{{ $publication->title }}</flux:heading>
        </div>

        @if ($canEdit)
            <div class="flex gap-2 sm:col-start-2 sm:row-start-1">
                <flux:button :href="route('publications.edit', $publication)" icon="pencil-square" wire:navigate class="max-sm:h-11 max-sm:flex-1">{{ __('Edit') }}</flux:button>
            </div>
        @endif
    </header>

    {{-- A record view: each label sits in a column as wide as the longest label, with its value beside it. Every row
         spans both columns through a subgrid, so the values line up. Labels align right, against their values, and
         the longest one starts on the page's left edge. The citations total shares the grid, so "Total citations"
         lines up with the labels above it, and its value and chart with their values. --}}
    @php
        $row = 'col-span-2 grid grid-cols-subgrid';
        $label = 'text-end text-zinc-500 text-balance';
        $value = 'text-zinc-900 wrap-anywhere';
    @endphp
    <div class="mt-6 grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 text-sm sm:gap-x-8">
        <dl class="{{ $row }} gap-y-3" data-test="publication-details">
            <div class="{{ $row }}">
                <dt class="{{ $label }}">{{ __('Authors') }}</dt>
                <dd class="{{ $value }}">{{ $publication->authors }}</dd>
            </div>
            <div class="{{ $row }}">
                <dt class="{{ $label }}">{{ __('Publication date') }}</dt>
                <dd class="{{ $value }} tabular-nums">{{ $publication->published_on->format('M j, Y') }}</dd>
            </div>
            <div class="{{ $row }}">
                <dt class="{{ $label }}">{{ __('Journal') }}</dt>
                <dd class="{{ $value }}">{{ $publication->journal }}</dd>
            </div>
            @foreach (['volume' => __('Volume'), 'issue' => __('Issue'), 'pages' => __('Pages')] as $field => $name)
                @if ($publication->$field)
                    <div class="{{ $row }}">
                        <dt class="{{ $label }}">{{ $name }}</dt>
                        <dd class="{{ $value }} tabular-nums">{{ $publication->$field }}</dd>
                    </div>
                @endif
            @endforeach
            <div class="{{ $row }}">
                <dt class="{{ $label }}">{{ __('Indexed in') }}</dt>
                <dd class="{{ $value }}" data-test="indexed-in">
                    {{ collect($publication->indexed_in)->map(fn ($key) => __(Publication::INDEXES[$key] ?? $key))->join(', ') ?: __('Not indexed') }}
                </dd>
            </div>
            <div class="{{ $row }}">
                <dt class="{{ $label }}">{{ __('DOI or link') }}</dt>
                <dd class="{{ $value }}"><flux:link :href="$publication->link" target="_blank" rel="noopener noreferrer">{{ $publication->link }}<span class="sr-only"> {{ __('(opens in a new tab)') }}</span></flux:link></dd>
            </div>
            @if ($publication->submission)
                <div class="{{ $row }}">
                    <dt class="{{ $label }}">{{ __('Research project') }}</dt>
                    <dd class="{{ $value }}">
                        @can('view', $publication->submission)
                            <flux:link :href="route('drive.show', $publication->submission)" wire:navigate>{{ $publication->submission->title }}</flux:link>
                        @else
                            {{ $publication->submission->title }}
                        @endcan
                    </dd>
                </div>
            @endif
            @if ($publication->description)
                <div class="{{ $row }}">
                    <dt class="{{ $label }}">{{ __('Description') }}</dt>
                    <dd class="{{ $value }} max-w-prose whitespace-pre-line">{{ $publication->description }}</dd>
                </div>
            @endif
        </dl>

        <section class="{{ $row }} mt-10 items-baseline" aria-labelledby="cited-heading">
            <h2 id="cited-heading" class="{{ $label }}">{{ __('Total citations') }}</h2>
            <p class="text-4xl font-semibold tabular-nums text-isu-green-700" data-test="cited-by">{{ number_format($total) }}</p>
            {{-- No chart for a paper nobody has cited yet: a total of 0 already says so --}}
            @if ($total)
                <div class="col-start-2 mt-3">
                    @include('partials.year-chart', ['years' => Citation::perYear($publication->citations(), $first), 'since' => $first, 'compact' => true])
                </div>
            @endif
        </section>
    </div>

    @if ($canEdit || $total)
        <section class="mt-10" aria-labelledby="citing-heading">
            {{-- Focusable from script only: focus lands here after a citing paper is removed --}}
            <flux:heading level="2" id="citing-heading" tabindex="-1" class="outline-none">{{ __('Citing papers') }}</flux:heading>

            @if ($canEdit)
                <form wire:submit="addCitation" class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_10rem_auto] sm:items-start" data-test="add-citation-form">
                    <flux:input wire:model="link" :label="__('Citing paper’s DOI or link')" type="text" inputmode="url" autocapitalize="off" autocomplete="off" spellcheck="false" maxlength="500" required class:input="max-sm:h-11" />
                    <flux:select wire:model="year" :label="__('Citing paper’s year')" class="max-sm:h-11">
                        @foreach (range(now()->year, $first) as $option)
                            <flux:select.option :value="$option">{{ $option }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button type="submit" variant="primary" icon="plus" class="sm:mt-6 max-sm:h-11">{{ __('Add citing paper') }}</flux:button>
                </form>
            @endif

            @if ($citations->isNotEmpty())
                {{-- One remove dialog for the whole list, filled in the browser, so a long list doesn't carry a dialog per row --}}
                <div x-data="{ removing: { id: null, link: '' } }">
                    <ul class="mt-4 divide-y divide-line border-y border-line" data-test="citations">
                        @foreach ($citations as $citation)
                            <li class="flex items-center gap-4 py-3" wire:key="citation-{{ $citation->id }}">
                                <span class="w-12 shrink-0 text-sm tabular-nums text-zinc-500">{{ $citation->year }}</span>
                                <flux:link :href="$citation->link" target="_blank" rel="noopener noreferrer" class="min-w-0 flex-1 text-sm wrap-anywhere">{{ $citation->link }}<span class="sr-only"> {{ __('(opens in a new tab)') }}</span></flux:link>
                                @if ($canEdit)
                                    <flux:button size="sm" variant="ghost" icon="trash" :aria-label="__('Remove citing paper')" class="max-sm:size-11"
                                        x-on:click="removing = { id: {{ $citation->id }}, link: {{ Js::from($citation->link) }} }; $dispatch('modal-show', { name: 'citation-remove' })" />
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    @if ($total > $citations->count())
                        <div class="flex flex-wrap items-center justify-between gap-3 py-3">
                            <flux:text class="tabular-nums" aria-live="polite">{{ __('Showing :shown of :total', ['shown' => number_format($citations->count()), 'total' => number_format($total)]) }}</flux:text>
                            {{-- Focus moves to the first newly shown paper, so keyboard users carry on reading there instead of
                                 landing back at the top when this button disappears --}}
                            <flux:button size="sm" icon="chevron-down" class="max-sm:h-11 max-sm:w-full" data-test="show-more-citations"
                                wire:loading.attr="disabled" wire:target="showMore"
                                x-on:click="$wire.showMore().then(() => document.querySelectorAll('[data-test=citations] a')[{{ $citations->count() }}]?.focus())">{{ __('Show more') }}</flux:button>
                        </div>
                    @endif

                    @if ($canEdit)
                        <flux:modal name="citation-remove" class="w-full sm:w-96">
                            <div class="flex flex-col gap-6">
                                <div>
                                    <flux:heading size="lg">{{ __('Remove this citing paper?') }}</flux:heading>
                                    <flux:text class="mt-2 wrap-anywhere" x-text="removing.link"></flux:text>
                                </div>
                                <div class="flex justify-end gap-2">
                                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                                    {{-- Disabled while the request runs, so a double click can't remove twice. Its row is gone afterwards, so focus moves to the heading --}}
                                    <flux:button variant="danger" wire:loading.attr="disabled" wire:target="removeCitation"
                                        x-on:click="$wire.removeCitation(removing.id).then(() => document.getElementById('citing-heading')?.focus())">{{ __('Remove') }}</flux:button>
                                </div>
                            </div>
                        </flux:modal>
                    @endif
                </div>
            @endif
        </section>
    @endif
</div>
