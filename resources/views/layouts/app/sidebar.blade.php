<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    {{-- Open work behind the sidebar badges and the phone menu dot. A saved review sends the new queue size, so the count updates without a reload. --}}
    @php
        $work = auth()->user()->openWork();
        ['toReview' => $toReview, 'toRevise' => $toRevise, 'toApprove' => $toApprove, 'unread' => $unread] = $work;
        ['reviewLabel' => $reviewLabel, 'revisionLabel' => $revisionLabel, 'approveLabel' => $approveLabel, 'unreadLabel' => $unreadLabel, 'driveIssue' => $driveIssue] = $work;
        // Two digits at most, so the badge never crowds the label; the hover title keeps the exact count.
        $capped = fn (int $count) => $count > 99 ? '99+' : $count;
    @endphp
    {{-- The counts are asked for again every minute while the tab is in view and the mouse has moved in the last 5 minutes,
         so a forgotten tab lets the app and database sleep on Laravel Cloud and lets its session end. Moving the mouse after
         that, or coming back to the tab, asks at once; a session that ended meanwhile reloads into the sign-in page.
         Pages showing a count that moved hear of it through the open-work-changed event and redraw. --}}
    <body class="min-h-screen bg-surface"
        x-data="{
            ...@js($work),
            timer: null,
            lastActive: Date.now(),
            idle() { return Date.now() - this.lastActive > 300000 },
            active() {
                if (this.idle()) this.refresh()
                this.lastActive = Date.now()
            },
            init() { this.timer = setInterval(() => document.hidden || this.idle() || this.refresh(), 60000) },
            destroy() { clearInterval(this.timer) },
            refresh() {
                fetch(@js(route('open-work')), { headers: { Accept: 'application/json' } })
                    .then(response => {
                        if (response.status === 401) location.reload()
                        return response.ok ? response.json() : null
                    })
                    .then(work => {
                        if (! work) return
                        const changed = Object.keys(work).filter(key => work[key] !== this[key])
                        Object.assign(this, work)
                        if (changed.length) Livewire.dispatch('open-work-changed', { changed })
                    })
            },
        }"
        x-on:mousemove.window.throttle.5000ms="active()"
        x-on:visibilitychange.document="if (! document.hidden) { lastActive = Date.now(); refresh() }"
        x-on:review-queue-changed.window="toReview = $event.detail.toReview; reviewLabel = $event.detail.label"
        x-on:open-work-stale.window="refresh()">
        {{-- 18rem rather than Flux's 16rem, so the longest links and their counts fit beside the nested-group indent --}}
        <flux:sidebar sticky collapsible="mobile" class="isu-sidebar w-72 border-e">
            {{-- The brand and the account menu stay put while the links between them scroll; each covers the sidebar's
                 padding with its own green, so links slide under them rather than showing through --}}
            <flux:sidebar.header class="sticky -top-4 z-10 -mx-2 -mt-4 shrink-0 bg-isu-green-800 px-2 pt-4 pb-2">
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            {{--
                Ordered by how often each page is used: daily work first, setup lower, rare system tasks last. My Profile lives in the user menu.
                For the Research Office every group folds: the daily groups start open, the rest open only on one of their own pages.
                Faculty have only the two daily groups, so theirs stay as plain headings.
            --}}
            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Overview')" icon="squares-2x2" :expandable="auth()->user()->isAdmin()" class="grid" data-test="overview-nav">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="megaphone" :href="route('announcements.index')" :current="request()->routeIs('announcements.*')" wire:navigate>
                        {{ __('Announcements') }}
                    </flux:sidebar.item>

                    {{-- Unread notices show as a count on every page; screen readers hear the sentence, not the bare number --}}
                    <flux:sidebar.item icon="bell" :href="route('notifications.index')" :current="request()->routeIs('notifications.*')" :badge="$capped($unread)" :badge:style="$unread ? null : 'display: none'" :badge:title="$unreadLabel" badge:x-bind:title="unreadLabel" badge:x-show="unread" badge:x-text="unread > 99 ? '99+' : unread" badge:aria-hidden="true" wire:navigate data-test="notifications-nav">
                        {{ __('Notifications') }}
                        @if ($unread)
                            <span class="sr-only">{{ $unreadLabel }}</span>
                        @endif
                    </flux:sidebar.item>
                </flux:sidebar.group>

                {{-- Projects and papers are both research records, so they share one group --}}
                <flux:sidebar.group :heading="__('Research')" icon="academic-cap" :expandable="auth()->user()->isAdmin()" class="grid" data-test="research-nav">
                    @if (auth()->user()->isAdmin())
                        {{-- The Research Office's open work leads the group, with a count visible from every page. Screen readers hear the sentence, not the bare number. --}}
                        <flux:sidebar.item icon="clipboard-document-check" :href="route('reviews.index')" :current="request()->routeIs('reviews.*')" :badge="$capped($toReview)" :badge:style="$toReview ? null : 'display: none'" :badge:title="$reviewLabel" badge:x-bind:title="reviewLabel" badge:x-show="toReview" badge:x-text="toReview > 99 ? '99+' : toReview" badge:aria-hidden="true" wire:navigate data-test="reviews-nav">
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
                    {{-- Sign-ups waiting for approval keep the group open and show as a count; the item opens straight to them --}}
                    <flux:sidebar.group :heading="__('Administration')" icon="shield-check" expandable :expanded="$toApprove > 0 || request()->routeIs('users.*', 'activity-log.*')" class="grid" data-test="administration-nav">
                        <flux:sidebar.item icon="users" :href="route('users.index', $toApprove ? ['status' => 'pending'] : [])" :current="request()->routeIs('users.*')" :badge="$capped($toApprove)" :badge:style="$toApprove ? null : 'display: none'" :badge:title="$approveLabel" badge:x-bind:title="approveLabel" badge:x-show="toApprove" badge:x-text="toApprove > 99 ? '99+' : toApprove" badge:aria-hidden="true" wire:navigate data-test="users-nav">
                            {{ __('Manage Users') }}
                            @if ($toApprove)
                                <span class="sr-only">{{ $approveLabel }}</span>
                            @endif
                        </flux:sidebar.item>

                        <flux:sidebar.item icon="clipboard-document-list" :href="route('activity-log.index')" :current="request()->routeIs('activity-log.*')" wire:navigate>
                            {{ __('Activity Log') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    {{-- The lists a project is filed under; set up once, changed rarely --}}
                    <flux:sidebar.group :heading="__('Filing options')" icon="rectangle-stack" expandable :expanded="request()->routeIs('departments.*', 'categories.*')" class="grid" data-test="filing-options-nav">
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
                    <flux:sidebar.group :heading="__('System')" icon="server-stack" expandable :expanded="$driveIssue !== null || request()->routeIs('google-drive.*')" class="grid" data-test="system-nav">
                        {{-- A rose "!" while uploads can't work: no account saved, disconnected, or full. The title says which,
                             and screen readers hear it after the link name --}}
                        <flux:sidebar.item icon="cloud" :href="route('google-drive.index')" :current="request()->routeIs('google-drive.*')" badge="!" badge:data-tone="alert" :badge:style="$driveIssue ? null : 'display: none'" :badge:title="$driveIssue" badge:x-bind:title="driveIssue" badge:x-show="driveIssue" badge:aria-hidden="true" wire:navigate data-test="google-drive-nav">
                            {{ __('Google Drive') }}
                            <span class="sr-only" x-show="driveIssue" x-text="driveIssue">{{ $driveIssue }}</span>
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                </flux:sidebar.nav>
            @endif

            {{-- A column, so the menu button still stretches the full width as it did straight inside the sidebar --}}
            <div class="sticky -bottom-4 z-10 -mx-2 -mb-4 flex shrink-0 flex-col bg-isu-green-800 px-2 pt-2 pb-4">
                <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
            </div>
        </flux:sidebar>

        <!-- Mobile User Menu -->
        {{-- Each control in the bar is 44px tall, like the portal's other touch targets on phones --}}
        <flux:header class="lg:hidden">
            {{-- The dot keeps open work in sight while the sidebar is tucked away; the inset moves to the wrapper so the dot lines up with the icon --}}
            <div class="relative -ms-3 lg:hidden">
                <flux:sidebar.toggle icon="bars-2" class="size-11!" />
                <span x-show="toReview || toRevise || unread || toApprove || driveIssue" @style(['display: none' => ! ($toReview || $toRevise || $unread || $toApprove || $driveIssue)]) aria-hidden="true" class="pointer-events-none absolute end-2.5 top-2.5 size-2 rounded-full ring-2 ring-white {{ $toRevise ? 'bg-rose-600' : 'bg-isu-gold-700' }}" x-bind:class="{ 'bg-rose-600': toRevise, 'bg-isu-gold-700': ! toRevise }" data-test="sidebar-toggle-dot"></span>
            </div>

            {{-- The sidebar's brand is tucked away on phones, so the seal shows here instead, once, and leads home --}}
            <flux:brand :href="route('dashboard')" wire:navigate :aria-label="__('Dashboard')" class="ms-1 h-11! rounded-md outline-accent focus-visible:outline-2 focus-visible:outline-offset-2" data-test="phone-brand">
                <x-slot name="logo" class="size-8">
                    <x-app-logo-icon class="size-8" />
                </x-slot>
            </flux:brand>

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :avatar="auth()->user()->profileImageUrl()"
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                    class="h-11"
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
