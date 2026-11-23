{{--
    A searchable picker for a long list, such as every faculty member. Free Flux has no combobox (Flux Pro does), so this
    is built on a Flux input with Alpine: type any part of a name to narrow the list, arrows to move, Enter or a tap to
    pick, Escape to close. It follows the WAI-ARIA combobox pattern: the input owns a listbox and points at the active
    option. Bind it with wire:model on the tag; x-modelable hands the picked id to Livewire. Leaving it empty clears the
    choice.
    Usage: <x-combobox wire:model="proponents.0.user_id" :options="$faculty->pluck('name', 'id')" :aria-label="__('Faculty')" :placeholder="__('Select faculty')" />
--}}
@props(['options', 'placeholder' => null, 'empty' => __('No one matches that name.')])

@php
    $model = $attributes->wire('model')->value();
    // Stable across renders, so morphing keeps the input pointing at its own listbox.
    $id = 'combobox-'.md5($model);
    $list = collect($options)->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])->values();
@endphp

<div x-data="combobox(@js($list), @js($id))" x-modelable="value" {{ $attributes->whereStartsWith('wire:model') }} x-on:click.outside="open && close()" class="relative" data-test="combobox">
    <flux:input
        x-model="query"
        x-on:input="type()"
        x-on:focus="show()"
        x-on:click="open || show()"
        x-on:blur="close()"
        x-on:keydown.arrow-down.prevent="move(1)"
        x-on:keydown.arrow-up.prevent="move(-1)"
        x-on:keydown.enter="enter($event)"
        x-on:keydown.escape="escape($event)"
        role="combobox"
        aria-autocomplete="list"
        aria-controls="{{ $id }}"
        x-bind:aria-expanded="open"
        x-bind:aria-activedescendant="activeId"
        autocomplete="off"
        :placeholder="$placeholder"
        :aria-label="$attributes->get('aria-label')"
        :required="$attributes->has('required')"
        :invalid="$errors->has($model)"
        icon:trailing="chevron-up-down"
        class:input="max-sm:h-11"
    />

    <ul id="{{ $id }}" role="listbox" x-show="open" x-cloak class="absolute inset-x-0 top-full z-10 mt-1 max-h-60 overflow-y-auto overscroll-contain rounded-lg border border-zinc-200 bg-white p-1 shadow-floating">
        <template x-for="(option, index) in matches" x-bind:key="option.value">
            <li
                x-bind:id="`${id}-${index}`"
                role="option"
                x-bind:aria-selected="option.value === String(value)"
                x-on:mousedown.prevent
                x-on:click="pick(option)"
                x-on:mousemove="active = index"
                x-bind:class="index === active && 'bg-zinc-100'"
                class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm text-zinc-800 max-sm:min-h-11"
            >
                <span class="min-w-0 flex-1 wrap-anywhere" x-text="option.label"></span>
                <flux:icon.check variant="mini" class="shrink-0 text-isu-green-700" x-show="option.value === String(value)" />
            </li>
        </template>
        <li x-show="! matches.length" class="px-2 py-1.5 text-sm text-zinc-600">{{ $empty }}</li>
    </ul>
</div>
