<?php

use App\Models\ActivityLog;
use App\Models\Citation;
use App\Models\Publication;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * One publication: its details, "Cited by N", citations per year, and the citing papers. Its authors add
 * and remove citing papers here; the Research Office only reads, checking each entry through its link.
 */
new #[Title('Publications')] class extends Component {
    public Publication $publication;

    public string $link = '';
    public ?int $year = null;

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
            'year' => __('year cited'),
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
}; ?>

@php
    $publication = $this->publication;
    $admin = auth()->user()->isAdmin();
    $canEdit = auth()->user()->can('update', $publication);
    $citations = $publication->citations()->orderByDesc('year')->orderBy('link')->get();
    $first = $publication->published_on->year;
    $owner = $admin ? $publication->faculty->first() : auth()->user();
@endphp

<section class="mx-auto w-full max-w-4xl">
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
            <flux:text class="mt-2 wrap-anywhere">{{ $publication->authors }}</flux:text>
        </div>

        @if ($canEdit)
            <div class="flex gap-2 sm:col-start-2 sm:row-start-1">
                <flux:button :href="route('publications.edit', $publication)" icon="pencil-square" wire:navigate class="max-sm:h-11 max-sm:flex-1">{{ __('Edit') }}</flux:button>
            </div>
        @endif
    </header>

    <dl class="mt-6 grid grid-cols-2 gap-x-6 gap-y-4 text-sm sm:grid-cols-3">
        <div class="col-span-2">
            <dt class="text-zinc-500">{{ __('Journal') }}</dt>
            <dd class="mt-1 font-medium text-zinc-800 wrap-anywhere">
                {{ $publication->journal }}{{ collect([
                    $publication->volume ? __('Vol. :volume', ['volume' => $publication->volume]) : null,
                    $publication->issue ? __('No. :issue', ['issue' => $publication->issue]) : null,
                    $publication->pages ? __('pp. :pages', ['pages' => $publication->pages]) : null,
                ])->filter()->map(fn ($part) => ', '.$part)->join('') }}
            </dd>
        </div>
        <div>
            <dt class="text-zinc-500">{{ __('Date published') }}</dt>
            <dd class="mt-1 font-medium tabular-nums text-zinc-800">{{ $publication->published_on->format('M j, Y') }}</dd>
        </div>
        <div class="col-span-2 sm:col-span-3">
            <dt class="text-zinc-500">{{ __('DOI or link') }}</dt>
            <dd class="mt-1 wrap-anywhere"><flux:link :href="$publication->link" target="_blank" rel="noopener noreferrer">{{ $publication->link }}</flux:link></dd>
        </div>
        <div class="col-span-2">
            <dt class="text-zinc-500">{{ __('Authors with a portal account') }}</dt>
            <dd class="mt-1 font-medium text-zinc-800">{{ $publication->faculty->pluck('name')->join(', ') }}</dd>
        </div>
        @if ($publication->submission)
            <div>
                <dt class="text-zinc-500">{{ __('Research project') }}</dt>
                <dd class="mt-1 font-medium text-zinc-800 wrap-anywhere">
                    @can('view', $publication->submission)
                        <flux:link :href="route('drive.show', $publication->submission)" wire:navigate>{{ $publication->submission->title }}</flux:link>
                    @else
                        {{ $publication->submission->title }}
                    @endcan
                </dd>
            </div>
        @endif
    </dl>

    @if ($publication->description)
        <flux:text class="mt-6 whitespace-pre-line">{{ $publication->description }}</flux:text>
    @endif

    <section class="mt-10" aria-labelledby="cited-heading">
        <p id="cited-heading" class="text-3xl font-semibold text-zinc-900" data-test="cited-by">{{ __('Cited by :count', ['count' => number_format($citations->count())]) }}</p>
        <flux:text class="mt-1">{{ trans_choice('{0} No other paper lists this one in its references yet.|{1} 1 other paper lists this one in its references.|[2,*] :count other papers list this one in their references.', $citations->count()) }}</flux:text>
        <div class="mt-4">
            @include('partials.year-chart', ['years' => Citation::perYear($publication->citations(), $first), 'since' => $first, 'heading' => __('Citations per year')])
        </div>
    </section>

    <section class="mt-10" aria-labelledby="citing-heading">
        <flux:heading level="2" id="citing-heading">{{ __('Citing papers') }}</flux:heading>
        @if ($canEdit)
            <flux:text class="mt-1">{{ __('A citing paper is a later paper that lists yours in its references. Find them on Google Scholar under “Cited by”, or in the journal’s own list, and add each one with the year it was published.') }}</flux:text>
        @endif

        @if ($canEdit)
            <form wire:submit="addCitation" class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_8rem_auto] sm:items-start" data-test="add-citation-form">
                <flux:input wire:model="link" :label="__('Citing paper’s DOI or link')" type="text" maxlength="500" required />
                <flux:select wire:model="year" :label="__('Year cited')" :description:trailing="__('The year the citing paper was published.')">
                    @foreach (range(now()->year, $first) as $option)
                        <flux:select.option :value="$option">{{ $option }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:button type="submit" variant="primary" icon="plus" class="sm:mt-6 max-sm:h-11">{{ __('Add citing paper') }}</flux:button>
            </form>
        @endif

        @if ($citations->isEmpty())
            <flux:text class="mt-3 text-sm">
                {{ $canEdit ? __('No citing papers yet. Add each paper that cites this one, with its DOI or link and the year it was published.') : __('No citing papers yet.') }}
            </flux:text>
        @else
            {{-- One remove dialog for the whole list, filled in the browser, so a long list doesn't carry a dialog per row --}}
            <div x-data="{ removing: { id: null, link: '' } }">
                <ul class="mt-4 divide-y divide-line border-y border-line" data-test="citations">
                    @foreach ($citations as $citation)
                        <li class="flex items-center gap-4 py-3" wire:key="citation-{{ $citation->id }}">
                            <span class="w-12 shrink-0 text-sm tabular-nums text-zinc-500">{{ $citation->year }}</span>
                            <flux:link :href="$citation->link" target="_blank" rel="noopener noreferrer" class="min-w-0 flex-1 text-sm wrap-anywhere">{{ $citation->link }}</flux:link>
                            @if ($canEdit)
                                <flux:button size="sm" variant="ghost" icon="trash" :aria-label="__('Remove citing paper')" class="max-sm:size-11"
                                    x-on:click="removing = { id: {{ $citation->id }}, link: {{ Js::from($citation->link) }} }; $dispatch('modal-show', { name: 'citation-remove' })" />
                            @endif
                        </li>
                    @endforeach
                </ul>

                @if ($canEdit)
                    <flux:modal name="citation-remove" class="w-full sm:w-96">
                        <div class="flex flex-col gap-6">
                            <div>
                                <flux:heading size="lg">{{ __('Remove this citing paper?') }}</flux:heading>
                                <flux:text class="mt-2 wrap-anywhere" x-text="removing.link"></flux:text>
                            </div>
                            <div class="flex justify-end gap-2">
                                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                                <flux:button variant="danger" x-on:click="$wire.removeCitation(removing.id)">{{ __('Remove') }}</flux:button>
                            </div>
                        </div>
                    </flux:modal>
                @endif
            </div>
        @endif
    </section>
</section>
