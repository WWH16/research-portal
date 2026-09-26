<x-layouts::auth :title="__('Sign in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Sign in')" :description="__('Use the email and password from your portal account.')" />

        <!-- Session Status -->
        <x-auth-session-status :status="session('status')" />

        <x-passkey-verify />

        {{-- A plain form post, so Alpine drives the loading state: disabling the submit button makes Flux show its spinner.
             pageshow resets it when the browser restores this page from the back/forward cache. --}}
        <form
            method="POST"
            action="{{ route('login.store') }}"
            class="flex flex-col gap-6"
            x-data="{ submitting: false }"
            x-on:submit="submitting = true"
            x-on:pageshow.window="submitting = false"
            x-bind:aria-busy="submitting"
        >
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="name@isu.edu.ph"
            />

            <!-- Password -->
            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Password')"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                        {{ __('Forgot your password?') }}
                    </flux:link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />

            <flux:button variant="primary" type="submit" class="w-full disabled:opacity-100!" x-bind:disabled="submitting" data-test="login-button">
                {{ __('Sign in') }}
            </flux:button>
        </form>

        <flux:text class="text-sm">
            {{ __('Need an account or locked out? Contact the Research Office.') }}
        </flux:text>
    </div>
</x-layouts::auth>
