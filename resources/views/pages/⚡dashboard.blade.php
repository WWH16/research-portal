<?php

use App\Models\Category;
use App\Models\Department;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * Admins get the summary for a filing-date range first: faculty counts per stage, each opening the faculty behind it
 * and exportable as a report. Below it, the monthly trend as stacked columns, "where from / what
 * about" as ranked horizontal bars, and the concept proposal review queue. Faculty get their own progress.
 */
new #[Title('Dashboard')] class extends Component {
    /** First and last filing date the admin summary covers, as Y-m-d. Defaults to this calendar year. */
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    /** The faculty group whose list is open under the summary tiles, if any. */
    #[Url(except: '')]
    public string $group = '';

    /** Department code the summary is narrowed to; faculty count under their own department. */
    #[Url(as: 'dept', except: '')]
    public string $department = '';

    public function mount(): void
    {
        $this->from = $this->from ?: now()->startOfYear()->toDateString();
        $this->to = $this->to ?: now()->endOfYear()->toDateString();
    }

    /**
     * Quick picks for the date range.
     */
    public function preset(string $key): void
    {
        [$from, $to] = match ($key) {
            'last-year' => [now()->subYear()->startOfYear(), now()->subYear()->endOfYear()],
            'last-12-months' => [now()->subMonths(11)->startOfMonth(), today()],
            default => [now()->startOfYear(), now()->endOfYear()],
        };

        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    /**
     * The filing-date window as [start of the first day, end of the last day]. Faculty always see the
     * last 12 months; a bad date falls back to this year, a reversed pair is swapped, not rejected, and
     * nothing starts before Submission::FIRST_YEAR.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    #[Computed]
    public function range(): array
    {
        if (! $this->isAdmin) {
            return [now()->startOfMonth()->subMonths(11), now()->endOfMonth()];
        }

        $parse = fn (string $date, CarbonInterface $fallback) => rescue(fn () => Date::createFromFormat('Y-m-d', $date), $fallback, false);
        $start = $parse($this->from, now()->startOfYear());
        $end = $parse($this->to, now()->endOfYear());

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        $first = Date::create(Submission::FIRST_YEAR);

        return [$start->max($first)->startOfDay(), $end->max($first)->endOfDay()];
    }

    /**
     * The range as people say it: "2026" for a whole calendar year, otherwise "Jan 1 – Jun 30, 2026".
     */
    #[Computed]
    public function rangeLabel(): string
    {
        [$start, $end] = $this->range;

        return match (true) {
            $start->isSameYear($end) && $start->isSameDay($start->startOfYear()) && $end->isSameDay($end->endOfYear()) => (string) $start->year,
            $start->isSameYear($end) => $start->format('M j').' – '.$end->format('M j, Y'),
            default => $start->format('M j, Y').' – '.$end->format('M j, Y'),
        };
    }

    /**
     * Narrow a project query to the chosen filing dates.
     */
    private function inRange(): Closure
    {
        return fn ($query) => $query->whereBetween('submissions.created_at', $this->range);
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
            'midyear' => ['label' => __('At mid-year stage'), 'filter' => fn ($query) => $query->where('status', 'Mid-year')],
            'completed' => ['label' => __('Completed'), 'filter' => fn ($query) => $query->where('status', 'Completed')],
            'delayed' => ['label' => __('Delayed'), 'filter' => fn ($query) => $query->delayed()],
        ];
    }

    /**
     * A constraint on a faculty member's projects: the chosen filing dates, narrowed to one group.
     */
    private function projectsIn(string $group): Closure
    {
        return fn ($query) => ($this->groups()[$group]['filter'])($query->tap($this->inRange()));
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
     * Projects filed in the chosen range, how many are completed, and how many of those had the
     * terminal report in by the target date.
     *
     * @return array{projects: int, completed: int, onTime: int}
     */
    #[Computed]
    public function rangeProjects(): array
    {
        $completed = $this->rangeRows->where('status', 'Completed');

        return [
            'projects' => $this->rangeRows->count(),
            'completed' => $completed->count(),
            'onTime' => $completed->filter(fn ($project) => $project->completedOnTime())->count(),
        ];
    }

    /**
     * Projects filed in the chosen range and college, loaded once for the admin summary and the monthly chart.
     */
    #[Computed]
    public function rangeRows(): Collection
    {
        return Submission::query()->tap($this->inRange())->tap($this->inDepartment())->get(['status', 'created_at', 'target_date', 'terminal_uploaded_at']);
    }

    /**
     * Every project the member filed or is a proponent on, newest first. A member has a handful, so the
     * faculty tiles, lists and chart all come from this one query.
     */
    #[Computed]
    public function myProjects(): Collection
    {
        return Submission::involving(Auth::user())->with('category:id,name')->latest()->get();
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
            Str::slug($label.' '.$this->department.' '.$this->from.' '.$this->to).'.csv',
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
        $counts = $this->myProjects->countBy('status');

        return collect(Submission::STATUSES)->mapWithKeys(fn ($status) => [$status => $counts->get($status, 0)])->all();
    }

    /**
     * Projects filed in each month of the range, split by their current status: the selected college's for
     * admins, the member's own for faculty. Capped at the range's last 24 months so the columns stay readable.
     *
     * @return list<array{month: CarbonInterface, counts: array<string, int>, total: int}>
     */
    #[Computed]
    public function monthly(): array
    {
        [$from, $end] = $this->range;
        $start = $from->startOfMonth()->max($end->startOfMonth()->subMonths(23));
        $months = (int) round($start->diffInMonths($end->startOfMonth())) + 1;

        // ponytail: grouped in PHP so it runs the same on MySQL and SQLite; move to a SQL GROUP BY past ~50k proposals a year.
        $rows = ($this->isAdmin ? $this->rangeRows : $this->myProjects)
            ->filter(fn ($submission) => $submission->created_at->between($start, $end))
            ->groupBy(fn ($submission) => $submission->created_at->format('Y-m'));

        return collect(range(0, $months - 1))->map(function ($offset) use ($start, $rows) {
            $month = $start->copy()->addMonths($offset);
            $inMonth = $rows->get($month->format('Y-m'), collect())->countBy('status');
            $counts = collect(Submission::STATUSES)->mapWithKeys(fn ($status) => [$status => $inMonth->get($status, 0)])->all();

            return ['month' => $month, 'counts' => $counts, 'total' => array_sum($counts)];
        })->all();
    }

    /**
     * Projects with a new or corrected concept proposal the Research Office hasn't reviewed, longest waiting first.
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
     * The top six of each reference list by number of projects filed in the range, for the ranked bar charts.
     *
     * @return array<string, Collection>
     */
    #[Computed]
    public function breakdowns(): array
    {
        $inRange = fn ($query) => $query->tap($this->inRange())->tap($this->inDepartment());
        $top = fn ($model, string $label) => $model::whereHas('submissions', $inRange)->withCount(['submissions' => $inRange])->orderByDesc('submissions_count')->limit(6)->get()
            ->map(fn ($row) => ['label' => $row->{$label}, 'title' => $row->{$label}, 'count' => $row->submissions_count]);

        // One department picked would chart a single bar, so that chart drops out.
        return array_filter([
            __('By college') => $this->department === '' ? $top(Department::class, 'code') : null,
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
            __('categories') => Category::exists() ? null : route('categories.index'),
            __('colleges') => Department::exists() ? null : route('departments.index'),
        ]);
    }

    /**
     * The member's projects that are past their target date, or whose concept proposal was returned for revision.
     */
    #[Computed]
    public function needsAttention(): Collection
    {
        return $this->myProjects
            ->filter(fn ($project) => $project->isDelayed() || $project->conceptReview() === 'returned')
            ->sortByDesc('updated_at')
            ->take(5);
    }
}; ?>

