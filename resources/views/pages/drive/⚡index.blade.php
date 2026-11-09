<?php

use App\Models\Department;
use App\Models\Submission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * The Research Drive shows projects, not loose files. Faculty see every project they filed or are a
 * proponent on, from any college, with no folders. Admins see one folder per college, always all of
 * them, and each project sits in exactly one: the college it is filed under.
 */
new #[Title('Research Drive')] class extends Component {
    /** Admins: the college folder that is open, by code. Empty shows every folder. */
    #[Url(except: '')]
    public string $college = '';

    #[Url(except: '')]
    public string $year = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Computed]
    public function office(): bool
    {
        return Auth::user()->isResearchOffice();
    }

    #[Computed]
    public function folder(): ?Department
    {
        return $this->office && $this->college !== '' ? Department::where('code', $this->college)->firstOrFail() : null;
    }

    /**
     * Every college with its number of projects for the chosen year, empty ones included,
     * so the Research Office sees which colleges haven't submitted.
     */
    #[Computed]
    public function folders(): Collection
    {
        return Department::withCount(['submissions' => fn ($query) => $this->filter($query)])->orderBy('code')->get(['id', 'code']);
    }

    /**
     * Faculty: their own projects, with their rows in each proponents table. The Research Office (admins and staff): the open folder's projects.
     */
    #[Computed]
    public function projects(): Collection
    {
        // ponytail: no pagination; add ->paginate() when one college passes a few hundred projects a year.
        $query = $this->office
            ? Submission::where('department_id', $this->folder?->id)->with('user:id,name')
            : Submission::involving(Auth::user())->with(['proponents' => fn ($query) => $query->where('user_id', Auth::id())->orderBy('study')]);

        return $this->filter($query)->latest()->get();
    }

    private function filter($query)
    {
        return $query
            ->when($this->year !== '', fn ($query) => $query->where('year', (int) $this->year))
            // Lists print underscores in a title as spaces, and LIKE's _ matches either.
            ->when(trim($this->search) !== '', fn ($query) => $query->where('title', 'like', '%'.str_replace(' ', '_', trim($this->search)).'%'));
    }
}; ?>

@php
    $browsingFolders = $this->office && ! $this->folder;
@endphp

