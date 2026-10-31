@props([
    'optionsRoute' => 'passkey.login-options',
    'submitRoute' => 'passkey.login',
    'label' => __('Sign in with a passkey'),
    'loadingLabel' => __('Authenticating…'),
    'separator' => __('Or continue with email'),
])

{{-- Fortify only registers the passkey routes while the feature is on, so route() below would throw without this guard. --}}
@if (\Laravel\Fortify\Features::canManagePasskeys())
@assets
@vite('resources/js/passkeys.js')
@endassets

<div
    x-data="{
        supported: false,
        loading: false,
        error: null,
        updateSupport() {
            this.supported = Boolean(window.Passkeys?.isSupported());
        },
        init() {
            this.updateSupport();

            window.addEventListener('passkeys:ready', () => this.updateSupport(), { once: true });
        },
        async verify() {
            this.loading = true;
            this.error = null;
            try {
                const response = await window.Passkeys.verify({
                    routes: {
                        options: '{{ route($optionsRoute) }}',
                        submit: '{{ route($submitRoute) }}',
                    },
                });
                Livewire.navigate(response.redirect || '/dashboard');
            } catch (e) {
                // The browser's own message is technical, so the member gets a plain one instead.
                if (e.constructor?.name !== 'UserCancelledError') {
                    this.error = @js(__('That passkey didn’t work. Try again, or sign in with your email and password.'));
                }
            } finally {
                this.loading = false;
            }
        },
    }"
>
    <template x-if="supported">
        <div>
            <div class="grid gap-2">
                <flux:button
                    variant="outline"
                    icon="finger-print"
                    class="w-full"
                    x-on:click="verify()"
                    x-bind:disabled="loading"
                >
                    <span x-show="!loading">{{ $label }}</span>
                    <span x-show="loading" x-cloak>{{ $loadingLabel }}</span>
                </flux:button>
                <p x-text="error" aria-live="polite"
                   class="text-sm text-center text-red-600 dark:text-red-400"></p>
            </div>

            <flux:separator :text="$separator" class="mt-6" />
        </div>
    </template>
</div>
@endif
