<x-layouts::auth :title="__('Confirm password')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Confirm password')"
            :description="__('Enter your password again to open this page.')"
        />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <x-passkey-verify
            options-route="passkey.confirm-options"
            submit-route="passkey.confirm"
            :label="__('Confirm with passkey')"
            :loading-label="__('Confirming...')"
            :separator="__('Or confirm with password')"
        />

        {{-- Same loading pattern as the login form: disabling the submit button makes Flux show its spinner. --}}
        <form
            method="POST"
            action="{{ route('password.confirm.store') }}"
            class="flex flex-col gap-6"
            x-data="formStates"
            x-bind:aria-busy="submitting"
        >
            @csrf

            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="current-password"
                viewable
            />

            <flux:button variant="primary" type="submit" class="w-full disabled:opacity-100!" x-bind:disabled="submitting" data-test="confirm-password-button">
                {{ __('Confirm password') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
