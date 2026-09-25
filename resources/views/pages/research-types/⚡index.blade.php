<?php

use App\Models\ResearchType;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Research Types')] class extends Component {
    public ?int $editingId = null;
    public string $name = '';

    public ?int $deletingId = null;

    #[Computed]
    public function researchTypes(): Collection
    {
        return ResearchType::withCount('submissions')->orderBy('name')->get();
    }

    #[Computed]
    public function deleting(): ?ResearchType
    {
        return $this->deletingId ? ResearchType::withCount('submissions')->find($this->deletingId) : null;
    }

    /**
     * Open the form for a new research type.
     */
    public function create(): void
    {
        $this->reset('editingId', 'name');
        $this->resetValidation();

        Flux::modal('research-type-form')->show();
    }

    /**
     * Open the form for an existing research type.
     */
    public function edit(int $id): void
    {
        $researchType = ResearchType::findOrFail($id);

        $this->editingId = $researchType->id;
        $this->name = $researchType->name;
        $this->resetValidation();

        Flux::modal('research-type-form')->show();
    }

    /**
     * Create or update the research type.
     */
    public function save(): void
    {
        $this->name = trim($this->name);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('research_types')->ignore($this->editingId)],
        ]);

        if ($this->editingId) {
            ResearchType::findOrFail($this->editingId)->update($validated);
            Flux::toast(variant: 'success', text: __('Research type updated.'));
        } else {
            ResearchType::create($validated);
            Flux::toast(variant: 'success', text: __('Research type added.'));
        }

        Flux::modal('research-type-form')->close();
        $this->reset('editingId', 'name');
        unset($this->researchTypes);
    }

    /**
     * Ask before deleting a research type.
     */
    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
        unset($this->deleting);

        Flux::modal('research-type-delete')->show();
    }

    /**
     * Delete the research type unless submissions still use it.
     */
    public function delete(): void
    {
        $researchType = ResearchType::withCount('submissions')->findOrFail($this->deletingId);

        if ($researchType->submissions_count > 0) {
            return;
        }

        $researchType->delete();

        Flux::modal('research-type-delete')->close();
        Flux::toast(variant: 'success', text: __('Research type deleted.'));
        $this->reset('deletingId');
        unset($this->researchTypes, $this->deleting);
    }
}; ?>

<section class="mx-auto w-full max-w-4xl">
    <header class="flex items-center gap-4 border-b border-line pb-6">
        <img src="{{ asset('images/isu_logo.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
        <div class="min-w-0">
            <flux:heading size="xl" level="1">{{ __('Research Types') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Kinds of research a proposal can be filed as.') }}</flux:text>
        </div>
        <flux:spacer />
        <flux:button variant="primary" icon="plus" wire:click="create" data-test="new-research-type-button">
            {{ __('New') }}
        </flux:button>
    </header>

    @if ($this->researchTypes->isEmpty())
        <div class="mt-8 rounded-xl border border-dashed border-line px-6 py-12 text-center">
            <flux:heading>{{ __('No research types yet') }}</flux:heading>
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
                @foreach ($this->researchTypes as $researchType)
                    <flux:table.row :key="$researchType->id">
                        <flux:table.cell variant="strong">{{ $researchType->name }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $researchType->submissions_count }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:dropdown position="bottom" align="end">
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" :aria-label="__('Actions for :name', ['name' => $researchType->name])" />

                                <flux:menu>
                                    <flux:menu.item icon="pencil-square" wire:click="edit({{ $researchType->id }})">{{ __('Edit') }}</flux:menu.item>
                                    <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $researchType->id }})">{{ __('Delete') }}</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <flux:modal name="research-type-form" class="w-full md:w-96">
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit research type') : __('New research type') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" type="text" maxlength="50" required autofocus />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-research-type-button">
                    {{ $editingId ? __('Save changes') : __('Add research type') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="research-type-delete" class="w-full md:w-96">
        @if ($this->deleting)
            <div class="flex flex-col gap-6">
                <div>
                    <flux:heading size="lg">{{ __('Delete :name?', ['name' => $this->deleting->name]) }}</flux:heading>

                    @if ($this->deleting->submissions_count > 0)
                        <flux:text class="mt-2">
                            {{ trans_choice('{1} One submission uses this research type, so it can’t be deleted. Rename it instead.|[2,*] :count submissions use this research type, so it can’t be deleted. Rename it instead.', $this->deleting->submissions_count) }}
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
                        <flux:button variant="danger" wire:click="delete" data-test="delete-research-type-button">{{ __('Delete') }}</flux:button>
                    @endif
                </div>
            </div>
        @endif
    </flux:modal>
</section>
