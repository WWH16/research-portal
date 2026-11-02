<x-layouts::auth :title="__('Reset password')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Reset password')" :description="__('Please enter your new password below')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        {{-- Same loading pattern as the login form: disabling the submit button makes Flux show its spinner. --}}
        <form
            method="POST"
            action="{{ route('password.update') }}"
            class="flex flex-col gap-6"
            x-data="{ submitting: false, password: '', confirmation: '' }"
            x-on:submit="submitting = true"
            x-on:pageshow.window="submitting = false"
            x-bind:aria-busy="submitting"
        >
            @csrf
            <!-- Token -->
            <input type="hidden" name="token" value="{{ request()->route('token') }}">

            <!-- Email Address -->
            <flux:input
                name="email"
                value="{{ request('email') }}"
                :label="__('Email')"
                type="email"
                required
                autocomplete="email"
            />

            <!-- Password -->
            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
                x-model="password"
            />

            <!-- Confirm Password -->
            <x-password-confirmation password="password" confirmation="confirmation" name="password_confirmation" x-model="confirmation" :placeholder="__('Confirm password')" />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full disabled:opacity-100!" x-bind:disabled="submitting" data-test="reset-password-button">
                    {{ __('Reset password') }}
                </flux:button>
            </div>
        </form>
    </div>
</x-layouts::auth>
