<?php

use App\Concerns\PasswordValidationRules;
use App\Models\ActivityLog;
use App\Notifications\PasswordChanged;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Title;
use Livewire\Component;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Passkeys;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use ParagonIE\ConstantTime\Base64UrlSafe;

new #[Title('Security settings')] class extends Component {
    use PasswordValidationRules;

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    public bool $canManageTwoFactor;

    public bool $twoFactorEnabled;

    public bool $requiresConfirmation;

    #[Locked]
    public bool $canManagePasskeys;

    #[Locked]
    public array $passkeys = [];

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $deletingPasskeyId = null;

    #[Locked]
    public string $deletingPasskeyName = '';

    /**
     * Mount the component.
     */
    public function mount(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $this->canManageTwoFactor = Features::canManageTwoFactorAuthentication();

        if ($this->canManageTwoFactor) {
            if (Fortify::confirmsTwoFactorAuthentication() && is_null(auth()->user()->two_factor_confirmed_at)) {
                $disableTwoFactorAuthentication(auth()->user());
            }

            $this->twoFactorEnabled = auth()->user()->hasEnabledTwoFactorAuthentication();
            $this->requiresConfirmation = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        $this->canManagePasskeys = Features::canManagePasskeys();

        if ($this->canManagePasskeys) {
            $this->loadPasskeys();
        }
    }

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            // The same password again would change nothing but still end the other sessions and send the email.
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => [...$this->passwordRules(), 'different:current_password'],
                'password_confirmation' => $this->passwordConfirmationRules(),
            ], ['password.different' => __('Your new password must be different from your current password.')]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => $validated['password'],
        ]);

        // Every other session ends on its next request; this one, and its "Remember me" cookie, carry on.
        Auth::logoutOtherDevices($validated['password']);

        ActivityLog::record('auth.password_changed');
        Auth::user()->notify(new PasswordChanged);

        $this->reset('current_password', 'password', 'password_confirmation');

        Flux::toast(variant: 'success', text: __('Password updated.'));
    }

    /**
     * Load the user's passkeys.
     */
    public function loadPasskeys(): void
    {
        $this->passkeys = auth()->user()->passkeys()
            ->select(['id', 'name', 'credential_id', 'credential', 'created_at', 'last_used_at'])
            ->latest()
            ->get()
            ->map(fn ($passkey) => [
                'id' => $passkey->id,
                'credential_id' => $passkey->credential_id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at_diff' => $passkey->created_at->diffForHumans(),
                'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
            ])
            ->toArray();
    }

    /**
     * Show the delete confirmation modal.
     */
    public function confirmDelete(int $passkeyId): void
    {
        $passkey = auth()->user()->passkeys()->findOrFail($passkeyId);

        $this->deletingPasskeyId = $passkey->id;
        $this->deletingPasskeyName = $passkey->name;
        $this->showDeleteModal = true;
    }

    /**
     * Delete the passkey.
     */
    public function deletePasskey(DeletePasskey $deletePasskey): void
    {
        if (! $this->deletingPasskeyId) {
            return;
        }

        $passkey = auth()->user()->passkeys()->findOrFail($this->deletingPasskeyId);

        $deletePasskey(auth()->user(), $passkey);

        $this->closeDeleteModal();
        $this->loadPasskeys();
        $this->dispatch('passkeys-changed', ids: array_column($this->passkeys, 'credential_id'));
    }

    /**
     * The account the member's password manager files this portal's passkeys under, so the page can tell it which
     * passkeys still work. WebAuthn reads the user handle as unpadded base64url.
     *
     * @return array{rpId: string, userId: string}
     */
    #[Computed]
    public function passkeyAccount(): array
    {
        return [
            'rpId' => Passkeys::relyingPartyId(),
            'userId' => Base64UrlSafe::encodeUnpadded(auth()->user()->getPasskeyUserHandle()),
        ];
    }

    /**
     * Close the delete confirmation modal.
     */
    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingPasskeyId = null;
        $this->deletingPasskeyName = '';
    }

    /**
     * Handle the two-factor authentication enabled event.
     */
    #[On('two-factor-enabled')]
    public function onTwoFactorEnabled(): void
    {
        $this->twoFactorEnabled = true;

        Flux::toast(variant: 'success', text: __('Two-factor authentication is on.'));
    }

    /**
     * Disable two-factor authentication for the user.
     */
    public function disable(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $disableTwoFactorAuthentication(auth()->user());

        $this->twoFactorEnabled = false;
    }
}; ?>

