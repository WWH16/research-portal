<x-layouts::auth :title="auth()->user()->isRejected() ? __('Sign-up not approved') : __('Waiting for approval')">
    <div class="flex flex-col gap-6">
        @if (auth()->user()->isRejected())
            <x-auth-header
                :title="__('Sign-up not approved')"
                :description="__('Your sign-up was not approved. Contact the Research Office if you think this is a mistake.')"
            />
        @else
            <x-auth-header
                :title="__('Waiting for approval')"
                :description="__('The Research Office will now review your sign-up. You will receive an email once your account has been approved, and you may then sign in to the portal.')"
            />

            {{-- Right after the emailed link, so the member knows that step worked; same quiet green as the verify page's notice --}}
            @if ($verified = session('verified'))
                <flux:callout
                    icon="check-circle"
                    :text="$verified"
                    data-test="email-verified"
                    class="[--callout-border:var(--color-isu-green-50)]! [--callout-background:var(--color-isu-green-50)]! [--callout-text:var(--color-isu-green-800)]! [--callout-icon:var(--color-isu-green-700)]!"
                />
            @endif
        @endif

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <flux:button variant="ghost" type="submit" class="w-full" data-test="logout-button">
                {{ __('Sign out') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
