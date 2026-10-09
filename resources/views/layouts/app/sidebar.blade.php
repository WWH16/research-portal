<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    {{-- Open work behind the sidebar badges and the phone menu dot. A saved review sends the new queue size, so the count updates without a reload. --}}
    @php
        $toReview = auth()->user()->isAdmin() ? \App\Models\Submission::where('awaiting_review', true)->count() : 0;
        $toRevise = auth()->user()->isAdmin() ? 0 : \App\Models\Submission::involving(auth()->user())->where('awaiting_review', false)->where('concept_passed', false)->count();
        $reviewLabel = \App\Models\Submission::reviewQueueLabel($toReview);
        $revisionLabel = trans_choice('{1} 1 project needs revision|[2,*] :count projects need revision', $toRevise);
        // Two digits at most, so the badge never crowds the label; the hover title keeps the exact count.
        $capped = fn (int $count) => $count > 99 ? '99+' : $count;
    @endphp
    <body class="min-h-screen bg-surface" x-data="{ toReview: @js($toReview), toRevise: @js($toRevise), reviewLabel: @js($reviewLabel) }" x-on:review-queue-changed.window="toReview = $event.detail.toReview; reviewLabel = $event.detail.label">
        <flux:sidebar sticky collapsible="mobile" class="isu-sidebar border-e">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            {{-- Ordered by how often each page is used: daily work first, setup lower, rare system tasks last. My Profile lives in the user menu. --}}
            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Overview')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="megaphone" :href="route('announcements.index')" :current="request()->routeIs('announcements.*')" wire:navigate>
                        {{ __('Announcements') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

                <flux:separator variant="subtle" />

                {{-- Projects and papers are both research records, so they share one group --}}
                <flux:sidebar.group :heading="__('Research')" class="grid">
                    @if (auth()->user()->isAdmin())
                        {{-- The Research Office's open work leads the group, with a count visible from every page. Screen readers hear the sentence, not the bare number. --}}
                        <flux:sidebar.item icon="clipboard-document-check" :href="route('reviews.index')" :current="request()->routeIs('reviews.*')" :badge="$toReview ? $capped($toReview) : null" :badge:title="$reviewLabel" badge:x-bind:title="reviewLabel" badge:x-show="toReview" badge:x-text="toReview > 99 ? '99+' : toReview" badge:aria-hidden="true" wire:navigate data-test="reviews-nav">
                            {{ __('Concept Reviews') }}
                            <span class="sr-only" x-show="toReview" x-text="reviewLabel">{{ $toReview ? $reviewLabel : '' }}</span>
                        </flux:sidebar.item>
                    @endif

                    {{-- Faculty see how many of their projects need a corrected concept proposal, and the item opens straight to them --}}
                    <flux:sidebar.item icon="document-text" :href="route('submissions.index', $toRevise ? ['status' => 'revision'] : [])" :current="request()->routeIs('submissions.*') && request('from') !== 'drive'" :badge="$toRevise ? $capped($toRevise) : null" badge:data-tone="revision" :badge:title="$revisionLabel" badge:aria-hidden="true" wire:navigate data-test="submissions-nav">
                        {{ __('Submissions') }}
                        @if ($toRevise)
                            <span class="sr-only">{{ $revisionLabel }}</span>
                        @endif
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="folder" :href="route('drive.index')" :current="request()->routeIs('drive.*') || request('from') === 'drive'" wire:navigate>
                        {{ __('Research Drive') }}
                    </flux:sidebar.item>

                    {{-- Faculty keep their own record; the Research Office reads everyone's --}}
                    @if (auth()->user()->isAdmin())
                        <flux:sidebar.item icon="book-open" :href="route('faculty.index')" :current="request()->routeIs('faculty.*', 'publications.*')" wire:navigate data-test="publications-nav">
                            {{ __('Faculty Publications') }}
                        </flux:sidebar.item>
                    @else
                        <flux:sidebar.item icon="book-open" :href="route('faculty.show', auth()->user())" :current="request()->routeIs('faculty.*', 'publications.*')" wire:navigate data-test="publications-nav">
                            {{ __('My Publications') }}
                        </flux:sidebar.item>
                    @endif
                </flux:sidebar.group>

                @if (auth()->user()->isAdmin())
                    <flux:separator variant="subtle" />

                    <flux:sidebar.group :heading="__('Administration')" class="grid">
                        <flux:sidebar.item icon="users" :href="route('users.index')" :current="request()->routeIs('users.*')" wire:navigate>
                            {{ __('Manage Users') }}
                        </flux:sidebar.item>

                        <flux:sidebar.item icon="clipboard-document-list" :href="route('activity-log.index')" :current="request()->routeIs('activity-log.*')" wire:navigate>
                            {{ __('Activity Log') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:separator variant="subtle" />

                    {{-- The lists a project is filed under; set up once, changed rarely --}}
                    <flux:sidebar.group :heading="__('Filing options')" class="grid">
                        <flux:sidebar.item icon="building-library" :href="route('departments.index')" :current="request()->routeIs('departments.*')" wire:navigate>
                            {{ __('Colleges') }}
                        </flux:sidebar.item>

                        <flux:sidebar.item icon="tag" :href="route('categories.index')" :current="request()->routeIs('categories.*')" wire:navigate>
                            {{ __('Categories') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            {{-- Rare and high-stakes, so it sits apart at the bottom, away from everyday clicks --}}
            @if (auth()->user()->isAdmin())
                <flux:sidebar.nav>
                    <flux:sidebar.group :heading="__('System')" class="grid">
                        <flux:sidebar.item icon="circle-stack" :href="route('backups.index')" :current="request()->routeIs('backups.*')" wire:navigate>
                            {{ __('Backup and Restore') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                </flux:sidebar.nav>
            @endif

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            {{-- The dot keeps open work in sight while the sidebar is tucked away; the inset moves to the wrapper so the dot lines up with the icon --}}
            <div class="relative -ms-2.5 lg:hidden">
                <flux:sidebar.toggle icon="bars-2" />
                <span x-show="toReview || toRevise" @style(['display: none' => ! ($toReview || $toRevise)]) aria-hidden="true" class="pointer-events-none absolute end-2 top-2 size-2 rounded-full ring-2 ring-white {{ $toRevise ? 'bg-rose-600' : 'bg-isu-gold-700' }}" data-test="sidebar-toggle-dot"></span>
            </div>

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :avatar="auth()->user()->profileImageUrl()"
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
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
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="user-circle" wire:navigate>
                            {{ __('My Profile') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

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
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        {{-- Confirms a clicked email verification link on whichever page the member lands on --}}
        @if ($verified = session('verified'))
            <div x-data x-init="$nextTick(() => $flux.toast(@js($verified), { variant: 'success' }))"></div>
        @endif

        @fluxScripts
    </body>
</html>
