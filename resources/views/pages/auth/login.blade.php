<x-layouts::auth :title="__('Sign in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Sign in')" :description="__('Use the email and password from your portal account.')" />

        <!-- Session Status -->
        <x-auth-session-status :status="session('status') ?? session('signed-out')" />

        <x-passkey-verify autofill />

        {{-- A plain form post, so formStates (app.js) shows its states: the button's spinner and locked fields while it
             sends, and a turned-down field red until it is edited. Wrong details mark both fields; the email stays and the
             focus goes to the password, which comes back empty. --}}
        <form
            method="POST"
            action="{{ route('login.store') }}"
            class="flex flex-col gap-6"
            x-data="formStates"
            x-bind:aria-busy="submitting"
        >
            @csrf

            <!-- Email Address -->
            {{-- username is what password managers save the account under; webauthn is where they offer saved passkeys --}}
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                :autofocus="! $errors->has('email')"
                autocomplete="username webauthn"
                placeholder="name_cyn@isu.edu.ph"
            />

            <!-- Password -->
            {{-- The reset link shares the label's row in normal flow, so it wraps under the label when text is enlarged
                 and its tab position matches where it sits on screen. --}}
            <flux:field>
                <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                    <flux:label>{{ __('Password') }}</flux:label>

                    <flux:link class="text-sm relative max-sm:before:absolute max-sm:before:-inset-x-1 max-sm:before:-inset-y-3.5" :href="route('password.request')" wire:navigate>
                        {{ __('Reset password') }}
                    </flux:link>
                </div>

                <flux:input
                    name="password"
                    type="password"
                    required
                    :invalid="$errors->has('email')"
                    :autofocus="$errors->has('email')"
                    autocomplete="current-password"
                    viewable
                />

                <flux:error name="password" />
            </flux:field>

            <!-- Remember Me -->
            {{-- Spelled out, so the label can stand 44px tall on a phone and the whole row takes the tap --}}
            <flux:field variant="inline" class="max-sm:items-center">
                <flux:checkbox name="remember" :checked="old('remember')" />
                <flux:label class="max-sm:min-h-11">{{ __('Remember me') }}</flux:label>
            </flux:field>

            {{-- The reCAPTCHA notice sits with the button, in the same fine print as the sign-up agreement --}}
            <div class="flex flex-col gap-3">
                <flux:button variant="primary" type="submit" class="w-full disabled:opacity-100!" x-bind:disabled="submitting" data-test="login-button">
                    {{ __('Sign in') }}
                </flux:button>

                @if (config('services.recaptcha.site_key'))
                    <p class="text-center text-xs text-zinc-600 dark:text-zinc-400">
                        <x-recaptcha action="login" />
                    </p>
                @endif
            </div>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Don’t have an account?') }}</span>
            <flux:link :href="route('register')" wire:navigate class="relative max-sm:before:absolute max-sm:before:-inset-x-1 max-sm:before:-inset-y-3.5">{{ __('Sign up') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
