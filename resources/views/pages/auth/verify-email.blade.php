<x-layouts::auth :title="__('Email verification')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Verify your email')"
            :description="__('We sent a verification link to :email. Open it to continue.', ['email' => auth()->user()->email])"
        />

        @if (session('status') == 'verification-link-sent')
            <div role="status" class="flex items-start gap-3">
                <flux:icon.envelope variant="mini" class="size-5 shrink-0 text-isu-green-700 motion-safe:animate-letter-posted" />

                <div class="flex flex-col gap-0.5">
                    <p class="text-sm font-medium text-isu-green-700">{{ __('Verification link sent. Check your inbox and open the link to continue.') }}</p>
                    <p class="text-xs text-zinc-500">{{ __('Sent at :time', ['time' => now()->format('g:i A')]) }}</p>
                </div>
            </div>
        @endif

        <div class="flex flex-col gap-3">
            {{-- Signing up already sent the link, so resending is a secondary action, not the next step: a green outline, not a solid button.
                 Same loading pattern as the login form: disabling the submit button makes Flux show its spinner. --}}
            <form
                method="POST"
                action="{{ route('verification.send') }}"
                x-data="{ submitting: false }"
                x-on:submit="submitting = true"
                x-on:pageshow.window="submitting = false"
                x-bind:aria-busy="submitting"
            >
                @csrf
                <flux:button type="submit" class="w-full border-isu-green-700! text-isu-green-700! hover:bg-isu-green-50! disabled:opacity-100!" x-bind:disabled="submitting" data-test="send-verification-link-button">
                    {{ __('Resend verification link') }}
                </flux:button>
            </form>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:button variant="ghost" type="submit" class="w-full" data-test="logout-button">
                    {{ __('Sign out') }}
                </flux:button>
            </form>
        </div>
    </div>
</x-layouts::auth>
