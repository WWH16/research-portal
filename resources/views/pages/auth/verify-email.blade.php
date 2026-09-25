<x-layouts::auth :title="__('Email verification')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Verify your email')"
            :description="__('Your email address isn’t verified yet. Send a verification link to :email to continue.', ['email' => auth()->user()->email])"
        />

        @if (session('status') == 'verification-link-sent')
            <flux:callout variant="success" icon="check-circle" :heading="__('Verification link sent. Check your inbox and open the link to continue.')" />
        @endif

        <div class="flex flex-col gap-3">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <flux:button type="submit" variant="primary" class="w-full">
                    {{ __('Send verification link') }}
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