<section class="mx-auto w-full max-w-5xl">
    {{-- On phones the search takes its own row, with the count and year under it --}}
    <x-page-title :subtitle="$this->office ? __('Documents for every project, filed under its college.') : __('Documents for the projects you filed or are a proponent on.')">
        {{ __('Research Drive') }}

        <x-slot:actions class="gap-3 max-sm:w-full">
            @unless ($browsingFolders)
                @if ($this->projects->isNotEmpty())
                    <flux:text class="text-sm tabular-nums max-sm:order-2">{{ trans_choice('{1} 1 project|[2,*] :count projects', $this->projects->count()) }}</flux:text>
                @endif
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search titles')" :aria-label="__('Search titles')" class="min-w-0 flex-1 max-sm:order-1 max-sm:basis-full sm:w-64 sm:flex-none" clearable />
            @endunless

            <flux:select wire:model.live="year" :aria-label="__('Year')" class="w-32 max-sm:order-3 max-sm:ms-auto sm:w-36" data-test="drive-year-select">
                <flux:select.option value="">{{ __('All years') }}</flux:select.option>
                @foreach (Submission::years() as $option)
                    <flux:select.option :value="$option">{{ $option }}</flux:select.option>
                @endforeach
            </flux:select>
        </x-slot:actions>
    </x-page-title>

    @if ($this->folder)
        <flux:breadcrumbs class="mt-4 min-w-0 flex-wrap gap-y-1">
            <flux:breadcrumbs.item :href="route('drive.index', array_filter(['year' => $year]))" wire:navigate>{{ __('Research Drive') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $this->folder->code }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>
    @endif

    @if ($browsingFolders && $this->folders->isEmpty())
        {{-- Each college is a folder, so with none there is nothing to open yet --}}
        <x-empty-state icon="folder" :heading="__('No college folders yet')" :text="__('Each college added on the Colleges page gets a folder here.')" class="mt-6" data-test="no-college-folders">
            {{-- Colleges is an admin page, so staff get no way there --}}
            @if (auth()->user()->isAdmin())
                <flux:button :href="route('departments.index')" wire:navigate class="max-sm:h-11">{{ __('Go to Colleges') }}</flux:button>
            @endif
        </x-empty-state>
    @elseif ($browsingFolders)
        <flux:table class="mt-4" data-test="college-folders">
            <flux:table.columns>
                <flux:table.column>{{ __('College') }}</flux:table.column>
                <flux:table.column align="end">{{ $year !== '' ? __('Projects in :year', ['year' => $year]) : __('Projects') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->folders as $department)
                    <flux:table.row :key="$department->id">
                        <flux:table.cell>
                            <a href="{{ route('drive.index', array_filter(['college' => $department->code, 'year' => $year])) }}" wire:navigate class="flex items-center gap-3 font-medium text-zinc-800 hover:underline max-sm:-my-3 max-sm:py-3">
                                <flux:icon.folder variant="mini" class="shrink-0 text-isu-green-700" />
                                {{ $department->code }}
                            </a>
                        </flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">
                            @if ($department->submissions_count)
                                {{ trans_choice('{1} 1 project|[2,*] :count projects', $department->submissions_count) }}
                            @else
                                <span class="text-zinc-500">{{ __('No projects yet') }}</span>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @else
        @if ($this->projects->isEmpty())
            @if (trim($search) !== '')
                <x-empty-state icon="magnifying-glass" :heading="__('No projects match “:term”.', ['term' => trim($search)])" :text="__('Try fewer words, or another year.')" class="mt-6" />
            @elseif ($this->folder)
                <x-empty-state icon="folder-open" :heading="__('No projects yet')" :text="$year !== '' ? __(':college has no projects filed for :year.', ['college' => $this->folder->code, 'year' => $year]) : __(':college has no projects filed yet.', ['college' => $this->folder->code])" class="mt-6" />
            @else
                <x-empty-state icon="document-plus" :heading="__('You’re not on any projects yet')" :text="__('Projects appear here once you submit one or a colleague lists you as a proponent.')" class="mt-6">
                    <flux:button :href="route('submissions.create')" variant="primary" icon="plus" class="max-sm:h-11" wire:navigate>{{ __('Create proposal') }}</flux:button>
                </x-empty-state>
            @endif
        @else
            <flux:table class="mt-4">
                <flux:table.columns>
                    <flux:table.column>{{ __('Project') }}</flux:table.column>
                    <flux:table.column class="max-sm:hidden">{{ $this->office ? __('Filed by') : __('My role') }}</flux:table.column>
                    <flux:table.column class="max-sm:hidden">{{ __('Year') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->projects as $submission)
                        @php($title = $submission->displayTitle())
                        <flux:table.row :key="$submission->id">
                            <flux:table.cell class="max-w-sm max-sm:whitespace-normal">
                                <a href="{{ route('drive.show', $submission) }}" wire:navigate title="{{ $title }}" class="flex min-w-0 items-center gap-3 font-medium text-zinc-800 hover:underline max-sm:-my-3 max-sm:py-3">
                                    <flux:icon.folder variant="mini" class="shrink-0 text-isu-green-700" />
                                    <span class="max-sm:line-clamp-2 sm:truncate">{{ $title }}</span>
                                </a>
                            </flux:table.cell>
                            <flux:table.cell class="max-sm:hidden">
                                {{ $this->office
                                    ? $submission->user->shortName()
                                    : ($submission->proponents->map(fn ($row) => __('Study :number', ['number' => $row->study]).' '.__($row->role))->join(', ') ?: __('Filed by you')) }}
                            </flux:table.cell>
                            <flux:table.cell class="tabular-nums max-sm:hidden">{{ $submission->year }}</flux:table.cell>
                            <flux:table.cell>@include('partials.project-status')</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    @endif
</section>
