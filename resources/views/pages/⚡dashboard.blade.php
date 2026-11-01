<?php

use App\Models\Category;
use App\Models\Department;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * Admins get one line on the concept proposals waiting for review, then faculty progress for a filing-date range:
 * faculty counts per stage, each opening the faculty behind it and exportable as a report, the monthly trend as
 * stacked columns, and "where from / what about" as ranked horizontal bars. Faculty get what needs their attention, their projects beside
 * the button that starts a new one, then their counts and monthly trend.
 */
new #[Title('Dashboard')] class extends Component {
    use WithPagination;

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
     * A new group, college or date range starts the faculty list back on its first page.
     */
    public function updated(): void
    {
        $this->resetPage();
    }

    /**
     * Redraw only when the sidebar finds work this dashboard shows, so it stays live without recounting every minute.
     *
     * @param  list<string>  $changed
     */
    #[On('open-work-changed')]
    public function openWorkChanged(array $changed): void
    {
        if (! array_intersect($changed, $this->isAdmin ? ['toReview', 'toApprove'] : ['toRevise', 'unread'])) {
            $this->skipRender();
        }
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
     * The open group's label, or null when no valid group is open or the viewer is not an admin.
     */
    #[Computed]
    public function groupLabel(): ?string
    {
        return $this->isAdmin ? ($this->groups()[$this->group]['label'] ?? null) : null;
    }

    /**
     * The faculty behind the open group's count, each with their projects in that group, by name.
     */
    private function groupQuery(): Builder
    {
        return $this->facultyIn($this->group)
            ->with(['department:id,code', 'projects' => $this->projectsIn($this->group)])
            ->select(['id', 'name', 'email', 'researcher_id', 'department_id'])
            ->orderBy('name')
            ->orderBy('id');
    }

    /**
     * One page of the open group's faculty, so a big group loads as fast as a small one.
     */
    #[Computed]
    public function groupFaculty(): LengthAwarePaginator
    {
        return $this->groupQuery()->paginate(25);
    }

    /**
     * Download the open group's whole faculty list, every page, as a CSV report.
     */
    public function export(): StreamedResponse
    {
        $this->authorize('viewAny', Submission::class);

        $label = $this->groupLabel ?? abort(404);

        return response()->csv(
            Str::slug($label.' '.$this->department.' '.$this->from.' '.$this->to).'.csv',
            [__('Name'), __('Researcher ID'), __('College'), __('Projects')],
            $this->groupQuery()->lazy()->map(fn ($member) => [$member->name, $member->researcher_id, $member->department?->code, $member->projects->pluck('title')->join('; ')]),
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
     * When the longest-waiting new or corrected concept proposal the Research Office hasn't reviewed was last updated.
     */
    #[Computed]
    public function oldestWaiting(): ?CarbonInterface
    {
        return Submission::where('awaiting_review', true)->oldest('updated_at')->value('updated_at');
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

        {{-- Proposals waiting for review get one line above the counts, in pastel seal gold, with the way to Concept
             Reviews, where the list itself lives. With none waiting the line says so --}}
        @if ($this->waitingCount === 0)
            <x-empty-state inline good icon="check-circle" :heading="__('No concept proposals are waiting for review.')" class="mt-6" data-test="waiting-tile" />
        @else
            <flux:callout icon="inbox-arrow-down" inline class="mt-6 [--callout-background:var(--color-highlight-soft)]! [--callout-border:var(--color-isu-gold-200)]! [--callout-icon:var(--color-isu-gold-600)]! [--callout-text:var(--color-isu-gold-800)]!" data-test="waiting-tile">
                <flux:callout.heading>{{ trans_choice('{1} One concept proposal is waiting for review.|[2,*] :count concept proposals are waiting for review.', $this->waitingCount) }}</flux:callout.heading>
                <flux:callout.text>{{ trans_choice('{1} It has waited :wait.|[2,*] The oldest has waited :wait.', $this->waitingCount, ['wait' => $this->oldestWaiting->diffForHumans(null, true)]) }}</flux:callout.text>
                <x-slot name="actions" class="max-sm:grid">
                    <flux:button size="sm" :href="route('reviews.index')" wire:navigate class="max-sm:h-11 max-sm:px-4">{{ __('Review them') }}</flux:button>
                </x-slot>
            </flux:callout>
        @endif

        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-12">
            {{-- Faculty progress, with the filters beside its heading: they narrow the counts and charts below --}}
            <section class="lg:col-span-12" aria-labelledby="progress-heading">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <flux:heading size="lg" level="2" id="progress-heading" class="text-xl!">{{ __('Faculty progress') }}</flux:heading>

                    {{-- Filters drop to their own full-width rows on phones instead of pushing the page sideways: the college and
                         the quick-range button share the first row, so the two dates get the whole second row and show in full --}}
                    <div class="flex w-full flex-wrap gap-3 sm:w-auto">
                        <flux:select wire:model.live="department" :aria-label="__('College')" class="min-w-0 flex-1 sm:w-40 sm:flex-none" data-test="department-select">
                            <flux:select.option value="">{{ __('All colleges') }}</flux:select.option>
                            @foreach (Department::orderBy('code')->pluck('code') as $option)
                                <flux:select.option :value="$option">{{ $option }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <div class="flex w-full items-center gap-2 max-sm:order-last sm:w-auto" data-test="date-range">
                            {{-- Typing a year fires an input per digit; the wait lets it finish before the summary reloads --}}
                            <flux:input type="date" wire:model.live.debounce.500ms="from" :min="Submission::FIRST_YEAR.'-01-01'" :max="$to" :aria-label="__('From')" class="min-w-0 flex-1 sm:w-40 sm:flex-none" class:input="max-sm:h-11" />
                            <span class="text-sm text-zinc-500" aria-hidden="true">–</span>
                            <flux:input type="date" wire:model.live.debounce.500ms="to" :min="$from" :aria-label="__('To')" class="min-w-0 flex-1 sm:w-40 sm:flex-none" class:input="max-sm:h-11" />
                        </div>

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

                {{-- Faculty counts by stage, each opening the list of faculty behind it. One strip split by hairlines: pairs on
                     phones, rows of three, then all six in a row once each has room for its note --}}
                <div class="mt-4 grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line lg:grid-cols-3 xl:grid-cols-6 *:first:rounded-tl-cell *:last:rounded-br-cell max-lg:*:nth-2:rounded-tr-cell max-lg:*:nth-5:rounded-bl-cell lg:max-xl:*:nth-3:rounded-tr-cell lg:max-xl:*:nth-4:rounded-bl-cell xl:*:first:rounded-bl-cell xl:*:last:rounded-tr-cell" data-test="summary-tiles">
                    @foreach ($kpis as $key => $kpi)
                        @include('partials.stat-tile', $kpi + ['value' => $facultyCounts[$key], 'href' => route('dashboard', $filters + ['group' => $key]).'#faculty-list', 'group' => $key])
                    @endforeach
                </div>
            </section>

            {{-- Trend over time, split by status: stacked columns --}}
            @include('partials.monthly-chart', ['monthly' => $this->monthly, 'heading' => __('Submissions per month'), 'period' => $scope])

            {{-- Magnitude comparisons for the selected year: ranked horizontal bars, one hue, value at the tip --}}
            @foreach ($this->breakdowns as $heading => $rows)
                {{-- Colleges and categories side by side; the category chart alone takes the row once a college is picked --}}
                <flux:card @class(['lg:col-span-6' => count($this->breakdowns) > 1, 'lg:col-span-12' => count($this->breakdowns) === 1])>
                    <flux:heading size="lg" level="2">{{ $heading }}</flux:heading>

                    @if ($rows->isEmpty())
                        <x-empty-state inline icon="chart-bar" :heading="__('No projects for :range.', ['range' => $this->rangeLabel])" class="mt-4" />
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
        </div>

        {{-- The faculty behind a count open in a flyout. Only this island re-renders on tile and page clicks;
             closing it clears the group --}}
        @island(name: 'faculty-list', always: true)
        <flux:modal name="faculty-list" variant="flyout" class="w-full max-w-none md:w-xl" aria-labelledby="faculty-list-heading" wire:close="$set('group', '')">
        @if ($this->groupLabel)
            @php
                $faculty = $this->groupFaculty;
                $where = $department === '' ? $this->rangeLabel : $department.', '.$this->rangeLabel;
            @endphp
            {{-- Keyed by group, so a newly opened group opens the panel; switching pages leaves it open as it is --}}
            <div id="faculty-list" wire:key="faculty-list-{{ $group }}" x-init="$flux.modal('faculty-list').show()" data-test="faculty-list">
                {{-- Clear of the panel's close button at the top right. Flux focuses nothing in a dialog it opens, so the heading
                     takes focus and is what a screen reader announces --}}
                <div class="pe-10">
                    <flux:heading size="lg" level="2" id="faculty-list-heading" tabindex="-1" autofocus class="outline-none">{{ $this->groupLabel }}, {{ $where }}</flux:heading>
                    <flux:text class="mt-1 tabular-nums">{{ trans_choice('{0} No faculty|{1} One faculty member|[2,*] :count faculty', $faculty->total(), ['count' => number_format($faculty->total())]) }}</flux:text>
                </div>

                @if ($faculty->isNotEmpty())
                    <flux:button size="sm" icon="arrow-down-tray" wire:click="export" data-test="export-button" class="mt-4 max-sm:h-11 max-sm:px-4">{{ __('Export CSV') }}</flux:button>
                @endif

                {{-- An empty list is one line with an icon: a check when empty is good news --}}
                @if ($faculty->isEmpty() && $group === 'pending')
                    <x-empty-state inline good icon="check-circle" :heading="__('Every faculty member :where has submitted in :range.', ['where' => $department === '' ? __('in the portal') : __('in :dept', ['dept' => $department]), 'range' => $this->rangeLabel])" class="mt-4" />
                @elseif ($faculty->isEmpty())
                    <x-empty-state inline icon="users" :heading="__('No faculty in this group for :range.', ['range' => $this->rangeLabel])" class="mt-4" />
                @else
                    {{-- One compact line per person; their project titles open under the name on demand --}}
                    <ul class="mt-4 divide-y divide-line border-t border-line text-sm transition-opacity" wire:loading.delay.class="opacity-60">
                        @foreach ($faculty as $member)
                            <li class="py-3" wire:key="member-{{ $member->id }}" @if ($group !== 'pending') x-data="{ open: false }" @endif>
                                <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1.5">
                                    {{-- Names run long, so the full name wraps if it must; it is never cut off --}}
                                    <div class="min-w-0">
                                        <p class="font-medium text-zinc-800 [overflow-wrap:anywhere]">{{ $member->name }}</p>
                                        <p class="mt-0.5 flex items-center gap-2 text-xs text-zinc-500">
                                            {{ $member->department?->code ?? __('No college') }}
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
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            x-on:click="open = ! open"
                                            x-bind:aria-expanded="open"
                                            aria-controls="projects-{{ $member->id }}"
                                            class="shrink-0 tabular-nums max-sm:h-11"
                                            data-test="projects-toggle"
                                        >
                                            {{ trans_choice('{1} :count project|[2,*] :count projects', $member->projects->count()) }}
                                            <flux:icon.chevron-down variant="micro" class="text-zinc-400 transition-transform duration-200 motion-reduce:transition-none" x-bind:class="open && 'rotate-180'" />
                                        </flux:button>
                                    @endif
                                </div>

                                @if ($group !== 'pending')
                                    <ul id="projects-{{ $member->id }}" x-show="open" x-cloak class="mt-2 grid gap-1.5 border-s border-line ps-3">
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

                    <flux:pagination :paginator="$faculty" scroll-to="#faculty-list" class="mt-2" />
                @endif
            </div>
        @endif
        </flux:modal>
        @endisland
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

        @php($calm = $this->needsAttention->isEmpty())

        {{-- What needs doing comes first. With nothing to act on it shrinks to one line, so the projects move up --}}
        @if ($calm)
            <x-empty-state inline good icon="check-circle" :heading="__('Nothing needs your attention.')" class="mt-6" data-test="attention-tile" />
        @else
            <flux:card class="mt-6" data-test="attention-tile">
                <flux:heading size="lg" level="2">{{ __('Needs your attention') }}</flux:heading>

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
                                <p class="mt-1 text-sm text-amber-800 wrap-anywhere">{{ __('What to fix: :remarks', ['remarks' => $submission->remarks]) }}</p>
                            @endif
                            <flux:link :href="route('submissions.edit', $submission)" wire:navigate class="mt-2 inline-block text-sm">{{ __('Open project') }}</flux:link>
                        </li>
                    @endforeach
                </ul>
            </flux:card>
        @endif

        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-12">
            {{-- The main action. First on phones, so it is on the first screen; beside the projects on wide screens --}}
            <flux:card class="self-start lg:col-span-4 lg:col-start-9 lg:row-start-1" data-test="submit-tile">
                <flux:heading size="lg" level="2">{{ __('Start a research project') }}</flux:heading>

                @if (auth()->user()->department_id)
                    <flux:text class="mt-2">{{ __('Upload your concept proposal for the Research Office to review. The detailed proposal and reports go on the same project later.') }}</flux:text>
                    <flux:button :href="route('submissions.create')" variant="primary" icon="plus" wire:navigate class="mt-4 w-full max-sm:h-11">{{ __('New proposal') }}</flux:button>
                @else
                    <flux:text class="mt-2">{{ __('Your account has no college yet. Contact the Research Office to have one assigned.') }}</flux:text>
                @endif
            </flux:card>

            {{-- The member's projects lead the page: they are what a member comes here to open --}}
            <flux:card class="lg:col-span-8 lg:col-start-1 lg:row-start-1">
                <div class="flex items-baseline justify-between gap-4">
                    <flux:heading size="lg" level="2">{{ __('Recent projects') }}</flux:heading>
                    @if ($total > 0)
                        <flux:link :href="route('submissions.index')" wire:navigate class="shrink-0 text-sm">{{ __('View all') }}</flux:link>
                    @endif
                </div>

                @if ($total === 0)
                    <x-empty-state inline icon="document-plus" :heading="__('You haven’t submitted a proposal yet.')" class="mt-4" />
                @else
                    <ul class="mt-4 divide-y divide-line">
                        @foreach ($this->myProjects->take(5) as $submission)
                            @php($title = $submission->displayTitle())
                            <li class="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
                                <div class="min-w-0 flex-1 text-sm">
                                    <a href="{{ route('drive.show', $submission) }}" wire:navigate title="{{ $title }}" class="line-clamp-2 font-medium text-zinc-800 [overflow-wrap:anywhere] hover:underline sm:line-clamp-1">{{ $title }}</a>
                                    {{-- Phones wrap this line so the due date is never cut off or split --}}
                                    <p class="mt-0.5 tabular-nums text-zinc-500 sm:truncate">
                                        {{ $submission->category->name }}.
                                        <span @class(['whitespace-nowrap', 'font-medium text-red-700' => $submission->isDelayed()])>{{ $submission->target_date ? __('Due :date', ['date' => $submission->target_date->format('M j, Y')]) : __('No schedule yet') }}</span>
                                    </p>
                                </div>
                                @include('partials.project-status', ['class' => 'items-end', 'timing' => true])
                            </li>
                        @endforeach
                    </ul>
                @endif
            </flux:card>

            {{-- Counts by stage, each opening the projects it counts. One strip split by hairlines: pairs below xl with the
                 last spanning the row, then all five in a row. The cells on its corners take its curve --}}
            <div class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line [&>:last-child]:col-span-2 lg:col-span-12 xl:grid-cols-5 xl:[&>:last-child]:col-span-1 *:first:rounded-tl-cell *:last:rounded-br-cell max-xl:*:nth-2:rounded-tr-cell max-xl:*:last:rounded-bl-cell xl:*:first:rounded-bl-cell xl:*:last:rounded-tr-cell">
                @foreach ($kpis as $kpi)
                    @include('partials.stat-tile', $kpi)
                @endforeach
            </div>

            @include('partials.monthly-chart', ['monthly' => $this->monthly, 'heading' => __('My submissions per month'), 'period' => __('the last 12 months')])
        </div>
    @endif
</section>
