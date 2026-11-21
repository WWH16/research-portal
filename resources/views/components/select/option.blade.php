{{-- One option in an x-select list. `selected` marks the one a plain form starts on. --}}
@props(['value' => '', 'selected' => false])

<li
    role="option"
    data-value="{{ $value }}"
    @if ($selected) data-selected @endif
    x-bind:id="optionId($el)"
    x-bind:aria-selected="isPicked($el)"
    x-bind:class="isActive($el) && 'bg-zinc-100'"
    x-on:click="pick($el.dataset.value)"
    x-on:mousemove="highlight($el)"
    class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm max-sm:min-h-11"
>
    <span class="min-w-0 flex-1 wrap-anywhere">{{ $slot }}</span>
    <flux:icon.check variant="mini" class="shrink-0 text-isu-green-700" x-bind:class="! isPicked($el.closest('[role=option]')) && 'invisible'" />
</li>
