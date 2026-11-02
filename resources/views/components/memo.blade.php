{{--
    An announcement's memo as a file row: what it is, then what to do with it. An image, a PDF and a Word memo all work
    alike. Show unfolds the memo under the row, its arrow turning over when open, and only then is it fetched from Google
    Drive (a second or more each), so a page of memos loads none until a member asks: the picture, or the pages in a
    frame that scrolls. Open full screen, or a click on what unfolded, opens it full screen in the memo viewer. An old .doc
    can't be shown in a browser, so it only downloads. Opening, unfolding or downloading sends `memo-opened` up to the
    row. On a phone the buttons wrap under the name rather than running past the row, 44px tall for the thumb.
    Usage: <x-memo :announcement="$announcement" />
    `label` replaces the file name; `:fold="false"` leaves out Show and its fold, as in the announcement form.
--}}
@props(['announcement', 'label' => null, 'fold' => true])

@php
    $kind = $announcement->memoPreview();
    $name = $announcement->attachment_name;
    $alt = $announcement->attachment_alt ?: __('Memo: :name', ['name' => $name]);
    $viewer = Js::from(['name' => $name, 'url' => $announcement->memoUrl(), 'kind' => $kind, 'alt' => $alt, 'link' => route('announcements.index', ['show' => $announcement->id]).'#announcement-'.$announcement->id]);
    [$show, $hide] = $kind === 'image' ? [__('Show image'), __('Hide image')] : [__('Show memo'), __('Hide memo')];
@endphp

{{-- Keyed by the memo's link, so a replaced memo starts folded and is fetched afresh --}}
<div wire:key="{{ $announcement->memoUrl() }}" x-data="memoFold(@js($kind === 'image' ? $announcement->memoPreviewUrl() : $announcement->memoUrl()), @js($kind), @js(__('Page :number of :total')))" {{ $attributes }} data-test="memo">
    {{-- As Classroom shows an attachment: a tile for the kind of file, its name, and what it is under the name --}}
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
        <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-zinc-100" aria-hidden="true">
            <flux:icon :icon="match ($kind) { 'image' => 'photo', 'pdf' => 'document-text', default => 'document' }" variant="mini" class="text-zinc-500" />
        </span>
        <div class="min-w-0 grow basis-40">
            <flux:text class="truncate font-medium text-zinc-800" :title="$name">{{ $label ?? $name }}</flux:text>
            <flux:text size="sm">{{ match ($kind) { 'image' => __('Image'), 'pdf' => __('PDF'), default => __('Word document') } }}</flux:text>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($kind)
                @if ($fold)
                    <flux:button size="sm" variant="ghost" icon:trailing="chevron-down" icon-trailing:class="in-aria-expanded:rotate-180" class="max-sm:h-11" x-on:click="toggle()" x-bind:aria-expanded="open" aria-expanded="false" aria-controls="memo-fold-{{ $announcement->id }}" data-test="toggle-memo">
                        <span x-text="open ? @js($hide) : @js($show)">{{ $show }}</span>
                    </flux:button>
                @endif
                {{-- An icon alone, as a file viewer's open-full-screen control; the tooltip and the label say what it does --}}
                <flux:tooltip :content="__('Open full screen')">
                    <flux:button size="sm" icon="arrows-pointing-out" class="max-sm:size-11" x-on:click="$dispatch('memo-opened'); $dispatch('memo-viewer', {{ $viewer }})" :aria-label="__('Open memo full screen: :name', ['name' => $name])" data-test="preview-memo" />
                </flux:tooltip>
            @else
                <flux:button size="sm" icon="arrow-down-tray" class="max-sm:h-11" :href="$announcement->memoUrl()" download x-on:click="$dispatch('memo-opened')" :aria-label="__('Download memo: :name', ['name' => $name])" data-test="download-memo">
                    {{ __('Download memo') }}
                </flux:button>
            @endif
        </div>
    </div>

    @if ($kind && $fold)
        <div id="memo-fold-{{ $announcement->id }}" x-show="open" x-collapse x-cloak class="pt-2">
            {{-- While Google Drive sends the memo it says so, and if it never comes the memo can still be downloaded. Under
                 the pointer the memo's frame darkens, a hint that it opens larger. --}}
            <flux:text x-show="loading && ! failed" role="status" data-test="memo-loading">{{ __('Loading the memo…') }}</flux:text>
            <div x-show="failed" x-cloak role="alert" class="flex flex-wrap items-center gap-x-3 gap-y-2" data-test="memo-failed">
                <flux:text>{{ __('The memo could not be shown here.') }}</flux:text>
                <flux:button size="sm" icon="arrow-down-tray" class="max-sm:h-11" :href="$announcement->memoUrl()" download x-on:click="$dispatch('memo-opened')">{{ __('Download memo') }}</flux:button>
            </div>
            @if ($kind === 'image')
                <button type="button" x-show="! loading && ! failed" x-on:click="$dispatch('memo-viewer', {{ $viewer }})" class="block w-fit max-w-full cursor-zoom-in overflow-hidden rounded-lg border border-line hover:border-zinc-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
                    <img x-bind:src="src" x-on:load="loading = false" x-on:error="failed = true" alt="{{ __(':description (opens the memo viewer)', ['description' => $alt]) }}" decoding="async" class="max-h-96 w-auto" />
                </button>
            @else
                {{-- As tall as the picture would be; the pages sit on a pale ground and scroll inside. A keyboard scrolls
                     them here and opens them with Open full screen. wire:ignore keeps a Livewire refresh of the page from
                     clearing pages already drawn. --}}
                <div x-show="! loading && ! failed" x-on:click="$dispatch('memo-viewer', {{ $viewer }})" x-on:resize.window.debounce="fit()" tabindex="0" role="region" aria-label="{{ $name }}" class="max-h-96 cursor-zoom-in overflow-auto rounded-lg border border-line bg-zinc-100 [scrollbar-width:thin] hover:border-zinc-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
                    <div x-ref="pages" wire:ignore data-memo-pages></div>
                </div>
            @endif
        </div>
    @endif
</div>
