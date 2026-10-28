<?php

use App\Models\ActivityLog;
use App\Models\GoogleDriveConnection;
use App\Models\Submission;
use App\Services\GoogleDrive;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Google Drive')] class extends Component {
    /** The first storage account, before any is saved. */
    public string $email = '';

    public string $newEmail = '';

    public string $newEmail_confirmation = '';

    public string $password = '';

    /**
     * The storage account new documents go to.
     */
    #[Computed]
    public function connection(): ?GoogleDriveConnection
    {
        return GoogleDriveConnection::current()?->load('connectedBy:id,name');
    }

    #[Computed]
    public function currentDocuments(): int
    {
        return $this->connection ? Submission::documentsOn($this->connection) : 0;
    }

    /**
     * Earlier storage accounts that still hold documents, each with how many. They stay connected so those
     * documents keep opening; an earlier account that holds nothing is left out.
     *
     * @return Collection<int, array{account: GoogleDriveConnection, documents: int}>
     */
    #[Computed]
    public function earlier(): Collection
    {
        return GoogleDriveConnection::whereNotNull('retired_at')->latest('retired_at')->get()
            ->map(fn (GoogleDriveConnection $account) => ['account' => $account, 'documents' => Submission::documentsOn($account)])
            ->filter(fn (array $row) => $row['documents'] > 0)
            ->values();
    }

    /**
     * Save the first storage account. Connect then accepts only this address, whichever admin clicks it.
     */
    public function saveAccount(): void
    {
        if ($this->connection) {
            return;
        }

        $this->email = strtolower(trim($this->email));
        $this->validate(['email' => ['required', 'string', 'email', 'max:255']], attributes: ['email' => __('Google account')]);

        DB::transaction(function () {
            ActivityLog::record('drive.account_set', GoogleDriveConnection::create(['email' => $this->email]));
        });

        Flux::toast(variant: 'success', text: __('Storage account saved. Connect it to start storing documents.'));
        unset($this->connection);
    }

    /**
     * Point new uploads at another Google account, for example when the current Drive is full. Nothing moves:
     * the current account is retired with its access kept, so its documents keep opening. Switching back to an
     * earlier account reuses it, folder and all. Retyping the address and the admin's password make it deliberate.
     */
    public function changeAccount(): void
    {
        $current = $this->connection;

        if (! $current) {
            return;
        }

        $this->newEmail = strtolower(trim($this->newEmail));
        $this->newEmail_confirmation = strtolower(trim($this->newEmail_confirmation));
        $this->validate([
            'newEmail' => ['required', 'string', 'email', 'max:255', 'confirmed'],
            'password' => ['required', 'string', 'current_password'],
        ], attributes: ['newEmail' => __('new Google account')]);

        if ($this->newEmail === $current->email) {
            $this->addError('newEmail', __('That is already the storage account.'));

            return;
        }

        DB::transaction(function () use ($current) {
            // An account never connected holds nothing, so it is replaced rather than kept.
            $current->folder_id ? $current->update(['retired_at' => now()]) : $current->delete();

            $next = GoogleDriveConnection::whereNotNull('retired_at')->where('email', $this->newEmail)->first();
            $next ? $next->update(['retired_at' => null]) : $next = GoogleDriveConnection::create(['email' => $this->newEmail]);

            ActivityLog::record('drive.account_changed', $next, ['from' => $current->email, 'to' => $next->email]);
        });

        $this->reset('newEmail', 'newEmail_confirmation', 'password');
        Flux::modal('drive-change')->close();
        Flux::toast(variant: 'success', text: __('Storage account changed. Connect it so new uploads can be saved.'));
        unset($this->connection, $this->currentDocuments, $this->earlier);
    }

    /**
     * Revoke the portal's access to the current account at Google. The account and its folder stay, so its
     * documents open again once the same account is connected.
     */
    public function disconnect(): void
    {
        $connection = $this->connection;

        if (! $connection?->isConnected()) {
            return;
        }

        // A token Google already revoked, or a moment without network, must not keep the portal connected.
        rescue(fn () => app(GoogleDrive::class)->revoke($connection->refresh_token), report: false);

        DB::transaction(function () use ($connection) {
            $connection->update(['refresh_token' => null]);
            ActivityLog::record('drive.disconnected', $connection);
        });

        Flux::modal('drive-disconnect')->close();
        Flux::toast(variant: 'success', text: __('Google Drive disconnected.'));
        unset($this->connection);
    }
}; ?>

