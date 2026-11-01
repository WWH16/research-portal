import avatarCropper from './avatar-cropper';

// Browsers put only PNG on the clipboard, so the memo is drawn onto a canvas and saved as one. A null blob fails the
// clipboard write, which says so.
const toPng = async (blob) => {
    const bitmap = await createImageBitmap(blob);
    const canvas = Object.assign(document.createElement('canvas'), { width: bitmap.width, height: bitmap.height });
    canvas.getContext('2d').drawImage(bitmap, 0, 0);

    return new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
};

// Draws a PDF or Word memo's pages: a PDF's as pictures by pdf.js, a Word memo's as HTML by docx-preview. Both load on
// first use, so pages without such a memo never fetch them. pdf.js draws a PDF, not the browser's own viewer, which a
// phone shows in a frame poorly or not at all (Chrome on Android draws nothing, Safari on an iPhone only the first page).
// The pages are drawn apart and handed back, so a memo opened while another is drawing never mixes with it.
const drawMemo = async (kind, url, label) => {
    const [response, drawer] = await Promise.all([fetch(url), kind === 'pdf' ? import('./memo-pdf.js') : import('docx-preview')]);

    if (!response.ok) {
        throw new Error(`The memo could not be loaded (${response.status}).`);
    }

    const sheet = document.createElement('div');
    await (kind === 'pdf' ? drawer.drawPdf(await response.arrayBuffer(), sheet, label) : drawer.renderAsync(await response.blob(), sheet));

    return [...sheet.childNodes];
};

// The memo viewer on Announcements: the memo alone, full screen, with zoom, Download and Share, and Copy image for an
// image. It opens fitted to the screen and zooms only when the member asks. A zoomed photo is sized in pixels; a PDF or
// Word memo's pages are fitted to the screen's width, scroll down, and zoom as a whole. Either way the frame scrolls.
const memoViewer = (pageLabel) => ({
    name: '',
    url: '',
    alt: '',
    // 'image', 'pdf' or 'docx'.
    kind: 'image',
    // The announcement's own address on the portal, which Share falls back to.
    link: '',
    // The memo as a file, fetched when the viewer opens, so Share and Copy image can hand it over inside the click.
    file: null,
    zoom: 1,
    // The fitted size over the real size, so zoom × fit is the share of the memo's real size on screen.
    fit: 1,
    // The memo's real width, known once it loads; nothing zooms before then.
    width: 0,
    // Whether the memo has come from Google Drive yet, or failed to; the viewer says which.
    loaded: false,
    failed: false,
    drag: null,
    // The timer that keeps a held zoom button going.
    holding: null,
    canCopy: Boolean(navigator.clipboard?.write && window.ClipboardItem),

    show({ name, url, alt, link, kind }) {
        const same = url === this.url;
        Object.assign(this, { name, url, alt, link, kind, zoom: 1, drag: null, ...(same ? {} : { width: 0, fit: 1, file: null, loaded: false, failed: false }) });
        this.$flux.modal('memo-viewer').show();

        if (kind !== 'image' && !same) {
            this.draw();
        }

        // The same memo again fires no load, so it is measured here, once the viewer has its size.
        this.$nextTick(() => (kind === 'image' ? this.$refs.photo.complete : this.loaded) && this.measure());

        // The browser already holds the memo for the viewer, so this comes from its cache.
        if (!this.file) {
            fetch(url)
                .then((response) => (response.ok ? response.blob() : null))
                .then((blob) => blob && this.url === url && (this.file = new File([blob], name, { type: blob.type })))
                .catch(() => {});
        }
    },

    // A memo opened while another was drawing leaves the viewer, and its state, to the new one.
    async draw() {
        const { kind, url } = this;
        this.$refs.pages.replaceChildren();

        try {
            const pages = await drawMemo(kind, url, pageLabel);

            if (this.url === url) {
                this.$refs.pages.replaceChildren(...pages);
                this.measure();
                this.loaded = true;
            }
        } catch {
            if (this.url === url) {
                this.failed = true;
            }
        }
    },

    // The share sheet gets the memo itself where the device can pass a file on, as phones can, and the
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

    // The fit leaves out the frame's padding, which keeps the photo clear of the buttons above and below it. Pages fit
    // the width alone; their real width is taken once, before any zoom.
    measure() {
        const { naturalWidth, naturalHeight } = this.$refs.photo;
        const { clientWidth, clientHeight } = this.$refs.stage;
        const { paddingTop, paddingBottom } = getComputedStyle(this.$refs.stage);

        if (this.kind !== 'image') {
            this.width ||= this.$refs.pages.offsetWidth;
            this.fit = this.width && clientWidth ? Math.min(1, clientWidth / this.width) : 1;

            return;
        }

        if (naturalWidth && naturalHeight && clientWidth && clientHeight) {
            this.width = naturalWidth;
            this.fit = Math.min(1, clientWidth / naturalWidth, (clientHeight - parseFloat(paddingTop) - parseFloat(paddingBottom)) / naturalHeight);
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

    get pagesStyle() {
        return { zoom: this.fit * this.zoom };
    },

    // Zooms by a factor, keeping the point under the pointer, or the middle of the screen, where it was.
    zoomBy(factor, at = null) {
        const next = Math.min(this.maxZoom, Math.max(1, this.zoom * factor));

        if (!this.width || next === this.zoom) {
            return;
        }

        const { stage } = this.$refs;
        const photo = this.kind === 'image' ? this.$refs.photo : this.$refs.pages;
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

// A memo folded under its row on Announcements: the picture of an image memo, or a PDF or Word memo's pages. It is
// fetched only when first unfolded, so nothing comes from Google Drive until a member asks; a picture ends `loading`
// from its own load event. Unfolding tells the row the memo was opened.
const memoFold = (url, kind, pageLabel) => ({
    open: false,
    src: null,
    loading: false,
    failed: false,

    async toggle() {
        this.open = !this.open;

        if (!this.open) {
            return;
        }

        this.$dispatch('memo-opened');

        if (this.src) {
            return;
        }

        this.src = url;
        this.loading = true;

        if (kind === 'image') {
            return;
        }

        try {
            this.$refs.pages.replaceChildren(...(await drawMemo(kind, url, pageLabel)));
        } catch {
            this.failed = true;
        }

        this.loading = false;
        // The pages show only once loading ends, and are measured against their frame then.
        this.$nextTick(() => this.fit());
    },

    // The pages keep their paper width; in the narrower fold the whole stack is scaled down to fit, so a page reads
    // without scrolling sideways. The fold calls it again when the window turns or resizes.
    fit() {
        const page = this.$refs.pages.querySelector('section.docx, .memo-pdf > canvas');
        const room = this.$refs.pages.parentElement.clientWidth;

        if (page && room) {
            this.$refs.pages.style.zoom = String(Math.min(1, room / (page.offsetWidth + 48)));
        }
    },
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('memoViewer', memoViewer);
    window.Alpine.data('memoFold', memoFold);
    window.Alpine.data('avatarCropper', avatarCropper);
});
