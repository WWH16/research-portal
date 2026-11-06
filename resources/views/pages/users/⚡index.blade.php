<?php

use App\Concerns\ProfileValidationRules;
use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\User;
use App\Notifications\AccountApproved;
use App\Notifications\AccountRejected;
use App\Notifications\PasswordChanged;
use App\Services\DocumentStorage;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
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

    /** A college code, or "none" for accounts without one; the dashboard links here with ?college=none. */
    #[Url(as: 'college', except: '')]
    public string $college = '';

    public ?int $editingId = null;
    public string $name = '';
    public string $email = '';
    public string $mobile = '';
    public ?int $department_id = null;
    public string $role = Role::Faculty->value;
    public string $password = '';

    /** "pending", "approved" or "rejected"; the sidebar's sign-up count links here with ?status=pending. */
    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    public ?int $deletingId = null;

    public ?int $rejectingId = null;

    /**
     * Any filter change starts again from the first page.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'roleFilter', 'college', 'statusFilter'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function users(): LengthAwarePaginator
    {
        $search = trim($this->search);

        $users = User::query()
            ->with('department:id,code')
            ->when($search !== '', function ($query) use ($search) {
                $term = '%'.$search.'%';

                $query->where(fn ($query) => $query
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('researcher_id', 'like', $term));
            })
            ->when(Role::tryFrom($this->roleFilter), fn ($query, Role $role) => $query->where('role', $role))
            ->when($this->statusFilter === 'pending', fn ($query) => $query->pendingApproval())
            ->when($this->statusFilter === 'approved', fn ($query) => $query->approved())
            ->when($this->statusFilter === 'rejected', fn ($query) => $query->whereNotNull('rejected_at'))
            ->when($this->college === 'none', fn ($query) => $query->whereNull('department_id'))
            ->when(! in_array($this->college, ['', 'none'], true), fn ($query) => $query->whereRelation('department', 'code', $this->college))
            ->orderBy('name')
            // Members with the same name keep one order, so none shows on two pages or on neither.
            ->orderBy('id')
            ->paginate(15);

        // A page past the end, from a link or once its last member was deleted or approved off a filter,
        // shows the last page that has members rather than claiming no one matches.
        if ($users->isEmpty() && $users->currentPage() > 1 && $users->total() > 0) {
            $this->setPage($users->lastPage());

            return $this->users();
        }

        return $users;
    }

    /**
     * The sidebar found the sign-up count moved, as when someone new confirmed their email: redraw the list.
     * Other counts leave it be.
     *
     * @param  list<string>  $changed
     */
    #[On('open-work-changed')]
    public function openWorkChanged(array $changed): void
    {
        if (in_array('toApprove', $changed, true)) {
            unset($this->users);
        } else {
            $this->skipRender();
        }
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
            ? User::withCount(['submissions', 'proponents', 'publications', 'driveItems', 'announcements'])->find($this->deletingId)
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
            $user->proponents_count ? trans_choice('{1} one place on a proponents list|[2,*] :count places on proponents lists', $user->proponents_count) : null,
            $user->publications_count ? trans_choice('{1} one publication|[2,*] :count publications', $user->publications_count) : null,
            $user->drive_items_count ? trans_choice('{1} one drive item|[2,*] :count drive items', $user->drive_items_count) : null,
            $user->announcements_count ? trans_choice('{1} one announcement|[2,*] :count announcements', $user->announcements_count) : null,
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
     * The roles the form can give. A faculty account keeps its role, so a project or paper never loses the only person
     * who can edit it. Admin and staff accounts swap between those two, and a new account can be any of the three.
     *
     * @return list<Role>
     */
    #[Computed]
    public function roles(): array
    {
        return match (true) {
            ! $this->editing => [Role::Faculty, Role::Staff, Role::Admin],
            $this->editing->isFaculty() => [Role::Faculty],
            default => [Role::Staff, Role::Admin],
        };
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
        $this->role = $user->role->value;

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
            $this->role = Auth::user()->role->value;
        }

        $validated = $this->validate([
            'name' => $this->nameRules(),
            'email' => $this->emailRules($this->editingId),
            'mobile' => ['nullable', 'string', 'max:20'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'role' => ['required', Rule::enum(Role::class)->only($this->roles)],
            'password' => [$this->editingId ? 'nullable' : 'required', 'string', Password::default()],
        ], attributes: [
            'department_id' => __('college'),
        ]);

        $validated['mobile'] = $validated['mobile'] ?: null;

        if (blank($validated['password'])) {
            unset($validated['password']);
        }

        // Each change and its log entry save together, so a failed log write never leaves a change the log missed.
        if ($this->editingId) {
            $user = DB::transaction(function () use ($validated) {
                $user = User::findOrFail($this->editingId);
                $user->forceFill($validated)->save();

                if ($user->wasChanged()) {
                    ActivityLog::record('user.updated', $user, array_filter([
                        'role' => $user->wasChanged('role') ? [$user->getPrevious()['role'], $user->role->value] : null,
                        'password' => $user->wasChanged('password'),
                    ]));
                }

                return $user;
            });

            // Told once the change has saved, and never shown the new password. Their other sessions end on their next
            // request; an admin changing their own password here keeps this one, as on the Security page.
            if ($user->wasChanged('password')) {
                $self = $user->is(Auth::user());

                if ($self) {
                    Auth::setUser($user);
                    Auth::logoutOtherDevices($validated['password']);
                }

                $user->notify(new PasswordChanged(byResearchOffice: ! $self));
            }

            Flux::toast(variant: 'success', text: __('User updated.'));
        } else {
            $user = User::createRetryingResearcherId(fn () => DB::transaction(function () use ($validated) {
                // Accounts an admin creates are trusted, so they skip email verification and approval;
                // markEmailAsVerified() saves, which creates the account.
                $user = (new User)->forceFill([...$validated, 'approved_at' => now()]);
                $user->markEmailAsVerified();
                ActivityLog::record('user.created', $user, ['role' => $user->role->value]);

                return $user;
            }));

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

        DB::transaction(function () {
            // Notifications point at the user without a foreign key, so they would outlive the account.
            $this->deleting->notifications()->delete();
            $this->deleting->delete();
            ActivityLog::record('user.deleted', $this->deleting);
        });

        // Nothing points at the photo once the account is gone, so it leaves Google Drive too.
        if ($this->deleting->profile_image) {
            app(DocumentStorage::class)->delete($this->deleting->profile_image);
        }

        Flux::modal('user-delete')->close();
        Flux::toast(variant: 'success', text: __('User deleted.'));
        $this->dispatch('open-work-stale');
        $this->reset('deletingId');
        unset($this->users, $this->deleting, $this->deletingBlocker);
    }

    /**
     * Let a pending or rejected sign-up into the portal. Approving a rejected account undoes a mistaken rejection.
     */
    public function approve(int $id): void
    {
        $user = User::findOrFail($id);

        if ($user->isApproved()) {
            return;
        }

        DB::transaction(function () use ($user) {
            $user->forceFill(['approved_at' => now(), 'rejected_at' => null])->save();
            ActivityLog::record('user.approved', $user);
        });

        // Told once the approval is saved, so they don't keep trying to sign in.
        $user->notify(new AccountApproved);

        Flux::toast(variant: 'success', text: __(':name can now use the portal.', ['name' => $user->name]));
        // The sidebar's sign-up count changes, so it counts again at once.
        $this->dispatch('open-work-stale');
        unset($this->users);
    }

    #[Computed]
    public function rejecting(): ?User
    {
        return $this->rejectingId ? User::pendingApproval()->find($this->rejectingId) : null;
    }

    /**
     * Ask before turning a sign-up down.
     */
    public function confirmReject(int $id): void
    {
        $this->rejectingId = $id;
        unset($this->rejecting);

        Flux::modal('user-reject')->show();
    }

    /**
     * Turn a pending sign-up down. The account stays, so the same email can't sign up again.
     */
    public function reject(): void
    {
        $user = $this->rejecting;

        if (! $user) {
            return;
        }

        DB::transaction(function () use ($user) {
            $user->forceFill(['rejected_at' => now()])->save();
            ActivityLog::record('user.rejected', $user);
        });

        $user->notify(new AccountRejected);

        Flux::modal('user-reject')->close();
        Flux::toast(variant: 'success', text: __('Sign-up rejected.'));
        $this->dispatch('open-work-stale');
        $this->reset('rejectingId');
        unset($this->users, $this->rejecting);
    }

    /**
     * Clear the search, role, status and college filters.
     */
    public function clearFilters(): void
    {
        $this->reset('search', 'roleFilter', 'statusFilter', 'college');
        $this->resetPage();
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'email', 'mobile', 'department_id', 'role', 'password');
        $this->resetValidation();
        unset($this->editing, $this->editingSelf, $this->roles);
    }
}; ?>

