@props(['when' => 'true'])

{{-- Asks the approval notice every 30 seconds while the tab is in view and the mouse has moved in the last 5 minutes, so
     a forgotten tab lets the app sleep on Laravel Cloud. Moving the mouse after that, or coming back to the tab, asks at
     once whether the member's account has moved on; a reload then shows where it stands. `when` is checked on an answer
     that came back, with `response` in scope. An ended session reloads into the sign-in page, which returns here after;
     any other failed check waits. The address is relative, so the check always goes to the host and scheme the page came from. --}}
<div
    x-data="{
        lastActive: Date.now(),
        idle() { return Date.now() - this.lastActive > 300000 },
        active() {
            if (this.idle()) this.check();
            this.lastActive = Date.now();
        },
        async check() {
            const response = await fetch(@js(route('approval.notice', absolute: false)), { headers: { Accept: 'application/json' } }).catch(() => null);
            if (response?.status === 401 || (response?.ok && ({{ $when }}))) location.reload();
        },
    }"
    x-init="setInterval(() => document.hidden || idle() || check(), 30000)"
    x-on:mousemove.window.throttle.5000ms="active()"
    x-on:visibilitychange.document="if (! document.hidden) { lastActive = Date.now(); check() }"
    {{ $attributes }}
></div>
