<?php

use App\Models\ActivityLog;
use App\Models\GoogleDriveConnection;
use App\Models\Submission;
use App\Services\DocumentStorage;
use App\Services\GoogleDrive;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Google Drive')] class extends Component {
    /** The first storage account, before any is saved. */
    public string $email = '';

    public string $newEmail = '';

    public string $newEmail_confirmation = '';

    /** The current account's address, typed out to confirm Disconnect or Change account. */
    public string $confirmEmail = '';

    public string $password = '';

    /** Which step of a confirmation dialog is showing: 1 explains what happens, 2 asks for the confirmation. */
    public int $step = 1;

    /** @var array{used: int, limit: int|null, drive: int, portal: int}|false|null The current account's Google storage, asked for after the page shows: null while checking, false when Google can't answer. */
    public array|false|null $usage = null;

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
        return $this->accounts->first(fn (array $row) => ! $row['account']->retired_at)['documents'] ?? 0;
    }

    /**
     * Every storage account that matters, each with how many documents it holds: the current one first, then
     * earlier ones newest first. Earlier accounts stay connected so their documents keep opening. One whose
     * documents were all replaced is listed while it is still connected, so it can be removed; one that holds
     * nothing and isn't connected is left out. Counted in one query, since every click on the page asks again.
     *
     * @return Collection<int, array{account: GoogleDriveConnection, documents: int}>
     */
    #[Computed]
    public function accounts(): Collection
    {
        $documents = Submission::documentsPerAccount();

        return GoogleDriveConnection::orderByRaw('retired_at is not null')->latest('retired_at')->get()
            ->map(fn (GoogleDriveConnection $account) => ['account' => $account, 'documents' => $documents->get($account->id, 0)])
            ->filter(fn (array $row) => ! $row['account']->retired_at || $row['documents'] > 0 || $row['account']->isConnected())
            ->values();
    }

    /**
     * The storage figures as the page shows them, or null until Google has answered: the total, the limit, what
     * is free, the share used, and what fills the account, the portal's own documents first, then the rest of
     * Drive, then Gmail and Photos. Each part's width is its share of the limit (of all use on a plan without
     * one), never below a sliver, so a small share still shows.
     *
     * @return array{used: string, limit: string|null, free: string|null, percent: float|null, parts: list<array{label: string, size: string, width: float, color: string}>}|null
     */
    #[Computed]
    public function storage(): ?array
    {
        if (! is_array($this->usage)) {
            return null;
        }

        ['used' => $used, 'limit' => $limit, 'drive' => $drive, 'portal' => $portal] = $this->usage;
        $whole = $limit ?: max(1, $used);
        $parts = [
            [__('Research portal documents'), $portal, 'bg-isu-green-600'],
            [__('Other Google Drive files'), max(0, $drive - $portal), 'bg-isu-gold-400'],
            [__('Gmail, Google Photos and other'), max(0, $used - $drive), 'bg-zinc-400'],
        ];

        return [
            'used' => self::size($used),
            'limit' => $limit ? self::size($limit) : null,
            'free' => $limit ? self::size(max(0, $limit - $used)) : null,
            'percent' => $limit ? min(100, $used / $limit * 100) : null,
            'parts' => array_map(fn (array $part) => [
                'label' => $part[0],
                'size' => self::size($part[1]),
                'width' => $part[1] > 0 ? max($part[1] / $whole * 100, 0.75) : 0,
                'color' => $part[2],
            ], $parts),
        ];
    }

    /**
     * Bytes in the largest unit that keeps the number at 1 or more, since a project PDF is a few MB in terabytes of
     * space. Two decimals and 1024-byte units, as Google's own storage page shows them.
     */
    private static function size(int $bytes): string
    {
        foreach (['TB' => 4, 'GB' => 3, 'MB' => 2, 'KB' => 1] as $unit => $power) {
            if ($bytes >= 1024 ** $power) {
                return round($bytes / 1024 ** $power, 2).' '.$unit;
            }
        }

        return $bytes.' B';
    }

    /**
     * The last few Google Drive changes, newest first; the Activity Log keeps the rest.
     *
     * @return Collection<int, ActivityLog>
     */
    #[Computed]
    public function recent(): Collection
    {
        return ActivityLog::where('action', 'like', 'drive.%')->latest()->latest('id')->limit(5)->get();
    }

    /**
     * Ask Google how full the current account is, after the page shows, so a slow Google never holds it up.
     * Kept ten minutes; when Google can't answer, the page shows nothing rather than an error. When Google says
     * the access or the folder is gone, the whole page redraws and asks for Connect instead of claiming all is well.
     */
    public function loadUsage(): void
    {
        $account = $this->connection;

        $this->usage = $account?->isConnected()
            ? rescue(fn () => Cache::remember("google-drive.usage.{$account->id}", now()->addMinutes(10), fn () => app(GoogleDrive::class)->usage($account)), function (Throwable $e) use ($account) {
                if (app(DocumentStorage::class)->markIfUnreachable($account, $e)) {
                    if (! $account->folder_id) {
                        session()->flash('drive-error', __('The “:folder” folder is gone from Google Drive, so new documents can’t be saved. Connect again to make a new folder.', ['folder' => GoogleDrive::FOLDER]));
                    }
                    $this->redirectRoute('google-drive.index', navigate: true);
                }

                return false;
            }, report: false)
            : false;
    }

    /**
     * Remove an earlier account that holds nothing any more and give its access back to Google. One that still
     * holds documents is never removed: they would stop opening.
     */
    public function removeEarlier(int $id): void
    {
        $account = GoogleDriveConnection::whereNotNull('retired_at')->find($id);

        if (! $account || Submission::documentsPerAccount()->get($account->id, 0) > 0) {
            return;
        }

        DB::transaction(function () use ($account) {
            $account->delete();
            ActivityLog::record('drive.account_removed', $account);
        });

        if ($account->isConnected()) {
            rescue(fn () => app(GoogleDrive::class)->revoke($account->refresh_token), report: false);
        }

        Flux::toast(variant: 'success', text: __(':email removed.', ['email' => $account->email]));
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
        $this->confirmCurrentAccount($current, [
            'newEmail' => ['required', 'string', 'email', 'max:255', 'confirmed'],
        ]);

        if ($this->newEmail === $current->email) {
            $this->addError('newEmail', __('That is already the storage account.'));

            return;
        }

        // An account that holds no documents is replaced rather than kept, and its access given back to Google below.
        $keep = Submission::documentsPerAccount()->get($current->id, 0) > 0;

        DB::transaction(function () use ($current, $keep) {
            $keep ? $current->update(['retired_at' => now()]) : $current->delete();

            $next = GoogleDriveConnection::whereNotNull('retired_at')->where('email', $this->newEmail)->first();
            $next ? $next->update(['retired_at' => null]) : $next = GoogleDriveConnection::create(['email' => $this->newEmail]);

            ActivityLog::record('drive.account_changed', $next, ['from' => $current->email, 'to' => $next->email]);
        });

        // Otherwise its token would stay saved with no page left to disconnect it from.
        if (! $keep && $current->isConnected()) {
            rescue(fn () => app(GoogleDrive::class)->revoke($current->refresh_token), report: false);
        }

        $this->resetConfirmation();
        Flux::modal('drive-change')->close();
        Flux::toast(variant: 'success', text: __('Storage account changed. Connect it so new uploads can be saved.'));
        unset($this->connection, $this->currentDocuments, $this->accounts);
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

        $this->confirmCurrentAccount($connection);

        // A token Google already revoked, or a moment without network, must not keep the portal connected.
        rescue(fn () => app(GoogleDrive::class)->revoke($connection->refresh_token), report: false);

        DB::transaction(function () use ($connection) {
            $connection->update(['refresh_token' => null]);
            ActivityLog::record('drive.disconnected', $connection);
        });

        $this->resetConfirmation();
        Flux::modal('drive-disconnect')->close();
        Flux::toast(variant: 'success', text: __('Google Drive disconnected.'));
        unset($this->connection);
    }

    /**
     * The danger-zone check shared by Disconnect and Change account, checked here whatever the dialog showed:
     * the current account's address typed out exactly, and the admin's own password.
     *
     * @param  array<string, list<mixed>>  $rules  Extra rules for the action's own fields.
     */
    private function confirmCurrentAccount(GoogleDriveConnection $account, array $rules = []): void
    {
        $this->confirmEmail = strtolower(trim($this->confirmEmail));

        $this->validate([
            'confirmEmail' => ['required', 'string', Rule::in([$account->email])],
            'password' => ['required', 'string', 'current_password'],
            ...$rules,
        ], [
            'confirmEmail.in' => __('Type :email exactly to confirm.', ['email' => $account->email]),
        ], ['newEmail' => __('new Google account')]);
    }

    /**
     * Start a confirmation dialog over: back to step one, with nothing typed.
     */
    public function resetConfirmation(): void
    {
        $this->reset('newEmail', 'newEmail_confirmation', 'confirmEmail', 'password', 'step');
        $this->resetValidation();
    }
}; ?>

