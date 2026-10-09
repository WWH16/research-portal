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
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Submissions')] class extends Component {
    use WithPagination;

    /** The project open in the Change dates dialog. */
    public ?int $datingId = null;
    public string $start_date = '';
    public string $target_date = '';

    /** A status, "proposal" (Concept or Detailed), "revision" (concept returned), "delayed", "presented" or "not-presented" (completed projects ticked or not for the in-house review); the dashboard tiles link here with ?status=. */
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
     * Confirm a just-submitted proposal with the same toast the rest of the portal uses.
     */
    public function mount(): void
    {
        if ($message = session('status')) {
            Flux::toast(variant: 'success', text: $message);
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
                $this->statusFilter === 'revision' => $query->where('awaiting_review', false)->where('concept_passed', false),
                $this->statusFilter === 'delayed' => $query->delayed(),
                $this->statusFilter === 'presented' => $query->where('presented', true),
                // Completed but not ticked: the projects to leave out when printing in-house review certificates.
                $this->statusFilter === 'not-presented' => $query->where('status', 'Completed')->where('presented', false),
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

        $rows = $this->filtered()->with('category:id,name')->latest()->lazy()->map(fn (Submission $project) => [
            $project->title,
            $project->year,
            $project->department->code,
            $project->status,
            __(['pending' => 'To review', 'passed' => 'Passed', 'returned' => 'Needs revision'][$project->conceptReview()]),
            $yesNo($project->isDelayed()),
            $project->user->name,
            $project->proponents->sortBy([['study', 'asc'], ['id', 'asc']])->groupBy('study')
                ->map(fn ($rows, $study) => __('Study :number', ['number' => $study]).': '.$rows->map(fn ($row) => $row->user->name.' ('.$row->role.')')->join(', '))
                ->join('; '),
            $project->proponents->unique('user_id')->count(),
            $project->category->name,
            $project->start_date?->toDateString(),
            $project->target_date?->toDateString(),
            $project->terminal_uploaded_at?->toDateString(),
            $yesNo($project->completedOnTime()),
            // Only a completed project can present, so earlier stages leave the cell blank rather than "No".
            $yesNo($project->status === 'Completed' ? $project->presented : null),
            collect(Submission::DOCUMENTS)->filter(fn ($label, $stage) => $project->{$stage.'_path'})->map(fn ($label, $stage) => ucfirst($stage))->join(', '),
            // Remarks are what to fix on a returned concept proposal; once a corrected one is in they no longer apply.
            $project->conceptReview() === 'returned' ? $project->remarks : '',
            $project->created_at->toDateString(),
            $project->abstract,
        ]);

        return response()->csv($filename, [
            __('Title'), __('Year'), __('College'), __('Status'), __('Concept review'), __('Delayed'), __('Filed by'),
            __('Proponents'), __('Faculty count'), __('Category'), __('Starting date'), __('Completion date'),
            __('Terminal report uploaded'), __('Finished on time'), __('Presented in-house'), __('Documents on file'), __('Remarks'), __('Encoded on'), __('Abstract'),
        ], $rows);
    }

    /**
     * Record whether a completed project presented at the in-house review, logged in its history. Not every
     * project presents, so the Research Office ticks it by hand before printing certificates. The value is
     * set, not flipped, so a page left open while another admin ticked the same project can't undo it.
     */
    public function setPresented(int $id, bool $presented): void
    {
        $this->authorize('review', Submission::class);

        $submission = Submission::findOrFail($id);
        abort_unless($submission->status === 'Completed', 403);

        if ($submission->presented !== $presented) {
            DB::transaction(function () use ($submission, $presented) {
                $submission->update(['presented' => $presented]);
                ActivityLog::record('submission.presented', $submission, ['presented' => $presented]);
            });
        }

        Flux::toast(variant: 'success', text: __($presented ? 'Marked “:title” as presented.' : 'Marked “:title” as not presented.', ['title' => Str::limit($submission->title, 60)]));
        unset($this->submissions);
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
}; ?>

<section class="mx-auto w-full {{ $this->monitoring ? 'max-w-6xl' : 'max-w-4xl' }}">
    <header class="flex flex-wrap items-center gap-4 border-b border-line pb-6">
        <img src="{{ asset('images/isu_seal-128.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
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

    {{-- Filters and results redraw together; opening the dates dialog redraws only the dates island below --}}
    @island(name: 'list', always: true)
    <div class="mt-6 flex flex-wrap gap-3">
        @if ($this->monitoring)
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search by faculty name')" :aria-label="__('Search by faculty name')" class="min-w-48 flex-1" />
        @endif

        <flux:select wire:model.live="statusFilter" :aria-label="__('Filter by status')" class="max-w-48" data-test="status-filter">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            <flux:select.option value="proposal">{{ __('At proposal stage') }}</flux:select.option>
            <flux:select.option value="revision">{{ __('Concept needs revision') }}</flux:select.option>
            <flux:select.option value="delayed">{{ __('Delayed') }}</flux:select.option>
            @if ($this->monitoring)
                <flux:select.option value="presented">{{ __('Presented in-house') }}</flux:select.option>
                <flux:select.option value="not-presented">{{ __('Not presented in-house') }}</flux:select.option>
            @endif
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
        {{-- Every cell starts at the top, so each row reads across one line from the title. relative anchors the cells'
             screen-reader-only text to the table, inside its scroll area, so on phones it can't widen the whole page --}}
        <flux:table class="relative mt-6 [&_td]:align-top" :paginate="$this->submissions">
            <flux:table.columns>
                <flux:table.column>{{ __('Project') }}</flux:table.column>
                @if ($this->monitoring)
                    <flux:table.column data-test="department-column">
                        <x-college-filter model="department" :value="$department" />
                    </flux:table.column>
                @endif
                <flux:table.column>{{ __('Year') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                @if ($this->monitoring)
                    <flux:table.column>{{ __('Presented in-house') }}</flux:table.column>
                @endif
                <flux:table.column>{{ __('Schedule') }}</flux:table.column>
                <flux:table.column class="w-0"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->submissions as $submission)
                    <flux:table.row :key="$submission->id">
                        <flux:table.cell class="max-w-sm">
                            <a href="{{ route('drive.show', $submission) }}" wire:navigate class="block truncate font-medium text-zinc-800 hover:underline">{{ $submission->title }}</a>
                            <p class="truncate text-zinc-500"><span class="sr-only">{{ __('Proponents:') }}</span> {{ $submission->proponents->pluck('user.name')->unique()->join(', ') }}</p>
                            {{-- Short names keep the links on one line under the title, quieter than it; screen readers hear the full
                                 document name and which project each link is for --}}
                            <div class="mt-1 flex flex-wrap gap-x-3 text-sm">
                                @foreach (Submission::DOCUMENTS as $stage => $label)
                                    @if ($submission->{$stage.'_path'})
                                        <flux:link variant="ghost" :href="route('submissions.document', [$submission, $stage])" target="_blank" rel="noopener" :aria-label="__(':document for :title, opens in a new tab', ['document' => __($label), 'title' => $submission->title])">
                                            {{ Str::before(__($label), ' ') }}
                                            <flux:icon.arrow-top-right-on-square variant="micro" class="inline size-3 align-[-1px]" />
                                        </flux:link>
                                    @endif
                                @endforeach
                            </div>
                            @if (! $this->monitoring && $submission->conceptReview() === 'returned')
                                <p class="mt-1 whitespace-normal text-sm text-amber-800">{{ __('Research Office remarks: :remarks', ['remarks' => $submission->remarks]) }}</p>
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
                        @if ($this->monitoring)
                            {{-- Completed rows only; the key redraws the box to the saved value. The accessible name starts with the
                                 visible state, so voice control matches it, and adds which project. --}}
                            <flux:table.cell>
                                @if ($submission->status === 'Completed')
                                    @php($state = $submission->presented ? __('Presented') : __('Not presented'))
                                    <flux:checkbox
                                        wire:key="presented-{{ $submission->id }}-{{ (int) $submission->presented }}"
                                        :checked="$submission->presented"
                                        wire:click="setPresented({{ $submission->id }}, {{ $submission->presented ? 'false' : 'true' }})"
                                        :label="$state"
                                        :aria-label="__(':state: :title', ['state' => $state, 'title' => $submission->title])"
                                        class="whitespace-nowrap py-0.5"
                                        data-test="presented-checkbox"
                                    />
                                    @unless ($submission->midyear_path)
                                        <p class="mt-1.5 flex items-center gap-1 whitespace-nowrap text-sm text-amber-800" data-test="skipped-stage">
                                            <flux:icon.exclamation-triangle variant="micro" class="shrink-0" aria-hidden="true" />
                                            {{ __('No mid-year report') }}
                                        </p>
                                    @endunless
                                @else
                                    {{-- A dash, not a blank, so the cell reads as "not yet" rather than missing data --}}
                                    <span class="text-zinc-500" aria-hidden="true">–</span>
                                    <span class="sr-only">{{ __('Not completed yet') }}</span>
                                @endif
                            </flux:table.cell>
                        @endif
                        <flux:table.cell class="tabular-nums">
                            <div class="whitespace-nowrap"><span class="text-zinc-500">{{ __('Starts') }}</span> {{ $submission->start_date?->format('M j, Y') ?? __('Not set') }}</div>
                            <div class="whitespace-nowrap"><span class="text-zinc-500">{{ __('Due') }}</span> {{ $submission->target_date?->format('M j, Y') ?? __('Not set') }}</div>
                            @if (($onTime = $submission->completedOnTime()) !== null)
                                <div class="text-sm {{ $onTime ? 'text-green-700' : 'text-amber-800' }}">{{ $onTime ? __('Finished on time') : __('Finished late') }}</div>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($this->monitoring)
                                <flux:button size="sm" variant="ghost" inset="top bottom" icon="calendar-days" wire:click="editDates({{ $submission->id }})" wire:island="dates" :aria-label="__('Change dates for :title', ['title' => $submission->title])" :tooltip="__('Change dates')" data-test="change-dates-button" class="max-sm:size-11" />
                            @else
                                <flux:button size="sm" variant="ghost" inset="top bottom" :href="route('submissions.edit', $submission)" wire:navigate :aria-label="__('Edit :title', ['title' => $submission->title])" data-test="edit-submission-button" class="max-sm:h-11">
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
                        <flux:input wire:model="target_date" :label="__('Completion date')" type="date" x-bind:min="$wire.start_date && new Date(Date.parse($wire.start_date) + 864e5).toISOString().slice(0, 10)" required />
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
