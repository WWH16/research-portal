{{-- The memo viewer: one per page, opened by <x-memo> with a memo-viewer event. Shared by the announcements list and the announcement form. --}}
{{-- A memo opens on its own: the memo alone on a dark ground, as large as the screen allows, with nothing beside it.
     Its file actions float at the top edge, Close at the corner, and the zoom controls at the bottom edge. A click
     beside the memo or Escape closes it too. The variant is bare, and its size and ground are forced past the phone
     rule for dialogs in app.css. --}}
<flux:modal name="memo-viewer" variant="bare" class="m-0 size-full max-h-none! max-w-none! bg-black/85! p-0!" aria-labelledby="memo-viewer-heading" data-test="memo-viewer">
    <div x-data="memoViewer(@js(__('Page :number of :total')))" x-on:memo-viewer.window="show($event.detail)" x-on:keydown.window="key($event)" x-on:resize.window.debounce="measure()" x-on:pointerup.window="release()" x-on:pointercancel.window="release()" class="relative size-full">
        <h2 id="memo-viewer-heading" class="sr-only" x-text="name"></h2>

        {{-- Fitted, a photo is whole at any size: a tall poster is scaled to the screen's height, a wide one to its
             width. A PDF or Word memo's pages are fitted to the screen's width and scroll down. Zoomed, the memo grows
             past the screen and this frame scrolls; a mouse drags a photo, a finger or trackpad scrolls either. The
             auto margins centre a fitted memo and let a zoomed one scroll to every edge. The frame keeps clear of the
             buttons above and below, so they never cover the top or foot of a fitted photo. wire:ignore keeps a
             Livewire refresh of the page from clearing pages already drawn. --}}
        <div x-ref="stage" x-on:click.self="$flux.modal('memo-viewer').close()" x-on:wheel="wheel($event)" class="flex size-full overflow-auto py-16 [scrollbar-width:none]">
            {{-- Laid out while hidden, so the pages can be measured the moment they are drawn --}}
            <div x-ref="pages" x-show="kind !== 'image'" x-bind:class="{ invisible: ! loaded }" x-bind:style="pagesStyle" wire:ignore class="m-auto w-max shrink-0" data-memo-pages></div>
            <img
                x-ref="photo"
                x-show="kind === 'image' && loaded"
                x-bind:src="kind === 'image' ? url : null"
                x-bind:alt="alt"
                x-bind:style="photoStyle"
                x-bind:class="zoom > 1 ? 'cursor-grab active:cursor-grabbing' : 'max-h-full max-w-full'"
                x-on:load="loaded = true; measure()"
                x-on:error="failed = true"
                x-on:pointerdown="grab($event)"
                x-on:pointermove="pan($event)"
                draggable="false"
                class="m-auto shrink-0 touch-manipulation object-contain select-none"
            />
        </div>

        {{-- Google Drive can take a second or more to send the memo, and may not send it at all --}}
        <flux:text x-show="! loaded && ! failed" role="status" class="pointer-events-none absolute inset-0 m-auto size-fit text-zinc-200">{{ __('Loading the memo…') }}</flux:text>
        <flux:text x-show="failed" x-cloak role="alert" class="pointer-events-none absolute inset-0 m-auto size-fit px-6 text-center text-zinc-200">{{ __('The memo could not be loaded. Close the viewer and try again later.') }}</flux:text>

        {{-- Zoom, only when the member asks: out, the share of the memo's real size now shown (a click fits it back to
             the screen), and in. A press steps to the next ten percent; holding a button then goes on a point at a time.
             A press from the keyboard (detail 0) steps once. Also Ctrl or ⌘ with the scroll wheel or a trackpad pinch,
             and the + − 0 keys. A click on the memo itself does nothing. --}}
        {{-- On a white ground, so pages scrolling under it never show through a disabled button --}}
        <div class="absolute bottom-3 start-1/2 -translate-x-1/2 rounded-lg bg-white">
            {{-- Fit to screen names itself in a tooltip; Flux still joins a tooltip-wrapped button to the group --}}
            <flux:button.group>
                <flux:button icon="magnifying-glass-minus" class="max-sm:size-11" x-on:pointerdown="hold(-1)" x-on:click="$event.detail || nudge(-1)" x-bind:disabled="zoom <= 1" :aria-label="__('Zoom out')" data-test="zoom-out" />
                <flux:button class="w-18 tabular-nums max-sm:h-11" x-on:click="zoomBy(1 / zoom)" x-bind:disabled="zoom <= 1" :tooltip="__('Fit to screen')">
                    <span class="sr-only">{{ __('Fit to screen, now') }}</span>
                    <span x-text="percent"></span>
                </flux:button>
                <flux:button icon="magnifying-glass-plus" class="max-sm:size-11" x-on:pointerdown="hold(1)" x-on:click="$event.detail || nudge(1)" x-bind:disabled="! width || zoom >= maxZoom" :aria-label="__('Zoom in')" data-test="zoom-in" />
            </flux:button.group>
        </div>

        <div class="absolute end-3 top-3 flex gap-2">
            <flux:tooltip :content="__('Copy image')" x-show="canCopy && kind === 'image'">
                <flux:button icon="clipboard-document" class="max-sm:size-11" x-bind:disabled="! file" x-on:click="copyImage({{ Js::from(__('Image copied.')) }}, {{ Js::from(__('The image could not be copied. Download it instead.')) }})" :aria-label="__('Copy image')" data-test="copy-image" />
            </flux:tooltip>
            <flux:tooltip :content="__('Download memo')">
                <flux:button icon="arrow-down-tray" class="max-sm:size-11" href="#" x-bind:href="url" download :aria-label="__('Download memo')" />
            </flux:tooltip>
            <flux:tooltip :content="__('Share memo')">
                <flux:button icon="share" class="max-sm:size-11" x-on:click="share({{ Js::from(__('Link to the announcement copied.')) }}, {{ Js::from(__('The announcement could not be shared. Download the memo instead.')) }})" :aria-label="__('Share memo')" data-test="share-memo" />
            </flux:tooltip>
            <flux:tooltip :content="__('Close viewer')" kbd="Esc">
                <flux:modal.close>
                    <flux:button icon="x-mark" class="max-sm:size-11" :aria-label="__('Close viewer')" />
                </flux:modal.close>
            </flux:tooltip>
        </div>
    </div>
</flux:modal>
