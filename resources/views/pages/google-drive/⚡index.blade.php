<?php

use App\Models\ActivityLog;
use App\Models\GoogleDriveConnection;
use App\Services\GoogleDrive;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Google Drive')] class extends Component {
    #[Computed]
    public function connection(): ?GoogleDriveConnection
    {
        return GoogleDriveConnection::current()?->load('connectedBy:id,name');
    }

    /**
     * Whether submission documents are set to go to Drive, so a missing connection stops every upload.
     */
    #[Computed]
    public function storesOnDrive(): bool
    {
        return config('filesystems.disks.submissions.driver') === 'google';
    }

    /**
     * Revoke the portal's access at Google and forget the connection. Documents already on Drive stay there,
     * and open again once the same account is connected.
     */
    public function disconnect(): void
    {
        $connection = $this->connection;

        if (! $connection) {
            return;
        }

        // A token Google already revoked, or a moment without network, must not keep the portal connected.
        rescue(fn () => app(GoogleDrive::class)->revoke($connection->refresh_token), report: false);

        DB::transaction(function () use ($connection) {
            $connection->delete();
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

    @if ($this->storesOnDrive && ! $this->connection)
        <flux:callout variant="danger" icon="exclamation-triangle" class="mt-6" data-test="drive-warning"
            :heading="__('Document uploads are failing')"
            :text="__('The portal stores documents on Google Drive, but no Google account is connected. Connect the Research Office account to restore uploads.')" />
    @endif

    @if (session('drive-error'))
        <flux:callout variant="danger" icon="x-circle" class="mt-6" :text="session('drive-error')" data-test="drive-error" />
    @elseif (session('status'))
        <flux:callout variant="success" icon="check-circle" class="mt-6" :text="session('status')" />
    @endif

    <div class="mt-6 rounded-xl border border-line p-6">
        @if ($this->connection)
            <flux:heading>{{ __('Connected as :email', ['email' => $this->connection->email]) }}</flux:heading>
            <flux:text class="mt-1">
                {{ $this->connection->connectedBy
                    ? __('Connected by :name on :date.', ['name' => $this->connection->connectedBy->name, 'date' => $this->connection->created_at->format('F j, Y')])
                    : __('Connected on :date.', ['date' => $this->connection->created_at->format('F j, Y')]) }}
                {{ __('Documents go to its “Research Portal” folder, shared with no one.') }}
            </flux:text>

            {{-- Reconnect renews access for the same account, for example after its password changed --}}
            <div class="mt-6 flex gap-2">
                <flux:button :href="route('google-drive.connect')">{{ __('Reconnect') }}</flux:button>
                <flux:modal.trigger name="drive-disconnect">
                    <flux:button variant="danger">{{ __('Disconnect') }}</flux:button>
                </flux:modal.trigger>
            </div>
        @else
            <flux:heading>{{ __('Not connected') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Connect the Research Office Google account so the portal can store documents on its Drive.') }}</flux:text>

            <div class="mt-6">
                <flux:button variant="primary" :href="route('google-drive.connect')" data-test="connect-drive-button">{{ __('Connect Google Drive') }}</flux:button>
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
