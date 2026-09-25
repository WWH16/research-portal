<?php

use App\Concerns\ProfileValidationRules;
use App\Models\Department;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Manage Users')] class extends Component {
    use ProfileValidationRules, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(as: 'role', except: '')]
    public string $roleFilter = '';

    public ?int $editingId = null;
    public string $name = '';
    public string $email = '';
    public string $mobile = '';
    public ?int $department_id = null;
    public string $role = 'faculty';
    public string $password = '';

    public ?int $deletingId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRoleFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function users(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return User::query()
            ->with('department:id,code,name')
            ->when($search !== '', function ($query) use ($search) {
                $term = '%'.$search.'%';

                $query->where(fn ($query) => $query
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('researcher_id', 'like', $term));
            })
            ->when(in_array($this->roleFilter, ['admin', 'faculty'], true), fn ($query) => $query->where('role', $this->roleFilter))
            ->orderBy('name')
            ->paginate(15);
    }

    #[Computed]
    public function departments(): Collection
    {
        return Department::orderBy('code')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function deleting(): ?User
    {
        return $this->deletingId
            ? User::withCount(['submissions', 'driveItems', 'announcements', 'activityLogs'])->find($this->deletingId)
            : null;
    }

    /**
     * Explain why the user being deleted must stay, or return null when they can go.
     */
    #[Computed]
    public function deletingBlocker(): ?string
    {
        $user = $this->deleting;

        if (! $user) {
            return null;
        }

        if ($user->is(Auth::user())) {
            return __('You can’t delete your own account from here.');
        }

        $uses = array_filter([
            $user->submissions_count ? trans_choice('{1} one submission|[2,*] :count submissions', $user->submissions_count) : null,
            $user->drive_items_count ? trans_choice('{1} one drive item|[2,*] :count drive items', $user->drive_items_count) : null,
            $user->announcements_count ? trans_choice('{1} one announcement|[2,*] :count announcements', $user->announcements_count) : null,
            $user->activity_logs_count ? trans_choice('{1} one activity log entry|[2,*] :count activity log entries', $user->activity_logs_count) : null,
        ]);

        return $uses === []
            ? null
            : __(':name still has :uses, so the account can’t be deleted.', ['name' => $user->name, 'uses' => implode(', ', $uses)]);
    }

    /**
     * The account open in the edit form, used to show its generated researcher ID.
     */
    #[Computed]
    public function editing(): ?User
    {
        return $this->editingId ? User::find($this->editingId) : null;
    }

    /**
     * Whether the form is editing the signed-in admin's own account.
     */
    #[Computed]
    public function editingSelf(): bool
    {
        return $this->editingId !== null && $this->editingId === Auth::id();
    }

    /**
     * Open the form for a new user.
     */
    public function create(): void
    {
        $this->resetForm();

        Flux::modal('user-form')->show();
    }

    /**
     * Open the form for an existing user.
     */
    public function edit(int $id): void
    {
        $user = User::findOrFail($id);

        $this->resetForm();
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->mobile = (string) $user->mobile;
        $this->department_id = $user->department_id;
        $this->role = $user->role;

        Flux::modal('user-form')->show();
    }

    /**
     * Create or update the user.
     */
    public function save(): void
    {
        $this->name = trim($this->name);
        $this->email = strtolower(trim($this->email));
        $this->mobile = trim($this->mobile);

        // Admins can't change their own role, so the portal always keeps at least one admin.
        if ($this->editingSelf) {
            $this->role = Auth::user()->role;
        }

        $validated = $this->validate([
            'name' => $this->nameRules(),
            'email' => $this->emailRules($this->editingId),
            'mobile' => ['nullable', 'string', 'max:20'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'role' => ['required', Rule::in(['admin', 'faculty'])],
            'password' => [$this->editingId ? 'nullable' : 'required', 'string', Password::default()],
        ], attributes: [
            'department_id' => __('department'),
        ]);

        $validated['mobile'] = $validated['mobile'] ?: null;

        if (blank($validated['password'])) {
            unset($validated['password']);
        }

        if ($this->editingId) {
            User::findOrFail($this->editingId)->update($validated);
            Flux::toast(variant: 'success', text: __('User updated.'));
        } else {
            // Accounts an admin creates are trusted, so they skip email verification.
            $user = User::create($validated);
            $user->markEmailAsVerified();
            Flux::toast(variant: 'success', text: __('User added as :id.', ['id' => $user->researcher_id]));
        }

        Flux::modal('user-form')->close();
        $this->resetForm();
        unset($this->users);
    }

    /**
     * Ask before deleting a user.
     */
    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
        unset($this->deleting, $this->deletingBlocker);

        Flux::modal('user-delete')->show();
    }

    /**
     * Delete the user unless it is the signed-in admin or other records still point to them.
     */
    public function delete(): void
    {
        if (! $this->deleting || $this->deletingBlocker) {
            return;
        }

        $this->deleting->delete();

        Flux::modal('user-delete')->close();
        Flux::toast(variant: 'success', text: __('User deleted.'));
        $this->reset('deletingId');
        unset($this->users, $this->deleting, $this->deletingBlocker);
    }

    /**
     * Clear the search and role filter.
     */
    public function clearFilters(): void
    {
        $this->reset('search', 'roleFilter');
        $this->resetPage();
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'email', 'mobile', 'department_id', 'role', 'password');
        $this->resetValidation();
        unset($this->editing, $this->editingSelf);
    }
}; ?>

