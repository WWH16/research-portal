<?php

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Submission;
use App\Notifications\ProjectDatesChanged;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
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
     * The Research Office (admins and staff) monitors every project in the portal; faculty see the ones they are on.
     */
    #[Computed]
    public function monitoring(): bool
    {
        return Auth::user()->isResearchOffice();
    }

    /**
     * Only admins move a project's dates; staff see the list without that column.
     */
    #[Computed]
    public function canChangeDates(): bool
    {
        return Auth::user()->can('review', Submission::class);
    }

    /**
     * The college codes for the College filter, read once for the column header and the phone filter that stands in for it.
     *
     * @return Collection<int, string>
     */
    #[Computed]
    public function collegeCodes(): Collection
    {
        return Department::orderBy('code')->pluck('code');
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
     * Redraw only when the sidebar finds a count that means this list moved: a proposal to review for admins, and for
     * faculty a project to revise or a notice, since passing a concept sends a notice but leaves nothing to revise.
     *
     * @param  list<string>  $changed
     */
    #[On('open-work-changed')]
    public function openWorkChanged(array $changed): void
    {
        if (! array_intersect($changed, $this->monitoring ? ['toReview'] : ['toRevise', 'unread'])) {
            $this->skipRender();
        }
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
                    ->where('title', 'like', $term)
                    ->orWhereHas('user', fn ($query) => $query->where('name', 'like', $term))
                    ->orWhereHas('proponents.user', fn ($query) => $query->where('name', 'like', $term))
                    ->orWhereHas('proponents', fn ($query) => $query->where('name', 'like', $term)));
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
                ->map(fn ($rows, $study) => __('Study :number', ['number' => $study]).': '.$rows->map(fn ($row) => ($row->user?->name ?? $row->name).' ('.$row->role.')')->join(', '))
                ->join('; '),
            $project->proponents->unique(fn ($row) => $row->personKey())->count(),
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
        $this->authorize('markPresented', Submission::class);

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
     * Save the new dates, logged like a project edit: "Changed the completion date from … to …". A completed
     * project's dates are locked, so whether it finished on time can't change after the fact.
     */
    public function saveDates(): void
    {
        $this->authorize('review', Submission::class);

        $validated = $this->validate([
            'start_date' => ['required', 'date'],
            'target_date' => ['required', 'date', 'after:start_date'],
        ], [
            'target_date.after' => __('The completion date must be after the starting date.'),
        ], [
            'start_date' => __('starting date'),
            'target_date' => __('completion date'),
        ]);

        [$submission, $dates] = DB::transaction(function () use ($validated) {
            $submission = Submission::findOrFail($this->datingId);
            abort_if($submission->status === 'Completed', 403);
            $submission->update($validated);

            // Compared by day, so a saved time of day on the old date doesn't count as a change.
            $dates = collect(['start_date', 'target_date'])
                ->filter(fn (string $column) => $submission->wasChanged($column))
                ->mapWithKeys(fn (string $column) => [$column => [
                    ($previous = $submission->getPrevious()[$column]) ? Date::parse($previous)->toDateString() : null,
                    $submission->{$column}->toDateString(),
                ]])
                ->filter(fn (array $change) => $change[0] !== $change[1])
                ->all();
            if ($dates !== []) {
                ActivityLog::record('submission.dates_changed', $submission, ['values' => $dates]);
            }

            return [$submission, $dates];
        });

        // The deadline reminders move with the dates, so the project's people hear of it, once the save has committed.
        if ($dates !== []) {
            Notification::send($submission->people(), new ProjectDatesChanged($submission));
        }

        Flux::modal('project-dates')->close();
        Flux::toast(variant: $dates === [] ? 'warning' : 'success', text: $dates === [] ? __('No changes to save.') : __('Dates changed.'));
        $this->datingId = null;
        unset($this->submissions, $this->dating);

        // Saving runs inside the dates island, which only redraws itself; the list has to show the new dates too.
        $this->renderIsland('list');
    }
}; ?>

