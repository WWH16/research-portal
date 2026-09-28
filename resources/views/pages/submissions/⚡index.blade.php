<?php

use App\Models\Submission;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Submissions')] class extends Component {
    use WithPagination;

    public ?int $reviewingId = null;
    public string $status = '';
    public string $remarks = '';

    /** Admins can narrow the list to one status; the dashboard links here with ?status=. */
    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    /**
     * Confirm a just-submitted proposal with the same toast the rest of the portal uses,
     * and open the review panel when an admin arrives from the dashboard with ?review=.
     */
    public function mount(): void
    {
        if ($message = session('status')) {
            Flux::toast(variant: 'success', text: $message);
        }

        if ($this->monitoring && ($id = request()->integer('review'))) {
            $this->review($id);
        }
    }

    /**
     * Admins monitor every proposal in the portal; faculty see only their own.
     */
    #[Computed]
    public function monitoring(): bool
    {
        return Auth::user()->isAdmin();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function submissions(): LengthAwarePaginator
    {
        $query = $this->monitoring
            ? Submission::query()->with(['user:id,name', 'department:id,code,name'])
            : Auth::user()->submissions();

        return $query
            ->with(['researchType:id,name', 'category:id,name'])
            ->when($this->monitoring && in_array($this->statusFilter, Submission::STATUSES, true), fn ($query) => $query->where('status', $this->statusFilter))
            ->latest()
            ->paginate(15);
    }

    #[Computed]
    public function reviewing(): ?Submission
    {
        return $this->reviewingId
            ? Submission::with(['user:id,name', 'department:id,code,name', 'researchType:id,name', 'category:id,name'])->find($this->reviewingId)
            : null;
    }

    /**
     * Open the review panel for a proposal. Faculty can load this page too, so every
     * review action checks for an admin on the server, not just in the markup.
     */
    public function review(int $id): void
    {
        abort_unless($this->monitoring, 403);

        $this->reviewingId = $id;
        unset($this->reviewing);
        $submission = $this->reviewing ?? abort(404);

        $this->status = $submission->status;
        $this->remarks = (string) $submission->remarks;
        $this->resetValidation();

        Flux::modal('review-submission')->show();
    }

    /**
     * Save the status and remarks. Asking for a revision needs remarks so the author knows what to change.
     */
    public function saveReview(): void
    {
        abort_unless($this->monitoring, 403);

        $this->remarks = trim($this->remarks);

        $validated = $this->validate([
            'status' => ['required', Rule::in(Submission::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000', 'required_if:status,For Revision'],
        ]);

        Submission::findOrFail($this->reviewingId)->update([
            'status' => $validated['status'],
            'remarks' => $validated['remarks'] ?: null,
        ]);

        Flux::modal('review-submission')->close();
        Flux::toast(variant: 'success', text: __('Status updated.'));
        unset($this->submissions, $this->reviewing);

        // Under a status filter, the saved proposal can leave the last page empty; step back to one with rows.
        if ($this->submissions->isEmpty() && $this->getPage() > 1) {
            $this->previousPage();
        }
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

        @if ($this->monitoring)
            <flux:select wire:model.live="statusFilter" :aria-label="__('Filter by status')" class="max-w-44 shrink-0">
                <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
                @foreach (Submission::STATUSES as $option)
                    <flux:select.option :value="$option">{{ __($option) }}</flux:select.option>
                @endforeach
            </flux:select>
        @else
            <flux:button :href="route('submissions.create')" variant="primary" icon="plus" class="shrink-0" wire:navigate>
                {{ __('New') }}
            </flux:button>
        @endif
    </header>

    @if ($this->submissions->isEmpty())
        <flux:text class="mt-8">
            @if ($this->monitoring && $statusFilter !== '')
                {{ __('No proposals have this status.') }}
            @else
                {{ $this->monitoring ? __('No proposals have been submitted yet.') : __('No submissions yet.') }}
            @endif
        </flux:text>
    @else
        <flux:table class="mt-6" :paginate="$this->submissions">
            <flux:table.columns>
                <flux:table.column>{{ __('Title') }}</flux:table.column>
                @if ($this->monitoring)
                    <flux:table.column>{{ __('Department') }}</flux:table.column>
                @endif
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column>{{ __('Category') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Submitted') }}</flux:table.column>
                @if ($this->monitoring)
                    <flux:table.column class="w-0"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
                @endif
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->submissions as $submission)
                    <flux:table.row :key="$submission->id">
                        <flux:table.cell class="max-w-xs">
                            <a href="{{ route('submissions.document', $submission) }}" target="_blank" rel="noopener" class="block truncate font-medium text-zinc-800 hover:underline">{{ $submission->title }}</a>
                            @if ($this->monitoring)
                                <div class="truncate text-zinc-500">{{ $submission->user->name }}</div>
                            @elseif ($submission->status === 'For Revision' && $submission->remarks)
                                <p class="mt-1 whitespace-normal text-sm text-amber-800">{{ __('Remarks: :remarks', ['remarks' => $submission->remarks]) }}</p>
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
                            <flux:badge size="sm" inset="top bottom" :color="Submission::STATUS_COLORS[$submission->status] ?? 'zinc'">
                                {{ $submission->status }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $submission->created_at->format('M j, Y') }}</flux:table.cell>
                        @if ($this->monitoring)
                            <flux:table.cell>
                                <flux:button size="sm" variant="ghost" inset="top bottom" wire:click="review({{ $submission->id }})" data-test="review-submission-button">
                                    {{ __('Review') }}
                                </flux:button>
                            </flux:table.cell>
                        @endif
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    @if ($this->monitoring)
        <flux:modal name="review-submission" variant="flyout" class="w-full md:w-[30rem]" aria-labelledby="review-submission-heading">
            @if ($this->reviewing)
                <form wire:submit="saveReview" class="flex flex-col gap-6">
                    <div class="pe-8">
                        <flux:heading size="lg" id="review-submission-heading">{{ $this->reviewing->title }}</flux:heading>
                        <flux:text class="mt-1">{{ $this->reviewing->user->name }}, {{ $this->reviewing->department->code }}</flux:text>
                    </div>

                    <dl class="grid grid-cols-2 gap-x-6 gap-y-4 text-sm">
                        <div>
                            <dt class="text-zinc-500">{{ __('Type') }}</dt>
                            <dd class="mt-1 font-medium text-zinc-800">{{ $this->reviewing->researchType->name }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500">{{ __('Category') }}</dt>
                            <dd class="mt-1 font-medium text-zinc-800">{{ $this->reviewing->category->name }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500">{{ __('Submitted') }}</dt>
                            <dd class="mt-1 font-medium tabular-nums text-zinc-800">{{ $this->reviewing->created_at->format('M j, Y') }}</dd>
                        </div>
                        @if ($this->reviewing->designation)
                            <div>
                                <dt class="text-zinc-500">{{ __('Designation') }}</dt>
                                <dd class="mt-1 font-medium text-zinc-800">{{ $this->reviewing->designation }}</dd>
                            </div>
                        @endif
                        @if ($this->reviewing->abstract)
                            <div class="col-span-2">
                                <dt class="text-zinc-500">{{ __('Abstract') }}</dt>
                                <dd class="mt-1 whitespace-pre-line text-zinc-800">{{ $this->reviewing->abstract }}</dd>
                            </div>
                        @endif
                    </dl>

                    <flux:button :href="route('submissions.document', $this->reviewing)" target="_blank" rel="noopener" icon="document-text" icon:trailing="arrow-top-right-on-square" class="self-start">
                        {{ __('Open document') }}
                    </flux:button>

                    <flux:separator variant="subtle" />

                    <flux:radio.group wire:model="status" :label="__('Status')" variant="segmented">
                        @foreach (Submission::STATUSES as $option)
                            <flux:radio :value="$option" :label="__($option)" />
                        @endforeach
                    </flux:radio.group>

                    <flux:textarea wire:model="remarks" :label="__('Remarks')" :description="__('Required when asking for a revision. The author sees these.')" rows="4" maxlength="2000" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" variant="primary" data-test="save-review-button">{{ __('Save') }}</flux:button>
                    </div>
                </form>
            @endif
        </flux:modal>
    @endif
</section>