@php
    // Stage colours come from the status tokens in app.css; written out in full so Tailwind keeps them.
    $bars = ['Concept' => 'bg-status-concept', 'Detailed' => 'bg-status-detailed', 'Mid-year' => 'bg-status-midyear', 'Completed' => 'bg-status-completed'];
    $proposalSwatches = [$bars['Concept'], $bars['Detailed']];
    // Delayed is red only when something is late; a zero stays neutral so it doesn't alarm anyone.
    $delayedSwatch = fn (int $count) => $count > 0 ? 'bg-status-delayed' : 'bg-zinc-300';
@endphp

<section class="mx-auto w-full max-w-6xl">
    <header class="flex flex-wrap items-center gap-4 border-b border-line pb-6">
        <img src="{{ asset('images/isu_seal-128.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
        <div class="min-w-0 flex-1">
            <flux:heading size="xl" level="1">{{ __('Dashboard') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Welcome, :name.', ['name' => auth()->user()->shortName()]) }}</flux:text>
        </div>

        @if ($this->isAdmin)
            {{-- Filters drop to their own full-width rows on phones instead of pushing the page sideways --}}
            <div class="flex w-full flex-wrap gap-3 sm:w-auto">
            <flux:select wire:model.live="department" :aria-label="__('College')" class="w-full sm:w-40" data-test="department-select">
                <flux:select.option value="">{{ __('All colleges') }}</flux:select.option>
                @foreach (Department::orderBy('code')->pluck('code') as $option)
                    <flux:select.option :value="$option">{{ $option }}</flux:select.option>
                @endforeach
            </flux:select>

            {{-- On phones the two dates share the row's width so the quick-range button stays on screen --}}
            <div class="flex w-full items-center gap-2 sm:w-auto" data-test="date-range">
                {{-- Typing a year fires an input per digit; the wait lets it finish before the summary reloads --}}
                <flux:input type="date" wire:model.live.debounce.500ms="from" :min="Submission::FIRST_YEAR.'-01-01'" :max="$to" :aria-label="__('From')" class="min-w-0 flex-1 sm:w-40 sm:flex-none" class:input="max-sm:h-11" />
                <span class="text-sm text-zinc-500" aria-hidden="true">–</span>
                <flux:input type="date" wire:model.live.debounce.500ms="to" :min="$from" :aria-label="__('To')" class="min-w-0 flex-1 sm:w-40 sm:flex-none" class:input="max-sm:h-11" />

                <flux:dropdown position="bottom" align="end">
                    <flux:button icon="calendar-days" :aria-label="__('Quick date ranges')" :tooltip="__('Quick date ranges')" class="max-sm:size-11" />
                    <flux:menu>
                        <flux:menu.item wire:click="preset('this-year')">{{ __('This year') }}</flux:menu.item>
                        @if (now()->year > Submission::FIRST_YEAR)
                            <flux:menu.item wire:click="preset('last-year')">{{ __('Last year') }}</flux:menu.item>
                        @endif
                        <flux:menu.item wire:click="preset('last-12-months')">{{ __('Last 12 months') }}</flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            </div>
            </div>
        @endif
    </header>

    @if ($this->isAdmin)
        @php
            $facultyCounts = $this->facultyCounts;
            $projects = $this->rangeProjects;
            $filters = array_filter(['from' => $from, 'to' => $to, 'dept' => $department]);
            $scope = $department === '' ? $this->rangeLabel : $department.', '.$this->rangeLabel;

            $kpis = [
                'submitted' => ['label' => __('Faculty who submitted'), 'note' => trans_choice('{0} No projects filed in :range|{1} Across one project in :range|[2,*] Across :count projects in :range', $projects['projects'], ['range' => $scope])],
                'pending' => ['label' => __('Not yet submitted'), 'note' => __('Verified faculty on no project yet')],
                'proposal' => ['label' => __('At proposal stage'), 'note' => __('Concept or detailed proposal uploaded'), 'swatch' => $proposalSwatches],
                'midyear' => ['label' => __('At mid-year stage'), 'note' => __('Mid-year progress report uploaded'), 'swatch' => $bars['Mid-year']],
                'completed' => ['label' => __('Completed'), 'note' => trans_choice('{0} No completed projects yet|{1} :on of 1 project finished on time|[2,*] :on of :count projects finished on time', $projects['completed'], ['on' => $projects['onTime']]), 'swatch' => $bars['Completed']],
                'delayed' => ['label' => __('Delayed'), 'note' => __('Past target date, no terminal report'), 'swatch' => $delayedSwatch($facultyCounts['delayed'])],
            ];
        @endphp

        {{-- Things blocking faculty from submitting, only when they apply. Fix-it buttons stack full width on phones --}}
        @if ($this->facultyWithoutDepartment > 0 || $this->missingSetup)
            <div class="mt-6 grid gap-3">
                @if ($this->facultyWithoutDepartment > 0)
                    <flux:callout variant="warning" icon="exclamation-triangle" data-test="no-department-tile">
                        <flux:callout.heading>{{ trans_choice('{1} One faculty member has no college and can’t submit.|[2,*] :count faculty members have no college and can’t submit.', $this->facultyWithoutDepartment) }}</flux:callout.heading>
                        <x-slot name="actions" class="max-sm:grid">
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
                        <x-slot name="actions" class="flex-wrap max-sm:grid">
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
            {{-- Six tiles: pairs on small screens, then two rows of three, so each keeps room for its note --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:col-span-12 lg:grid-cols-3" data-test="summary-tiles">
                @foreach ($kpis as $key => $kpi)
                    @include('partials.stat-tile', $kpi + ['value' => $facultyCounts[$key], 'href' => route('dashboard', $filters + ['group' => $key]).'#faculty-list'])
                @endforeach
            </div>

            @if ($group !== '' && isset($kpis[$group]))
                <flux:card id="faculty-list" class="lg:col-span-12" data-test="faculty-list">
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
                                ? __('Every faculty member :where has submitted in :range.', ['where' => $department === '' ? __('in the portal') : __('in :dept', ['dept' => $department]), 'range' => $this->rangeLabel])
                                : __('No faculty in this group for :range.', ['range' => $this->rangeLabel]) }}
                        </flux:text>
                    @else
                        {{-- Person on the left, their project titles on the right; long titles wrap inside the card. --}}
                        <ul class="mt-4 divide-y divide-line border-t border-line text-sm">
                            @foreach ($this->groupFaculty as $member)
                                <li class="grid gap-1.5 py-3 sm:grid-cols-[14rem_minmax(0,1fr)] sm:gap-4 lg:grid-cols-[18rem_minmax(0,1fr)]">
                                    {{-- Names run long, so every row stacks the same way: the full name, wrapping if it
                                         must, then college and count on their own line. No name is ever cut off --}}
                                    <div>
                                        <p class="font-medium text-zinc-800 [overflow-wrap:anywhere]">{{ $member->name }}</p>
                                        <p class="mt-0.5 flex items-center gap-2 text-xs text-zinc-500">
                                            {{ $member->department?->code ?? __('No college') }}
                                            @if ($group !== 'pending')
                                                · {{ trans_choice('{1} :count project|[2,*] :count projects', $member->projects->count()) }}
                                            @endif
                                            @if ($group === 'delayed')
                                                {{-- Delayed work needs a follow-up: one click copies the email, and a small bubble
                                                     right above the icon confirms it where the admin is already looking --}}
                                                <button
                                                    type="button"
                                                    x-data="{ copied: false, message: '' }"
                                                    x-on:click="navigator.clipboard.writeText(@js($member->email)).then(
                                                        () => { copied = true; message = @js(__('Copied!')) },
                                                        () => { message = @js(__('Couldn’t copy: :email', ['email' => $member->email])) },
                                                    ).then(() => setTimeout(() => { copied = false; message = '' }, 1800))"
                                                    class="relative shrink-0 rounded text-zinc-500 hover:text-isu-green-700 focus-visible:outline-2 focus-visible:outline-accent max-sm:before:absolute max-sm:before:-inset-3.5"
                                                    title="{{ __('Copy :email', ['email' => $member->email]) }}"
                                                    aria-label="{{ __('Copy :name’s email', ['name' => $member->name]) }}"
                                                    data-test="copy-email"
                                                >
                                                    <flux:icon.envelope variant="micro" x-show="! copied" />
                                                    <flux:icon.check variant="micro" x-show="copied" x-cloak class="text-isu-green-700" />
                                                    <span
                                                        x-show="message"
                                                        x-cloak
                                                        x-transition.opacity
                                                        x-text="message"
                                                        role="status"
                                                        class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1.5 -translate-x-1/2 whitespace-nowrap rounded-md bg-zinc-900 px-2 py-1 text-xs font-medium text-white shadow-md shadow-zinc-900/20"
                                                    ></span>
                                                </button>
                                            @endif
                                        </p>
                                    </div>
                                    @if ($group === 'pending')
                                        {{-- No projects to list, so show how to reach them for a follow-up --}}
                                        <flux:link :href="'mailto:'.$member->email" variant="ghost" class="truncate !font-normal">{{ $member->email }}</flux:link>
                                    @else
                                    <ul class="grid gap-1.5">
                                        @foreach ($member->projects as $project)
                                            <li>
                                                <flux:link :href="route('drive.show', $project)" variant="ghost" class="!font-normal [overflow-wrap:anywhere]" wire:navigate>{{ $project->displayTitle() }}</flux:link>
                                            </li>
                                        @endforeach
                                    </ul>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </flux:card>
            @endif

            {{-- Trend over time, split by status: stacked columns --}}
            @include('partials.monthly-chart', ['monthly' => $this->monthly, 'heading' => __('Submissions per month'), 'period' => $scope])

            {{-- Magnitude comparisons for the selected year: ranked horizontal bars, one hue, value at the tip --}}
            @foreach ($this->breakdowns as $heading => $rows)
                {{-- Colleges and categories side by side; the category chart alone takes the row once a college is picked --}}
                <flux:card @class(['lg:col-span-6' => count($this->breakdowns) > 1, 'lg:col-span-12' => count($this->breakdowns) === 1])>
                    <flux:heading level="2">{{ $heading }}</flux:heading>

                    @if ($rows->isEmpty())
                        <flux:text class="mt-4">{{ __('No projects for :range.', ['range' => $this->rangeLabel]) }}</flux:text>
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
                </flux:card>
            @endforeach

            {{-- The review queue: new and corrected concept proposals, longest waiting first, so nothing waits forever --}}
            <flux:card class="lg:col-span-12" data-test="waiting-tile">
                <div class="flex items-baseline justify-between gap-4">
                    <flux:heading level="2">{{ __('Concept proposals to review') }}</flux:heading>
                    @if ($this->waitingCount > 0)
                        <flux:link :href="route('reviews.index')" wire:navigate class="shrink-0 text-sm">{{ __('View all :count', ['count' => $this->waitingCount]) }}</flux:link>
                    @endif
                </div>

                @if ($this->waiting->isEmpty())
                    <flux:text class="mt-4">{{ __('No concept proposals are waiting for review.') }}</flux:text>
                @else
                    <ul class="mt-4 divide-y divide-line">
                        @foreach ($this->waiting as $submission)
                            {{-- Phones wrap the title to two lines instead of cutting it to a few words beside the button --}}
                            <li class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                                <div class="min-w-0 flex-1">
                                    <p class="line-clamp-2 text-sm font-medium text-zinc-800 sm:line-clamp-1">{{ $submission->displayTitle() }}</p>
                                    <p class="text-sm text-zinc-500 sm:truncate">
                                        {{ $submission->user->name }}, {{ $submission->department->code }}.
                                        {{ __('At :status, updated :when', ['status' => __($submission->status), 'when' => $submission->updated_at->diffForHumans()]) }}
                                    </p>
                                </div>
                                <flux:button size="sm" :href="route('reviews.index', ['review' => $submission->id])" wire:navigate class="shrink-0 max-sm:h-11 max-sm:px-4">{{ __('Review') }}</flux:button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </flux:card>
        </div>
    @else
        @php
            $counts = $this->statusCounts;
            $total = array_sum($counts);
            $delayed = $this->myProjects->filter->isDelayed()->count();

            $kpis = [
                ['label' => __('My projects'), 'value' => $total, 'note' => __('Filed or listed as proponent'), 'href' => route('submissions.index')],
                ['label' => __('At proposal stage'), 'value' => $counts['Concept'] + $counts['Detailed'], 'note' => __('Concept or detailed proposal uploaded'), 'href' => route('submissions.index', ['status' => 'proposal']), 'swatch' => $proposalSwatches],
                ['label' => __('At mid-year stage'), 'value' => $counts['Mid-year'], 'note' => __('Mid-year progress report uploaded'), 'href' => route('submissions.index', ['status' => 'Mid-year']), 'swatch' => $bars['Mid-year']],
                ['label' => __('Completed'), 'value' => $counts['Completed'], 'note' => __('Terminal report uploaded'), 'href' => route('submissions.index', ['status' => 'Completed']), 'swatch' => $bars['Completed']],
                ['label' => __('Delayed'), 'value' => $delayed, 'note' => __('Past target date, no terminal report'), 'href' => route('submissions.index', ['status' => 'delayed']), 'swatch' => $delayedSwatch($delayed)],
            ];
        @endphp

        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-12">
            {{-- Five tiles: one row only once each has room (xl); pairs below that, the last spanning the row --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 sm:[&>:last-child]:col-span-2 lg:col-span-12 xl:grid-cols-5 xl:[&>:last-child]:col-span-1">
                @foreach ($kpis as $kpi)
                    @include('partials.stat-tile', $kpi)
                @endforeach
            </div>

            @php($calm = $this->needsAttention->isEmpty())

            {{-- With nothing to act on, the attention panel shrinks to one line so the chart and projects move up --}}
            @if ($calm)
                <p class="flex items-center gap-2 text-sm text-zinc-600 lg:col-span-12" data-test="attention-tile">
                    <flux:icon.check-circle variant="mini" class="shrink-0 text-isu-green-700" aria-hidden="true" />
                    {{ __('Nothing needs your attention.') }}
                </p>
            @else
                <flux:card class="lg:col-span-8" data-test="attention-tile">
                    <flux:heading level="2">{{ __('Needs your attention') }}</flux:heading>

                    <ul class="mt-4 divide-y divide-line">
                        @foreach ($this->needsAttention as $submission)
                            <li class="py-3 first:pt-0 last:pb-0">
                                <div class="flex items-center gap-3">
                                    <p class="min-w-0 flex-1 truncate text-sm font-medium text-zinc-800">{{ $submission->displayTitle() }}</p>
                                    @if ($submission->conceptReview() === 'returned')
                                        <flux:badge size="sm" color="amber">{{ __('Concept needs revision') }}</flux:badge>
                                    @endif
                                    @if ($submission->isDelayed())
                                        <flux:badge size="sm" color="red">{{ __('Delayed') }}</flux:badge>
                                    @endif
                                </div>
                                @if ($submission->isDelayed())
                                    <p class="mt-1 text-sm text-zinc-600">{{ __('Target date :date has passed. Upload the terminal report when it’s ready.', ['date' => $submission->target_date->format('M j, Y')]) }}</p>
                                @endif
                                @if ($submission->conceptReview() === 'returned')
                                    <p class="mt-1 text-sm text-amber-800">{{ __('What to fix: :remarks', ['remarks' => $submission->remarks]) }}</p>
                                @endif
                                <flux:link :href="route('submissions.edit', $submission)" wire:navigate class="mt-2 inline-block text-sm">{{ __('Open project') }}</flux:link>
                            </li>
                        @endforeach
                    </ul>
                </flux:card>
            @endif

            {{-- The dashboard's main action. Beside the attention list it stacks; alone it runs the full width as one band --}}
            <flux:card @class(['lg:col-span-4' => ! $calm, 'lg:col-span-12' => $calm]) data-test="submit-tile">
                <div @class(['lg:flex lg:items-center lg:justify-between lg:gap-6' => $calm])>
                    <div>
                        <flux:heading level="2">{{ __('Start a research project') }}</flux:heading>

                        @if (auth()->user()->department_id)
                            <flux:text class="mt-2">{{ __('Upload your concept proposal for the Research Office to review. The detailed proposal and reports go on the same project later.') }}</flux:text>
                        @else
                            <flux:text class="mt-2">{{ __('Your account has no college yet. Contact the Research Office to have one assigned.') }}</flux:text>
                        @endif
                    </div>

                    @if (auth()->user()->department_id)
                        <flux:button :href="route('submissions.create')" variant="primary" icon="plus" wire:navigate @class(['mt-4 shrink-0 max-sm:h-11 max-sm:w-full', 'lg:mt-0' => $calm])>{{ __('New proposal') }}</flux:button>
                    @endif
                </div>
            </flux:card>

            @include('partials.monthly-chart', ['monthly' => $this->monthly, 'heading' => __('My submissions per month'), 'period' => __('the last 12 months')])

            <flux:card class="lg:col-span-12">
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
                        @foreach ($this->myProjects->take(5) as $submission)
                            @php($title = $submission->displayTitle())
                            <li class="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
                                <div class="min-w-0 flex-1 text-sm">
                                    <a href="{{ route('drive.show', $submission) }}" wire:navigate title="{{ $title }}" class="line-clamp-2 font-medium text-zinc-800 [overflow-wrap:anywhere] hover:underline sm:line-clamp-1">{{ $title }}</a>
                                    <p class="mt-0.5 truncate tabular-nums text-zinc-500">
                                        {{ $submission->category->name }} ·
                                        <span @class(['font-medium text-red-700' => $submission->isDelayed()])>{{ $submission->target_date ? __('Due :date', ['date' => $submission->target_date->format('M j, Y')]) : __('No schedule yet') }}</span>
                                    </p>
                                </div>
                                @include('partials.project-status', ['class' => 'items-end', 'timing' => true])
                            </li>
                        @endforeach
                    </ul>
                @endif
            </flux:card>
        </div>
    @endif
</section>
