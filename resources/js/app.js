import avatarCropper from './avatar-cropper';

// Browsers put only PNG on the clipboard, so the memo is drawn onto a canvas and saved as one. A null blob fails the
// clipboard write, which says so.
const toPng = async (blob) => {
    const bitmap = await createImageBitmap(blob);
    const canvas = Object.assign(document.createElement('canvas'), { width: bitmap.width, height: bitmap.height });
    canvas.getContext('2d').drawImage(bitmap, 0, 0);

    return new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
};

// The image memo viewer on Announcements: the photo alone, full screen, with zoom, Copy image, Download and Share. It
// opens fitted to the screen and zooms only when the member asks; a zoomed photo is sized in pixels and its frame scrolls.
const memoImage = () => ({
    name: '',
    url: '',
    alt: '',
    // The announcement's own address on the portal, which Share falls back to.
    link: '',
    // The photo as a file, fetched when the viewer opens, so Share and Copy image can hand it over inside the click.
    file: null,
    zoom: 1,
    // The fitted size over the real size, so zoom × fit is the share of the photo's real pixels on screen.
    fit: 1,
    // The photo's real width, known once it loads; nothing zooms before then.
    width: 0,
    drag: null,
    // The timer that keeps a held zoom button going.
    holding: null,
    canCopy: Boolean(navigator.clipboard?.write && window.ClipboardItem),

    show({ name, url, alt, link }) {
        const same = url === this.url;
        Object.assign(this, { name, url, alt, link, zoom: 1, drag: null, ...(same ? {} : { width: 0, fit: 1, file: null }) });
        this.$flux.modal('memo-image').show();
        // The same photo again fires no load, so it is measured here, once the viewer has its size.
        this.$nextTick(() => this.$refs.photo.complete && this.measure());

        // The browser already holds the photo for the viewer, so this comes from its cache.
        if (!this.file) {
            fetch(url)
                .then((response) => (response.ok ? response.blob() : null))
                .then((blob) => blob && this.url === url && (this.file = new File([blob], name, { type: blob.type })))
                .catch(() => {});
        }
    },

    // The share sheet gets the photo itself where the device can pass a file on, as phones can, and the
    // announcement's link where it can't. With no share sheet at all, as in Firefox on a computer, the link is copied.
    share(copied, failed) {
        const files = this.file ? [this.file] : [];
        const payload = files.length && navigator.canShare?.({ files }) ? { files, title: this.name } : { title: this.name, url: this.link };

        if (navigator.share) {
            navigator.share(payload).catch((error) => error.name === 'AbortError' || this.$flux.toast(failed, { variant: 'danger' }));

            return;
        }

        navigator.clipboard
            .writeText(this.link)
            .then(() => this.$flux.toast(copied, { variant: 'success' }))
            .catch(() => this.$flux.toast(failed, { variant: 'danger' }));
    },

    measure() {
        const { naturalWidth, naturalHeight } = this.$refs.photo;
        const { clientWidth, clientHeight } = this.$refs.stage;

        if (naturalWidth && naturalHeight && clientWidth && clientHeight) {
            this.width = naturalWidth;
            this.fit = Math.min(1, clientWidth / naturalWidth, clientHeight / naturalHeight);
        }
    },

    // Up to four times the fitted size, or twice the real size for a large photo, whichever is more.
    get maxZoom() {
        return Math.max(4, 2 / this.fit);
    },

    get percent() {
        return `${Math.round(this.zoom * this.fit * 100)}%`;
    },

    get photoStyle() {
        return this.zoom > 1 ? { width: `${Math.round(this.width * this.fit * this.zoom)}px` } : {};
    },

    // Zooms by a factor, keeping the point under the pointer, or the middle of the screen, where it was.
    zoomBy(factor, at = null) {
        const next = Math.min(this.maxZoom, Math.max(1, this.zoom * factor));

        if (!this.width || next === this.zoom) {
            return;
        }

        const { photo, stage } = this.$refs;
        const before = photo.getBoundingClientRect();
        const frame = stage.getBoundingClientRect();
        const x = at?.clientX ?? frame.left + frame.width / 2;
        const y = at?.clientY ?? frame.top + frame.height / 2;
        const across = Math.min(1, Math.max(0, (x - before.left) / before.width));
        const down = Math.min(1, Math.max(0, (y - before.top) / before.height));

        this.zoom = next;
        this.$nextTick(() => {
            const after = photo.getBoundingClientRect();
            stage.scrollLeft += after.left + across * after.width - x;
            stage.scrollTop += after.top + down * after.height - y;
        });
    },

    // A press zooms to the next ten percent, such as 34% to 40% or 50%; held, it then goes on one point at a time, so
    // the member stops exactly where they like. The small allowance keeps 39.99% from counting as below 40%.
    nudge(direction, fine = false, at = null) {
        const shown = this.zoom * this.fit * 100;
        const target = fine ? Math.round(shown) + direction : direction > 0 ? Math.floor(shown / 10 + 0.01) * 10 + 10 : Math.ceil(shown / 10 - 0.01) * 10 - 10;
        this.zoomBy(target / shown, at);
    },

    // A pressed zoom button steps ten, then after a moment keeps stepping one at a time until it is let go.
    hold(direction) {
        this.release();
        this.nudge(direction);
        this.holding = setTimeout(() => (this.holding = setInterval(() => this.nudge(direction, true), 30)), 400);
    },

    // Timeouts and intervals share their ids, so this clears whichever is running.
    release() {
        clearInterval(this.holding);
        this.holding = null;
    },

    // Ctrl or ⌘ with the wheel, which is also what a trackpad pinch sends, zooms; the wheel alone scrolls a zoomed photo.
    // A mouse wheel's notch steps ten; a pinch sends many small turns, each one point.
    wheel(event) {
        if (event.ctrlKey || event.metaKey) {
            event.preventDefault();
            this.nudge(event.deltaY < 0 ? 1 : -1, Math.abs(event.deltaY) < 50, event);
        }
    },

    // A key press steps ten; a key held down repeats one point at a time.
    key(event) {
        if (!this.$root.closest('dialog')?.open || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }

        const step = { '+': 1, '=': 1, '-': -1 }[event.key];

        if (step || event.key === '0') {
            event.preventDefault();
            step ? this.nudge(step, event.repeat) : this.zoomBy(1 / this.zoom);
        }
    },

    // A mouse drags a zoomed photo around; a finger scrolls it natively.
    grab(event) {
        if (this.zoom > 1 && event.pointerType === 'mouse') {
            this.drag = { x: event.clientX, y: event.clientY };
            event.currentTarget.setPointerCapture(event.pointerId);
        }
    },

    pan(event) {
        if (!this.drag || !event.buttons) {
            this.drag = null;

            return;
        }

        this.$refs.stage.scrollBy(this.drag.x - event.clientX, this.drag.y - event.clientY);
        this.drag = { x: event.clientX, y: event.clientY };
    },

    // The clipboard item is made inside the click, with the PNG still being drawn, as Safari asks.
    copyImage(copied, failed) {
        navigator.clipboard
            .write([new ClipboardItem({ 'image/png': toPng(this.file) })])
            .then(() => this.$flux.toast(copied, { variant: 'success' }))
            .catch(() => this.$flux.toast(failed, { variant: 'danger' }));
    },
});

