<x-layouts::auth :title="__('Two-factor authentication')">
    <div class="flex flex-col gap-6">
        <div
            class="relative w-full h-auto"
            x-cloak
            x-data="{
                showRecoveryInput: @js($errors->has('recovery_code')),
                code: '',
                recovery_code: '',
                focusOtp() {
                    this.$nextTick(() => this.$refs.otp?.querySelector('input')?.focus());
                },
                init() {
                    if (! this.showRecoveryInput) {
                        this.focusOtp();
                    }
                },
                toggleInput() {
                    this.showRecoveryInput = !this.showRecoveryInput;

                    this.code = '';
                    this.recovery_code = '';

                    $nextTick(() => {
                        this.showRecoveryInput
                            ? this.$refs.recovery_code?.focus()
                            : this.focusOtp();
                    });
                },
            }"
        >
            <div x-show="!showRecoveryInput">
                <x-auth-header
                    :title="__('Authentication code')"
                    :description="__('Enter the 6-digit code from the authenticator app on your phone.')"
                />
            </div>

            <div x-show="showRecoveryInput">
                <x-auth-header
                    :title="__('Recovery code')"
                    :description="__('Enter one of the recovery codes you saved when you turned on two-factor authentication.')"
                />
            </div>

            {{-- The sixth digit sends the code by itself, and Verify turns to a spinner at once, as on the sign-in form. The
                 first box offers the code a phone or password manager has ready (autocomplete="one-time-code", from Flux). --}}
            <form
                method="POST"
                action="{{ route('two-factor.login.store') }}"
                x-data="{ submitting: false }"
                x-on:submit="submitting = true"
                x-on:pageshow.window="submitting = false"
                x-bind:aria-busy="submitting"
            >
                @csrf

                <div class="space-y-5 text-center">
                    <div x-show="!showRecoveryInput">
                        <div class="flex items-center justify-center my-5" x-ref="otp">
                            <flux:otp
                                x-model="code"
                                length="6"
                                submit="auto"
                                name="code"
                                :label="__('Authentication code')"
                                label:sr-only
                                class="mx-auto"
                             />
                        </div>
                    </div>

                    <div x-show="showRecoveryInput">
                        <div class="my-5">
                            <flux:input
                                type="text"
                                name="recovery_code"
                                x-ref="recovery_code"
                                x-bind:required="showRecoveryInput"
                                autocomplete="one-time-code"
                                x-model="recovery_code"
                                :aria-label="__('Recovery code')"
                            />
                        </div>

                        <flux:error name="recovery_code" />
                    </div>

                    <flux:button
                        variant="primary"
                        type="submit"
                        class="w-full disabled:opacity-100!"
                        x-bind:disabled="submitting"
                        data-test="verify-code-button"
                    >
                        {{ __('Verify code') }}
                    </flux:button>
                </div>

                <flux:button variant="ghost" class="mt-2 w-full" x-on:click="toggleInput()"><span x-text="showRecoveryInput ? @js(__('Use an authentication code')) : @js(__('Use a recovery code'))">{{ __('Use a recovery code') }}</span></flux:button>
            </form>
        </div>
    </div>
</x-layouts::auth>
