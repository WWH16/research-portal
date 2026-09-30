<?php

use App\Models\Department;
use App\Models\Submission;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Submissions')] class extends Component {
    use WithPagination;

    public ?int $reviewingId = null;
    public string $status = '';
    public string $remarks = '';
    public ?int $year = null;

    /** Admins can narrow the list to a status, "review" or "delayed"; the dashboard links here with ?status=. */
    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'year', except: '')]
    public string $yearFilter = '';

    /** Admins narrow the list to one college by department code; the dashboard links here with ?dept=. */
    #[Url(as: 'dept', except: '')]
    public string $department = '';

    /** Admins find a faculty member's projects by name. */
    #[Url(as: 'q', except: '')]
    public string $search = '';

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
     * Admins monitor every project in the portal; faculty see the ones they are on.
     */
    #[Computed]
    public function monitoring(): bool
    {
        return Auth::user()->isAdmin();
    }

    /**
     * Clear every filter at once, so a filter that empties the table can always be undone.
     */
    public function clearFilters(): void
    {
        $this->reset('statusFilter', 'yearFilter', 'department', 'search');
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['statusFilter', 'yearFilter', 'department', 'search'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function submissions(): LengthAwarePaginator
    {
        return $this->filtered()->latest()->paginate(15);
    }

    /**
     * The projects this member may see, narrowed by the active filters. The table and the
     * export both read from here, so the export always matches what the list shows.
     */
    private function filtered(): Builder
    {
        $query = $this->monitoring
            ? Submission::query()->with(['user:id,name', 'department:id,code,name'])
            : Submission::involving(Auth::user());

        return $query
            ->with('proponents.user:id,name')
            ->when($this->yearFilter !== '', fn ($query) => $query->where('year', (int) $this->yearFilter))
            ->when($this->monitoring && $this->department !== '', fn ($query) => $query->whereRelation('department', 'code', $this->department))
            ->when($this->monitoring, fn ($query) => match (true) {
                $this->statusFilter === 'review' => $query->where('awaiting_review', true),
                $this->statusFilter === 'delayed' => $query->delayed(),
                in_array($this->statusFilter, Submission::STATUSES, true) => $query->where('status', $this->statusFilter),
                default => $query,
            })
            ->when($this->monitoring && trim($this->search) !== '', function ($query) {
                $term = '%'.trim($this->search).'%';
                $query->where(fn ($query) => $query
                    ->whereHas('user', fn ($query) => $query->where('name', 'like', $term))
                    ->orWhereHas('proponents.user', fn ($query) => $query->where('name', 'like', $term)));
            });
    }

    /**
     * Download every project matching the filters, across all pages, one row per project.
     */
    public function export(): StreamedResponse
    {
        abort_unless($this->monitoring, 403);

        $filters = array_filter([$this->department, $this->yearFilter, $this->statusFilter, trim($this->search)]);
        $filename = 'submissions-'.($filters ? Str::slug(implode(' ', $filters)) : today()->toDateString()).'.csv';
        $yesNo = fn (?bool $value) => $value === null ? '' : ($value ? __('Yes') : __('No'));

        $rows = $this->filtered()->with(['researchType:id,name', 'category:id,name'])->latest()->lazy()->map(fn (Submission $project) => [
            $project->title,
            $project->year,
            $project->department->code,
            $project->status,
            $yesNo($project->awaiting_review),
            $yesNo($project->isDelayed()),
            $project->user->name,
            $project->proponents->sortBy([['study', 'asc'], ['id', 'asc']])->groupBy('study')
                ->map(fn ($rows, $study) => __('Study :number', ['number' => $study]).': '.$rows->map(fn ($row) => $row->user->name.' ('.$row->role.')')->join(', '))
                ->join('; '),
            $project->proponents->unique('user_id')->count(),
            $project->researchType->name,
            $project->category->name,
            $project->start_date?->toDateString(),
            $project->target_date?->toDateString(),
            $project->terminal_uploaded_at?->toDateString(),
            $yesNo($project->completedOnTime()),
            collect(Submission::DOCUMENTS)->filter(fn ($label, $stage) => $project->{$stage.'_path'})->map(fn ($label, $stage) => ucfirst($stage))->join(', '),
            $project->remarks,
            $project->created_at->toDateString(),
            $project->abstract,
        ]);

        return response()->csv($filename, [
            __('Title'), __('Year'), __('College'), __('Status'), __('Awaiting review'), __('Delayed'), __('Filed by'),
            __('Proponents'), __('Faculty count'), __('Research type'), __('Category'), __('Starting date'), __('Completion date'),
            __('Terminal report uploaded'), __('Finished on time'), __('Documents on file'), __('Remarks'), __('Encoded on'), __('Abstract'),
        ], $rows);
    }

    #[Computed]
    public function reviewing(): ?Submission
    {
        return $this->reviewingId
            ? Submission::with(['user:id,name', 'department:id,code,name', 'researchType:id,name', 'category:id,name', 'proponents.user:id,name'])->find($this->reviewingId)
            : null;
    }

    /**
     * Open the review panel for a project. Faculty can load this page too, so every
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
        $this->year = $submission->year;
        $this->resetValidation();

        Flux::modal('review-submission')->show();
    }

    /**
     * Save the review. Saving takes the project off the review list whether or not the status
     * moves, so a document found incomplete stays at its old status until a corrected upload.
     */
    public function saveReview(): void
    {
        abort_unless($this->monitoring, 403);

        $this->remarks = trim($this->remarks);

        $validated = $this->validate([
            'status' => ['required', Rule::in(Submission::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'year' => ['required', 'integer', 'between:2000,'.(now()->year + 1)],
        ]);

        Submission::findOrFail($this->reviewingId)->update([
            'status' => $validated['status'],
            'remarks' => $validated['remarks'] ?: null,
            'year' => $validated['year'],
            'awaiting_review' => false,
        ]);

        Flux::modal('review-submission')->close();
        Flux::toast(variant: 'success', text: __('Review saved.'));
        unset($this->submissions, $this->reviewing);

        // Under a filter, the saved project can leave the last page empty; step back to one with rows.
        if ($this->submissions->isEmpty() && $this->getPage() > 1) {
            $this->previousPage();
        }
    }
}; ?>

<section class="mx-auto w-full {{ $this->monitoring ? 'max-w-5xl' : 'max-w-4xl' }}">
    <header class="flex flex-wrap items-center gap-4 border-b border-line pb-6">
        <img src="{{ asset('images/isu_seal.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
        <div class="min-w-0 flex-1">
            <flux:heading size="xl" level="1">{{ __('Submissions') }}</flux:heading>
            <flux:text class="mt-1">
                {{ $this->monitoring ? __('Every project filed across the portal.') : __('Projects you filed or are a proponent on.') }}
            </flux:text>
        </div>

        @unless ($this->monitoring)
            <flux:button :href="route('submissions.create')" variant="primary" icon="plus" class="shrink-0" wire:navigate>
                {{ __('New') }}
            </flux:button>
        @endunless
    </header>

    <div class="mt-6 flex flex-wrap gap-3">
        @if ($this->monitoring)
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search faculty')" :aria-label="__('Search faculty')" class="min-w-48 flex-1" />

            <flux:select wire:model.live="statusFilter" :aria-label="__('Filter by status')" class="max-w-44">
                <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
                <flux:select.option value="review">{{ __('Awaiting review') }}</flux:select.option>
                <flux:select.option value="delayed">{{ __('Delayed') }}</flux:select.option>
                @foreach (Submission::STATUSES as $option)
                    <flux:select.option :value="$option">{{ __($option) }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        <flux:select wire:model.live="yearFilter" :aria-label="__('Filter by year')" class="max-w-36">
            <flux:select.option value="">{{ __('All years') }}</flux:select.option>
            @foreach (Submission::years() as $option)
                <flux:select.option :value="$option">{{ $option }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($this->monitoring)
            <flux:button icon="arrow-down-tray" wire:click="export" :disabled="$this->submissions->isEmpty()" data-test="export-submissions-button">
                {{ __('Export CSV') }}
            </flux:button>
        @endif
    </div>

    @if ($this->submissions->isEmpty())
        <flux:text class="mt-8">
            @if ($statusFilter !== '' || $yearFilter !== '' || $department !== '' || $search !== '')
                {{ __('No projects match these filters.') }}
                <flux:link as="button" wire:click="clearFilters" class="ms-1" data-test="clear-filters-button">{{ __('Clear filters') }}</flux:link>
            @else
                {{ $this->monitoring ? __('No proposals have been submitted yet.') : __('No submissions yet.') }}
            @endif
        </flux:text>
    @else
        <flux:table class="mt-6" :paginate="$this->submissions">
            <flux:table.columns>
                <flux:table.column>{{ __('Project') }}</flux:table.column>
                @if ($this->monitoring)
                    <flux:table.column data-test="department-column">
                        {{-- The column filters itself: pick a college from its header --}}
                        <flux:dropdown position="bottom" align="start">
                            <flux:button
                                variant="ghost"
                                size="sm"
                                inset="top bottom"
                                icon:trailing="chevron-down"
                                class="-ms-2 {{ $department !== '' ? 'text-isu-green-700!' : '' }}"
                                :aria-label="$department !== '' ? __('College, filtered to :code. Change filter', ['code' => $department]) : __('College. Filter by college')"
                            >
                                {{ $department !== '' ? $department : __('College') }}
                            </flux:button>

                            <flux:menu class="max-h-80 min-w-40 overflow-y-auto">
                                <flux:menu.radio.group wire:model.live="department">
                                    <flux:menu.radio value="">{{ __('All colleges') }}</flux:menu.radio>
                                    <flux:menu.separator />
                                    @foreach (Department::orderBy('code')->pluck('code') as $option)
                                        <flux:menu.radio :value="$option">{{ $option }}</flux:menu.radio>
                                    @endforeach
                                </flux:menu.radio.group>
                            </flux:menu>
                        </flux:dropdown>
                    </flux:table.column>
                @endif
                <flux:table.column>{{ __('Year') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Duration') }}</flux:table.column>
                <flux:table.column class="w-0"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->submissions as $submission)
                    <flux:table.row :key="$submission->id">
                        <flux:table.cell class="max-w-sm">
                            <p class="truncate font-medium text-zinc-800">{{ $submission->title }}</p>
                            <p class="truncate text-zinc-500">{{ $submission->proponents->pluck('user.name')->unique()->join(', ') }}</p>
                            <div class="mt-1 flex flex-wrap gap-x-3 text-sm">
                                @foreach (Submission::DOCUMENTS as $stage => $label)
                                    @if ($submission->{$stage.'_path'})
                                        <flux:link :href="route('submissions.document', [$submission, $stage])" target="_blank" rel="noopener">{{ __($label) }}</flux:link>
                                    @endif
                                @endforeach
                            </div>
                            @if (! $this->monitoring && $submission->remarks && ! $submission->awaiting_review)
                                <p class="mt-1 whitespace-normal text-sm text-amber-800">{{ __('Remarks: :remarks', ['remarks' => $submission->remarks]) }}</p>
                            @endif
                        </flux:table.cell>
                        @if ($this->monitoring)
                            <flux:table.cell>
                                {{ $submission->department->code }}
                            </flux:table.cell>
                        @endif
                        <flux:table.cell class="tabular-nums">{{ $submission->year }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-wrap gap-1">
                                <flux:badge size="sm" inset="top bottom" :color="Submission::STATUS_COLORS[$submission->status] ?? 'zinc'">{{ __($submission->status) }}</flux:badge>
                                @if ($submission->awaiting_review)
                                    <flux:badge size="sm" inset="top bottom" color="blue">{{ __('Awaiting review') }}</flux:badge>
                                @endif
                                @if ($submission->isDelayed())
                                    <flux:badge size="sm" inset="top bottom" color="red">{{ __('Delayed') }}</flux:badge>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="tabular-nums">
                            <div class="whitespace-nowrap">{{ $submission->start_date?->format('M j, Y') ?? '—' }} –</div>
                            <div class="whitespace-nowrap">{{ $submission->target_date?->format('M j, Y') ?? '—' }}</div>
                            @if (($onTime = $submission->completedOnTime()) !== null)
                                <div class="text-sm {{ $onTime ? 'text-green-700' : 'text-amber-800' }}">{{ $onTime ? __('Finished on time') : __('Finished late') }}</div>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($this->monitoring)
                                <flux:button size="sm" variant="ghost" inset="top bottom" wire:click="review({{ $submission->id }})" data-test="review-submission-button">
                                    {{ __('Review') }}
                                </flux:button>
                            @else
                                <flux:button size="sm" variant="ghost" inset="top bottom" :href="route('submissions.edit', $submission)" wire:navigate data-test="edit-submission-button">
                                    {{ __('Edit') }}
                                </flux:button>
                            @endif
                        </flux:table.cell>
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
                            <dt class="text-zinc-500">{{ __('Encoded') }}</dt>
                            <dd class="mt-1 font-medium tabular-nums text-zinc-800">{{ $this->reviewing->created_at->format('M j, Y') }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500">{{ __('Starting date') }}</dt>
                            <dd class="mt-1 font-medium tabular-nums text-zinc-800">{{ $this->reviewing->start_date?->format('M j, Y') ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500">{{ __('Completion date') }}</dt>
                            <dd class="mt-1 font-medium tabular-nums text-zinc-800">{{ $this->reviewing->target_date?->format('M j, Y') ?? '—' }}</dd>
                        </div>
                        @if ($this->reviewing->terminal_uploaded_at)
                            <div class="col-span-2">
                                <dt class="text-zinc-500">{{ __('Terminal report uploaded') }}</dt>
                                <dd class="mt-1 font-medium tabular-nums text-zinc-800">
                                    {{ $this->reviewing->terminal_uploaded_at->format('M j, Y') }}
                                    @if (($onTime = $this->reviewing->completedOnTime()) !== null)
                                        ({{ $onTime ? __('on time') : __('late') }})
                                    @endif
                                </dd>
                            </div>
                        @endif
                        @if ($this->reviewing->designation)
                            <div>
                                <dt class="text-zinc-500">{{ __('Designation') }}</dt>
                                <dd class="mt-1 font-medium text-zinc-800">{{ $this->reviewing->designation }}</dd>
                            </div>
                        @endif
                        <div class="col-span-2">
                            <dt class="text-zinc-500">{{ __('Proponents') }}</dt>
                            <dd class="mt-1 grid gap-1 text-zinc-800">
                                @foreach ($this->reviewing->proponents->sortBy('study')->groupBy('study') as $study => $rows)
                                    <p><span class="font-medium">{{ __('Study :number', ['number' => $study]) }}:</span> {{ $rows->map(fn ($row) => $row->user->name.' ('.__($row->role).')')->join(', ') }}</p>
                                @endforeach
                            </dd>
                        </div>
                        @if ($this->reviewing->abstract)
                            <div class="col-span-2">
                                <dt class="text-zinc-500">{{ __('Abstract') }}</dt>
                                <dd class="mt-1 whitespace-pre-line text-zinc-800">{{ $this->reviewing->abstract }}</dd>
                            </div>
                        @endif
                    </dl>

                    <div class="flex flex-wrap gap-2">
                        @foreach (Submission::DOCUMENTS as $stage => $label)
                            @if ($this->reviewing->{$stage.'_path'})
                                <flux:button size="sm" :href="route('submissions.document', [$this->reviewing, $stage])" target="_blank" rel="noopener" icon="document-text" icon:trailing="arrow-top-right-on-square">
                                    {{ __($label) }}
                                </flux:button>
                            @endif
                        @endforeach
                    </div>

                    <flux:separator variant="subtle" />

                    <flux:radio.group wire:model="status" :label="__('Status')" :description="__('Move the status only once the latest document is accepted.')" variant="segmented">
                        @foreach (Submission::STATUSES as $option)
                            <flux:radio :value="$option" :label="__($option)" />
                        @endforeach
                    </flux:radio.group>

                    <flux:input wire:model="year" :label="__('Year')" :description="__('The year the project was actually submitted.')" type="number" min="2000" :max="now()->year + 1" required />

                    <flux:textarea wire:model="remarks" :label="__('Remarks')" :description="__('Say what to fix when a document is incomplete. The researcher sees these.')" rows="4" maxlength="2000" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" variant="primary" data-test="save-review-button">{{ __('Save review') }}</flux:button>
                    </div>
                </form>
            @endif
        </flux:modal>
    @endif
</section>
