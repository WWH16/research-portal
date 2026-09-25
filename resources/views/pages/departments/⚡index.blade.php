<?php

use App\Models\Department;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Departments')] class extends Component {
    public ?int $editingId = null;
    public string $code = '';
    public string $name = '';

    public ?int $deletingId = null;

    #[Computed]
    public function departments(): Collection
    {
        return Department::withCount(['users', 'submissions'])->orderBy('code')->get();
    }

    #[Computed]
    public function deleting(): ?Department
    {
        return $this->deletingId
            ? Department::withCount(['users', 'submissions', 'driveItems'])->find($this->deletingId)
            : null;
    }

    /**
     * Describe what still references the department being deleted, or null when nothing does.
     */
    #[Computed]
    public function deletingBlocker(): ?string
    {
        $department = $this->deleting;

        if (! $department) {
            return null;
        }

        $uses = array_filter([
            $department->users_count ? trans_choice('{1} one member|[2,*] :count members', $department->users_count) : null,
            $department->submissions_count ? trans_choice('{1} one submission|[2,*] :count submissions', $department->submissions_count) : null,
            $department->drive_items_count ? trans_choice('{1} one drive item|[2,*] :count drive items', $department->drive_items_count) : null,
        ]);

        return $uses === [] ? null : implode(', ', $uses);
    }

    /**
     * Open the form for a new department.
     */
    public function create(): void
    {
        $this->reset('editingId', 'code', 'name');
        $this->resetValidation();

        Flux::modal('department-form')->show();
    }

    /**
     * Open the form for an existing department.
     */
    public function edit(int $id): void
    {
        $department = Department::findOrFail($id);

        $this->editingId = $department->id;
        $this->code = $department->code;
        $this->name = $department->name;
        $this->resetValidation();

        Flux::modal('department-form')->show();
    }

    /**
     * Create or update the department.
     */
    public function save(): void
    {
        $this->code = strtoupper(trim($this->code));
        $this->name = trim($this->name);

        $validated = $this->validate([
            'code' => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('departments')->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:150'],
        ]);

        if ($this->editingId) {
            Department::findOrFail($this->editingId)->update($validated);
            Flux::toast(variant: 'success', text: __('Department updated.'));
        } else {
            Department::create($validated);
            Flux::toast(variant: 'success', text: __('Department added.'));
        }

        Flux::modal('department-form')->close();
        $this->reset('editingId', 'code', 'name');
        unset($this->departments);
    }

    /**
     * Ask before deleting a department.
     */
    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
        unset($this->deleting, $this->deletingBlocker);

        Flux::modal('department-delete')->show();
    }

    /**
     * Delete the department unless members, submissions, or drive items still use it.
     */
    public function delete(): void
    {
        if (! $this->deleting || $this->deletingBlocker) {
            return;
        }

        $this->deleting->delete();

        Flux::modal('department-delete')->close();
        Flux::toast(variant: 'success', text: __('Department deleted.'));
        $this->reset('deletingId');
        unset($this->departments, $this->deleting, $this->deletingBlocker);
    }
}; ?>

<section class="mx-auto w-full max-w-4xl">
    <header class="flex items-center gap-4 border-b border-line pb-6">
        <img src="{{ asset('images/isu_seal.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
        <div class="min-w-0">
            <flux:heading size="xl" level="1">{{ __('Departments') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Colleges and units that members and proposals belong to.') }}</flux:text>
        </div>
        <flux:spacer />
        <flux:button variant="primary" icon="plus" wire:click="create" data-test="new-department-button">
            {{ __('New') }}
        </flux:button>
    </header>

    @if ($this->departments->isEmpty())
        <div class="mt-8 rounded-xl border border-dashed border-line px-6 py-12 text-center">
            <flux:heading>{{ __('No departments yet') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Add one so members and proposals can be assigned to it.') }}</flux:text>
        </div>
    @else
        <flux:table class="mt-6">
            <flux:table.columns>
                <flux:table.column class="w-28">{{ __('Code') }}</flux:table.column>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Members') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Submissions') }}</flux:table.column>
                <flux:table.column class="w-0"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->departments as $department)
                    <flux:table.row :key="$department->id">
                        <flux:table.cell variant="strong">{{ $department->code }}</flux:table.cell>
                        <flux:table.cell class="max-w-xs truncate">{{ $department->name }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $department->users_count }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $department->submissions_count }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:dropdown position="bottom" align="end">
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" :aria-label="__('Actions for :name', ['name' => $department->code])" />

                                <flux:menu>
                                    <flux:menu.item icon="pencil-square" wire:click="edit({{ $department->id }})">{{ __('Edit') }}</flux:menu.item>
                                    <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $department->id }})">{{ __('Delete') }}</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <flux:modal name="department-form" class="w-full md:w-[28rem]">
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit department') : __('New department') }}</flux:heading>

            <flux:input wire:model="code" :label="__('Code')" :description:trailing="__('Short label, like CCS. Letters, numbers, dashes.')" type="text" maxlength="20" class:input="uppercase" required autofocus />

            <flux:input wire:model="name" :label="__('Name')" type="text" maxlength="150" required />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-department-button">
                    {{ $editingId ? __('Save changes') : __('Add department') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="department-delete" class="w-full md:w-96">
        @if ($this->deleting)
            <div class="flex flex-col gap-6">
                <div>
                    <flux:heading size="lg">{{ __('Delete :name?', ['name' => $this->deleting->code]) }}</flux:heading>

                    @if ($this->deletingBlocker)
                        <flux:text class="mt-2">
                            {{ __('Still used by :uses, so it can’t be deleted. Rename it instead.', ['uses' => $this->deletingBlocker]) }}
                        </flux:text>
                    @else
                        <flux:text class="mt-2">{{ __(':name will no longer be available to members or proposals.', ['name' => $this->deleting->name]) }}</flux:text>
                    @endif
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    @unless ($this->deletingBlocker)
                        <flux:button variant="danger" wire:click="delete" data-test="delete-department-button">{{ __('Delete') }}</flux:button>
                    @endunless
                </div>
            </div>
        @endif
    </flux:modal>
</section>
