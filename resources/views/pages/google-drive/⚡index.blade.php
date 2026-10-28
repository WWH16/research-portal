<?php

use App\Models\ActivityLog;
use App\Models\GoogleDriveConnection;
use App\Models\Submission;
use App\Services\GoogleDrive;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Google Drive')] class extends Component {
    public string $email = '';

    public bool $editing = false;

    #[Computed]
    public function connection(): ?GoogleDriveConnection
    {
        return GoogleDriveConnection::first()?->load('connectedBy:id,name');
    }

    /**
     * Whether the storage account may still change: once a document is stored, the portal could not read it
     * from another account's Drive.
     */
    #[Computed]
    public function accountLocked(): bool
    {
        return Submission::anyDocumentStored();
    }

    public function changeAccount(): void
    {
        $this->email = (string) $this->connection?->email;
        $this->editing = true;
    }

    /**
     * Save the one Google account that stores documents. Connect then accepts only this address, whichever
     * admin clicks it. Changing it drops the earlier account's access and folder, which hold no documents yet.
     */
    public function saveAccount(): void
    {
        $this->email = strtolower(trim($this->email));
        $this->validate(['email' => ['required', 'string', 'email', 'max:255']], attributes: ['email' => __('Google account')]);

        $connection = $this->connection;

        if ($connection?->email === $this->email) {
            $this->editing = false;

            return;
        }

        if ($connection && $this->accountLocked) {
            $this->addError('email', __('Documents are already stored on :email’s Drive, so the account can’t change.', ['email' => $connection->email]));

            return;
        }

        if ($connection?->isConnected()) {
            rescue(fn () => app(GoogleDrive::class)->revoke($connection->refresh_token), report: false);
        }

        DB::transaction(function () use ($connection) {
            $connection = $connection
                ? tap($connection)->update(['email' => $this->email, 'refresh_token' => null, 'folder_id' => null, 'connected_by' => null])
                : GoogleDriveConnection::create(['email' => $this->email]);
            ActivityLog::record('drive.account_set', $connection);
        });

        $this->editing = false;
        Flux::toast(variant: 'success', text: __('Storage account saved. Connect it to start storing documents.'));
        unset($this->connection);
    }

    /**
     * Revoke the portal's access at Google. The saved account and its folder stay, so documents on Drive
     * open again once the same account is connected.
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

    {{-- Documents set to go to Drive with no account connected: every upload fails --}}
    @if (config('filesystems.disks.submissions.driver') === 'google' && ! $this->connection?->isConnected())
        <flux:callout variant="danger" icon="exclamation-triangle" class="mt-6" data-test="drive-warning"
            :heading="__('Document uploads are failing')"
            :text="__('The portal stores documents on Google Drive, but no Google account is connected. Connect the storage account to restore uploads.')" />
    @endif

    @if (session('drive-error'))
        <flux:callout variant="danger" icon="x-circle" class="mt-6" :text="session('drive-error')" data-test="drive-error" />
    @elseif (session('status'))
        <flux:callout variant="success" icon="check-circle" class="mt-6" :text="session('status')" />
    @endif

    <div class="mt-6 rounded-xl border border-line p-6">
        @if (! $this->connection || $editing)
            {{-- Step one: the address every admin's Connect must match --}}
            <flux:heading>{{ $this->connection ? __('Change the storage account') : __('No storage account yet') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Enter the one Google account whose Drive keeps every project document, such as the Research Office’s portal account. Any admin can then connect it, and the portal refuses every other account.') }}</flux:text>

            <form wire:submit="saveAccount" class="mt-6 flex flex-col gap-4 sm:flex-row sm:items-start">
                <div class="sm:flex-1">
                    <flux:input wire:model="email" type="email" :label="__('Google account')" :placeholder="__('name@gmail.com')" autocomplete="off" />
                </div>
                <div class="flex gap-2 sm:mt-6">
                    <flux:button type="submit" variant="primary" data-test="save-account-button">{{ __('Save account') }}</flux:button>
                    @if ($editing)
                        <flux:button variant="ghost" wire:click="$set('editing', false)">{{ __('Cancel') }}</flux:button>
                    @endif
                </div>
            </form>
        @elseif ($this->connection->isConnected())
            <flux:heading>{{ __('Connected as :email', ['email' => $this->connection->email]) }}</flux:heading>
            <flux:text class="mt-1">
                {{ $this->connection->connectedBy
                    ? __('Connected by :name on :date.', ['name' => $this->connection->connectedBy->name, 'date' => $this->connection->updated_at->format('F j, Y')])
                    : __('Connected on :date.', ['date' => $this->connection->updated_at->format('F j, Y')]) }}
                {{ __('Documents go to its “Research Portal” folder, shared with no one.') }}
            </flux:text>

            {{-- Reconnect renews access for the same account, for example after its password changed --}}
            <div class="mt-6 flex flex-wrap gap-2">
                <flux:button :href="route('google-drive.connect')">{{ __('Reconnect') }}</flux:button>
                <flux:modal.trigger name="drive-disconnect">
                    <flux:button variant="danger">{{ __('Disconnect') }}</flux:button>
                </flux:modal.trigger>
                @unless ($this->accountLocked)
                    <flux:button variant="ghost" wire:click="changeAccount">{{ __('Change account') }}</flux:button>
                @endunless
            </div>
        @else
            <flux:heading>{{ __('Not connected') }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('Storage account:') }} <span class="font-medium text-zinc-800">{{ $this->connection->email }}</span>.
                {{ __('Connect it, then sign in to Google as this account. The portal refuses any other account.') }}
            </flux:text>

            <div class="mt-6 flex flex-wrap gap-2">
                <flux:button variant="primary" :href="route('google-drive.connect')" data-test="connect-drive-button">{{ __('Connect Google Drive') }}</flux:button>
                @unless ($this->accountLocked)
                    <flux:button variant="ghost" wire:click="changeAccount">{{ __('Change account') }}</flux:button>
                @endunless
            </div>
        @endif
    </div>

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
