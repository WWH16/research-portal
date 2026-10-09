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

    /**
     * Any filter change starts again from the first page.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'college'], true)) {
            $this->resetPage();
        }
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

    <flux:input
        wire:model.live.debounce.300ms="search"
        icon="magnifying-glass"
        :placeholder="__('Search name or researcher ID')"
        :aria-label="__('Search faculty')"
        clearable
        class="mt-6"
    />

    @if ($this->faculty->isEmpty())
        <div class="mt-6 rounded-xl border border-dashed border-line px-6 py-12 text-center">
            <flux:heading>{{ __('No faculty match these filters') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Try a different name, or show every college.') }}</flux:text>
            <flux:button variant="ghost" size="sm" class="mt-4" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
        </div>
    @else
        <flux:table class="mt-4" :paginate="$this->faculty" data-test="faculty-table">
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>
                    <x-college-filter model="college" :value="$college" with-none />
                </flux:table.column>
                <flux:table.column align="end">{{ __('Publications') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Citations') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->faculty as $member)
                    <flux:table.row :key="$member->id">
                        <flux:table.cell>
                            <a href="{{ route('faculty.show', $member) }}" wire:navigate class="flex min-w-0 items-center gap-3 hover:underline">
                                <flux:avatar size="sm" :src="$member->profileImageUrl()" :name="$member->name" :initials="$member->initials()" />
                                <span class="truncate font-medium text-zinc-800">{{ $member->name }}</span>
                            </a>
                        </flux:table.cell>
                        <flux:table.cell>{{ $member->department?->code ?? '—' }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ number_format($member->publications_count) }}</flux:table.cell>
                        <flux:table.cell align="end" variant="strong" class="tabular-nums">{{ number_format($member->citations_count) }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</section>
