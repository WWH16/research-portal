<?php

use App\Models\Category;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Categories')] class extends Component {
    public ?int $editingId = null;
    public string $name = '';

    public ?int $deletingId = null;

    #[Computed]
    public function categories(): Collection
    {
        return Category::withCount('submissions')->orderBy('name')->get();
    }

    #[Computed]
    public function deleting(): ?Category
    {
        return $this->deletingId ? Category::withCount('submissions')->find($this->deletingId) : null;
    }

    /**
     * Open the form for a new category.
     */
    public function create(): void
    {
        $this->reset('editingId', 'name');
        $this->resetValidation();

        Flux::modal('category-form')->show();
    }

    /**
     * Open the form for an existing category.
     */
    public function edit(int $id): void
    {
        $category = Category::findOrFail($id);

        $this->editingId = $category->id;
        $this->name = $category->name;
        $this->resetValidation();

        Flux::modal('category-form')->show();
    }

    /**
     * Create or update the category.
     */
    public function save(): void
    {
        $this->name = trim($this->name);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('categories')->ignore($this->editingId)],
        ]);

        if ($this->editingId) {
            Category::findOrFail($this->editingId)->update($validated);
            Flux::toast(variant: 'success', text: __('Category updated.'));
        } else {
            Category::create($validated);
            Flux::toast(variant: 'success', text: __('Category added.'));
        }

        Flux::modal('category-form')->close();
        $this->reset('editingId', 'name');
        unset($this->categories);
    }

    /**
     * Ask before deleting a category.
     */
    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
        unset($this->deleting);

        Flux::modal('category-delete')->show();
    }

    /**
     * Delete the category unless submissions still use it.
     */
    public function delete(): void
    {
        $category = Category::withCount('submissions')->findOrFail($this->deletingId);

        if ($category->submissions_count > 0) {
            return;
        }

        $category->delete();

        Flux::modal('category-delete')->close();
        Flux::toast(variant: 'success', text: __('Category deleted.'));
        $this->reset('deletingId');
        unset($this->categories, $this->deleting);
    }
}; ?>

<section class="mx-auto w-full max-w-4xl">
    <header class="flex items-center gap-4 border-b border-line pb-6">
        <img src="{{ asset('images/isu_seal.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
        <div class="min-w-0">
            <flux:heading size="xl" level="1">{{ __('Categories') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Subject areas a proposal can be filed under.') }}</flux:text>
        </div>
        <flux:spacer />
        <flux:button variant="primary" icon="plus" wire:click="create" data-test="new-category-button">
            {{ __('New') }}
        </flux:button>
    </header>

    @if ($this->categories->isEmpty())
        <div class="mt-8 rounded-xl border border-dashed border-line px-6 py-12 text-center">
            <flux:heading>{{ __('No categories yet') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Add one so proposals can be classified when they are submitted.') }}</flux:text>
        </div>
    @else
        <flux:table class="mt-6">
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Submissions') }}</flux:table.column>
                <flux:table.column class="w-0"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->categories as $category)
                    <flux:table.row :key="$category->id">
                        <flux:table.cell variant="strong">{{ $category->name }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $category->submissions_count }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:dropdown position="bottom" align="end">
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" :aria-label="__('Actions for :name', ['name' => $category->name])" />

                                <flux:menu>
                                    <flux:menu.item icon="pencil-square" wire:click="edit({{ $category->id }})">{{ __('Edit') }}</flux:menu.item>
                                    <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $category->id }})">{{ __('Delete') }}</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <flux:modal name="category-form" class="w-full md:w-96">
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit category') : __('New category') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" type="text" maxlength="50" required autofocus />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-category-button">
                    {{ $editingId ? __('Save changes') : __('Add category') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="category-delete" class="w-full md:w-96">
        @if ($this->deleting)
            <div class="flex flex-col gap-6">
                <div>
                    <flux:heading size="lg">{{ __('Delete :name?', ['name' => $this->deleting->name]) }}</flux:heading>

                    @if ($this->deleting->submissions_count > 0)
                        <flux:text class="mt-2">
                            {{ trans_choice('{1} One submission uses this category, so it can’t be deleted. Rename it instead.|[2,*] :count submissions use this category, so it can’t be deleted. Rename it instead.', $this->deleting->submissions_count) }}
                        </flux:text>
                    @else
                        <flux:text class="mt-2">{{ __('It will no longer be available when submitting proposals.') }}</flux:text>
                    @endif
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    @if ($this->deleting->submissions_count === 0)
                        <flux:button variant="danger" wire:click="delete" data-test="delete-category-button">{{ __('Delete') }}</flux:button>
                    @endif
                </div>
            </div>
        @endif
    </flux:modal>
</section>
