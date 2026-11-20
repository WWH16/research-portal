{{--
    A six-digit code in six boxes, for two-factor sign-in and for confirming two-factor setup. Every state shows in place:
    - Typing: the boxes as typed. The first box offers a code the phone or password manager has ready, and the sixth
      digit (or a pasted code) sends it by itself.
    - Checking: the boxes dim and "Checking the code…" shows with a spinner until the answer comes.
    - Wrong: the boxes turn red and shake once, with the reason under them.
    - Typing again: the red clears at once. The server's next answer sets the state again.
    The states are attributes on the wrapper (data-loading, data-invalid), so a Livewire render always has the last word.
    Put it inside the form that checks the code, and bind it as flux:otp is bound (x-model or wire:model).
    `target` names the Livewire action that checks the code, which drives the checking state. A plain form sets it as
    it submits. Dispatch `code-rejected` from the action to have focus return to the first box.
    Usage: <form wire:submit="confirmTwoFactor"><x-code-input wire:model="code" target="confirmTwoFactor" /></form>
           <form method="POST" action="…"><x-code-input x-model="code" /></form>
--}}
@props(['name' => 'code', 'label' => __('Authentication code'), 'target' => null])

<div
    @if ($target)
        wire:loading.attr="data-loading"
        wire:target="{{ $target }}"
    @else
        x-init="$el.closest('form')?.addEventListener('submit', () => $el.setAttribute('data-loading', 'true'))"
        x-on:pageshow.window="$el.removeAttribute('data-loading')"
    @endif
    x-data
    x-on:code-rejected.window="$nextTick(() => $el.querySelector('input')?.focus())"
    @if ($errors->has($name)) data-invalid="true" @endif
    class="group/code flex flex-col items-center"
    data-code-input
>
    <flux:otp
        {{ $attributes }}
        :name="$name"
        length="6"
        submit="auto"
        :aria-label="$label"
        {{-- On the code itself: Flux keeps each box's input event to itself and announces the code here, without bubbling --}}
        x-on:input="$el.closest('[data-code-input]').removeAttribute('data-invalid')"
        class="transition-opacity group-data-loading/code:pointer-events-none group-data-loading/code:opacity-60 motion-reduce:transition-none"
    />

    {{-- The live region is always there, so a screen reader hears the line when it appears --}}
    <p role="status" class="text-sm text-zinc-600">
        <span class="hidden items-center gap-2 group-data-loading/code:mt-3 group-data-loading/code:flex">
            <flux:icon.loading variant="micro" />
            {{ __('Checking the code…') }}
        </span>
    </p>

    <flux:error :name="$name" class="text-center text-balance group-not-data-invalid/code:hidden group-data-loading/code:hidden" />
</div>
