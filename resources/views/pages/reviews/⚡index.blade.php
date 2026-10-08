<?php

use App\Concerns\ReviewsSubmissions;
use App\Models\Submission;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Concept Reviews')] class extends Component {
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
}; ?>

<section class="mx-auto w-full max-w-5xl">
    <header class="flex flex-wrap items-center gap-4 border-b border-line pb-6">
        <img src="{{ asset('images/isu_seal-128.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
        <div class="min-w-0 flex-1">
            <flux:heading size="xl" level="1">{{ __('Concept Reviews') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Concept proposals waiting for a decision, longest waiting first.') }}</flux:text>
        </div>
    </header>

    @island(name: 'list', always: true)
    @if ($this->queue->isEmpty())
        <flux:text class="mt-8" data-test="reviews-empty">
            {{ __('No concept proposals are waiting for review. New and corrected ones appear here as proponents upload them.') }}
            <flux:link :href="route('submissions.index')" wire:navigate class="ms-1">{{ __('See all submissions') }}</flux:link>
        </flux:text>
    @else
        <flux:table class="mt-6" :paginate="$this->queue">
            <flux:table.columns>
                <flux:table.column>{{ __('Project') }}</flux:table.column>
                <flux:table.column>{{ __('College') }}</flux:table.column>
                <flux:table.column>{{ __('Waiting since') }}</flux:table.column>
                <flux:table.column class="w-0"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->queue as $submission)
                    <flux:table.row :key="$submission->id">
                        <flux:table.cell class="max-w-sm">
                            <p class="truncate font-medium text-zinc-800">{{ $submission->title }}</p>
                            <p class="truncate text-zinc-500">{{ $submission->proponents->pluck('user.name')->unique()->join(', ') }}</p>
                            @if ($submission->concept_path)
                                {{-- Every row has this link and a Review button, so screen readers also hear which project each one is for --}}
                                <flux:link :href="route('submissions.document', [$submission, 'concept'])" target="_blank" rel="noopener" :aria-label="__('Concept proposal for :title, opens in a new tab', ['title' => $submission->title])" class="mt-1 text-sm">
                                    {{ __('Concept proposal') }}
                                    <flux:icon.arrow-top-right-on-square variant="micro" class="inline size-3 align-[-1px]" />
                                </flux:link>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $submission->department->code }}</flux:table.cell>
                        <flux:table.cell class="tabular-nums">
                            <div class="whitespace-nowrap">{{ $submission->updated_at->format('M j, Y') }}</div>
                            <div class="text-sm text-zinc-500">{{ $submission->updated_at->diffForHumans() }}</div>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:button size="sm" variant="primary" inset="top bottom" wire:click="review({{ $submission->id }})" wire:island="review" :aria-label="__('Review :title', ['title' => $submission->title])" data-test="review-submission-button" class="max-sm:h-11">
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
