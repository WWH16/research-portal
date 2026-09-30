<?php

use App\Models\Category;
use App\Models\Department;
use App\Models\ResearchType;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * Admins get the yearly summary first: faculty counts per stage, each opening the faculty behind it
 * and exportable as a report. Below it, the monthly trend as stacked columns, "where from / what
 * about" as ranked horizontal bars, and the review queue. Faculty get their own progress.
 */
new #[Title('Dashboard')] class extends Component {
    /** The year the admin summary covers; projects are filed under the year they were submitted. */
    #[Url]
    public int $year = 0;

    /** The faculty group whose list is open under the summary tiles, if any. */
    #[Url(except: '')]
    public string $group = '';

    /** Department code the summary is narrowed to; faculty count under their own department. */
    #[Url(as: 'dept', except: '')]
    public string $department = '';

    public function mount(): void
    {
        $this->year = $this->year ?: now()->year;
    }

    #[Computed]
    public function isAdmin(): bool
    {
        return Auth::user()->isAdmin();
    }

    /**
     * The yearly summary's faculty groups, each keyed to the projects that put someone in it.
     * A member counts once per group however many projects or studies they are on, and can be
     * in several groups at once, such as one completed project and one still at proposal stage.
     *
     * @return array<string, array{label: string, filter: Closure}>
     */
    private function groups(): array
    {
        return [
            'submitted' => ['label' => __('Faculty who submitted'), 'filter' => fn ($query) => $query],
            // The inverse of "submitted": verified faculty on no project filed that year. See facultyIn().
            'pending' => ['label' => __('Not yet submitted'), 'filter' => fn ($query) => $query],
            'proposal' => ['label' => __('At proposal stage'), 'filter' => fn ($query) => $query->whereIn('status', Submission::PROPOSAL_STAGES)],
            'completed' => ['label' => __('Completed'), 'filter' => fn ($query) => $query->where('status', 'Completed')],
            'delayed' => ['label' => __('Delayed'), 'filter' => fn ($query) => $query->delayed()],
        ];
    }

    /**
     * A constraint on a faculty member's projects: the selected year, narrowed to one group.
     */
    private function projectsIn(string $group): Closure
    {
        return fn ($query) => ($this->groups()[$group]['filter'])($query->where('year', $this->year));
    }

    /**
     * Faculty in one group for the selected year, narrowed to the selected department.
     */
    private function facultyIn(string $group): Builder
    {
        $query = $group === 'pending'
            ? User::where('role', 'faculty')->whereNotNull('email_verified_at')->whereDoesntHave('projects', $this->projectsIn($group))
            : User::whereHas('projects', $this->projectsIn($group));

        return $query->tap($this->inDepartment());
    }

    /**
     * Number of faculty in each group for the selected year and department.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function facultyCounts(): array
    {
        return collect($this->groups())->map(fn ($group, $key) => $this->facultyIn($key)->count())->all();
    }

    /**
     * Narrow a query to the selected department, when one is picked. Works on faculty and projects alike.
     */
    private function inDepartment(): Closure
    {
        return fn ($query) => $query->when($this->department !== '', fn ($query) => $query->whereRelation('department', 'code', $this->department));
    }

    /**
     * The selected year's projects, how many are completed, and how many of those had the
     * terminal report in by the target date.
     *
     * @return array{projects: int, completed: int, onTime: int}
     */
    #[Computed]
    public function yearProjects(): array
    {
        $projects = Submission::where('year', $this->year)->tap($this->inDepartment())->get(['status', 'target_date', 'terminal_uploaded_at']);
        $completed = $projects->where('status', 'Completed');

        return [
            'projects' => $projects->count(),
            'completed' => $completed->count(),
            'onTime' => $completed->filter(fn ($project) => $project->completedOnTime())->count(),
        ];
    }

    /**
     * The faculty behind the open group's count, each with their projects in that group.
     */
    #[Computed]
    public function groupFaculty(): Collection
    {
        if (! $this->isAdmin || ! array_key_exists($this->group, $this->groups())) {
            return collect();
        }

        return $this->facultyIn($this->group)
            ->with(['department:id,code', 'projects' => $this->projectsIn($this->group)])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'researcher_id', 'department_id']);
    }

    /**
     * Download the open group's faculty list as a CSV report.
     */
    public function export(): StreamedResponse
    {
        $this->authorize('viewAny', Submission::class);

        $label = $this->groups()[$this->group]['label'] ?? abort(404);
        $faculty = $this->groupFaculty;

        return response()->csv(
            Str::slug($label.' '.$this->department.' '.$this->year).'.csv',
            [__('Name'), __('Researcher ID'), __('College'), __('Projects')],
            $faculty->map(fn ($member) => [$member->name, $member->researcher_id, $member->department?->code, $member->projects->pluck('title')->join('; ')]),
        );
    }

    /**
     * The member's projects per status, in stage order, for the faculty tiles.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function statusCounts(): array
    {
        $counts = Submission::involving(Auth::user())->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(Submission::STATUSES)->mapWithKeys(fn ($status) => [$status => (int) ($counts[$status] ?? 0)])->all();
    }

    /**
     * Projects filed in each of the last 12 months, split by their current status: everyone's for admins, the member's own for faculty.
     *
     * @return list<array{month: Carbon, counts: array<string, int>, total: int}>
     */
    #[Computed]
    public function monthly(): array
    {
        $start = now()->startOfMonth()->subMonths(11);

        // ponytail: grouped in PHP so it runs the same on MySQL and SQLite; move to a SQL GROUP BY past ~50k proposals a year.
        $query = $this->isAdmin ? Submission::query() : Submission::involving(Auth::user());
        $rows = $query->where('created_at', '>=', $start)->get(['status', 'created_at'])
            ->groupBy(fn ($submission) => $submission->created_at->format('Y-m'));

        return collect(range(0, 11))->map(function ($offset) use ($start, $rows) {
            $month = $start->copy()->addMonths($offset);
            $inMonth = $rows->get($month->format('Y-m'), collect())->countBy('status');
            $counts = collect(Submission::STATUSES)->mapWithKeys(fn ($status) => [$status => $inMonth->get($status, 0)])->all();

            return ['month' => $month, 'counts' => $counts, 'total' => array_sum($counts)];
        })->all();
    }

    /**
     * Projects with a new or corrected document the Research Office hasn't reviewed, longest waiting first.
     */
    #[Computed]
    public function waiting(): Collection
    {
        return Submission::where('awaiting_review', true)
            ->with(['user:id,name', 'department:id,code'])
            ->oldest('updated_at')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function waitingCount(): int
    {
        return Submission::where('awaiting_review', true)->count();
    }

    /**
     * The top six of each reference list by number of projects in the selected year, for the ranked bar charts.
     *
     * @return array<string, Collection>
     */
    #[Computed]
    public function breakdowns(): array
    {
        $inYear = fn ($query) => $query->where('year', $this->year)->tap($this->inDepartment());
        $top = fn ($model, string $label) => $model::whereHas('submissions', $inYear)->withCount(['submissions' => $inYear])->orderByDesc('submissions_count')->limit(6)->get()
            ->map(fn ($row) => ['label' => $row->{$label}, 'title' => $row->{$label}, 'count' => $row->submissions_count]);

        // One department picked would chart a single bar, so that chart drops out.
        return array_filter([
            __('By college') => $this->department === '' ? $top(Department::class, 'code') : null,
            __('By research type') => $top(ResearchType::class, 'name'),
            __('By category') => $top(Category::class, 'name'),
        ]);
    }

    #[Computed]
    public function facultyWithoutDepartment(): int
    {
        return User::where('role', 'faculty')->whereNull('department_id')->count();
    }

    /**
     * Reference lists faculty need before they can submit, keyed by label with the page that fills them.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function missingSetup(): array
    {
        return array_filter([
            __('research types') => ResearchType::exists() ? null : route('research-types.index'),
            __('categories') => Category::exists() ? null : route('categories.index'),
            __('colleges') => Department::exists() ? null : route('departments.index'),
        ]);
    }

    #[Computed]
    public function delayedCount(): int
    {
        return Submission::involving(Auth::user())->delayed()->count();
    }

    /**
     * The member's projects that are past their target date, or reviewed with remarks to act on.
     */
    #[Computed]
    public function needsAttention(): Collection
    {
        return Submission::involving(Auth::user())
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->delayed())
                ->orWhere(fn ($query) => $query->whereNotNull('remarks')->where('awaiting_review', false)->where('status', '!=', 'Completed')))
            ->latest('updated_at')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function latest(): Collection
    {
        return Submission::involving(Auth::user())->with('researchType:id,name')->latest()->limit(5)->get();
    }
}; ?>