// The PDF and Word memo viewer on Announcements. A PDF shows in the browser's own viewer; a Word memo is drawn as pages
// by docx-preview, which loads on first use, so pages without one never fetch it.
const memoPreview = () => ({
    name: '',
    url: '',
    kind: null,
    loading: false,
    failed: false,

    async show({ name, url, kind }) {
        // On a phone or tablet the frame shows a PDF poorly or not at all (Chrome on Android draws nothing, Safari on an
        // iPhone shows only the first page), so the memo opens in a new tab in the device's own viewer. This runs inside
        // the click, so it isn't blocked as a popup.
        if (kind === 'pdf' && (!navigator.pdfViewerEnabled || window.matchMedia('(pointer: coarse)').matches)) {
            window.open(url, '_blank', 'noopener');

            return;
        }

        Object.assign(this, { name, url, kind, failed: false });
        this.$refs.pages.replaceChildren();
        this.$flux.modal('memo-preview').show();

        if (kind !== 'docx') {
            return;
        }

        // A memo replaced in the viewer while it loaded leaves the viewer, and its state, to the new one.
        const current = () => this.url === url;
        this.loading = true;

        try {
            const response = await fetch(url);

            if (!response.ok) {
                throw new Error(`The memo could not be loaded (${response.status}).`);
            }

            const [{ renderAsync }, file] = await Promise.all([import('docx-preview'), response.blob()]);

            if (current()) {
                this.$refs.pages.style.zoom = '';
                await renderAsync(file, this.$refs.pages);
                this.fitPages();
            }
        } catch {
            if (current()) {
                this.failed = true;
            }
        } finally {
            if (current()) {
                this.loading = false;
            }
        }
    },

    // Word pages keep their paper width; on a narrow screen the whole stack is scaled down to fit, so a page reads
    // without scrolling sideways. The viewer calls it again when the window turns or resizes.
    fitPages() {
        const page = this.$refs.pages.querySelector('section.docx');
        const room = this.$refs.pages.clientWidth;

        if (this.kind === 'docx' && page && room) {
            this.$refs.pages.style.zoom = String(Math.min(1, room / (page.offsetWidth + 48)));
        }
    },
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('memoImage', memoImage);
    window.Alpine.data('memoPreview', memoPreview);
    window.Alpine.data('avatarCropper', avatarCropper);
});
