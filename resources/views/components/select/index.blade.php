{{--
    A dropdown list in Flux's look. Free Flux's select opens the browser's own list (Flux's styled one is Pro), so this
    opens a Flux-styled list on every screen, phones included. The field matches flux:select: label, badge, description
    and error. It follows the WAI-ARIA select-only combobox pattern: the field holds the focus and points at the active
    option; arrows move, Home and End jump, Page Up and Down skip ten, Enter or Space picks, Escape closes, and typing
    letters jumps to the option that starts with them. The list floats above dialogs and tables (a popover), under the
    field, or over it when there is no room below.
    Bind it with wire:model (with any modifiers), or give it a name in a plain form, which posts a hidden field; there an
    option marked selected starts picked.
    Usage: <x-select wire:model.live="college" :label="__('College')" class="max-sm:h-11">
               <x-select.option value="">{{ __('All colleges') }}</x-select.option>
               <x-select.option value="CCSICT">CCSICT</x-select.option>
           </x-select>
--}}
@props(['label' => null, 'placeholder' => null, 'badge' => null, 'name' => null, 'disabled' => false])

@php
    $model = $attributes->wire('model')->value();
    $id = 'select-'.md5($model ?: $name ?: $label ?: 'select');
    $description = $attributes->get('description:trailing');
    $invalid = $errors->has($model ?: (string) $name);
    // One bag for the field: Blade forwards only a bag named $attributes inside a component tag, so the rest (data-test,
    // wire:model and its modifiers, the field's own class) is merged here.
    $field = $attributes->except(['description:trailing', 'field:class', 'class', 'aria-label', 'required'])
        ->merge([
            'x-data' => 'listbox('.Js::from($id).', '.Js::from((string) $placeholder).')',
            'x-modelable' => 'value',
            'x-on:click.outside' => 'open && close()',
            'data-select' => true,
        ], escape: false)
        ->class($attributes->get('field:class'));
@endphp

<flux:field :attributes="$field">
    @if ($label)
        <flux:label :badge="$badge" id="{{ $id }}-label" x-on:click="$refs.button.focus()">{{ $label }}</flux:label>
    @endif

    @if ($name)
        <input type="hidden" name="{{ $name }}" x-bind:value="value">
    @endif

    <div class="relative">
        <button
            type="button"
            x-ref="button"
            role="combobox"
            aria-haspopup="listbox"
            aria-controls="{{ $id }}-list"
            x-bind:aria-expanded="open"
            x-bind:aria-activedescendant="open && active >= 0 ? `${id}-${active}` : null"
            @if ($label) aria-labelledby="{{ $id }}-label {{ $id }}-value" @else aria-label="{{ $attributes->get('aria-label') }}" @endif
            x-on:click="open ? close() : show()"
            x-on:keydown="keydown($event)"
            @disabled($disabled)
            @if ($invalid) aria-invalid="true" data-invalid @endif
            data-flux-control
            {{ $attributes->only('class')->class('relative block h-10 w-full rounded-lg border border-zinc-200 border-b-zinc-300/80 bg-white py-2 ps-3 pe-10 text-start text-base text-zinc-700 shadow-xs focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 disabled:cursor-default disabled:opacity-75 disabled:shadow-none data-invalid:border-red-500 sm:text-sm') }}
        >
            <span id="{{ $id }}-value" class="block truncate" x-text="label || placeholder" x-bind:class="! label && 'text-zinc-500'"></span>
            <flux:icon.chevron-up-down variant="mini" class="pointer-events-none absolute inset-y-0 end-2 my-auto text-zinc-400" />
        </button>

        <ul
            x-ref="list"
            id="{{ $id }}-list"
            popover="manual"
            role="listbox"
            @if ($label) aria-labelledby="{{ $id }}-label" @endif
            class="listbox-popover inset-auto m-0 max-h-60 overflow-y-auto overscroll-contain rounded-lg border border-zinc-200 bg-white p-1 text-zinc-800 shadow-floating"
        >
            {{ $slot }}
        </ul>
    </div>

    <flux:error :name="$model ?: $name" />

    @if ($description)
        <flux:description>{{ $description }}</flux:description>
    @endif
</flux:field>
