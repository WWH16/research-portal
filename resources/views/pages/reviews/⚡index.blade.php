<?php

use App\Concerns\ReviewsSubmissions;
use App\Models\Submission;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Concept reviews')] class extends Component {
    use ReviewsSubmissions, WithPagination;

    /**
     * Open the review panel when an admin arrives from the dashboard with ?review=.
     */
    public function mount(): void
    {
        if ($id = request()->integer('review')) {
            $this->review($id);
        }
    }

    /**
     * New and corrected concept proposals, longest waiting first, the same order as the dashboard queue.
     */
    #[Computed]
    public function queue(): LengthAwarePaginator
    {
        // ponytail: updated_at also moves when dates change; order by the concept upload time if that reorders the queue wrongly.
        return Submission::where('awaiting_review', true)
            ->with(['department:id,code', 'proponents.user:id,name'])
            ->oldest('updated_at')
            ->orderBy('id')
            ->paginate(15);
    }

    /**
     * Close the panel and redraw the list, which the decided project has now left.
     */
    protected function reviewSaved(): void
    {
        Flux::modal('review-submission')->close();
        Flux::toast(variant: 'success', text: __('Review saved.'));
        unset($this->queue, $this->reviewing);

        if ($this->queue->isEmpty() && $this->getPage() > 1) {
            $this->previousPage();
        }

        $this->renderIsland('list');
    }

    /**
     * The sidebar found the review count moved, as when a new proposal came in: redraw the list. Other counts leave it be.
     *
     * @param  list<string>  $changed
     */
    #[On('open-work-changed')]
    public function openWorkChanged(array $changed): void
    {
        if (! in_array('toReview', $changed, true)) {
            $this->skipRender();

            return;
        }

        unset($this->queue);
        $this->renderIsland('list');
    }
}; ?>

<section class="mx-auto w-full max-w-5xl">
    <x-page-title :subtitle="__('Concept proposals waiting for a decision, longest waiting first.')">{{ __('Concept reviews') }}</x-page-title>

    @island(name: 'list', always: true)
    @if ($this->queue->isEmpty())
        <x-empty-state icon="clipboard-document-check" :heading="__('No concept proposals are waiting for review.')" :text="__('New and corrected ones appear here as proponents upload them.')" class="mt-8" data-test="reviews-empty">
            <flux:button :href="route('submissions.index')" wire:navigate class="max-sm:h-11">{{ __('See all submissions') }}</flux:button>
        </x-empty-state>
    @else
        {{-- The pager shows only once there is a second page, as on Submissions; its arrows are 44px on phones --}}
        <flux:table container:class="mt-6 table-frame" :paginate="$this->queue->hasPages() ? $this->queue : null" pagination:class="max-sm:[&_button]:size-11">
            <flux:table.columns>
                <flux:table.column>{{ __('Project') }}</flux:table.column>
                {{-- Phones keep the project and its Review button; the project cell repeats college and wait in short --}}
                <flux:table.column class="max-sm:hidden">{{ __('College') }}</flux:table.column>
                <flux:table.column class="max-sm:hidden">{{ __('Waiting since') }}</flux:table.column>
                <flux:table.column class="w-0"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->queue as $submission)
                    @php($title = $submission->displayTitle())
                    <flux:table.row :key="$submission->id">
                        <flux:table.cell class="max-w-sm max-sm:whitespace-normal">
                            <flux:tooltip :content="$title" class="block min-w-0"><p class="font-medium text-zinc-800 max-sm:line-clamp-2 sm:truncate">{{ $title }}</p></flux:tooltip>
                            <p class="truncate text-zinc-500">{{ $submission->proponents->unique(fn ($row) => $row->personKey())->map->shortName()->join(', ') }}</p>
                            <p class="text-sm text-zinc-500 sm:hidden">{{ $submission->department->code }} · {{ $submission->updated_at->diffForHumans() }}</p>
                            @if ($submission->concept_path)
                                {{-- Every row has this link and a Review button, so screen readers also hear which project each one is for --}}
                                @php($viewer = $submission->documentViewer('concept'))
                                <x-opens-in-viewer :viewer="$viewer">
                                    <flux:link :href="route('submissions.document', [$submission, 'concept'])" target="_blank" rel="noopener" :aria-label="$viewer ? __('Concept proposal for :title', ['title' => $title]) : __('Concept proposal for :title, opens in a new tab', ['title' => $title])" class="mt-1 text-sm max-sm:inline-flex max-sm:min-h-11 max-sm:items-center max-sm:gap-1">
                                        {{ __('Concept proposal') }}
                                        <flux:icon :icon="$viewer ? 'arrows-pointing-out' : 'arrow-top-right-on-square'" variant="micro" class="inline size-3 align-[-1px]" />
                                    </flux:link>
                                </x-opens-in-viewer>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="max-sm:hidden">{{ $submission->department->code }}</flux:table.cell>
                        <flux:table.cell class="tabular-nums max-sm:hidden">
                            <div class="whitespace-nowrap">{{ $submission->updated_at->format('M j, Y') }}</div>
                            <div class="text-sm text-zinc-500">{{ $submission->updated_at->diffForHumans() }}</div>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:button size="sm" variant="primary" inset="top bottom" wire:click="review({{ $submission->id }})" wire:island="review" :aria-label="__('Review :title', ['title' => $title])" data-test="review-submission-button" class="max-sm:h-11">
                                {{ __('Review') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
    @endisland

    @include('partials.review-panel')
</section>
