<?php

use App\Models\Submission;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Submissions')] class extends Component {
    public ?int $reviewingId = null;
    public string $status = '';
    public string $remarks = '';

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

        $submission = Submission::findOrFail($id);

        $this->reviewingId = $submission->id;
        $this->status = $submission->status;
        $this->remarks = (string) $submission->remarks;
        $this->resetValidation();
        unset($this->reviewing);

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
                            <flux:badge size="sm" inset="top bottom" :color="match ($submission->status) { 'OK' => 'green', 'For Revision' => 'amber', default => 'zinc' }">
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
                        {{ __('Open PDF') }}
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
