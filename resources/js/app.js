import avatarCropper from './avatar-cropper';

// The memo viewer on Announcements. A PDF shows in the browser's own viewer; a Word memo is drawn as pages by
// docx-preview, which loads on first use, so pages without one never fetch it.
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
    window.Alpine.data('memoPreview', memoPreview);
    window.Alpine.data('avatarCropper', avatarCropper);
});
