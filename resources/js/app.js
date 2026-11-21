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

// The searchable picker in components/combobox. `value` is the picked option's value, which wire:model reads through
// x-modelable; `query` is the text in the box. Focusing shows the whole list with the current pick active; typing
// narrows it to options holding every word typed. Leaving the box puts the picked name back, or clears the pick when
// the box was emptied.
const combobox = (options, id) => ({
    options,
    id,
    value: '',
    query: '',
    open: false,
    typed: false,
    active: -1,

    init() {
        this.query = this.label();
        this.$watch('value', () => (this.query = this.label()));
    },

    label() {
        return this.options.find((option) => option.value === String(this.value ?? ''))?.label ?? '';
    },

    get matches() {
        const words = this.typed ? this.query.toLocaleLowerCase().split(/\s+/).filter(Boolean) : [];

        return this.options.filter((option) => words.every((word) => option.label.toLocaleLowerCase().includes(word)));
    },

    get activeId() {
        return this.open && this.matches[this.active] ? `${this.id}-${this.active}` : null;
    },

    show() {
        this.open = true;
        this.typed = false;
        this.active = Math.max(0, this.matches.findIndex((option) => option.value === String(this.value ?? '')));
        this.reveal();
    },

    type() {
        this.open = true;
        this.typed = true;
        this.active = 0;
    },

    move(step) {
        if (!this.open) {
            return this.show();
        }

        const count = this.matches.length;
        this.active = count ? (this.active + step + count) % count : -1;
        this.reveal();
    },

    reveal() {
        this.$nextTick(() => this.activeId && document.getElementById(this.activeId)?.scrollIntoView({ block: 'nearest' }));
    },

    pick(option) {
        this.value = option.value;
        this.query = option.label;
        this.open = false;
    },

    enter(event) {
        if (this.open && this.matches[this.active]) {
            event.preventDefault();
            this.pick(this.matches[this.active]);
        }
    },

    // Escape closes the list first, and only a second press reaches whatever holds the picker.
    escape(event) {
        if (this.open) {
            event.preventDefault();
            event.stopPropagation();
            this.close();
        }
    },

    close() {
        this.open = false;

        if (this.query.trim() === '') {
            this.value = '';
        }

        this.query = this.label();
    },
});

// The states every plain-post auth form shows (sign-in, sign-up, password reset and the rest). While it sends,
// `submitting` disables the submit button, which makes Flux show its spinner, and aria-busy dims and locks the fields
// (app.css); pageshow resets it when the browser brings the page back from its back/forward cache. A field the server
// turned down is red with its reason and takes the focus when the page comes back unless another field is set to; it
// drops the red the moment it is edited, as every form does (clearWhenEdited below). `extra` adds the form's own
// values, such as the passwords sign-up compares.
const formStates = (extra = {}) => ({
    ...extra,
    submitting: false,

    init() {
        this.$el.addEventListener('submit', () => (this.submitting = true));
        window.addEventListener('pageshow', () => (this.submitting = false));

        if (!this.$el.querySelector('[autofocus]')) {
            this.$nextTick(() => this.$el.querySelector('[aria-invalid="true"]')?.focus());
        }
    },
});

// A field the server turned down drops its red and its reason the moment it is edited, on every form (Livewire or a
// plain post), rather than staying red until the next save. The server's next answer sets it again if still wrong.
const clearWhenEdited = ({ target }) => {
    if (target.getAttribute?.('aria-invalid') !== 'true') {
        return;
    }

    target.removeAttribute('aria-invalid');
    target.removeAttribute('data-invalid');
    target.closest('[data-flux-field]')?.querySelectorAll('[data-flux-error]').forEach((error) => (error.hidden = true));
};

document.addEventListener('input', clearWhenEdited);
document.addEventListener('change', clearWhenEdited);

// A range slider's filled share (--fill in app.css) follows its handle as it moves.
const fillRange = (slider) => {
    const min = Number(slider.min || 0);
    const max = Number(slider.max || 100);
    slider.style.setProperty('--fill', `${((Number(slider.value) - min) / (max - min || 1)) * 100}%`);
};

document.addEventListener('input', ({ target }) => target.matches?.('input[type="range"]') && fillRange(target));
document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('input[type="range"]').forEach(fillRange));

// Every form is checked on the server, which shows each reason in Flux's red style beside its field, with the focus
// moved there. So forms skip the browser's own check, whose bubbles ("Please fill out this field.") would show first in
// a different look. Set on every form when a page loads, after a wire:navigate, and whenever Livewire adds or redraws
// a form, since a redraw takes the attribute back to the server's markup.
const skipBrowserCheck = (root) => {
    if (root instanceof HTMLFormElement) {
        root.noValidate = true;
    }

    root.querySelectorAll?.('form').forEach((form) => (form.noValidate = true));
};

