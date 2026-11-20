<x-layouts::auth :title="__('Reset password')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Reset password')" :description="__('Choose a new password for your portal account.')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        {{-- Same loading pattern as the login form: disabling the submit button makes Flux show its spinner. --}}
        <form
            method="POST"
            action="{{ route('password.update') }}"
            class="flex flex-col gap-6"
            x-data="formStates({ password: '', confirmation: '' })"
            x-bind:aria-busy="submitting"
        >
            @csrf
            <!-- Token -->
            <input type="hidden" name="token" value="{{ request()->route('token') }}">

            <!-- Email Address -->
            {{-- username, so password managers update the saved password for this email rather than adding another --}}
            <flux:input
                name="email"
                value="{{ request('email') }}"
                :label="__('Email address')"
                type="email"
                required
                autocomplete="username"
            />

            <!-- Password -->
            <div>
                <flux:input
                    name="password"
                    :label="__('New password')"
                    type="password"
                    required
                    autocomplete="new-password"
                    passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                    viewable
                    x-model="password"
                    aria-describedby="password-requirements"
                />

                <x-password-requirements id="password-requirements" password="password" />
            </div>

            <!-- Confirm Password -->
            <x-password-confirmation password="password" confirmation="confirmation" name="password_confirmation" x-model="confirmation" />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full disabled:opacity-100!" x-bind:disabled="submitting" data-test="reset-password-button">
                    {{ __('Reset password') }}
                </flux:button>
            </div>
        </form>
    </div>
</x-layouts::auth>
