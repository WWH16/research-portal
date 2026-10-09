<flux:dropdown position="bottom" align="start">
    {{-- Shows who is signed in and in what capacity; the avatar keeps the plain name for its label. --}}
    <flux:sidebar.profile
        :avatar="auth()->user()->profileImageUrl()"
        :avatar:name="auth()->user()->name"
        :initials="auth()->user()->initials()"
        icon:trailing="chevrons-up-down"
        data-test="sidebar-menu-button"
    >
        <x-slot name="name">
            {{-- Long names wrap to a second line instead of cutting off --}}
            <span class="line-clamp-2 text-start break-words">{{ auth()->user()->name }}</span>
            <span class="block truncate text-start text-xs font-normal text-isu-green-200">
                @if (auth()->user()->isAdmin())
                    {{ __('Administrator') }}
                @elseif (auth()->user()->department)
                    {{ __('Faculty, :college', ['college' => auth()->user()->department->code]) }}
                @else
                    {{ __('Faculty') }}
                @endif
            </span>
        </x-slot>
    </flux:sidebar.profile>

    <flux:menu>
        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
            <flux:avatar
                :src="auth()->user()->profileImageUrl()"
                :name="auth()->user()->name"
                :initials="auth()->user()->initials()"
            />
            <div class="grid flex-1 text-start text-sm leading-tight">
                <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
            </div>
        </div>
        <flux:menu.separator />
        <flux:menu.radio.group>
            <flux:menu.item :href="route('profile.edit')" icon="user-circle" wire:navigate>
                {{ __('My Profile') }}
            </flux:menu.item>
            <form method="POST" action="{{ route('logout') }}" class="w-full">
                @csrf
                <flux:menu.item
                    as="button"
                    type="submit"
                    icon="arrow-right-start-on-rectangle"
                    class="w-full cursor-pointer"
                    data-test="logout-button"
                >
                    {{ __('Log out') }}
                </flux:menu.item>
            </form>
        </flux:menu.radio.group>
    </flux:menu>
</flux:dropdown>
