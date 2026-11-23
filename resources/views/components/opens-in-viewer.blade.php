{{--
    Opens a file link in the portal's own full-screen viewer (x-memo-viewer, in the app layout) instead of the browser's
    PDF viewer in a new tab, for PDF and Word files: the same viewer Announcements uses for memos, with zoom, Download
    and Share. The link inside keeps its address, so a Ctrl, ⌘ or Shift click still opens it in a new tab, and a file the
    viewer can't show (an old .doc) just follows the link. `viewer` is what the viewer needs (name, url, kind, link), as
    Submission::documentViewer() gives it, or null. It adds a wrapper with no box of its own (display: contents), so
    around a flux:tooltip put it outside the tooltip, never as the tooltip's first element, which Flux measures to place it.
    Usage: <x-opens-in-viewer :viewer="$submission->documentViewer('concept')"><flux:button :href="…">Open file</flux:button></x-opens-in-viewer>
--}}
@props(['viewer'])

@if ($viewer)
    <span class="contents" x-data x-on:click="if (! ($event.ctrlKey || $event.metaKey || $event.shiftKey)) { $event.preventDefault(); $dispatch('memo-viewer', {{ Js::from($viewer) }}) }">{{ $slot }}</span>
@else
    {{ $slot }}
@endif