<section class="mx-auto w-full max-w-5xl">
    <header class="flex items-center gap-4 border-b border-line pb-6">
        <img src="{{ asset('images/isu_seal-128.png') }}" alt="{{ __('Isabela State University') }}" class="size-12 shrink-0 object-contain" />
        <div class="min-w-0">
            <flux:heading size="xl" level="1">{{ __('Google Drive') }}</flux:heading>
            <flux:text class="mt-1">{{ __('The one Research Office account that stores every project document.') }}</flux:text>
        </div>
    </header>

    {{-- A failed Connect on a working account: a saved account that isn't connected says why in its own callout.
         Flux draws the danger icon in red-400, under 3:1 on the red fill, so the icon takes red-600. --}}
    @php($failing = $this->connection && ! $this->connection->isConnected())
    @if (session('drive-error') && ! $failing)
        <flux:callout variant="danger" icon="x-circle" class:icon="text-red-600!" class="mt-6" :text="session('drive-error')" data-test="drive-error" />
    @endif

    @if ($failing)
        <flux:callout variant="danger" icon="exclamation-triangle" class:icon="text-red-600!" class="mt-6" data-test="drive-warning">
            <flux:callout.heading><h2>{{ __('Document uploads are failing') }}</h2></flux:callout.heading>
            <flux:callout.text>{{ __(':email isn’t connected. Connect it, then sign in to Google as this account.', ['email' => $this->connection->email]) }}</flux:callout.text>
            @if (session('drive-error'))
                <flux:callout.text class="font-medium">{{ session('drive-error') }}</flux:callout.text>
            @endif

            <x-slot name="actions">
                {{-- A link, so Flux shows no spinner itself: this one runs until the next page opens, and resets on Back --}}
                <flux:button variant="primary" :href="route('google-drive.connect')" data-test="connect-drive-button"
                    x-data="{ busy: false }" x-on:click="busy = true" x-on:pageshow.window="busy = false" x-bind:class="busy && 'pointer-events-none opacity-75'" x-bind:aria-disabled="busy">
                    <flux:icon.loading class="hidden" x-bind:class="{ hidden: ! busy }" variant="micro" />
                    {{ __('Connect Google Drive') }}
                </flux:button>
                <flux:modal.trigger name="drive-change">
                    <flux:button variant="ghost">{{ __('Change account') }}</flux:button>
                </flux:modal.trigger>
            </x-slot>
        </flux:callout>
    @else
        {{-- The one boxed card: the account new documents go to, and what to do about it --}}
        <div class="mt-6 rounded-xl border border-line p-5">
            @if (! $this->connection)
                {{-- Step one: the address every admin's Connect must match --}}
                <flux:heading size="lg" level="2">{{ __('No storage account yet') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Enter the Google account that will store project documents, such as the Research Office’s portal account. Any admin can then connect it; no other account can.') }}</flux:text>

                <form wire:submit="saveAccount" class="mt-6 flex flex-col gap-4 sm:flex-row sm:items-start">
                    <div class="sm:flex-1">
                        <flux:input wire:model="email" type="email" :label="__('Google account')" :placeholder="__('name@gmail.com')" autocomplete="off" />
                    </div>
                    <flux:button type="submit" variant="primary" class="sm:mt-6" data-test="save-account-button">{{ __('Save account') }}</flux:button>
                </form>
            @else
                <flux:heading size="lg" level="2">{{ __('Connected as :email', ['email' => $this->connection->email]) }}</flux:heading>
                <flux:text class="mt-1">
                    {{ $this->connection->connectedBy
                        ? __('Connected by :name on :date.', ['name' => $this->connection->connectedBy->name, 'date' => $this->connection->updated_at->format('F j, Y')])
                        : __('Connected on :date.', ['date' => $this->connection->updated_at->format('F j, Y')]) }}
                    {{ __('New documents go to its “:folder” folder, shared with no one.', ['folder' => GoogleDrive::FOLDER]) }}
                </flux:text>

                {{-- While connected there is nothing to reconnect: when Google revokes access, the portal marks the account
                     not connected and the callout above offers Connect again. --}}
                <div class="mt-4 flex flex-wrap gap-2">
                    {{-- Opens for whoever is signed in to Google as the storage account; anyone else sees no access --}}
                    <flux:button :href="'https://drive.google.com/drive/folders/'.$this->connection->folder_id" target="_blank" icon:trailing="arrow-top-right-on-square">{{ __('Open folder in Google Drive') }}<span class="sr-only"> {{ __('(opens in a new tab)') }}</span></flux:button>
                    <flux:modal.trigger name="drive-disconnect">
                        <flux:button variant="danger">{{ __('Disconnect') }}</flux:button>
                    </flux:modal.trigger>
                    <flux:modal.trigger name="drive-change">
                        <flux:button variant="ghost">{{ __('Change account') }}</flux:button>
                    </flux:modal.trigger>
                </div>
            @endif
        </div>
    @endif

    {{-- Below the card, plain sections: reference and housekeeping, a divider and a heading each --}}

    {{-- Google's whole storage for the account, Gmail and Photos included: uploads fail once it is full. Asked for
         after the page shows, so a slow Google never holds the page up. An island, so the answer redraws this
         section alone instead of the whole page; a full redraw draws it too, from the figures already loaded. --}}
    @if ($this->connection?->isConnected())
        @island(name: 'storage', always: true)
        <div class="mt-8 border-t border-line pt-6" wire:init="loadUsage" data-test="drive-storage">
            <flux:heading size="lg" level="2">{{ __('Storage') }}</flux:heading>

            @if ($usage === null)
                <flux:text class="mt-1">{{ __('Checking storage…') }}</flux:text>
            @elseif ($usage === false)
                <flux:text class="mt-1">{{ __('Storage use unavailable right now.') }}</flux:text>
            @else
                @php($storage = $this->storage)
                <div class="mt-3 flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1" data-test="drive-usage">
                    <p class="flex items-baseline gap-2">
                        <span class="text-3xl font-semibold tabular-nums text-zinc-800">{{ $storage['used'] }}</span>
                        <span class="text-zinc-500">{{ $storage['limit'] ? __('of :limit used', ['limit' => $storage['limit']]) : __('used') }}</span>
                    </p>
                    @if ($storage['free'])
                        <flux:text class="tabular-nums">{{ __(':free free', ['free' => $storage['free']]) }}</flux:text>
                    @endif
                </div>

                {{-- What fills the account, in the order storage() gives it; a screen reader hears the amount, not a bare number --}}
                <div class="mt-3 flex h-2 gap-px overflow-hidden rounded-full bg-zinc-200" role="meter" aria-valuemin="0" aria-valuemax="100"
                    aria-valuenow="{{ round($storage['percent'] ?? 100, 1) }}" aria-valuetext="{{ $storage['used'] }} {{ $storage['limit'] ? __('of :limit used', ['limit' => $storage['limit']]) : __('used') }}" aria-label="{{ __('Google storage used') }}">
                    @foreach ($storage['parts'] as $part)
                        @if ($part['width'])
                            <div class="h-full {{ $part['color'] }}" style="width: {{ $part['width'] }}%"></div>
                        @endif
                    @endforeach
                </div>

                <ul class="mt-3 flex flex-wrap gap-x-6 gap-y-1.5" data-test="drive-usage-parts">
                    @foreach ($storage['parts'] as $part)
                        <li class="flex items-center gap-2 text-sm text-zinc-500">
                            <span class="size-2.5 shrink-0 rounded-sm {{ $part['color'] }}" aria-hidden="true"></span>
                            {{ $part['label'] }}
                            <span class="font-medium tabular-nums text-zinc-800">{{ $part['size'] }}</span>
                        </li>
                    @endforeach
                </ul>

                @if (($storage['percent'] ?? 0) >= 90)
                    <flux:callout variant="warning" icon="exclamation-triangle" class="mt-4" data-test="drive-full-warning"
                        :heading="__('Google Drive is almost full')"
                        :text="__('Uploads fail once it is full. Free up space in :email, or change the storage account.', ['email' => $this->connection->email])" />
                @endif
            @endif
        </div>
        @endisland
    @endif

    {{-- Documents stay on the account that saved them, so the accounts an admin moved away from still matter --}}
    @if ($this->accounts->isNotEmpty())
        <div class="mt-8 border-t border-line pt-6" data-test="drive-accounts">
            <flux:heading size="lg" level="2">{{ __('Storage accounts') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Documents stay on the account that saved them. Don’t delete these Google accounts or their “:folder” folders.', ['folder' => GoogleDrive::FOLDER]) }}</flux:text>

            {{-- The table scrolls on a phone instead of widening the page --}}
            <div class="mt-3 overflow-x-auto">
                {{-- The action column only when a row has a button, so Documents sits flush right otherwise --}}
                @php($actions = $this->accounts->contains(fn (array $row) => $row['account']->retired_at && (! $row['documents'] || ! $row['account']->isConnected())))
                <flux:table class="w-full min-w-[32rem]">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Google account') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Documents') }}</flux:table.column>
                        @if ($actions)
                            <flux:table.column class="w-0"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
                        @endif
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->accounts as $row)
                            <flux:table.row :key="$row['account']->id">
                                <flux:table.cell variant="strong" class="max-w-xs truncate" :title="$row['account']->email">{{ $row['account']->email }}</flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex flex-wrap gap-1.5">
                                        <flux:badge size="sm" color="zinc">{{ $row['account']->retired_at ? __('Earlier') : __('Current') }}</flux:badge>
                                        @if ($row['account']->isConnected())
                                            <flux:badge size="sm" color="green">{{ __('Connected') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="red">{{ __('Not connected') }}</flux:badge>
                                        @endif
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $row['documents'] }}</flux:table.cell>
                                @if ($actions)
                                    <flux:table.cell>
                                        {{-- The current account's own buttons sit above; earlier ones are reconnected or removed here --}}
                                        @if ($row['account']->retired_at)
                                            @if (! $row['documents'])
                                                <flux:button size="sm" variant="danger" wire:click="removeEarlier({{ $row['account']->id }})"
                                                    wire:confirm="{{ __('Remove :email? It holds no documents. The portal gives up its Google access.', ['email' => $row['account']->email]) }}"
                                                    data-test="remove-earlier-button">{{ __('Remove') }}</flux:button>
                                            @elseif (! $row['account']->isConnected())
                                                <flux:button size="sm" :href="route('google-drive.connect', ['account' => $row['account']->id])"
                                                    x-data="{ busy: false }" x-on:click="busy = true" x-on:pageshow.window="busy = false" x-bind:class="busy && 'pointer-events-none opacity-75'" x-bind:aria-disabled="busy">
                                                    <flux:icon.loading class="hidden" x-bind:class="{ hidden: ! busy }" variant="micro" />
                                                    {{ __('Reconnect') }}
                                                </flux:button>
                                            @endif
                                        @endif
                                    </flux:table.cell>
                                @endif
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>
    @endif

    @if ($this->recent->isNotEmpty())
        <div class="mt-8 border-t border-line pt-6" data-test="drive-recent">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <flux:heading size="lg" level="2">{{ __('Recent changes') }}</flux:heading>
                <flux:link :href="route('activity-log.index', ['group' => 'drive'])" wire:navigate class="text-sm">{{ __('View all') }}</flux:link>
            </div>

            <ul class="mt-2 divide-y divide-line">
                @foreach ($this->recent as $entry)
                    <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-2.5">
                        <flux:text>
                            <span class="font-medium text-zinc-800">{{ $entry->actor_name ?? __('Unknown') }}</span>
                            {{ $entry->summary() }}
                            {{-- The current account is named at the top, so only another account is named here --}}
                            @if ($entry->subject() && $entry->subject() !== $this->connection?->email)
                                <span class="text-zinc-800">{{ $entry->subject() }}</span>
                            @endif
                        </flux:text>
                        <flux:text class="text-sm">{{ $entry->day() }}, {{ $entry->created_at->format('g:i A') }}</flux:text>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
    {{--
        Danger zone, like GitHub's and DigitalOcean's: step one says what happens, with real numbers; step two asks for
        the current account typed out, which keeps the button disabled until it matches, and the admin's password.
        The server checks both again. Closing a dialog starts it over. Enter in a field moves to the next one and then
        to the button, which a disabled button would otherwise swallow; Enter on the button submits as usual.
    --}}
    @if ($this->connection)
        <flux:modal name="drive-change" wire:close="resetConfirmation" class="w-full sm:w-[30rem]" aria-labelledby="drive-change-heading">
            <div class="flex flex-col gap-6">
                <flux:heading size="lg" id="drive-change-heading">{{ __('Change the storage account?') }}</flux:heading>

                @if ($step === 1)
                    <div class="flex flex-col gap-3">
                        <flux:text>
                            {{ $this->currentDocuments
                                ? trans_choice('{1} New uploads go to the new account. The 1 document on :email stays there and keeps opening, so don’t delete that account.|[2,*] New uploads go to the new account. The :count documents on :email stay there and keep opening, so don’t delete that account.', $this->currentDocuments, ['email' => $this->connection->email])
                                : __('New uploads go to the new account. :email holds no documents yet.', ['email' => $this->connection->email]) }}
                        </flux:text>
                        <flux:text>{{ __('Until the new account is connected, uploads fail.') }}</flux:text>
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="danger" wire:click="$set('step', 2)" data-test="change-understood-button">{{ __('I understand, continue') }}</flux:button>
                    </div>
                @else
                    <form wire:submit="changeAccount" class="flex flex-col gap-5" x-data="{ typed: '' }"
                        x-on:keydown.enter="const fields = [...$el.querySelectorAll('input, [type=submit]')], i = fields.indexOf($event.target); if (i >= 0 && fields[i + 1]) { $event.preventDefault(); fields[i + 1].focus() }">
                        <flux:text>{{ __('Until the new account is connected, uploads fail.') }}</flux:text>

                        <flux:field>
                            <flux:label>{{ __('Current account') }}</flux:label>
                            <flux:description>{{ __('Type') }} <strong class="rounded bg-zinc-200 px-1.5 py-0.5 font-medium text-zinc-900 select-all">{{ $this->connection->email }}</strong> {{ __('to confirm.') }}</flux:description>
                            <flux:input wire:model="confirmEmail" x-on:input="typed = $event.target.value.trim().toLowerCase()" autocomplete="off" autofocus />
                            <flux:error name="confirmEmail" />
                        </flux:field>
                        <flux:input wire:model="newEmail" type="email" :label="__('New Google account')" autocomplete="off" />
                        <flux:input wire:model="newEmail_confirmation" type="email" :label="__('Type the new account again')" autocomplete="off" />
                        <flux:input wire:model="password" type="password" :label="__('Your portal password')" autocomplete="current-password" viewable />

                        <div class="flex justify-end gap-2">
                            <flux:modal.close>
                                <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                            </flux:modal.close>
                            <flux:button type="submit" variant="danger" :loading="false" x-bind:disabled="typed !== @js($this->connection->email)" data-test="change-account-button">
                                <flux:icon.loading wire:loading wire:target="changeAccount" variant="micro" />
                                {{ __('Change account') }}
                            </flux:button>
                        </div>
                    </form>
                @endif
            </div>
        </flux:modal>

        @if ($this->connection->isConnected())
            <flux:modal name="drive-disconnect" wire:close="resetConfirmation" class="w-full sm:w-[30rem]" aria-labelledby="drive-disconnect-heading">
                <div class="flex flex-col gap-6">
                    <flux:heading size="lg" id="drive-disconnect-heading">{{ __('Disconnect Google Drive?') }}</flux:heading>

                    @if ($step === 1)
                        <div class="flex flex-col gap-3">
                            <flux:text>
                                {{ trans_choice('{0} Uploads stop until you connect again. No documents are stored on :email yet.|{1} Uploads stop, and 1 document on :email can’t be opened until you connect again.|[2,*] Uploads stop, and :count documents on :email can’t be opened until you connect again.', $this->currentDocuments, ['email' => $this->connection->email]) }}
                            </flux:text>
                            <flux:text>{{ __('The files stay on Google Drive. Connect the same account to bring them back.') }}</flux:text>
                        </div>

                        <div class="flex justify-end gap-2">
                            <flux:modal.close>
                                <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                            </flux:modal.close>
                            <flux:button variant="danger" wire:click="$set('step', 2)" data-test="disconnect-understood-button">{{ __('I understand, continue') }}</flux:button>
                        </div>
                    @else
                        <form wire:submit="disconnect" class="flex flex-col gap-5" x-data="{ typed: '' }"
                            x-on:keydown.enter="const fields = [...$el.querySelectorAll('input, [type=submit]')], i = fields.indexOf($event.target); if (i >= 0 && fields[i + 1]) { $event.preventDefault(); fields[i + 1].focus() }">
                            <flux:text>{{ __('Uploads stop, and documents on this account can’t be opened until you connect again.') }}</flux:text>

                            <flux:field>
                                <flux:label>{{ __('Current account') }}</flux:label>
                                <flux:description>{{ __('Type') }} <strong class="rounded bg-zinc-200 px-1.5 py-0.5 font-medium text-zinc-900 select-all">{{ $this->connection->email }}</strong> {{ __('to confirm.') }}</flux:description>
                                <flux:input wire:model="confirmEmail" x-on:input="typed = $event.target.value.trim().toLowerCase()" autocomplete="off" autofocus />
                                <flux:error name="confirmEmail" />
                            </flux:field>
                            <flux:input wire:model="password" type="password" :label="__('Your portal password')" autocomplete="current-password" viewable />

                            <div class="flex justify-end gap-2">
                                <flux:modal.close>
                                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                                </flux:modal.close>
                                <flux:button type="submit" variant="danger" :loading="false" x-bind:disabled="typed !== @js($this->connection->email)" data-test="disconnect-drive-button">
                                    <flux:icon.loading wire:loading wire:target="disconnect" variant="micro" />
                                    {{ __('Disconnect') }}
                                </flux:button>
                            </div>
                        </form>
                    @endif
                </div>
            </flux:modal>
        @endif
    @endif
</section>