skipBrowserCheck(document);
document.addEventListener('DOMContentLoaded', () => skipBrowserCheck(document));
document.addEventListener('livewire:navigated', () => skipBrowserCheck(document));
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('morph.added', ({ el }) => skipBrowserCheck(el));
    window.Livewire.hook('morph.updated', ({ el }) => skipBrowserCheck(el));

    // A redrawn dropdown list or date picker fills its options' ids and ticks in again (see listbox and datePicker).
    // The field's own text is wire:ignore, so Alpine alone writes it.
    window.Livewire.hook('morph.updated', ({ el }) => {
        const field = (el.nodeType === Node.TEXT_NODE ? el.parentElement : el)?.closest?.('[data-select], [data-resync]');
        field?._x_dataStack && window.Alpine.$data(field).tick++;
    });
});

// The dropdown list in components/select. `value` is the picked option's value, which wire:model reads through
// x-modelable; `active` is the option the keyboard or pointer is on while the list is open. The options are the
// component's own <li role="option"> elements, read from the page, so Livewire can redraw them freely. A Livewire
// redraw puts the server's markup back over what Alpine filled in (the field's text, the options' ids and ticks), so
// each redraw bumps `tick`, which every one of those reads, and Alpine fills them in again.
const listbox = (id, placeholder) => ({
    id,
    placeholder,
    // Declared here, as Alpine writes a property no scope has yet onto the outermost one, which every field shares
    field: null,
    tick: 0,
    value: '',
    open: false,
    active: -1,
    typed: '',
    typedAt: 0,

    // The field's own parts are found inside the field itself. Not $refs: Alpine looks those up from the element a
    // binding runs on (an option Livewire is redrawing can be detached), and init() runs before the children's x-refs
    // exist, when $refs can answer with another field's list.
    init() {
        this.field = this.$el;

        const preset = this.list.querySelector('[data-selected]');

        if (preset && (this.value === '' || this.value === null)) {
            this.value = preset.dataset.value;
        }

        this.place = this.place.bind(this);
    },

    get list() {
        return this.field.querySelector('[role="listbox"]');
    },

    get trigger() {
        return this.field.querySelector('button[role="combobox"]');
    },

    options() {
        return [...this.list.querySelectorAll('[role="option"]')];
    },

    indexOf(value) {
        return this.options().findIndex((option) => option.dataset.value === String(value ?? ''));
    },

    get label() {
        return this.tick >= 0 ? (this.options()[this.indexOf(this.value)]?.textContent.trim() ?? '') : '';
    },

    optionId(option) {
        return this.tick >= 0 ? `${this.id}-${this.options().indexOf(option)}` : null;
    },

    isActive(option) {
        return this.tick >= 0 && this.open && this.options().indexOf(option) === this.active;
    },

    isPicked(option) {
        return this.tick >= 0 && String(this.value ?? '') === option.dataset.value;
    },

    highlight(option) {
        this.active = this.options().indexOf(option);
    },

    show(at = null) {
        if (this.trigger.disabled) {
            return;
        }

        this.open = true;
        this.active = at ?? Math.max(0, this.indexOf(this.value));
        this.list.showPopover();
        this.place();
        this.reveal();
        window.addEventListener('scroll', this.place, true);
        window.addEventListener('resize', this.place);
    },

    close(refocus = false) {
        this.open = false;
        this.list.matches(':popover-open') && this.list.hidePopover();
        window.removeEventListener('scroll', this.place, true);
        window.removeEventListener('resize', this.place);
        refocus && this.trigger.focus();
    },

    // Under the field at its width, or over it when the list wouldn't fit below.
    place() {
        const field = this.trigger.getBoundingClientRect();
        const list = this.list;
        const below = window.innerHeight - field.bottom;
        const above = below < list.offsetHeight + 8 && field.top > below;

        Object.assign(list.style, {
            left: `${field.left}px`,
            width: `${field.width}px`,
            top: above ? `${field.top - list.offsetHeight - 4}px` : `${field.bottom + 4}px`,
        });
    },

    reveal() {
        this.$nextTick(() => this.options()[this.active]?.scrollIntoView({ block: 'nearest' }));
    },

    move(step) {
        const count = this.options().length;
        this.active = count ? Math.min(count - 1, Math.max(0, this.active + step)) : -1;
        this.reveal();
    },

    pick(value) {
        this.value = value;
        this.close(true);
        this.trigger.dispatchEvent(new Event('change', { bubbles: true }));
    },

    pickActive() {
        const option = this.options()[this.active];
        option ? this.pick(option.dataset.value) : this.close(true);
    },

    // Letters typed within a moment of each other spell the start of an option; the same letter again moves on to
    // the next option that starts with it.
    typeAhead(letter) {
        const now = Date.now();
        this.typed = (now - this.typedAt > 700 ? '' : this.typed) + letter.toLowerCase();
        this.typedAt = now;

        const options = this.options();
        const from = this.typed.length === 1 ? this.active + 1 : this.active;
        const match = [...options.slice(from), ...options.slice(0, from)].find((option) => option.textContent.trim().toLowerCase().startsWith(this.typed));

        if (match) {
            this.active = options.indexOf(match);
            this.reveal();
        }
    },

    keydown(event) {
        const { key } = event;
        const letter = key.length === 1 && key !== ' ' && !event.ctrlKey && !event.metaKey && !event.altKey;

        if (!this.open) {
            if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(key)) {
                event.preventDefault();
                this.show();
            } else if (key === 'Home' || key === 'End') {
                event.preventDefault();
                this.show(key === 'Home' ? 0 : this.options().length - 1);
            } else if (letter) {
                event.preventDefault();
                this.show();
                this.typeAhead(key);
            }

            return;
        }

        const moves = { ArrowDown: 1, ArrowUp: -1, PageDown: 10, PageUp: -10 };

        if (key in moves) {
            event.preventDefault();
            event.altKey ? this.pickActive() : this.move(moves[key]);
        } else if (key === 'Home' || key === 'End') {
            event.preventDefault();
            this.active = key === 'Home' ? 0 : this.options().length - 1;
            this.reveal();
        } else if (key === 'Enter' || key === ' ') {
            event.preventDefault();
            this.pickActive();
        } else if (key === 'Escape') {
            // Only the list closes; a dialog around it stays open
            event.preventDefault();
            event.stopPropagation();
            this.close(true);
        } else if (key === 'Tab') {
            this.close();
        } else if (letter) {
            event.preventDefault();
            this.typeAhead(key);
        }
    },
});