<section class="mx-auto w-full max-w-3xl">
    <header class="flex items-center gap-4 border-b border-line pb-6">
        <img src="{{ asset('images/isu_seal-128.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
        <div class="min-w-0">
            <flux:heading size="xl" level="1">{{ __('Google Drive') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Where the portal stores project documents. One Research Office account serves the whole portal.') }}</flux:text>
        </div>
    </header>

    {{-- A saved account that isn't connected: every upload fails until it is --}}
    @if ($this->connection && ! $this->connection->isConnected())
        <flux:callout variant="danger" icon="exclamation-triangle" class="mt-6" data-test="drive-warning"
            :heading="__('Document uploads are failing')"
            :text="__('New documents go to :email, but it isn’t connected. Connect it to restore uploads.', ['email' => $this->connection->email])" />
    @endif

    @if (session('drive-error'))
        <flux:callout variant="danger" icon="x-circle" class="mt-6" :text="session('drive-error')" data-test="drive-error" />
    @elseif (session('status'))
        <flux:callout variant="success" icon="check-circle" class="mt-6" :text="session('status')" />
    @endif

    <div class="mt-6 rounded-xl border border-line p-6">
        @if (! $this->connection)
            {{-- Step one: the address every admin's Connect must match --}}
            <flux:heading>{{ __('No storage account yet') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Enter the one Google account whose Drive keeps every project document, such as the Research Office’s portal account. Any admin can then connect it, and the portal refuses every other account.') }}</flux:text>

            <form wire:submit="saveAccount" class="mt-6 flex flex-col gap-4 sm:flex-row sm:items-start">
                <div class="sm:flex-1">
                    <flux:input wire:model="email" type="email" :label="__('Google account')" :placeholder="__('name@gmail.com')" autocomplete="off" />
                </div>
                <flux:button type="submit" variant="primary" class="sm:mt-6" data-test="save-account-button">{{ __('Save account') }}</flux:button>
            </form>
        @else
            @if ($this->connection->isConnected())
                <flux:heading>{{ __('Connected as :email', ['email' => $this->connection->email]) }}</flux:heading>
                <flux:text class="mt-1">
                    {{ $this->connection->connectedBy
                        ? __('Connected by :name on :date.', ['name' => $this->connection->connectedBy->name, 'date' => $this->connection->updated_at->format('F j, Y')])
                        : __('Connected on :date.', ['date' => $this->connection->updated_at->format('F j, Y')]) }}
                    {{ __('New documents go to its “:folder” folder, shared with no one.', ['folder' => GoogleDrive::FOLDER]) }}
                </flux:text>
            @else
                <flux:heading>{{ __('Not connected') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __('Storage account:') }} <span class="font-medium text-zinc-800">{{ $this->connection->email }}</span>.
                    {{ __('Connect it, then sign in to Google as this account. The portal refuses any other account.') }}
                </flux:text>
            @endif

            <div class="mt-6 flex flex-wrap gap-2">
                @if ($this->connection->isConnected())
                    {{-- Reconnect renews access for the same account, for example after its password changed --}}
                    <flux:button :href="route('google-drive.connect')">{{ __('Reconnect') }}</flux:button>
                    <flux:modal.trigger name="drive-disconnect">
                        <flux:button variant="danger">{{ __('Disconnect') }}</flux:button>
                    </flux:modal.trigger>
                @else
                    <flux:button variant="primary" :href="route('google-drive.connect')" data-test="connect-drive-button">{{ __('Connect Google Drive') }}</flux:button>
                @endif
                <flux:modal.trigger name="drive-change">
                    <flux:button variant="ghost">{{ __('Change account') }}</flux:button>
                </flux:modal.trigger>
            </div>
        @endif
    </div>

    {{-- Documents stay on the account that saved them, so the accounts an admin moved away from still matter --}}
    @if ($this->earlier->isNotEmpty())
        <div class="mt-6 rounded-xl border border-line p-6">
            <flux:heading>{{ __('Earlier storage accounts') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Older documents stay on these accounts and still open in the portal. Keep these Google accounts, and don’t delete them or their “:folder” folders.', ['folder' => GoogleDrive::FOLDER]) }}</flux:text>

            <ul class="mt-4 divide-y divide-line">
                @foreach ($this->earlier as $row)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <div class="truncate font-medium text-zinc-800">{{ $row['account']->email }}</div>
                            <flux:text class="text-sm">
                                {{ trans_choice('{1} 1 document|[2,*] :count documents', $row['documents']) }} ·
                                {{ $row['account']->isConnected() ? __('Connected') : __('Not connected, so these documents can’t open') }}
                            </flux:text>
                        </div>
                        @unless ($row['account']->isConnected())
                            <flux:button size="sm" :href="route('google-drive.connect', ['account' => $row['account']->id])">{{ __('Reconnect') }}</flux:button>
                        @endunless
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($this->connection)
        <flux:modal name="drive-change" class="w-full sm:w-[30rem]" aria-labelledby="drive-change-heading">
            <form wire:submit="changeAccount" class="flex flex-col gap-6">
                <div>
                    <flux:heading size="lg" id="drive-change-heading">{{ __('Change the storage account?') }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ $this->currentDocuments
                            ? trans_choice('{1} New uploads will be saved to the new account. Nothing moves: 1 document is stored on :email, and it stays there and keeps opening, so don’t delete that Google account.|[2,*] New uploads will be saved to the new account. Nothing moves: :count documents are stored on :email, and they stay there and keep opening, so don’t delete that Google account.', $this->currentDocuments, ['email' => $this->connection->email])
                            : __('New uploads will be saved to the new account. No documents are stored on :email yet.', ['email' => $this->connection->email]) }}
                    </flux:text>
                </div>

                <flux:input wire:model="newEmail" type="email" :label="__('New Google account')" autocomplete="off" />
                <flux:input wire:model="newEmail_confirmation" type="email" :label="__('Type it again')" autocomplete="off" />
                <flux:input wire:model="password" type="password" :label="__('Your portal password')" autocomplete="current-password" viewable />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="danger" data-test="change-account-button">{{ __('Change account') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    <flux:modal name="drive-disconnect" class="w-full sm:w-96" aria-labelledby="drive-disconnect-heading">
        <div class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg" id="drive-disconnect-heading">{{ __('Disconnect Google Drive?') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Uploads stop and documents can’t be opened until the same account is connected again. The files stay on Drive.') }}</flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="disconnect" data-test="disconnect-drive-button">{{ __('Disconnect') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
