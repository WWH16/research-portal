<?php

use App\Models\Category;
use App\Models\ResearchType;
use Flux\Flux;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * Research types and categories are both plain named lists that submissions point at,
 * so one page manages either. The route picks which one through its "type" default.
 */
new class extends Component {
    public const TYPES = [
        'research-types' => [
            'model' => ResearchType::class,
            'title' => 'Research Types',
            'description' => 'Kinds of research a proposal can be filed as.',
            'singular' => 'research type',
            'plural' => 'research types',
        ],
        'categories' => [
            'model' => Category::class,
            'title' => 'Categories',
            'description' => 'Subject areas a proposal can be filed under.',
            'singular' => 'category',
            'plural' => 'categories',
        ],
    ];

    #[Locked]
    public string $type;

    public ?int $editingId = null;
    public string $name = '';

    public ?int $deletingId = null;

    public function mount(string $type): void
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        $this->type = $type;
    }

    /**
     * @return array{model: class-string<Model>, title: string, description: string, singular: string, plural: string}
     */
    #[Computed]
    public function config(): array
    {
        return self::TYPES[$this->type];
    }

    #[Computed]
    public function records(): Collection
    {
        return $this->config['model']::withCount('submissions')->orderBy('name')->get();
    }

    #[Computed]
    public function deleting(): ?Model
    {
        return $this->deletingId ? $this->config['model']::withCount('submissions')->find($this->deletingId) : null;
    }

    /**
     * Open the form for a new record.
     */
    public function create(): void
    {
        $this->reset('editingId', 'name');
        $this->resetValidation();

        Flux::modal('named-record-form')->show();
    }

    /**
     * Open the form for an existing record.
     */
    public function edit(int $id): void
    {
        $record = $this->config['model']::findOrFail($id);

        $this->editingId = $record->id;
        $this->name = $record->name;
        $this->resetValidation();

        Flux::modal('named-record-form')->show();
    }

    /**
     * Create or update the record.
     */
    public function save(): void
    {
        $model = $this->config['model'];
        $this->name = trim($this->name);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique($model)->ignore($this->editingId)],
        ]);

        if ($this->editingId) {
            $model::findOrFail($this->editingId)->update($validated);
            Flux::toast(variant: 'success', text: __(':Type updated.', ['type' => $this->config['singular']]));
        } else {
            $model::create($validated);
            Flux::toast(variant: 'success', text: __(':Type added.', ['type' => $this->config['singular']]));
        }

        Flux::modal('named-record-form')->close();
        $this->reset('editingId', 'name');
        unset($this->records);
    }

    /**
     * Ask before deleting a record.
     */
    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
        unset($this->deleting);

        Flux::modal('named-record-delete')->show();
    }

    /**
     * Delete the record unless submissions still use it.
     */
    public function delete(): void
    {
        $record = $this->config['model']::withCount('submissions')->findOrFail($this->deletingId);

        if ($record->submissions_count > 0) {
            return;
        }

        $record->delete();

        Flux::modal('named-record-delete')->close();
        Flux::toast(variant: 'success', text: __(':Type deleted.', ['type' => $this->config['singular']]));
        $this->reset('deletingId');
        unset($this->records, $this->deleting);
    }

    public function rendering($view): void
    {
        $view->title(__($this->config['title']));
    }
}; ?>

<section class="mx-auto w-full max-w-4xl">
    <header class="flex items-center gap-4 border-b border-line pb-6">
        <img src="{{ asset('images/isu_seal.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
        <div class="min-w-0">
            <flux:heading size="xl" level="1">{{ __($this->config['title']) }}</flux:heading>
            <flux:text class="mt-1">{{ __($this->config['description']) }}</flux:text>
        </div>
        <flux:spacer />
        <flux:button variant="primary" icon="plus" wire:click="create" data-test="new-named-record-button">
            {{ __('New') }}
        </flux:button>
    </header>

    @if ($this->records->isEmpty())
        <div class="mt-8 rounded-xl border border-dashed border-line px-6 py-12 text-center">
            <flux:heading>{{ __('No :types yet', ['types' => $this->config['plural']]) }}</flux:heading>
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
                @foreach ($this->records as $record)
                    <flux:table.row :key="$record->id">
                        <flux:table.cell variant="strong">{{ $record->name }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $record->submissions_count }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:dropdown position="bottom" align="end">
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" :aria-label="__('Actions for :name', ['name' => $record->name])" />

                                <flux:menu>
                                    <flux:menu.item icon="pencil-square" wire:click="edit({{ $record->id }})">{{ __('Edit') }}</flux:menu.item>
                                    <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $record->id }})">{{ __('Delete') }}</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <flux:modal name="named-record-form" class="w-full md:w-96">
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit :type', ['type' => $this->config['singular']]) : __('New :type', ['type' => $this->config['singular']]) }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" type="text" maxlength="50" required autofocus />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-named-record-button">
                    {{ $editingId ? __('Save changes') : __('Add :type', ['type' => $this->config['singular']]) }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="named-record-delete" class="w-full md:w-96" aria-labelledby="named-record-delete-heading">
        @if ($this->deleting)
            <div class="flex flex-col gap-6">
                <div>
                    <flux:heading size="lg" id="named-record-delete-heading">{{ __('Delete :name?', ['name' => $this->deleting->name]) }}</flux:heading>

                    @if ($this->deleting->submissions_count > 0)
                        <flux:text class="mt-2">
                            {{ trans_choice('{1} One submission uses this :type, so it can’t be deleted. Rename it instead.|[2,*] :count submissions use this :type, so it can’t be deleted. Rename it instead.', $this->deleting->submissions_count, ['type' => $this->config['singular']]) }}
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
                        <flux:button variant="danger" wire:click="delete" data-test="delete-named-record-button">{{ __('Delete') }}</flux:button>
                    @endif
                </div>
            </div>
        @endif
    </flux:modal>
</section>