// The date picker in components/date-picker. `value` is a Y-m-d string, which wire:model reads through x-modelable;
// `cursor` is the day the keyboard is on, which also sets the month shown. Dates are built at local midnight and
// written back as Y-m-d by hand, so no time zone shifts a day. min and max are read from the field's data-min and
// data-max each time, so a limit bound to another field (x-bind:min) always holds.
const pad = (number) => String(number).padStart(2, '0');
const toIso = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
const fromIso = (iso) => {
    const [year, month, day] = String(iso ?? '').split('-').map(Number);
    return year && month && day ? new Date(year, month - 1, day) : null;
};
const addDays = (date, days) => new Date(date.getFullYear(), date.getMonth(), date.getDate() + days);
const addMonths = (date, months) => {
    const target = new Date(date.getFullYear(), date.getMonth() + months, 1);
    const last = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate();
    return new Date(target.getFullYear(), target.getMonth(), Math.min(date.getDate(), last));
};

const datePicker = (id, required) => ({
    id,
    required,
    // Declared here, as Alpine writes a property no scope has yet onto the outermost one, which every field shares
    field: null,
    place: null,
    tick: 0,
    value: '',
    open: false,
    view: 'days',
    cursor: toIso(new Date()),

    // The field's own parts are found inside the field itself. Not $refs or $root: Alpine looks those up from the
    // element a handler ran on (moving to another month redraws the grid, so a day button can be gone by then), and
    // init() runs before the children's x-refs exist.
    init() {
        this.field = this.$el;
    },

    get trigger() {
        return this.field.querySelector('button[aria-haspopup="dialog"]');
    },

    get calendar() {
        return this.field.querySelector('[role="dialog"]');
    },

    get min() {
        return this.tick >= 0 ? this.field.dataset.min || null : null;
    },

    get max() {
        return this.tick >= 0 ? this.field.dataset.max || null : null;
    },

    get display() {
        const date = this.tick >= 0 ? fromIso(this.value) : null;
        return date ? date.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) : '';
    },

    get viewMonth() {
        return fromIso(this.cursor).getMonth();
    },

    get year() {
        return fromIso(this.cursor).getFullYear();
    },

    get monthLabel() {
        return fromIso(this.cursor).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    },

    get weekdays() {
        const sunday = new Date(2023, 0, 1);
        return [...Array(7)].map((_, index) => {
            const day = addDays(sunday, index);
            return { short: day.toLocaleDateString('en-US', { weekday: 'short' }).slice(0, 2), long: day.toLocaleDateString('en-US', { weekday: 'long' }) };
        });
    },

    get months() {
        return [...Array(12)].map((_, index) => ({ index, label: new Date(2023, index, 1).toLocaleDateString('en-US', { month: 'short' }) }));
    },

    outOfRange(iso) {
        return Boolean((this.min && iso < this.min) || (this.max && iso > this.max));
    },

    get todayAllowed() {
        return !this.outOfRange(toIso(new Date()));
    },

    // Six weeks from the Sunday on or before the 1st, so the grid keeps its height from month to month.
    get weeks() {
        const shown = fromIso(this.cursor);
        const first = new Date(shown.getFullYear(), shown.getMonth(), 1);
        const start = addDays(first, -first.getDay());
        const today = toIso(new Date());

        return [...Array(6)].map((_, week) =>
            [...Array(7)].map((_, weekday) => {
                const date = addDays(start, week * 7 + weekday);
                const iso = toIso(date);

                return {
                    iso,
                    number: date.getDate(),
                    inMonth: date.getMonth() === shown.getMonth(),
                    today: iso === today,
                    out: this.outOfRange(iso),
                    label: date.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }) + (iso === this.value ? ', selected' : ''),
                };
            }),
        );
    },

    show() {
        if (this.trigger.disabled) {
            return;
        }

        const start = this.value || toIso(new Date());
        this.cursor = this.min && start < this.min ? this.min : this.max && start > this.max ? this.max : start;
        this.view = 'days';
        this.open = true;
        this.calendar.showPopover();
        this.place = this.place ?? this.placeCalendar.bind(this);
        this.place();
        window.addEventListener('scroll', this.place, true);
        window.addEventListener('resize', this.place);
        this.focusCursor();
    },

    close(refocus = false) {
        this.open = false;
        this.calendar.matches(':popover-open') && this.calendar.hidePopover();
        window.removeEventListener('scroll', this.place, true);
        window.removeEventListener('resize', this.place);
        refocus && this.trigger.focus();
    },

    // Under the field from its left edge, kept on screen, or over the field when there's no room below.
    placeCalendar() {
        const field = this.trigger.getBoundingClientRect();
        const calendar = this.calendar;
        const below = window.innerHeight - field.bottom;
        const above = below < calendar.offsetHeight + 8 && field.top > below;
        const left = Math.max(8, Math.min(field.left, window.innerWidth - calendar.offsetWidth - 8));

        Object.assign(calendar.style, {
            left: `${left}px`,
            top: above ? `${field.top - calendar.offsetHeight - 4}px` : `${field.bottom + 4}px`,
        });
    },

    // After Alpine has drawn the month the cursor is in: a new month's days are only there a tick after the change.
    focusCursor() {
        this.$nextTick(() => setTimeout(() => this.calendar.querySelector(`[data-iso="${this.cursor}"]`)?.focus()));
    },

    // The keyboard stops at min and max, so Enter always lands on a day that can be picked.
    moveTo(date) {
        const iso = toIso(date);
        this.cursor = this.min && iso < this.min ? this.min : this.max && iso > this.max ? this.max : iso;
        this.focusCursor();
    },

    step(direction) {
        const shown = fromIso(this.cursor);
        this.cursor = toIso(this.view === 'days' ? addMonths(shown, direction) : addMonths(shown, direction * 12));
    },

    chooseMonth(index) {
        const shown = fromIso(this.cursor);
        this.cursor = toIso(addMonths(shown, index - shown.getMonth()));
        this.view = 'days';
        this.focusCursor();
    },

    pick(day) {
        if (day.out) {
            return;
        }

        this.value = day.iso;
        this.close(true);
        this.trigger.dispatchEvent(new Event('change', { bubbles: true }));
    },

    pickToday() {
        const today = toIso(new Date());
        this.outOfRange(today) || this.pick({ iso: today, out: false });
    },

    clear() {
        this.value = '';
        this.close(true);
        this.trigger.dispatchEvent(new Event('change', { bubbles: true }));
    },

    key(event, day) {
        const date = fromIso(day.iso);
        const moves = {
            ArrowLeft: () => addDays(date, -1),
            ArrowRight: () => addDays(date, 1),
            ArrowUp: () => addDays(date, -7),
            ArrowDown: () => addDays(date, 7),
            Home: () => addDays(date, -date.getDay()),
            End: () => addDays(date, 6 - date.getDay()),
            PageUp: () => addMonths(date, event.shiftKey ? -12 : -1),
            PageDown: () => addMonths(date, event.shiftKey ? 12 : 1),
        };

        if (moves[event.key]) {
            event.preventDefault();
            this.moveTo(moves[event.key]());
        } else if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            this.pick(day);
        }
    },

    // A modal dialog: Tab stays inside it, and Escape closes it alone, not a dialog around the field.
    trap(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            this.close(true);

            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const stops = [...this.calendar.querySelectorAll('button:not([disabled]):not([tabindex="-1"])')].filter((stop) => stop.offsetParent !== null);
        const first = stops[0];
        const last = stops[stops.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    },
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('datePicker', datePicker);
    window.Alpine.data('listbox', listbox);
    window.Alpine.data('formStates', formStates);
    window.Alpine.data('combobox', combobox);
    window.Alpine.data('memoViewer', memoViewer);
    window.Alpine.data('memoFold', memoFold);
    window.Alpine.data('avatarCropper', avatarCropper);
});
