<x-layouts::auth :title="__('Register')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Create an account')" :description="__('Enter your details below to create your account')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        {{-- Same loading pattern as the login form: disabling the submit button makes Flux show its spinner. --}}
        <form
            method="POST"
            action="{{ route('register.store') }}"
            class="flex flex-col gap-6"
            x-data="{ submitting: false, password: '', confirmation: '' }"
            x-on:submit="submitting = true"
            x-on:pageshow.window="submitting = false"
            x-bind:aria-busy="submitting"
        >
            @csrf
            <!-- Name -->
            <flux:input
                name="name"
                :label="__('Name')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('Full name')"
            />

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autocomplete="email"
                placeholder="name_cyn@isu.edu.ph"
            />

            <!-- College -->
            <flux:select name="department_id" :label="__('College')" :placeholder="__('Choose your college')" required>
                @foreach ($departments as $department)
                    <flux:select.option :value="$department->id" :selected="old('department_id') == $department->id">{{ $department->code }}</flux:select.option>
                @endforeach
            </flux:select>

            <!-- Password -->
            <div>
                <flux:input
                    name="password"
                    :label="__('Password')"
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

            {{-- The agreement sits with the button that makes it, in the smallest step of the type scale --}}
            <div class="flex flex-col gap-3">
                <flux:button type="submit" variant="primary" class="w-full disabled:opacity-100!" x-bind:disabled="submitting" data-test="register-user-button">
                    {{ __('Create account') }}
                </flux:button>

                <p class="text-center text-xs leading-5 text-pretty text-zinc-600">
                    {{ __('By creating an account, you agree to the') }}
                    <flux:link :href="route('terms')">{{ __('Terms of Service') }}</flux:link>
                    {{ __('and') }}
                    <flux:link :href="route('privacy')">{{ __('Privacy Policy') }}</flux:link>.
                </p>
            </div>

            <x-recaptcha action="register" />
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
