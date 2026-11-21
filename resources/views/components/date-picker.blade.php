{{--
    A date field in Flux's look with a Flux-styled calendar, built because Flux's date picker is Pro (free Flux's date
    field opens the browser's own calendar). It opens on every screen, phones included, and follows the WAI-ARIA date
    picker dialog pattern: arrows move by day and week, Home and End to the week's start and end, Page Up and Down by
    month (with Shift, by year), Enter or Space picks, Escape closes and hands the focus back to the field. The month's
    name opens a month grid for jumping across a year. Today and Clear sit at the foot (Clear only when the date is
    optional). Days outside min and max can't be picked.
    The value is a Y-m-d string, as the browser's date field gives, so the server reads it unchanged. Bind it with
    wire:model (with any modifiers). `min` and `max` take Y-m-d strings; `x-bind:min` takes an Alpine expression, for a
    start that follows another field. `short` writes the month short ("Nov 21, 2026"), for a narrow filter field.
    Usage: <x-date-picker wire:model="target_date" :label="__('Completion date')" :min="$start" class:input="max-sm:h-11" />
--}}
@props(['label' => null, 'placeholder' => null, 'min' => null, 'max' => null, 'disabled' => false, 'required' => false, 'short' => false])

@php
    $model = $attributes->wire('model')->value();
    $id = 'date-'.md5($model ?: $label ?: 'date');
    $invalid = $errors->has((string) $model);
    // One bag for the field: Blade forwards only a bag named $attributes inside a component tag.
    $field = $attributes->except(['class:input', 'field:class', 'x-bind:min', 'aria-label'])
        ->merge([
            'x-data' => 'datePicker('.Js::from($id).', '.Js::from($required).', '.Js::from($short).')',
            'x-modelable' => 'value',
            'x-on:click.outside' => 'open && close()',
            'data-min' => $min,
            'data-max' => $max,
            'data-resync' => true,
            ...($attributes->has('x-bind:min') ? ['x-bind:data-min' => $attributes->get('x-bind:min')] : []),
        ], escape: false)
        ->class($attributes->get('field:class'));
@endphp

<flux:field :attributes="$field">
    @if ($label)
        <flux:label id="{{ $id }}-label" x-on:click="$refs.button.focus()">{{ $label }}</flux:label>
    @endif

    {{-- min-w-0: the field lays out as a grid, and without it this cell would keep the full text's width --}}
    <div class="relative min-w-0">
        <button
            type="button"
            x-ref="button"
            aria-haspopup="dialog"
            aria-controls="{{ $id }}-calendar"
            x-bind:aria-expanded="open"
            @if ($label) aria-labelledby="{{ $id }}-label {{ $id }}-value" @else aria-label="{{ $attributes->get('aria-label') }}" @endif
            x-on:click="open ? close() : show()"
            x-on:keydown.arrow-down.prevent="show()"
            @disabled($disabled)
            @if ($invalid) aria-invalid="true" data-invalid @endif
            data-flux-control
            class="{{ Flux::classes('relative block h-10 w-full rounded-lg border border-zinc-200 border-b-zinc-300/80 bg-white py-2 ps-3 pe-10 text-start text-base text-zinc-700 shadow-xs focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 disabled:cursor-default disabled:opacity-75 disabled:shadow-none data-invalid:border-red-500 sm:text-sm')->add($attributes->get('class:input')) }}"
        >
            <span id="{{ $id }}-value" wire:ignore class="block truncate tabular-nums" x-text="display || @js($placeholder ?? __('Choose a date'))" x-bind:class="! display && 'text-zinc-500'"></span>
            <flux:icon.calendar-days variant="mini" class="pointer-events-none absolute inset-y-0 end-2 my-auto text-zinc-400" />
        </button>

        {{-- Drawn by Alpine alone, so a Livewire redraw leaves it be (wire:ignore) --}}
        <div
            id="{{ $id }}-calendar"
            popover="manual"
            role="dialog"
            aria-modal="true"
            aria-label="{{ $label ? __('Choose :label', ['label' => Str::lower($label)]) : __('Choose a date') }}"
            x-on:keydown="trap($event)"
            wire:ignore
            class="listbox-popover inset-auto m-0 w-[16.75rem] max-w-[calc(100vw-1rem)] rounded-xl border border-zinc-200 bg-white p-2 text-zinc-800 shadow-floating max-sm:w-[20.25rem]"
        >
            <div class="flex items-center justify-between gap-2">
                <flux:button size="sm" variant="ghost" square icon="chevron-left" x-on:click="step(-1)" class="max-sm:size-11">
                    <span class="sr-only" x-text="view === 'days' ? @js(__('Previous month')) : @js(__('Previous year'))">{{ __('Previous month') }}</span>
                </flux:button>
                <flux:button size="sm" variant="ghost" x-on:click="view = view === 'days' ? 'months' : 'days'" x-bind:aria-expanded="view === 'months'" class="text-sm font-semibold max-sm:h-11">
                    <span x-text="view === 'days' ? monthLabel : year" aria-live="polite"></span>
                    <span class="sr-only" x-text="view === 'days' ? @js(__(', choose a month')) : @js(__(', back to the days'))"></span>
                </flux:button>
                <flux:button size="sm" variant="ghost" square icon="chevron-right" x-on:click="step(1)" class="max-sm:size-11">
                    <span class="sr-only" x-text="view === 'days' ? @js(__('Next month')) : @js(__('Next year'))">{{ __('Next month') }}</span>
                </flux:button>
            </div>

            <table x-show="view === 'days'" role="grid" x-bind:aria-label="monthLabel" class="mt-1 w-full table-fixed border-collapse text-center text-[0.8125rem]">
                <thead>
                    <tr>
                        <template x-for="day in weekdays" x-bind:key="day.short">
                            <th scope="col" class="pb-0.5 text-xs font-medium text-zinc-500" x-bind:abbr="day.long" x-text="day.short"></th>
                        </template>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="week in weeks" x-bind:key="week[0].iso">
                        <tr>
                            <template x-for="day in week" x-bind:key="day.iso">
                                <td role="gridcell" class="p-0.5 max-sm:p-0" x-bind:aria-selected="day.iso === value">
                                    <button
                                        type="button"
                                        x-bind:data-iso="day.iso"
                                        x-bind:tabindex="day.iso === cursor ? 0 : -1"
                                        x-bind:aria-disabled="day.out ? 'true' : null"
                                        x-bind:aria-current="day.today ? 'date' : null"
                                        x-bind:aria-label="day.label"
                                        x-on:click="pick(day)"
                                        x-on:keydown="key($event, day)"
                                        x-text="day.number"
                                        x-bind:class="{
                                            'bg-accent text-accent-foreground font-semibold': day.iso === value,
                                            'hover:bg-zinc-100': day.iso !== value && ! day.out,
                                            'text-zinc-400': ! day.inMonth && day.iso !== value,
                                            'text-zinc-300 line-through cursor-default': day.out,
                                            'ring-1 ring-inset ring-isu-green-600': day.today && day.iso !== value,
                                        }"
                                        class="mx-auto grid size-8 place-items-center rounded-md tabular-nums focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent max-sm:size-11"
                                    ></button>
                                </td>
                            </template>
                        </tr>
                    </template>
                </tbody>
            </table>

            <div x-show="view === 'months'" x-cloak class="mt-1 grid grid-cols-3 gap-1">
                <template x-for="month in months" x-bind:key="month.index">
                    <button
                        type="button"
                        x-on:click="chooseMonth(month.index)"
                        x-text="month.label"
                        x-bind:aria-pressed="month.index === viewMonth"
                        x-bind:class="month.index === viewMonth ? 'bg-accent text-accent-foreground font-semibold' : 'hover:bg-zinc-100'"
                        class="h-8 rounded-md text-sm focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent max-sm:h-11"
                    ></button>
                </template>
            </div>

            <div class="mt-1.5 flex items-center justify-between gap-2 border-t border-line pt-1.5">
                <flux:button size="sm" variant="ghost" x-on:click="pickToday()" x-bind:disabled="! todayAllowed" class="max-sm:h-11">{{ __('Today') }}</flux:button>
                @unless ($required)
                    <flux:button size="sm" variant="ghost" x-on:click="clear()" x-bind:disabled="! value" class="max-sm:h-11">{{ __('Clear') }}</flux:button>
                @endunless
            </div>
        </div>
    </div>

    <flux:error :name="$model" />
</flux:field>
