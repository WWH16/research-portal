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
                :description="__('Your email is verified. The Research Office will review your sign-up, and you can use the portal once it’s approved.')"
            />
        @endif

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <flux:button variant="ghost" type="submit" class="w-full" data-test="logout-button">
                {{ __('Sign out') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