{{-- Same width as the Research Drive, so moving between the two lists doesn't shift the page --}}
<section class="mx-auto w-full max-w-5xl">
    <x-page-title :subtitle="$this->monitoring ? __('Every project filed across the portal.') : __('Projects you filed or are a proponent on.')">{{ __('Submissions') }}</x-page-title>

    {{-- Filters and results redraw together; opening the dates dialog redraws only the dates island below.
         The filters drop Flux's shadow and darker bottom edge so they sit quieter than the table they narrow. --}}
    @island(name: 'list', always: true)
    <div class="mt-6 flex flex-wrap items-center gap-3 [&_[data-flux-control]]:border-b-zinc-200 [&_[data-flux-control]]:shadow-none">
        @if ($this->monitoring)
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search titles or faculty')" :aria-label="__('Search titles or faculty')" class="min-w-48 flex-1" />
        @endif

        {{-- On phones the two filters share one row at half width each, instead of two uneven rows --}}
        <div class="flex gap-3 max-sm:w-full">
            <flux:select wire:model.live="statusFilter" :aria-label="__('Filter by status')" class="max-w-48 max-sm:max-w-none max-sm:flex-1" data-test="status-filter">
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

            <flux:select wire:model.live="yearFilter" :aria-label="__('Filter by year')" class="max-w-36 max-sm:max-w-none max-sm:flex-1">
                <flux:select.option value="">{{ __('All years') }}</flux:select.option>
                @foreach (Submission::years() as $option)
                    <flux:select.option :value="$option">{{ $option }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        {{-- The count sits with the filters it answers; the pager below only appears once there is a second page --}}
        @if ($this->submissions->isNotEmpty())
            <flux:text class="text-sm tabular-nums sm:ms-auto" data-test="results-count">
                {{ trans_choice('{1} 1 project|[2,*] :count projects', $this->submissions->total()) }}
            </flux:text>
        @endif

        @if ($this->monitoring)
            {{-- Phones drop the College column, so its filter comes up here beside the count --}}
            <div class="sm:hidden" data-test="phone-college-filter">
                <x-college-filter model="department" :value="$department" :codes="$this->collegeCodes" />
            </div>

            <flux:button icon="arrow-down-tray" wire:click="export" :disabled="$this->submissions->isEmpty()" data-test="export-submissions-button" class="max-sm:ms-auto max-sm:h-11">
                {{ __('Export CSV') }}
            </flux:button>
        @else
            {{-- Takes the count's place at the end of the row when there is no count --}}
            <flux:button :href="route('submissions.create')" variant="primary" icon="plus" wire:navigate @class(['max-sm:h-11 max-sm:w-full', 'sm:ms-auto' => $this->submissions->isEmpty()])>
                {{ __('New proposal') }}
            </flux:button>
        @endif
    </div>

    @if ($this->submissions->isEmpty())
        {{-- Filters that find nothing say so and undo themselves; an empty portal and an empty account each say what fills them --}}
        @if ($statusFilter !== '' || $yearFilter !== '' || $department !== '' || $search !== '')
            <x-empty-state icon="magnifying-glass" :heading="__('No projects match these filters.')" :text="__('Try another status, year, college or search.')" class="mt-8">
                <flux:button wire:click="clearFilters" class="max-sm:h-11" data-test="clear-filters-button">{{ __('Clear filters') }}</flux:button>
            </x-empty-state>
        @elseif ($this->monitoring)
            <x-empty-state icon="folder-open" :heading="__('No projects have been filed yet.')" :text="__('They appear here as faculty submit proposals.')" class="mt-8" />
        @else
            <x-empty-state icon="document-plus" :heading="__('You’re not on any projects yet.')" :text="__('Submit a proposal, or ask a colleague to list you as a proponent on theirs.')" class="mt-8">
                @if (auth()->user()->department_id)
                    <flux:button :href="route('submissions.create')" icon="plus" wire:navigate class="max-sm:h-11">{{ __('New proposal') }}</flux:button>
                @endif
            </x-empty-state>
        @endif
    @else
        {{-- Every cell starts at the top, so each row reads across one line from the title. relative anchors the cells'
             screen-reader-only text to the table, inside its scroll area, so on phones it can't widen the whole page. Columns sit
             a little closer than Flux's default, so the seven of them fit a laptop screen with the document pills on one line --}}
        <flux:table class="relative mt-4 [&_td]:align-top [&_td+td]:ps-1 [&_th+th]:ps-1" :paginate="$this->submissions->hasPages() ? $this->submissions : null">
            <flux:table.columns>
                <flux:table.column>{{ __('Project') }}</flux:table.column>
                @if ($this->monitoring)
                    <flux:table.column class="max-sm:hidden" data-test="department-column">
                        <x-college-filter model="department" :value="$department" :codes="$this->collegeCodes" />
                    </flux:table.column>
                @endif
                {{-- Phones keep the project and its actions; the project cell repeats status, college and due date in short --}}
                <flux:table.column class="max-sm:hidden">{{ __('Year') }}</flux:table.column>
                <flux:table.column class="max-sm:hidden">{{ __('Status') }}</flux:table.column>
                @if ($this->monitoring)
                    <flux:table.column class="max-sm:hidden">{{ __('Presented') }}</flux:table.column>
                @endif
                <flux:table.column class="max-sm:hidden">{{ __('Schedule') }}</flux:table.column>
                @if (! $this->monitoring || $this->canChangeDates)
                    <flux:table.column align="end">{{ $this->monitoring ? __('Dates') : __('Actions') }}</flux:table.column>
                @endif
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->submissions as $submission)
                    @php
                        $title = $submission->displayTitle();
                        $overdue = $submission->isDelayed();
                        $onTime = $submission->completedOnTime();
                        // How the project stands against its due date, in the schedule column and on phones
                        $standing = match (true) {
                            $onTime !== null => $onTime ? __('Finished on time') : __('Finished late'),
                            $overdue => trans_choice('{1} 1 day overdue|[2,*] :count days overdue', (int) $submission->target_date->diffInDays(today())),
                            $submission->target_date !== null => trans_choice('{0} Due today|{1} 1 day left|[2,*] :count days left', (int) today()->diffInDays($submission->target_date)),
                            default => __('No schedule yet'),
                        };
                        $standingClass = match (true) {
                            $onTime === true => 'text-green-700',
                            $onTime === false => 'text-orange-700',
                            $overdue => 'font-medium text-red-700',
                            default => 'text-zinc-500',
                        };
                        $due = __('Due :date', ['date' => $submission->target_date?->format('M j, Y') ?? __('not set')]);
                    @endphp
                    <flux:table.row :key="$submission->id">
                        <flux:table.cell class="max-w-sm whitespace-normal sm:min-w-64">
                            <a href="{{ route('drive.show', $submission) }}" wire:navigate title="{{ $title }}" class="line-clamp-2 font-medium text-zinc-800 [overflow-wrap:anywhere] hover:underline">{{ $title }}</a>
                            <p class="mt-0.5 truncate text-zinc-500"><span class="sr-only">{{ __('Proponents:') }}</span> {{ $submission->proponents->unique(fn ($row) => $row->personKey())->map->shortName()->join(', ') }}</p>
                            {{-- All four documents in stage order: a filled pill opens one on file, a dashed one is still to come, so
                                 every row shows the same sequence, on one line from tablet width up. Phones set them two by two at
                                 equal widths, 36px tall, so the pairs line up and each is easy to tap. A filled pill carries a small
                                 open-in-new-tab icon, the one the portal's other document links use, so it reads as something to open.
                                 Screen readers hear the full document name and which project it is for. A tooltip names the full
                                 document; on phones it is a block, so the pill keeps its cell width --}}
                            <ul class="mt-2 grid grid-cols-2 gap-1.5 sm:flex sm:gap-1" aria-label="{{ __('Documents') }}">
                                @foreach (Submission::DOCUMENTS as $stage => $label)
                                    <li>
                                        <flux:tooltip :content="$submission->{$stage.'_path'} ? __('Open the :document in a new tab', ['document' => Str::lower(__($label))]) : __(':document: not uploaded yet', ['document' => __($label)])" class="max-sm:block">
                                            @if ($submission->{$stage.'_path'})
                                                <a href="{{ route('submissions.document', [$submission, $stage]) }}" target="_blank" rel="noopener" aria-label="{{ __(':document for :title, opens in a new tab', ['document' => __($label), 'title' => $submission->title]) }}" class="block rounded-md outline-accent hover:[&>*]:bg-isu-green-100! focus-visible:outline-2 focus-visible:outline-offset-2">
                                                    <flux:badge size="sm" class="gap-0.5! bg-isu-green-50! px-1.5! text-isu-green-800! max-sm:h-9 max-sm:w-full max-sm:justify-center">{{ Str::before(__($label), ' ') }}<flux:icon.arrow-top-right-on-square variant="micro" class="size-3 shrink-0 text-isu-green-700" aria-hidden="true" /></flux:badge>
                                                </a>
                                            @else
                                                <span class="inline-flex items-center whitespace-nowrap rounded-md border border-dashed border-zinc-300 px-1.5 py-[3px] text-xs font-medium text-zinc-500 max-sm:h-9 max-sm:w-full max-sm:justify-center">
                                                    {{ Str::before(__($label), ' ') }}<span class="sr-only">{{ __(': not uploaded yet') }}</span>
                                                </span>
                                            @endif
                                        </flux:tooltip>
                                    </li>
                                @endforeach
                            </ul>
                            {{-- Set further below the pills than they sit from each other, so a status badge reads apart from them --}}
                            <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 sm:hidden" data-test="phone-summary">
                                @include('partials.project-status', ['delay' => false, 'stageAfterReview' => true])
                                @if ($this->monitoring)
                                    <p class="text-xs text-zinc-600">{{ $submission->department->code }}</p>
                                @endif
                                {{-- Two items, so the row wraps between them rather than joining them with a dot --}}
                                <p class="text-xs tabular-nums text-zinc-600">{{ $due }}</p>
                                <p class="text-xs tabular-nums {{ $standingClass }}">{{ $standing }}</p>
                            </div>
                            @if (! $this->monitoring && $submission->conceptReview() === 'returned')
                                <p class="mt-2 text-sm text-amber-800 wrap-anywhere">{{ __('Research Office remarks: :remarks', ['remarks' => $submission->remarks]) }}</p>
                            @endif
                        </flux:table.cell>
                        @if ($this->monitoring)
                            <flux:table.cell class="max-sm:hidden">
                                {{ $submission->department->code }}
                            </flux:table.cell>
                        @endif
                        <flux:table.cell class="tabular-nums max-sm:hidden">{{ $submission->year }}</flux:table.cell>
                        <flux:table.cell class="max-sm:hidden">
                            {{-- Delayed shows in the schedule as the overdue days, so the status keeps to stage and review; at Concept
                                 the status says only where the review stands, since the pills already show the concept on file --}}
                            @include('partials.project-status', ['delay' => false, 'stageAfterReview' => true])
                        </flux:table.cell>
                        @if ($this->monitoring)
                            {{-- Completed rows only; the key redraws the box to the saved value. The accessible name starts with the
                                 visible state, so voice control matches it, and adds which project. --}}
                            <flux:table.cell class="max-sm:hidden">
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
                                        <p class="mt-1.5 flex items-center gap-1 text-sm text-amber-800" data-test="skipped-stage">
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
                        {{-- Three lines on every row: the due date leads, the start date follows, and the last line says how the
                             project stands against the due date, so the column never shifts from row to row --}}
                        <flux:table.cell class="whitespace-nowrap tabular-nums max-sm:hidden">
                            <div @class(['font-medium', 'text-red-700' => $overdue, 'text-zinc-800' => ! $overdue])>{{ $due }}</div>
                            <div class="text-sm text-zinc-500">{{ __('Starts :date', ['date' => $submission->start_date?->format('M j, Y') ?? __('not set')]) }}</div>
                            <div class="text-sm {{ $standingClass }}">{{ $standing }}</div>
                        </flux:table.cell>
                        @if (! $this->monitoring || $this->canChangeDates)
                            <flux:table.cell align="end">
                                @if ($this->monitoring && $submission->status === 'Completed')
                                    <span class="text-zinc-500" aria-hidden="true">–</span>
                                    <span class="sr-only">{{ __('Dates locked: project completed') }}</span>
                                @elseif ($this->monitoring)
                                    <flux:button size="sm" variant="ghost" inset="top bottom" icon="calendar-days" wire:click="editDates({{ $submission->id }})" wire:island="dates" :aria-label="__('Change dates for :title', ['title' => $submission->title])" :tooltip="__('Change dates')" data-test="change-dates-button" class="max-sm:size-11" />
                                @else
                                    <flux:button size="sm" variant="ghost" inset="top bottom" icon="pencil-square" :href="route('submissions.edit', $submission)" wire:navigate :aria-label="__('Edit :title', ['title' => $submission->title])" data-test="edit-submission-button" class="max-sm:h-11">
                                        {{ __('Edit') }}
                                    </flux:button>
                                @endif
                            </flux:table.cell>
                        @endif
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
    @endisland

    @if ($this->canChangeDates)
        {{-- Closing forgets the project without a request, so later filter changes don't reload it --}}
        <flux:modal name="project-dates" wire:close="$set('datingId', null, false)" class="w-full sm:w-96" aria-labelledby="project-dates-heading">
            @island(name: 'dates', always: true)
            @if ($this->dating)
                <form wire:submit="saveDates" class="flex flex-col gap-6">
                    <div>
                        <flux:heading size="lg" id="project-dates-heading">{{ __('Change dates') }}</flux:heading>
                        <flux:text class="mt-1 line-clamp-2 [overflow-wrap:anywhere]" title="{{ $this->dating->displayTitle() }}">{{ $this->dating->displayTitle() }}</flux:text>
                    </div>

                    <div class="grid gap-6 sm:grid-cols-2">
                        <flux:input wire:model="start_date" :label="__('Starting date')" type="date" required />
                        <flux:input wire:model="target_date" :label="__('Completion date')" type="date" x-bind:min="$wire.start_date && new Date(Date.parse($wire.start_date) + 864e5).toISOString().slice(0, 10)" required />
                    </div>

                    <flux:text class="text-sm">{{ __('For an extension or a correction. The faculty on this project will be notified of the new dates. The status and review list stay as they are.') }}</flux:text>

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