<section class="mx-auto w-full max-w-5xl">
    <header class="flex items-center gap-4 border-b border-line pb-6">
        <img src="{{ asset('images/isu_seal.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
        <div class="min-w-0 flex-1">
            <flux:heading size="xl" level="1">{{ __('Manage Users') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Accounts, roles, and department assignments for portal members.') }}</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create" class="shrink-0" data-test="new-user-button">
            {{ __('New') }}
        </flux:button>
    </header>

    <div class="mt-6 flex flex-col gap-3 sm:flex-row">
        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            :placeholder="__('Search name, email, or researcher ID')"
            :aria-label="__('Search users')"
            clearable
            class="sm:flex-1"
        />

        <flux:select wire:model.live="roleFilter" :aria-label="__('Filter by role')" class="sm:max-w-44">
            <flux:select.option value="">{{ __('All roles') }}</flux:select.option>
            <flux:select.option value="admin">{{ __('Admins') }}</flux:select.option>
            <flux:select.option value="faculty">{{ __('Faculty') }}</flux:select.option>
        </flux:select>
    </div>

    @if ($this->users->isEmpty())
        <div class="mt-6 rounded-xl border border-dashed border-line px-6 py-12 text-center">
            <flux:heading>{{ __('No users match these filters') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Try a different name or email, or show every role.') }}</flux:text>
            <flux:button variant="ghost" size="sm" class="mt-4" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
        </div>
    @else
        <flux:table class="mt-4" :paginate="$this->users">
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column class="max-md:hidden">{{ __('Researcher ID') }}</flux:table.column>
                <flux:table.column>{{ __('Department') }}</flux:table.column>
                <flux:table.column>{{ __('Role') }}</flux:table.column>
                <flux:table.column class="w-0"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->users as $user)
                    <flux:table.row :key="$user->id">
                        <flux:table.cell>
                            <div class="flex min-w-0 items-center gap-3">
                                <flux:avatar size="sm" :name="$user->name" :initials="$user->initials()" />
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="truncate font-medium text-zinc-800">{{ $user->name }}</span>
                                        @if ($user->is(auth()->user()))
                                            <span class="inline-flex shrink-0 items-center rounded-md bg-isu-green-700 px-1.5 py-0.5 text-xs font-medium text-white">{{ __('You') }}</span>
                                        @endif
                                    </div>
                                    <div class="truncate text-zinc-500">{{ $user->email }}</div>
                                </div>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="tabular-nums max-md:hidden">{{ $user->researcher_id ?? '—' }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($user->department)
                                <span title="{{ $user->department->name }}">{{ $user->department->code }}</span>
                            @else
                                <span class="text-zinc-400">—</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" inset="top bottom" :color="$user->isAdmin() ? 'green' : 'zinc'">
                                {{ $user->isAdmin() ? __('Admin') : __('Faculty') }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:dropdown position="bottom" align="end">
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" :aria-label="__('Actions for :name', ['name' => $user->name])" />

                                <flux:menu>
                                    <flux:menu.item icon="pencil-square" wire:click="edit({{ $user->id }})">{{ __('Edit') }}</flux:menu.item>
                                    <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $user->id }})">{{ __('Delete') }}</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <flux:modal name="user-form" class="w-full md:w-[34rem]">
        <form wire:submit="save" class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ $editingId ? __('Edit user') : __('New user') }}</flux:heading>
                <flux:text class="mt-1 tabular-nums">
                    {{ $this->editing?->researcher_id
                        ? __('Researcher ID :id', ['id' => $this->editing->researcher_id])
                        : __('A researcher ID is assigned automatically when the account is created.') }}
                </flux:text>
            </div>

            <flux:input wire:model="name" :label="__('Full name')" type="text" maxlength="255" required autofocus autocomplete="off" />

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="email" :label="__('Email')" type="email" maxlength="255" required autocomplete="off" />
                <flux:input wire:model="mobile" :label="__('Mobile')" :badge="__('Optional')" type="tel" maxlength="20" autocomplete="off" />
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:select wire:model="department_id" :label="__('Department')" :placeholder="__('No department')">
                    @foreach ($this->departments as $department)
                        <flux:select.option :value="$department->id">{{ $department->code }} · {{ $department->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div>
                    <flux:select wire:model="role" :label="__('Role')" :disabled="$this->editingSelf">
                        <flux:select.option value="faculty">{{ __('Faculty') }}</flux:select.option>
                        <flux:select.option value="admin">{{ __('Admin') }}</flux:select.option>
                    </flux:select>

                    @if ($this->editingSelf)
                        <flux:text class="mt-2 text-sm">{{ __('You can’t change your own role.') }}</flux:text>
                    @endif
                </div>
            </div>

            <flux:input
                wire:model="password"
                :label="$editingId ? __('New password') : __('Password')"
                :description:trailing="$editingId ? __('Leave blank to keep the current password.') : __('Share it with the member; they can change it in Settings.')"
                type="password"
                autocomplete="new-password"
                viewable
                :required="! $editingId"
            />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-user-button">
                    {{ $editingId ? __('Save changes') : __('Add user') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="user-delete" class="w-full md:w-96">
        @if ($this->deleting)
            <div class="flex flex-col gap-6">
                <div>
                    <flux:heading size="lg">{{ __('Delete :name?', ['name' => $this->deleting->name]) }}</flux:heading>

                    @if ($this->deletingBlocker)
                        <flux:text class="mt-2">{{ $this->deletingBlocker }}</flux:text>
                    @else
                        <flux:text class="mt-2">{{ __(':email will no longer be able to sign in. This can’t be undone.', ['email' => $this->deleting->email]) }}</flux:text>
                    @endif
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    @unless ($this->deletingBlocker)
                        <flux:button variant="danger" wire:click="delete" data-test="delete-user-button">{{ __('Delete') }}</flux:button>
                    @endunless
                </div>
            </div>
        @endif
    </flux:modal>
</section>