<section class="w-full">
    <x-pages::settings.layout :heading="__('Security')" :subheading="__('Your password and how you sign in.')">
        <form method="POST" wire:submit="updatePassword">
            <x-pages::settings.section :heading="__('Update password')">
                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:input
                        wire:model="current_password"
                        :label="__('Current password')"
                        type="password"
                        required
                        autocomplete="current-password"
                        viewable
                        class="sm:col-span-2"
                        class:input="max-sm:h-11"
                    />
                    <flux:input
                        wire:model="password"
                        :label="__('New password')"
                        type="password"
                        required
                        autocomplete="new-password"
                        passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                        viewable
                        class:input="max-sm:h-11"
                    />
                    <x-password-confirmation password="$wire.password" confirmation="$wire.password_confirmation" wire:model="password_confirmation" class:input="max-sm:h-11" />
                </div>

                <x-slot:footer>
                    <flux:button variant="primary" type="submit" data-test="update-password-button">
                        {{ __('Update password') }}
                    </flux:button>
                </x-slot:footer>
            </x-pages::settings.section>
        </form>

        @if ($canManageTwoFactor)
            <x-pages::settings.section :heading="__('Two-factor authentication')">

                <div class="flex flex-col w-full mx-auto space-y-6 text-sm" wire:cloak>
                    @if ($twoFactorEnabled)
                        <div class="space-y-4">
                            <flux:text>
                                {{ __('Each time you sign in, you also enter the 6-digit code from the authenticator app on your phone.') }}
                            </flux:text>

                            <div class="flex justify-start">
                                <flux:modal.trigger name="disable-two-factor">
                                    <flux:button variant="danger" class="max-sm:h-11">{{ __('Disable 2FA') }}</flux:button>
                                </flux:modal.trigger>
                            </div>

                            {{-- Turning it off deletes the app's secret and the recovery codes, so it asks first. Cancel takes
                                 the focus as the dialog opens, so Enter never turns it off by accident. --}}
                            <flux:modal name="disable-two-factor" class="w-full sm:w-96" aria-labelledby="disable-two-factor-heading">
                                <div class="flex flex-col gap-6">
                                    <div>
                                        <flux:heading size="lg" id="disable-two-factor-heading">{{ __('Turn off two-factor authentication?') }}</flux:heading>
                                        <flux:text class="mt-2">{{ __('You will sign in with your password alone. Your recovery codes stop working, and turning it on again means scanning a new QR code.') }}</flux:text>
                                    </div>

                                    <div class="flex justify-end gap-2">
                                        <flux:modal.close>
                                            <flux:button variant="ghost" class="max-sm:h-11" autofocus>{{ __('Cancel') }}</flux:button>
                                        </flux:modal.close>
                                        <flux:button variant="danger" class="max-sm:h-11" wire:click="disable" data-test="confirm-disable-two-factor">{{ __('Turn off') }}</flux:button>
                                    </div>
                                </div>
                            </flux:modal>

                            <livewire:pages::settings.two-factor.recovery-codes :$requiresConfirmation />
                        </div>
                    @else
                        <div class="space-y-4">
                            <flux:text>
                                {{ __('Once it is on, each time you sign in you also enter a 6-digit code from an authenticator app on your phone.') }}
                            </flux:text>

                            <flux:modal.trigger name="two-factor-setup-modal">
                                <flux:button
                                    variant="primary"
                                    class="max-sm:h-11"
                                    wire:click="$dispatch('start-two-factor-setup')"
                                >
                                    {{ __('Enable 2FA') }}
                                </flux:button>
                            </flux:modal.trigger>

                            <livewire:pages::settings.two-factor-setup-modal :requires-confirmation="$requiresConfirmation" />
                        </div>
                    @endif
                </div>
            </x-pages::settings.section>
        @endif

        @if ($canManagePasskeys)
            <x-pages::settings.section :heading="__('Passkeys')" :description="__('Manage your passkeys for passwordless sign-in')">
                {{-- Tells the member's password manager which passkeys still work here, when the page opens and after a
                     removal, so a removed passkey stops being offered at sign-in. Chrome, Edge and Safari do this; other
                     browsers skip it. --}}
                <div
                    class="flex flex-col w-full mx-auto space-y-6 text-sm"
                    wire:cloak
                    x-data="{
                        sync(ids) {
                            if (! window.PublicKeyCredential?.signalAllAcceptedCredentials) return;
                            PublicKeyCredential.signalAllAcceptedCredentials({ ...@js($this->passkeyAccount), allAcceptedCredentialIds: ids })
                                .catch((error) => console.warn('The password manager was not sent the passkey list.', error));
                        },
                    }"
                    x-init="sync(@js(array_column($passkeys, 'credential_id')))"
                    x-on:passkeys-changed.window="sync($event.detail.ids)"
                >
                    <div class="border rounded-lg border-zinc-200 dark:border-zinc-700 overflow-hidden">
                        @forelse ($passkeys as $passkey)
                            <div class="flex items-center justify-between p-4 {{ ! $loop->last ? 'border-b border-zinc-200 dark:border-zinc-700' : '' }}">
                                <div class="flex items-center gap-4">
                                    <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-zinc-100 dark:bg-zinc-800">
                                        <flux:icon.key class="size-5 text-zinc-500 dark:text-zinc-400" />
                                    </div>
                                    <div class="space-y-1">
                                        <div class="flex items-center gap-2.5">
                                            <p class="font-medium tracking-tight">{{ $passkey['name'] }}</p>
                                            @if ($passkey['authenticator'])
                                                <flux:badge size="sm">{{ $passkey['authenticator'] }}</flux:badge>
                                            @endif
                                        </div>
                                        <p class="text-zinc-500 dark:text-zinc-400 text-xs">
                                            {{ __('Added :time', ['time' => $passkey['created_at_diff']]) }}
                                            @if ($passkey['last_used_at_diff'])
                                                <span class="opacity-50 mx-1">/</span>
                                                {{ __('Last used :time', ['time' => $passkey['last_used_at_diff']]) }}
                                            @endif
                                        </p>
                                    </div>
                                </div>

                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    icon:variant="outline"
                                    wire:click="confirmDelete({{ $passkey['id'] }})"
                                    :aria-label="__('Remove passkey :name', ['name' => $passkey['name']])"
                                    class="text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/50"
                                />
                            </div>
                        @empty
                            <div class="p-8 text-center">
                                <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl bg-zinc-100 dark:bg-zinc-800">
                                    <flux:icon.key class="size-7 text-zinc-400 dark:text-zinc-500" />
                                </div>
                                <p class="font-medium">{{ __('No passkeys yet') }}</p>
                            </div>
                        @endforelse
                    </div>

                    <x-passkey-registration />
                </div>
            </x-pages::settings.section>
        @endif
    </x-pages::settings.layout>

    <flux:modal
        name="delete-passkey-modal"
        class="max-w-md md:min-w-md"
        @close="closeDeleteModal"
        wire:model="showDeleteModal"
    >
        <div class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Remove passkey') }}</flux:heading>
                <flux:text>
                    {{ __('Are you sure you want to remove the passkey ":name"? You will no longer be able to use it to sign in.', ['name' => $deletingPasskeyName]) }}
                </flux:text>
            </div>

            <div class="flex gap-3 justify-end">
                <flux:button
                    variant="outline"
                    wire:click="closeDeleteModal"
                >
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button
                    variant="danger"
                    wire:click="deletePasskey"
                >
                    {{ __('Remove passkey') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
