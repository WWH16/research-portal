@props(['url'])
{{-- The action as a bold, underlined link on its own line, like the rest of the letter: no button to draw --}}
<p class="action"><a href="{{ $url }}" class="action-link" target="_blank" rel="noopener">{!! $slot !!}</a></p>
