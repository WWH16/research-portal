<?php

use App\Models\Category;
use App\Models\Department;
use App\Models\ResearchType;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Each chart answers one question with the form that fits it: headline numbers as stat tiles,
 * the monthly trend as stacked columns, and "where from / what about"
 * as ranked horizontal bars. Faculty get the same tiles and monthly chart for their own proposals.
 */
new #[Title('Dashboard')] class extends Component {
    #[Computed]
    public function isAdmin(): bool
    {
        return Auth::user()->isAdmin();
    }

    /**
     * Submissions per status, in review order, for everyone (admins) or the signed-in member.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function statusCounts(): array
    {
        $query = $this->isAdmin ? Submission::query() : Auth::user()->submissions();
        $counts = $query->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(Submission::STATUSES)->mapWithKeys(fn ($status) => [$status => (int) ($counts[$status] ?? 0)])->all();
    }

    /**
     * Proposals filed in each of the last 12 months, split by their current status: everyone's for admins, their own for faculty.
     *
     * @return list<array{month: Carbon, counts: array<string, int>, total: int}>
     */
    #[Computed]
    public function monthly(): array
    {
        $start = now()->startOfMonth()->subMonths(11);

        // ponytail: grouped in PHP so it runs the same on MySQL and SQLite; move to a SQL GROUP BY past ~50k proposals a year.
        $query = $this->isAdmin ? Submission::query() : Auth::user()->submissions();
        $rows = $query->where('created_at', '>=', $start)->get(['status', 'created_at'])
            ->groupBy(fn ($submission) => $submission->created_at->format('Y-m'));

        return collect(range(0, 11))->map(function ($offset) use ($start, $rows) {
            $month = $start->copy()->addMonths($offset);
            $inMonth = $rows->get($month->format('Y-m'), collect())->countBy('status');
            $counts = collect(Submission::STATUSES)->mapWithKeys(fn ($status) => [$status => $inMonth->get($status, 0)])->all();

            return ['month' => $month, 'counts' => $counts, 'total' => array_sum($counts)];
        })->all();
    }

    #[Computed]
    public function waiting(): Collection
    {
        return Submission::where('status', 'Pending')
            ->with(['user:id,name', 'department:id,code'])
            ->oldest()
            ->limit(5)
            ->get();
    }

    /**
     * The top six of each reference list by number of proposals, for the ranked bar charts.
     *
     * @return array<string, Collection>
     */
    #[Computed]
    public function breakdowns(): array
    {
        $top = fn ($model, string $label) => $model::whereHas('submissions')->withCount('submissions')->orderByDesc('submissions_count')->limit(6)->get()
            ->map(fn ($row) => ['label' => $row->{$label}, 'title' => $row->name, 'count' => $row->submissions_count]);

        return [
            __('By department') => $top(Department::class, 'code'),
            __('By research type') => $top(ResearchType::class, 'name'),
            __('By category') => $top(Category::class, 'name'),
        ];
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
            __('departments') => Department::exists() ? null : route('departments.index'),
        ]);
    }

    #[Computed]
    public function needsRevision(): Collection
    {
        return Auth::user()->submissions()->where('status', 'For Revision')->latest('updated_at')->limit(5)->get();
    }

    #[Computed]
    public function latest(): Collection
    {
        return Auth::user()->submissions()->with('researchType:id,name')->latest()->limit(5)->get();
    }
}; ?>

@php
    $counts = $this->statusCounts;
    $total = array_sum($counts);
    // Chart colours per status. Pending is a deliberate neutral; For Revision and OK were checked for
    // colour-blind separation. Written out in full so Tailwind keeps them.
    $bars = ['Pending' => 'bg-zinc-400', 'For Revision' => 'bg-[#ed9400]', 'OK' => 'bg-green-700'];
    $tile = 'rounded-xl border border-line bg-surface p-5 sm:p-6';
    $percent = fn ($count) => $total ? round($count / $total * 100) : 0;
@endphp