<section class="mx-auto w-full max-w-5xl">
    <x-page-title :subtitle="__('Accounts, roles, and college assignments for portal members.')">{{ __('Manage Users') }}</x-page-title>

    <div class="mt-6 flex flex-col gap-3 sm:flex-row">
        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            :placeholder="__('Search name, email, or researcher ID')"
            :aria-label="__('Search users')"
            clearable
            class="sm:flex-1"
        />

        <flux:select wire:model.live="roleFilter" data-test="role-filter" :aria-label="__('Filter by role')" class="sm:max-w-44">
            <flux:select.option value="">{{ __('All roles') }}</flux:select.option>
            <flux:select.option :value="Role::Admin->value">{{ __('Admins') }}</flux:select.option>
            <flux:select.option :value="Role::Staff->value">{{ __('Staff') }}</flux:select.option>
            <flux:select.option :value="Role::Faculty->value">{{ __('Faculty') }}</flux:select.option>
        </flux:select>

        <flux:select wire:model.live="statusFilter" :aria-label="__('Filter by status')" class="sm:max-w-44">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            <flux:select.option value="pending">{{ __('Pending') }}</flux:select.option>
            <flux:select.option value="approved">{{ __('Approved') }}</flux:select.option>
            <flux:select.option value="rejected">{{ __('Rejected') }}</flux:select.option>
        </flux:select>

        <flux:button variant="primary" icon="plus" wire:click="create" class="shrink-0 max-sm:h-11" data-test="new-user-button">
            {{ __('New') }}
        </flux:button>
    </div>

    @if ($this->users->isEmpty())
        <div class="mt-6 rounded-xl border border-dashed border-line px-6 py-12 text-center">
            <flux:heading>{{ __('No users match these filters') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Try a different name or email, or show every role and college.') }}</flux:text>
            <flux:button variant="ghost" size="sm" class="mt-4" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
        </div>
    @else
        <flux:table class="mt-4" :paginate="$this->users">
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column class="max-md:hidden">{{ __('Researcher ID') }}</flux:table.column>
                <flux:table.column data-test="college-column">
                    <x-college-filter model="college" :value="$college" :codes="$this->departments->pluck('code')" with-none />
                </flux:table.column>
                <flux:table.column>{{ __('Role') }}</flux:table.column>
                <flux:table.column class="w-0"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->users as $user)
                    <flux:table.row :key="$user->id">
                        <flux:table.cell>
                            <div class="flex min-w-0 items-center gap-3">
                                <flux:avatar size="sm" :src="$user->profileImageUrl()" :name="$user->name" :initials="$user->initials()" />
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="truncate font-medium text-zinc-800">{{ $user->name }}</span>
                                        @if ($user->is(auth()->user()))
                                            <span class="inline-flex shrink-0 items-center rounded-md bg-isu-green-700 px-1.5 py-0.5 text-xs font-medium text-white">{{ __('You') }}</span>
                                        @endif
                                    </div>
                                    <div class="truncate text-xs text-zinc-500">{{ $user->email }}</div>
                                </div>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="tabular-nums max-md:hidden">{{ $user->researcher_id ?? '—' }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($user->department)
                                {{ $user->department->code }}
                            @else
                                <span class="text-zinc-400">—</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" inset="top bottom" data-test="role-badge" :color="match ($user->role) { Role::Admin => 'green', Role::Staff => 'blue', default => 'zinc' }">
                                {{ __($user->role->name) }}
                            </flux:badge>
                            @if ($user->isRejected())
                                <flux:badge size="sm" inset="top bottom" color="red" data-test="rejected-badge">{{ __('Rejected') }}</flux:badge>
                            @elseif (! $user->isApproved())
                                <flux:badge size="sm" inset="top bottom" color="yellow" data-test="pending-badge">{{ __('Pending') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:dropdown position="bottom" align="end">
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" :aria-label="__('Actions for :name', ['name' => $user->name])" />

                                <flux:menu>
                                    @unless ($user->isApproved())
                                        <flux:menu.item icon="check-circle" wire:click="approve({{ $user->id }})">{{ __('Approve') }}</flux:menu.item>
                                    @endunless
                                    @unless ($user->isApproved() || $user->isRejected())
                                        <flux:menu.item icon="x-circle" variant="danger" wire:click="confirmReject({{ $user->id }})">{{ __('Reject') }}</flux:menu.item>
                                    @endunless
                                    <flux:menu.item icon="pencil-square" wire:click="edit({{ $user->id }})">{{ __('Edit') }}</flux:menu.item>
                                    <flux:menu.item icon="clipboard-document-list" :href="route('activity-log.index', ['user' => $user->id])" wire:navigate>{{ __('View activity') }}</flux:menu.item>
                                    <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $user->id }})">{{ __('Delete') }}</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    {{-- Closing either dialog forgets its account without a request, so later searches don't reload it --}}
    <flux:modal name="user-form" wire:close="$set('editingId', null, false)" class="w-full sm:w-[34rem]">
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
                <flux:select wire:model="department_id" :label="__('College')">
                    {{-- A real, selectable option so users without a department show it and admins can clear one. --}}
                    <flux:select.option value="">{{ __('No college') }}</flux:select.option>
                    @foreach ($this->departments as $department)
                        <flux:select.option :value="$department->id">{{ $department->code }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="role" :label="__('Role')" :disabled="$this->editingSelf || $this->editing?->isFaculty()">
                    @foreach ($this->roles as $option)
                        <flux:select.option :value="$option->value">{{ __($option->name) }}</flux:select.option>
                    @endforeach
                </flux:select>

                {{-- Its own grid row under the Role column, so College and Role stay level above it. --}}
                @if ($this->editingSelf)
                    <flux:text class="-mt-4 text-sm sm:col-start-2">{{ __('You can’t change your own role.') }}</flux:text>
                @elseif ($this->editing?->isFaculty())
                    <flux:text class="-mt-4 text-sm sm:col-start-2">{{ __('A faculty account keeps its role, so its projects and papers always have someone who can edit them.') }}</flux:text>
                @endif
            </div>

            <flux:input
                wire:model="password"
                :label="$editingId ? __('New password') : __('Password')"
                :description:trailing="$editingId ? __('Leave blank to keep the current password.') : __('Share it with the member; they can change it under My Profile, Security.')"
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

    <flux:modal name="user-reject" wire:close="$set('rejectingId', null, false)" class="w-full sm:w-96" aria-labelledby="user-reject-heading">
        @if ($this->rejecting)
            <div class="flex flex-col gap-6">
                <div>
                    <flux:heading size="lg" id="user-reject-heading">{{ __('Reject :name?', ['name' => $this->rejecting->name]) }}</flux:heading>
                    <flux:text class="mt-2">{{ __(':email won’t be able to use the portal or sign up again with this email.', ['email' => $this->rejecting->email]) }}</flux:text>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" wire:click="reject" data-test="reject-user-button">{{ __('Reject') }}</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    <flux:modal name="user-delete"wire:close="$set('deletingId', null, false)" class="w-full sm:w-96" aria-labelledby="user-delete-heading">
        @if ($this->deleting)
            <div class="flex flex-col gap-6">
                <div>
                    <flux:heading size="lg" id="user-delete-heading">{{ __('Delete :name?', ['name' => $this->deleting->name]) }}</flux:heading>

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
