<?php

use App\Models\ActivityLog;
use App\Models\Submission;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Renderless;
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

    /** The project open in the Change dates dialog. */
    public ?int $datingId = null;
    public string $start_date = '';
    public string $target_date = '';

    /** A status, "proposal" (Concept or Detailed), "review" or "delayed"; the dashboard tiles link here with ?status=. */
    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'year', except: '')]
    public string $yearFilter = '';

    /** Admins narrow the list to one college by department code; the dashboard links here with ?dept=. */
    #[Url(as: 'dept', except: '')]
    public string $department = '';

    /** "drive" when an admin came to review from a Research Drive project, so closing the review goes back there. */
    #[Url(except: '')]
    public string $from = '';

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
            ? Submission::query()->with(['user:id,name', 'department:id,code'])
            : Submission::involving(Auth::user());

        return $query
            ->with('proponents.user:id,name')
            ->when($this->yearFilter !== '', fn ($query) => $query->where('year', (int) $this->yearFilter))
            ->when($this->monitoring && $this->department !== '', fn ($query) => $query->whereRelation('department', 'code', $this->department))
            ->tap(fn ($query) => match (true) {
                $this->statusFilter === 'proposal' => $query->whereIn('status', Submission::PROPOSAL_STAGES),
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
        $this->authorize('viewAny', Submission::class);

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
            ? Submission::with(['user:id,name', 'department:id,code', 'researchType:id,name', 'category:id,name', 'proponents.user:id,name,department_id', 'proponents.user.department:id,code'])->find($this->reviewingId)
            : null;
    }

    /**
     * Open the review panel for a project. Faculty can load this page too, so every
     * review action checks for an admin on the server, not just in the markup.
     */
    public function review(int $id): void
    {
        $this->authorize('review', Submission::class);

        $this->reviewingId = $id;
        unset($this->reviewing);
        $submission = $this->reviewing ?? abort(404);

        $this->status = $submission->status;
        $this->remarks = (string) $submission->remarks;
        $this->resetValidation();

        Flux::modal('review-submission')->show();
    }

    /**
     * Closing a review opened from the Drive, saved or not, goes back to that project. Otherwise forget the
     * project, so later filter and page changes don't reload it. Nothing on screen changes, so skip the render.
     */
    #[Renderless]
    public function closeReview(): void
    {
        if ($this->from === 'drive' && $this->reviewingId) {
            $this->redirectRoute('drive.show', $this->reviewingId, navigate: true);

            return;
        }

        $this->reviewingId = null;
    }

    #[Computed]
    public function dating(): ?Submission
    {
        return $this->datingId ? Submission::find($this->datingId, ['id', 'title', 'start_date', 'target_date']) : null;
    }

    /**
     * Open the Change dates dialog. Once a project is filed only the Research Office moves its dates, for an
     * extension or a correction, and doing so is not a review: the status and the review list stay as they are.
     */
    public function editDates(int $id): void
    {
        $this->authorize('review', Submission::class);

        $this->datingId = $id;
        unset($this->dating);
        $submission = $this->dating ?? abort(404);

        $this->start_date = (string) $submission->start_date?->toDateString();
        $this->target_date = (string) $submission->target_date?->toDateString();
        $this->resetValidation();

        Flux::modal('project-dates')->show();
    }

    /**
     * Save the new dates, logged like a project edit: "Changed the completion date from … to …".
     */
    public function saveDates(): void
    {
        $this->authorize('review', Submission::class);

        $validated = $this->validate([
            'start_date' => ['required', 'date'],
            'target_date' => ['required', 'date', 'after:start_date'],
        ], attributes: [
            'start_date' => __('starting date'),
            'target_date' => __('completion date'),
        ]);

        DB::transaction(function () use ($validated) {
            $submission = Submission::findOrFail($this->datingId);
            $submission->update($validated);

            $dates = collect(['start_date', 'target_date'])
                ->filter(fn (string $column) => $submission->wasChanged($column))
                ->mapWithKeys(fn (string $column) => [$column => [
                    ($previous = $submission->getPrevious()[$column]) ? Date::parse($previous)->toDateString() : null,
                    $submission->{$column}->toDateString(),
                ]])
                ->all();
            if ($dates !== []) {
                ActivityLog::record('submission.dates_changed', $submission, ['values' => $dates]);
            }
        });

        Flux::modal('project-dates')->close();
        Flux::toast(variant: 'success', text: __('Dates changed.'));
        $this->datingId = null;
        unset($this->submissions, $this->dating);

        // Saving runs inside the dates island, which only redraws itself; the list has to show the new dates too.
        $this->renderIsland('list');
    }

    /**
     * Save the review. Saving takes the project off the review list whether or not the status
     * moves, so a document found incomplete stays at its old status until a corrected upload.
     */
    public function saveReview(): void
    {
        $this->authorize('review', Submission::class);

        $this->remarks = trim($this->remarks);

        $validated = $this->validate([
            'status' => ['required', Rule::in(Submission::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        // The review and its log entry save together, so a failed log write never leaves a review the log missed.
        DB::transaction(function () use ($validated) {
            $submission = Submission::findOrFail($this->reviewingId);
            $submission->update([
                'status' => $validated['status'],
                'remarks' => $validated['remarks'] ?: null,
                'awaiting_review' => false,
            ]);

            // The project keeps only the latest remarks, so the log holds each review's own copy.
            ActivityLog::record('submission.reviewed', $submission, array_filter([
                'status' => [$submission->getPrevious()['status'] ?? $submission->status, $submission->status],
                'remarks' => $submission->remarks,
            ]));
        });

        if ($this->from === 'drive') {
            session()->flash('status', __('Review saved.'));
            $this->closeReview();

            return;
        }

        Flux::modal('review-submission')->close();
        Flux::toast(variant: 'success', text: __('Review saved.'));
        unset($this->submissions, $this->reviewing);

        // Under a filter, the saved project can leave the last page empty; step back to one with rows.
        if ($this->submissions->isEmpty() && $this->getPage() > 1) {
            $this->previousPage();
        }

        // Saving runs inside the review island, which only redraws itself; the list has to show the new status too.
        $this->renderIsland('list');
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
            <flux:button :href="route('submissions.create')" variant="primary" icon="plus" class="shrink-0 max-sm:h-11 max-sm:w-full" wire:navigate>
                {{ __('New proposal') }}
            </flux:button>
        @endunless
    </header>

    {{-- Filters and results redraw together; opening a review redraws only the review island below --}}
    @island(name: 'list', always: true)
    <div class="mt-6 flex flex-wrap gap-3">
        @if ($this->monitoring)
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search by faculty name')" :aria-label="__('Search by faculty name')" class="min-w-48 flex-1" />
        @endif

        <flux:select wire:model.live="statusFilter" :aria-label="__('Filter by status')" class="max-w-48" data-test="status-filter">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            <flux:select.option value="proposal">{{ __('At proposal stage') }}</flux:select.option>
            <flux:select.option value="review">{{ __('Awaiting review') }}</flux:select.option>
            <flux:select.option value="delayed">{{ __('Delayed') }}</flux:select.option>
            @foreach (Submission::STATUSES as $option)
                <flux:select.option :value="$option">{{ __($option) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="yearFilter" :aria-label="__('Filter by year')" class="max-w-36">
            <flux:select.option value="">{{ __('All years') }}</flux:select.option>
            @foreach (Submission::years() as $option)
                <flux:select.option :value="$option">{{ $option }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($this->monitoring)
            <flux:button icon="arrow-down-tray" wire:click="export" :disabled="$this->submissions->isEmpty()" data-test="export-submissions-button" class="max-sm:h-11 max-sm:w-full">
                {{ __('Export CSV') }}
            </flux:button>
        @endif
    </div>

    @if ($this->submissions->isEmpty())
        <flux:text class="mt-8">
            @if ($statusFilter !== '' || $yearFilter !== '' || $department !== '' || $search !== '')
                {{ __('No projects match these filters.') }}
                <flux:link as="button" wire:click="clearFilters" class="ms-1" data-test="clear-filters-button">{{ __('Clear filters') }}</flux:link>
            @elseif ($this->monitoring)
                {{ __('No projects have been filed yet. They appear here as faculty submit proposals.') }}
            @else
                {{ __('You’re not on any projects yet. Submit a proposal, or ask a colleague to list you as a proponent on theirs.') }}
            @endif
        </flux:text>
    @else
        <flux:table class="mt-6" :paginate="$this->submissions">
            <flux:table.columns>
                <flux:table.column>{{ __('Project') }}</flux:table.column>
                @if ($this->monitoring)
                    <flux:table.column data-test="department-column">
                        <x-college-filter model="department" :value="$department" />
                    </flux:table.column>
                @endif
                <flux:table.column>{{ __('Year') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Duration') }}</flux:table.column>
                {{-- On phones the actions column stays pinned to the right edge while the table scrolls sideways --}}
                <flux:table.column class="w-0 max-sm:sticky max-sm:right-0 max-sm:border-l max-sm:border-line max-sm:bg-surface"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
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
                            @include('partials.project-status')
                        </flux:table.cell>
                        <flux:table.cell class="tabular-nums">
                            <div class="whitespace-nowrap">{{ $submission->start_date?->format('M j, Y') ?? '—' }} –</div>
                            <div class="whitespace-nowrap">{{ $submission->target_date?->format('M j, Y') ?? '—' }}</div>
                            @if (($onTime = $submission->completedOnTime()) !== null)
                                <div class="text-sm {{ $onTime ? 'text-green-700' : 'text-amber-800' }}">{{ $onTime ? __('Finished on time') : __('Finished late') }}</div>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="max-sm:sticky max-sm:right-0 max-sm:border-l max-sm:border-line max-sm:bg-surface">
                            @if ($this->monitoring)
                                <div class="flex items-center gap-1">
                                    <flux:button size="sm" variant="ghost" inset="top bottom" wire:click="review({{ $submission->id }})" wire:island="review" data-test="review-submission-button" class="max-sm:h-11">
                                        {{ __('Review') }}
                                    </flux:button>
                                    <flux:button size="sm" variant="ghost" inset="top bottom" icon="calendar-days" wire:click="editDates({{ $submission->id }})" wire:island="dates" :aria-label="__('Change dates for :title', ['title' => $submission->title])" :tooltip="__('Change dates')" data-test="change-dates-button" class="max-sm:size-11" />
                                </div>
                            @else
                                <flux:button size="sm" variant="ghost" inset="top bottom" :href="route('submissions.edit', $submission)" wire:navigate data-test="edit-submission-button" class="max-sm:h-11">
                                    {{ __('Edit') }}
                                </flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
    @endisland

    @if ($this->monitoring)
        <flux:modal name="review-submission" variant="flyout" wire:close="closeReview" class="w-full max-sm:p-5 sm:w-[30rem]" aria-labelledby="review-submission-heading">
            {{-- Also redraws on full renders, which is free once a closed review forgets its project --}}
            @island(name: 'review', always: true)
            @if ($this->reviewing)
                <form wire:submit="saveReview" class="flex flex-col gap-6">
                    <div class="pe-8">
                        <flux:heading size="lg" id="review-submission-heading">{{ $this->reviewing->title }}</flux:heading>
                        <flux:text class="mt-1">{{ __('Filed by :name · :college', ['name' => $this->reviewing->user->name, 'college' => $this->reviewing->department->code]) }}</flux:text>
                    </div>

                    @php($latest = $this->reviewing->latestUpload())
                    @if ($this->reviewing->awaiting_review)
                        <flux:callout color="blue" icon="document-arrow-up" data-test="review-to-check">
                            <flux:callout.heading>
                                {{ $latest ? __('To review: :document', ['document' => __(Submission::DOCUMENTS[$latest['stage']])]) : __('Waiting for review') }}
                            </flux:callout.heading>
                            @if ($latest)
                                <flux:callout.text>{{ __('Uploaded :date. Open it below, then set the status or leave remarks.', ['date' => $latest['at']->format('M j, Y, g:i A')]) }}</flux:callout.text>
                            @endif
                        </flux:callout>
                    @else
                        <flux:text class="text-sm" data-test="review-nothing-new">{{ __('Already reviewed. No new document since then.') }}</flux:text>
                    @endif

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
                            <dt class="text-zinc-500">{{ __('Year') }}</dt>
                            <dd class="mt-1 font-medium tabular-nums text-zinc-800">{{ $this->reviewing->year }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500">{{ __('Encoded on') }}</dt>
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
                                    <p><span class="font-medium">{{ __('Study :number', ['number' => $study]) }}:</span> {{ $rows->map(fn ($row) => $row->user->name.' ('.__($row->role).', '.($row->user->department?->code ?? __('No college')).')')->join(', ') }}</p>
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

                    <div>
                        <p class="text-sm text-zinc-500">{{ __('Documents') }}</p>
                        <div class="mt-2 flex flex-wrap gap-2 max-sm:grid">
                            @foreach (Submission::DOCUMENTS as $stage => $label)
                                @if ($this->reviewing->{$stage.'_path'})
                                    <flux:button size="sm" :href="route('submissions.document', [$this->reviewing, $stage])" target="_blank" rel="noopener" icon="document-text" icon:trailing="arrow-top-right-on-square" :variant="$this->reviewing->awaiting_review && $latest && $latest['stage'] === $stage ? 'primary' : 'outline'" class="max-sm:h-11">
                                        {{ __($label) }}
                                    </flux:button>
                                @endif
                            @endforeach
                        </div>
                    </div>

                    <flux:separator variant="subtle" />

                    {{-- Four statuses don't fit one segmented row on phones, so they sit two by two there --}}
                    <flux:radio.group wire:model="status" :label="__('Status')" :description="__('Pick the stage whose document you accept: Concept or Detailed for a proposal, Completed for the terminal report. Saving without a change still takes the project off the review list.')" variant="segmented" class="max-sm:grid max-sm:h-auto max-sm:grid-cols-2 max-sm:gap-1">
                        @foreach (Submission::STATUSES as $option)
                            <flux:radio :value="$option" :label="__($option)" class="max-sm:h-10" />
                        @endforeach
                    </flux:radio.group>

                    <flux:textarea wire:model="remarks" :label="__('Remarks')" :description="__('Say what to fix when a document is incomplete. Everyone on the project sees these.')" rows="4" maxlength="2000" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost" class="max-sm:h-11">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" variant="primary" data-test="save-review-button" class="max-sm:h-11">{{ __('Save review') }}</flux:button>
                    </div>
                </form>
            @endif
            @endisland
        </flux:modal>

        {{-- Closing forgets the project without a request, so later filter changes don't reload it --}}
        <flux:modal name="project-dates" wire:close="$set('datingId', null, false)" class="w-full sm:w-96" aria-labelledby="project-dates-heading">
            @island(name: 'dates', always: true)
            @if ($this->dating)
                <form wire:submit="saveDates" class="flex flex-col gap-6">
                    <div>
                        <flux:heading size="lg" id="project-dates-heading">{{ __('Change dates') }}</flux:heading>
                        <flux:text class="mt-1">{{ $this->dating->title }}</flux:text>
                    </div>

                    <div class="grid gap-6 sm:grid-cols-2">
                        <flux:input wire:model="start_date" :label="__('Starting date')" type="date" required />
                        <flux:input wire:model="target_date" :label="__('Completion date')" type="date" required />
                    </div>

                    <flux:text class="text-sm">{{ __('For an extension or a correction. The status and review list stay as they are.') }}</flux:text>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" variant="primary" data-test="save-dates-button">{{ __('Save dates') }}</flux:button>
                    </div>
                </form>
            @endif
            @endisland
        </flux:modal>
    @endif
</section>