@php
    // Stage colours come from the status tokens in app.css; written out in full so Tailwind keeps them.
    $bars = ['Submitted' => 'bg-status-submitted', 'Concept' => 'bg-status-concept', 'Detailed' => 'bg-status-detailed', 'Completed' => 'bg-status-completed'];
    $tile = 'rounded-xl border border-line bg-surface p-5 sm:p-6';
@endphp

<section class="mx-auto w-full max-w-6xl">
    <header class="flex flex-wrap items-center gap-4 border-b border-line pb-6">
        <img src="{{ asset('images/isu_seal.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
        <div class="min-w-0 flex-1">
            <flux:heading size="xl" level="1">{{ __('Dashboard') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Welcome, :name.', ['name' => auth()->user()->name]) }}</flux:text>
        </div>

        @if ($this->isAdmin)
            {{-- Filters drop to their own full-width row on phones instead of pushing the page sideways --}}
            <div class="flex w-full gap-3 sm:w-auto">
            <flux:select wire:model.live="department" :aria-label="__('College')" class="min-w-0 flex-1 sm:w-40 sm:flex-none" data-test="department-select">
                <flux:select.option value="">{{ __('All colleges') }}</flux:select.option>
                @foreach (Department::orderBy('code')->pluck('code') as $option)
                    <flux:select.option :value="$option">{{ $option }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="year" :aria-label="__('Year')" class="w-28 shrink-0" data-test="year-select">
                @foreach (Submission::years($year) as $option)
                    <flux:select.option :value="$option">{{ $option }}</flux:select.option>
                @endforeach
            </flux:select>
            </div>
        @endif
    </header>

    @if ($this->isAdmin)
        @php
            $facultyCounts = $this->facultyCounts;
            $projects = $this->yearProjects;
            $filters = array_filter(['year' => $year, 'dept' => $department]);
            $scope = $department === '' ? $year : $department.', '.$year;

            $kpis = [
                'submitted' => ['label' => __('Faculty who submitted'), 'note' => trans_choice('{0} No projects filed for :year|{1} Across one project in :year|[2,*] Across :count projects in :year', $projects['projects'], ['year' => $scope])],
                'pending' => ['label' => __('Not yet submitted'), 'note' => __('Verified faculty on no project yet')],
                'proposal' => ['label' => __('At proposal stage'), 'note' => __('Concept or detailed proposal accepted'), 'swatch' => $bars['Detailed']],
                'completed' => ['label' => __('Completed'), 'note' => trans_choice('{0} No completed projects yet|{1} :on of 1 project finished on time|[2,*] :on of :count projects finished on time', $projects['completed'], ['on' => $projects['onTime']]), 'swatch' => $bars['Completed']],
                'delayed' => ['label' => __('Delayed'), 'note' => __('Past target date, no terminal report'), 'swatch' => 'bg-status-delayed'],
            ];
        @endphp

        {{-- Things blocking faculty from submitting, only when they apply --}}
        @if ($this->facultyWithoutDepartment > 0 || $this->missingSetup)
            <div class="mt-6 grid gap-3">
                @if ($this->facultyWithoutDepartment > 0)
                    <flux:callout variant="warning" icon="exclamation-triangle" data-test="no-department-tile">
                        <flux:callout.heading>{{ trans_choice('{1} One faculty member has no college and can’t submit.|[2,*] :count faculty members have no college and can’t submit.', $this->facultyWithoutDepartment) }}</flux:callout.heading>
                        <x-slot name="actions">
                            <flux:button size="sm" :href="route('users.index', ['college' => 'none', 'role' => 'faculty'])" wire:navigate class="max-sm:h-11 max-sm:px-4">{{ __('Assign colleges') }}</flux:button>
                        </x-slot>
                    </flux:callout>
                @endif

                @if ($this->missingSetup)
                    <flux:callout variant="warning" icon="exclamation-triangle" data-test="setup-tile">
                        <flux:callout.heading>{{ __('Finish setup') }}</flux:callout.heading>
                        <flux:callout.text>
                            {{ __('Faculty can’t submit until you add :lists.', ['lists' => collect($this->missingSetup)->keys()->join(', ', ' and ')]) }}
                        </flux:callout.text>
                        <x-slot name="actions">
                            @foreach ($this->missingSetup as $label => $url)
                                <flux:button size="sm" :href="$url" wire:navigate class="max-sm:h-11 max-sm:px-4">{{ __('Add :list', ['list' => $label]) }}</flux:button>
                            @endforeach
                        </x-slot>
                    </flux:callout>
                @endif
            </div>
        @endif

        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-12">
            {{-- The yearly summary: faculty counts by stage, each opening the list of faculty behind it --}}
            {{-- Five tiles: one row only once each has room (xl); pairs below that, the last spanning the row --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 sm:[&>:last-child]:col-span-2 lg:col-span-12 xl:grid-cols-5 xl:[&>:last-child]:col-span-1" data-test="summary-tiles">
                @foreach ($kpis as $key => $kpi)
                    @include('partials.stat-tile', $kpi + ['value' => $facultyCounts[$key], 'href' => route('dashboard', $filters + ['group' => $key]).'#faculty-list'])
                @endforeach
            </div>

            @if ($group !== '' && isset($kpis[$group]))
                <section id="faculty-list" class="{{ $tile }} lg:col-span-12" data-test="faculty-list">
                    <div class="flex flex-wrap items-baseline justify-between gap-4">
                        <div>
                            <flux:heading level="2">{{ $kpis[$group]['label'] }}, {{ $scope }}</flux:heading>
                            <flux:text class="mt-1">{{ trans_choice('{0} No faculty|{1} One faculty member|[2,*] :count faculty', $this->groupFaculty->count()) }}</flux:text>
                        </div>
                        <div class="flex gap-2">
                            @if ($this->groupFaculty->isNotEmpty())
                                <flux:button size="sm" icon="arrow-down-tray" wire:click="export" data-test="export-button" class="max-sm:h-11 max-sm:px-4">{{ __('Export CSV') }}</flux:button>
                            @endif
                            <flux:button size="sm" variant="ghost" :href="route('dashboard', $filters)" wire:navigate class="max-sm:h-11 max-sm:px-4">{{ __('Close') }}</flux:button>
                        </div>
                    </div>

                    @if ($this->groupFaculty->isEmpty())
                        <flux:text class="mt-4">
                            {{ $group === 'pending'
                                ? __('Every faculty member :where has submitted for :year.', ['where' => $department === '' ? __('in the portal') : __('in :dept', ['dept' => $department]), 'year' => $year])
                                : __('No faculty in this group for :year.', ['year' => $year]) }}
                        </flux:text>
                    @else
                        {{-- Person on the left, their projects on the right. Each project is title | status, so statuses
                             line up in one column and long titles wrap inside the card instead of pushing past it. --}}
                        <ul class="mt-4 divide-y divide-line border-t border-line text-sm">
                            @foreach ($this->groupFaculty as $member)
                                <li class="grid gap-1.5 py-2.5 sm:grid-cols-[14rem_minmax(0,1fr)] sm:gap-4">
                                    {{-- Name and meta on one line so a one-project row stays one line tall --}}
                                    <p class="flex min-w-0 items-center gap-2 self-start">
                                        <span class="truncate font-medium text-zinc-800" title="{{ $member->name }}">{{ $member->name }}</span>
                                        <span class="shrink-0 text-xs text-zinc-500">
                                            {{ $member->department?->code ?? __('No college') }}
                                            @if ($group !== 'pending')
                                                · {{ $member->projects->count() }}
                                                <span class="sr-only">{{ trans_choice('{1} project|[2,*] projects', $member->projects->count()) }}</span>
                                            @endif
                                        </span>
                                    </p>
                                    @if ($group === 'pending')
                                        {{-- No projects to list, so show how to reach them for a follow-up --}}
                                        <flux:link :href="'mailto:'.$member->email" variant="ghost" class="truncate">{{ $member->email }}</flux:link>
                                    @else
                                    <ul class="grid gap-1.5">
                                        @foreach ($member->projects as $project)
                                            <li class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3">
                                                <flux:link :href="route('submissions.index', ['year' => $year, 'review' => $project->id])" variant="ghost" wire:navigate>{{ $project->title }}</flux:link>
                                                {{-- Stage plus Awaiting review / Delayed; reversed so the stage keeps its column at the right edge --}}
                                                @include('partials.project-status', ['submission' => $project, 'class' => 'flex-row-reverse', 'timing' => true])
                                            </li>
                                        @endforeach
                                    </ul>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif

            {{-- Trend over time, split by status: stacked columns --}}
            @include('partials.monthly-chart', ['monthly' => $this->monthly, 'heading' => __('Submissions per month')])

            {{-- Magnitude comparisons for the selected year: ranked horizontal bars, one hue, value at the tip --}}
            @foreach ($this->breakdowns as $heading => $rows)
                <section class="{{ $tile }} lg:col-span-4">
                    <flux:heading level="2">{{ $heading }}</flux:heading>

                    @if ($rows->isEmpty())
                        <flux:text class="mt-4">{{ __('No projects for :year.', ['year' => $year]) }}</flux:text>
                    @else
                        @php
                            $most = $rows->max('count');
                        @endphp
                        <dl class="mt-5 grid gap-3">
                            @foreach ($rows as $row)
                                <div class="grid grid-cols-[minmax(0,7rem)_1fr] items-center gap-3 text-sm">
                                    <dt class="truncate text-zinc-800" title="{{ $row['title'] }}">{{ $row['label'] }}</dt>
                                    <dd class="flex items-center gap-2 border-s border-zinc-300 py-0.5">
                                        <span class="h-4 rounded-e bg-isu-green-600" style="width: {{ max(2, $row['count'] / $most * 80) }}%" aria-hidden="true"></span>
                                        <span class="font-medium tabular-nums text-zinc-700">{{ $row['count'] }}</span>
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                </section>
            @endforeach

            {{-- The review queue: new and corrected uploads, longest waiting first, so nothing waits forever --}}
            <section class="{{ $tile }} lg:col-span-12" data-test="waiting-tile">
                <div class="flex items-baseline justify-between gap-4">
                    <flux:heading level="2">{{ __('Waiting for review') }}</flux:heading>
                    @if ($this->waitingCount > 0)
                        <flux:link :href="route('submissions.index', ['status' => 'review'])" wire:navigate class="shrink-0 text-sm">{{ __('View all :count', ['count' => $this->waitingCount]) }}</flux:link>
                    @endif
                </div>

                @if ($this->waiting->isEmpty())
                    <flux:text class="mt-4">{{ __('Nothing is waiting for review.') }}</flux:text>
                @else
                    <ul class="mt-4 divide-y divide-line">
                        @foreach ($this->waiting as $submission)
                            <li class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-medium text-zinc-800">{{ $submission->title }}</p>
                                    <p class="truncate text-sm text-zinc-500">
                                        {{ $submission->user->name }}, {{ $submission->department->code }}.
                                        {{ __('At :status, updated :when', ['status' => __($submission->status), 'when' => $submission->updated_at->diffForHumans()]) }}
                                    </p>
                                </div>
                                <flux:button size="sm" :href="route('submissions.index', ['review' => $submission->id])" wire:navigate class="shrink-0 max-sm:h-11 max-sm:px-4">{{ __('Review') }}</flux:button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    @else
        @php
            $counts = $this->statusCounts;
            $total = array_sum($counts);

            $kpis = [
                ['label' => __('My projects'), 'value' => $total, 'note' => __('Filed or listed as proponent'), 'href' => route('submissions.index')],
                ['label' => __('At proposal stage'), 'value' => $counts['Concept'] + $counts['Detailed'], 'note' => __('Concept or detailed proposal accepted'), 'href' => route('submissions.index', ['status' => 'proposal']), 'swatch' => $bars['Detailed']],
                ['label' => __('Completed'), 'value' => $counts['Completed'], 'note' => __('Terminal report accepted'), 'href' => route('submissions.index', ['status' => 'Completed']), 'swatch' => $bars['Completed']],
                ['label' => __('Delayed'), 'value' => $this->delayedCount, 'note' => __('Past target date, no terminal report'), 'href' => route('submissions.index', ['status' => 'delayed']), 'swatch' => 'bg-status-delayed'],
            ];
        @endphp

        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-12">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:col-span-12 lg:grid-cols-4">
                @foreach ($kpis as $kpi)
                    @include('partials.stat-tile', $kpi)
                @endforeach
            </div>

            <section class="{{ $tile }} lg:col-span-8" data-test="attention-tile">
                <flux:heading level="2">{{ __('Needs your attention') }}</flux:heading>

                @if ($this->needsAttention->isEmpty())
                    <flux:text class="mt-4">{{ __('Nothing needs your attention.') }}</flux:text>
                @else
                    <ul class="mt-4 divide-y divide-line">
                        @foreach ($this->needsAttention as $submission)
                            <li class="py-3 first:pt-0 last:pb-0">
                                <div class="flex items-center gap-3">
                                    <p class="min-w-0 flex-1 truncate font-medium text-zinc-800">{{ $submission->title }}</p>
                                    @if ($submission->isDelayed())
                                        <flux:badge size="sm" color="red">{{ __('Delayed') }}</flux:badge>
                                    @endif
                                </div>
                                @if ($submission->isDelayed())
                                    <p class="mt-1 text-sm text-zinc-600">{{ __('Target date :date has passed. Upload the terminal report when it’s ready.', ['date' => $submission->target_date->format('M j, Y')]) }}</p>
                                @endif
                                @if ($submission->remarks && ! $submission->awaiting_review)
                                    <p class="mt-1 text-sm text-amber-800">{{ __('Remarks: :remarks', ['remarks' => $submission->remarks]) }}</p>
                                @endif
                                <flux:link :href="route('submissions.edit', $submission)" wire:navigate class="mt-2 inline-block text-sm">{{ __('Open project') }}</flux:link>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <div class="flex flex-col gap-6 lg:col-span-4">
                <section class="{{ $tile }}" data-test="submit-tile">
                    <flux:heading level="2">{{ __('Submit a proposal') }}</flux:heading>

                    @if (auth()->user()->department_id)
                        <flux:text class="mt-2">{{ __('Enter the project once, then upload each document on it as it’s ready.') }}</flux:text>
                        <flux:button :href="route('submissions.create')" variant="primary" icon="plus" wire:navigate class="mt-4">{{ __('New proposal') }}</flux:button>
                    @else
                        <flux:text class="mt-2">{{ __('Your account has no college yet. Contact the Research Office to have one assigned.') }}</flux:text>
                    @endif
                </section>
            </div>

            @include('partials.monthly-chart', ['monthly' => $this->monthly, 'heading' => __('My submissions per month')])

            <section class="{{ $tile }} lg:col-span-12">
                <div class="flex items-baseline justify-between gap-4">
                    <flux:heading level="2">{{ __('Recent projects') }}</flux:heading>
                    @if ($total > 0)
                        <flux:link :href="route('submissions.index')" wire:navigate class="shrink-0 text-sm">{{ __('View all') }}</flux:link>
                    @endif
                </div>

                @if ($total === 0)
                    <flux:text class="mt-4">{{ __('You haven’t submitted a proposal yet.') }}</flux:text>
                @else
                    <ul class="mt-4 divide-y divide-line">
                        @foreach ($this->latest as $submission)
                            <li class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-medium text-zinc-800">{{ $submission->title }}</p>
                                    <p class="truncate text-sm text-zinc-500">{{ $submission->researchType->name }}, {{ $submission->year }}</p>
                                </div>
                                <flux:badge size="sm" :color="\App\Models\Submission::STATUS_COLORS[$submission->status] ?? 'zinc'">{{ __($submission->status) }}</flux:badge>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    @endif
</section>
