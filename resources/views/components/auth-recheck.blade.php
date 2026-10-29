@props(['when' => 'true'])

{{-- Asks the approval notice every 30 seconds, and when the tab comes back into view, whether the member's account has
     moved on; a reload then shows where it stands. `when` is checked on an answer that came back, with `response` in
     scope. An ended session reloads into the sign-in page, which returns here after; any other failed check waits.
     The address is relative, so the check always goes to the host and scheme the page came from. --}}
<div
    x-data="{
        async check() {
            const response = await fetch(@js(route('approval.notice', absolute: false)), { headers: { Accept: 'application/json' } }).catch(() => null);
            if (response?.status === 401 || (response?.ok && ({{ $when }}))) location.reload();
        },
    }"
    x-init="setInterval(() => document.hidden || check(), 30000)"
    x-on:visibilitychange.document="document.hidden || check()"
    {{ $attributes }}
></div>
