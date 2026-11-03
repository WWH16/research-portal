<x-layouts::auth :title="__('Forgot password')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Forgot password')" :description="__('Enter your email to receive a password reset link')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        {{-- Same loading pattern as the login form: disabling the submit button makes Flux show its spinner. --}}
        <form
            method="POST"
            action="{{ route('password.email') }}"
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
                type="email"
                required
                autofocus
                placeholder="name_cyn@isu.edu.ph"
            />

            {{-- The reCAPTCHA notice sits with the button, in the same fine print as on the sign-in form --}}
            <div class="flex flex-col gap-3">
                <flux:button variant="primary" type="submit" class="w-full disabled:opacity-100!" x-bind:disabled="submitting" data-test="email-password-reset-link-button">
                    {{ __('Email password reset link') }}
                </flux:button>

                @if (config('services.recaptcha.site_key'))
                    <p class="text-center text-xs leading-5 text-pretty text-zinc-600 dark:text-zinc-400">
                        <x-recaptcha action="forgot_password" />
                    </p>
                @endif
            </div>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-400">
            <span>{{ __('Or, return to') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('sign in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
