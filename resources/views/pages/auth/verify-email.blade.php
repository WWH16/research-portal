<x-layouts::auth :title="__('Email verification')">
    <div class="flex flex-col gap-6">
        {{-- The address stands out so a typo is easy to catch, and wraps anywhere so a long one fits a narrow phone --}}
        @php
            $email = '<strong class="font-medium text-zinc-800 wrap-anywhere">'.e(auth()->user()->email).'</strong>';
        @endphp
        <x-auth-header
            :title="__('Verify your email')"
            :description="new Illuminate\Support\HtmlString(__('We sent a verification link to :email. Open it to continue.', ['email' => $email]))"
        />

        {{-- The link also works on another device, such as a phone. The approval notice answers only once the address is
             verified, so any answer means the member can move on. --}}
        <x-auth-recheck data-test="verified-poll" />

        {{-- An expired or altered link brings the member back here; the resend button below is the way forward --}}
        @if (session('status') == 'verification-link-expired')
            <flux:callout
                variant="warning"
                icon="exclamation-triangle"
                :text="__('That verification link has expired or is no longer valid. Request a new link below.')"
                data-test="verification-link-expired"
            />
        @endif

        {{-- Only after a resend, as a notice rather than a second paragraph; the line above already says what to do --}}
        @if (session('status') == 'verification-link-sent')
            @php($sent = __('A new verification link has been sent.'))
            {{-- Quiet on purpose: a pale green tint, regular weight, no border. It confirms; the page's job is still the inbox. --}}
            <flux:callout
                icon="envelope"
                :text="$sent"
                class="[--callout-border:var(--color-isu-green-50)]! [--callout-background:var(--color-isu-green-50)]! [--callout-text:var(--color-isu-green-800)]! [--callout-icon:var(--color-isu-green-700)]!"
            />

            {{-- The notice arrives with the reloaded page, and screen readers skip a live region's text that is already there
                 on load. This empty region gets the sentence a moment later, so it is read out. --}}
            <p role="status" class="sr-only" x-data x-init="setTimeout(() => $el.textContent = @js($sent), 100)"></p>
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
