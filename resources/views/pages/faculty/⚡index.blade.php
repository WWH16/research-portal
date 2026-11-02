<?php

use App\Models\Citation;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * Every faculty member with how many publications they recorded and how often those were cited, for the
 * Research Office. Members with none are listed too, so the office can see who hasn't added any.
 */
new #[Title('Faculty Publications')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(as: 'college', except: '')]
    public string $college = '';

    #[Url(as: 'sort', except: 'name')]
    public string $sortBy = 'name';

    #[Url(as: 'direction', except: 'asc')]
    public string $sortDirection = 'asc';

    /**
     * Any filter change starts again from the first page.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'college'], true)) {
            $this->resetPage();
        }
    }

    /**
     * A number column opens with the most and the name column from A; a second click on the same column flips it.
     */
    public function sort(string $column): void
    {
        $this->sortDirection = $this->sortBy === $column
            ? ($this->sortDirection === 'asc' ? 'desc' : 'asc')
            : ($column === 'name' ? 'asc' : 'desc');
        $this->sortBy = $column;
        $this->resetPage();
    }

    #[Computed]
    public function faculty(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return User::where('role', 'faculty')
            ->with('department:id,code')
            ->withCount('publications')
            ->addSelect(['citations_count' => Citation::selectRaw('count(*)')
                ->join('publication_user', 'publication_user.publication_id', '=', 'citations.publication_id')
                ->whereColumn('publication_user.user_id', 'users.id')])
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('researcher_id', 'like', '%'.$search.'%')))
            ->when($this->college === 'none', fn ($query) => $query->whereNull('department_id'))
            ->when(! in_array($this->college, ['', 'none'], true), fn ($query) => $query->whereRelation('department', 'code', $this->college))
            // Only a listed column, so the address can't order by anything else; ties go by name.
            ->orderBy(match ($this->sortBy) { 'publications' => 'publications_count', 'citations' => 'citations_count', default => 'name' }, $this->sortDirection === 'desc' ? 'desc' : 'asc')
            ->orderBy('name')
            ->paginate(25);
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'college');
        $this->resetPage();
    }
}; ?>

<section class="mx-auto w-full max-w-5xl">
    <header class="flex items-center gap-4 border-b border-line pb-6">
        <img src="{{ asset('images/isu_seal-128.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
        <div class="min-w-0 flex-1">
            <flux:heading size="xl" level="1">{{ __('Faculty Publications') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Publications and citations each faculty member recorded. Open a paper’s DOI or link to check it.') }}</flux:text>
        </div>
    </header>

    <div class="mt-6 flex items-center gap-3">
        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            :placeholder="__('Search name or researcher ID')"
            :aria-label="__('Search faculty')"
            clearable
            class="flex-1"
        />

        {{-- Phones drop the College column, so its filter comes up here beside the search --}}
        <div class="sm:hidden" data-test="phone-college-filter">
            <x-college-filter model="college" :value="$college" with-none />
        </div>
    </div>

    @if ($this->faculty->isEmpty())
        @if (trim($search) !== '' || $college !== '')
            <x-empty-state icon="magnifying-glass" :heading="__('No faculty match these filters')" :text="__('Try a different name, or show every college.')" class="mt-6">
                <flux:button wire:click="clearFilters" class="max-sm:h-11">{{ __('Clear filters') }}</flux:button>
            </x-empty-state>
        @else
            <x-empty-state icon="users" :heading="__('No faculty yet')" :text="__('Faculty members appear here once their accounts are approved, with the publications each one records.')" class="mt-6" />
        @endif
    @else
        {{-- Each sortable header says which way it sorts, for screen readers as well as by its arrow --}}
        @php($ariaSort = fn (string $column) => $sortBy === $column ? ($sortDirection === 'desc' ? 'descending' : 'ascending') : 'none')
        <flux:table class="mt-4" :paginate="$this->faculty" data-test="faculty-table">
            <flux:table.columns>
                <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" :aria-sort="$ariaSort('name')" wire:click="sort('name')">{{ __('Name') }}</flux:table.column>
                <flux:table.column class="max-sm:hidden">
                    <x-college-filter model="college" :value="$college" with-none />
                </flux:table.column>
                <flux:table.column align="end" sortable :sorted="$sortBy === 'publications'" :direction="$sortDirection" :aria-sort="$ariaSort('publications')" wire:click="sort('publications')">{{ __('Publications') }}</flux:table.column>
                <flux:table.column align="end" sortable :sorted="$sortBy === 'citations'" :direction="$sortDirection" :aria-sort="$ariaSort('citations')" wire:click="sort('citations')">{{ __('Citations') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->faculty as $member)
                    {{-- The name link stretches over the whole row, as on the profile's list. Keyboard focus shades the row the same as hover. --}}
                    <flux:table.row :key="$member->id" class="relative hover:bg-canvas has-[a:focus-visible]:bg-canvas">
                        <flux:table.cell>
                            <a href="{{ route('faculty.show', $member) }}" wire:navigate class="flex min-w-0 items-center gap-3 after:absolute after:inset-0 hover:underline">
                                {{-- Hidden from screen readers, so the link reads as the name alone --}}
                                <flux:avatar size="sm" aria-hidden="true" :src="$member->profileImageUrl()" :name="$member->name" :initials="$member->initials()" />
                                <span class="truncate font-medium text-zinc-800">{{ $member->name }}</span>
                            </a>
                        </flux:table.cell>
                        <flux:table.cell class="max-sm:hidden">{{ $member->department?->code ?? __('No college') }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ number_format($member->publications_count) }}</flux:table.cell>
                        <flux:table.cell align="end" variant="strong" class="tabular-nums">{{ number_format($member->citations_count) }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</section>