<section class="mx-auto w-full max-w-6xl">
    <header class="flex items-center gap-4 border-b border-line pb-6">
        <img src="{{ asset('images/isu_seal.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
        <div class="min-w-0">
            <flux:heading size="xl" level="1">{{ __('Dashboard') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Welcome, :name.', ['name' => auth()->user()->name]) }}</flux:text>
        </div>
    </header>

    @if ($this->isAdmin)
        @php
            $thisMonth = $this->monthly[11]['total'];
            $lastMonth = $this->monthly[10]['total'];
            $change = $thisMonth - $lastMonth;
            $oldest = $this->waiting->first();

            $kpis = [
                [
                    'label' => __('Proposals filed'),
                    'value' => $total,
                    'note' => $change === 0 ? __(':count this month, same as last month', ['count' => $thisMonth]) : __(':count this month, :change vs last month', ['count' => $thisMonth, 'change' => ($change > 0 ? '+' : '').$change]),
                    'href' => route('submissions.index'),
                    'spark' => array_column($this->monthly, 'total'),
                ],
                [
                    'label' => __('Waiting for review'),
                    'value' => $counts['Pending'],
                    'note' => $oldest ? __('Oldest filed :when', ['when' => $oldest->created_at->diffForHumans()]) : __('Nothing to review'),
                    'href' => route('submissions.index', ['status' => 'Pending']),
                    'swatch' => $bars['Pending'],
                ],
                [
                    'label' => __('For Revision'),
                    'value' => $counts['For Revision'],
                    'note' => __(':percent% of all proposals', ['percent' => $percent($counts['For Revision'])]),
                    'href' => route('submissions.index', ['status' => 'For Revision']),
                    'swatch' => $bars['For Revision'],
                ],
                [
                    'label' => __('Approved'),
                    'value' => $counts['OK'],
                    'note' => __(':percent% of all proposals', ['percent' => $percent($counts['OK'])]),
                    'href' => route('submissions.index', ['status' => 'OK']),
                    'swatch' => $bars['OK'],
                ],
            ];
        @endphp

        {{-- Things blocking faculty from submitting, only when they apply --}}
        @if ($this->facultyWithoutDepartment > 0 || $this->missingSetup)
            <div class="mt-6 grid gap-3">
                @if ($this->facultyWithoutDepartment > 0)
                    <flux:callout variant="warning" icon="exclamation-triangle" data-test="no-department-tile">
                        <flux:callout.heading>{{ trans_choice('{1} One faculty member has no department and can’t submit.|[2,*] :count faculty members have no department and can’t submit.', $this->facultyWithoutDepartment) }}</flux:callout.heading>
                        <x-slot name="actions">
                            <flux:button size="sm" :href="route('users.index')" wire:navigate>{{ __('Assign departments') }}</flux:button>
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
                                <flux:button size="sm" :href="$url" wire:navigate>{{ __('Add :list', ['list' => $label]) }}</flux:button>
                            @endforeach
                        </x-slot>
                    </flux:callout>
                @endif
            </div>
        @endif

        <div class="mt-6 grid gap-6 lg:grid-cols-12">
            {{-- Headline numbers: stat tiles, each opening the list it counts --}}
            <div class="grid gap-4 sm:grid-cols-2 lg:col-span-12 lg:grid-cols-4">
                @foreach ($kpis as $kpi)
                    @include('partials.stat-tile', $kpi)
                @endforeach
            </div>

            {{-- Trend over time, split by status: stacked columns --}}
            @include('partials.monthly-chart', ['monthly' => $this->monthly, 'heading' => __('Submissions per month')])

            {{-- Magnitude comparisons: ranked horizontal bars, one hue, value at the tip --}}
            @foreach ($this->breakdowns as $heading => $rows)
                <section class="{{ $tile }} lg:col-span-4">
                    <flux:heading level="2">{{ $heading }}</flux:heading>

                    @if ($rows->isEmpty())
                        <flux:text class="mt-4">{{ __('No proposals yet.') }}</flux:text>
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

            {{-- The review queue: oldest first, so nothing waits forever --}}
            <section class="{{ $tile }} lg:col-span-12" data-test="waiting-tile">
                <div class="flex items-baseline justify-between gap-4">
                    <flux:heading level="2">{{ __('Waiting for review') }}</flux:heading>
                    @if ($counts['Pending'] > 0)
                        <flux:link :href="route('submissions.index', ['status' => 'Pending'])" wire:navigate class="shrink-0 text-sm">{{ __('View all pending') }}</flux:link>
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
                                        {{ __('Submitted :when', ['when' => $submission->created_at->diffForHumans()]) }}
                                    </p>
                                </div>
                                <flux:button size="sm" :href="route('submissions.index', ['review' => $submission->id])" wire:navigate class="shrink-0">{{ __('Review') }}</flux:button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    @else
        @php
            $kpis = [
                ['label' => __('My proposals'), 'value' => $total, 'note' => __('Filed so far'), 'href' => route('submissions.index')],
                ['label' => __('Pending'), 'value' => $counts['Pending'], 'note' => __('Waiting for the Research Office'), 'href' => route('submissions.index'), 'swatch' => $bars['Pending']],
                ['label' => __('For Revision'), 'value' => $counts['For Revision'], 'note' => __('Sent back with remarks'), 'href' => route('submissions.index'), 'swatch' => $bars['For Revision']],
                ['label' => __('Approved'), 'value' => $counts['OK'], 'note' => __(':percent% of your proposals', ['percent' => $percent($counts['OK'])]), 'href' => route('submissions.index'), 'swatch' => $bars['OK']],
            ];
        @endphp

        <div class="mt-6 grid gap-6 lg:grid-cols-12">
            <div class="grid gap-4 sm:grid-cols-2 lg:col-span-12 lg:grid-cols-4">
                @foreach ($kpis as $kpi)
                    @include('partials.stat-tile', $kpi)
                @endforeach
            </div>

            <section class="{{ $tile }} lg:col-span-8" data-test="attention-tile">
                <flux:heading level="2">{{ __('Needs your attention') }}</flux:heading>

                @if ($this->needsRevision->isEmpty())
                    <flux:text class="mt-4">{{ __('Nothing needs your attention.') }}</flux:text>
                @else
                    <ul class="mt-4 divide-y divide-line">
                        @foreach ($this->needsRevision as $submission)
                            <li class="py-3 first:pt-0 last:pb-0">
                                <div class="flex items-center gap-3">
                                    <p class="min-w-0 flex-1 truncate font-medium text-zinc-800">{{ $submission->title }}</p>
                                    <flux:badge size="sm" :color="Submission::STATUS_COLORS['For Revision']">{{ __('For Revision') }}</flux:badge>
                                </div>
                                @if ($submission->remarks)
                                    <p class="mt-1 text-sm text-amber-800">{{ __('Remarks: :remarks', ['remarks' => $submission->remarks]) }}</p>
                                @endif
                                <flux:link :href="route('submissions.document', $submission)" target="_blank" rel="noopener" class="mt-2 inline-block text-sm">{{ __('Open document') }}</flux:link>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <div class="flex flex-col gap-6 lg:col-span-4">
                <section class="{{ $tile }}" data-test="submit-tile">
                    <flux:heading level="2">{{ __('Submit a proposal') }}</flux:heading>

                    @if (auth()->user()->department_id)
                        <flux:text class="mt-2">{{ __('Upload your proposal as a PDF or Word file, up to 10 MB.') }}</flux:text>
                        <flux:button :href="route('submissions.create')" variant="primary" icon="plus" wire:navigate class="mt-4">{{ __('New proposal') }}</flux:button>
                    @else
                        <flux:text class="mt-2">{{ __('Your account has no department yet. Contact the Research Office to have one assigned.') }}</flux:text>
                    @endif
                </section>
            </div>

            @include('partials.monthly-chart', ['monthly' => $this->monthly, 'heading' => __('My submissions per month')])

            <section class="{{ $tile }} lg:col-span-12">
                <div class="flex items-baseline justify-between gap-4">
                    <flux:heading level="2">{{ __('Recent proposals') }}</flux:heading>
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
                                    <p class="truncate text-sm text-zinc-500">{{ $submission->researchType->name }}, {{ $submission->created_at->format('M j, Y') }}</p>
                                </div>
                                <flux:badge size="sm" :color="Submission::STATUS_COLORS[$submission->status] ?? 'zinc'">{{ __($submission->status) }}</flux:badge>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    @endif
</section>
