/*
 * Lets a member frame their profile photo before it is saved. The crop happens in the
 * browser because the server has no GD or Imagick, and redrawing the photo onto a canvas
 * also drops its EXIF data (camera details, GPS location) before it is uploaded.
 */
const OUTPUT_SIZE = 512;
const MAX_BYTES = 10 * 1024 * 1024;
const TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const MAX_ZOOM = 3;
// Enough pixels for a sharp 512px crop at full zoom. A phone photo is 12 MP or more, and dragging and
// zooming that full bitmap is what made the dialog lag on phones, so a bigger photo is shrunk to this first.
const WORKING_SIZE = OUTPUT_SIZE * MAX_ZOOM;

/**
 * A canvas of the given size, painted white, since transparent PNGs would otherwise turn black in the JPEG.
 */
function whiteCanvas(width, height) {
    const canvas = Object.assign(document.createElement('canvas'), { width, height });
    const context = canvas.getContext('2d');

    context.fillStyle = '#fff';
    context.fillRect(0, 0, width, height);

    return { canvas, context };
}

/**
 * A copy of the chosen photo no larger than the crop needs, as an object URL. Smaller photos come back as they are.
 */
async function workingCopy(url) {
    const image = new Image();
    image.src = url;
    await image.decode();

    const ratio = WORKING_SIZE / Math.min(image.naturalWidth, image.naturalHeight);

    if (ratio >= 1) return url;

    const { canvas, context } = whiteCanvas(Math.round(image.naturalWidth * ratio), Math.round(image.naturalHeight * ratio));

    context.imageSmoothingQuality = 'high';
    context.drawImage(image, 0, 0, canvas.width, canvas.height);

    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.92));
    URL.revokeObjectURL(url);

    return URL.createObjectURL(blob);
}

export default (messages) => ({
    src: null,
    error: '',
    saving: false,
    small: false,
    zoom: 1,
    x: 0,
    y: 0,
    width: 0,
    height: 0,
    stage: 0,
    drag: null,
    frame: null,

    async pick(event) {
        const file = event.target.files[0];

        // Clear the input so choosing the same file again still opens the dialog.
        event.target.value = '';
        this.error = '';

        if (! file) return;
        if (! TYPES.includes(file.type)) return (this.error = messages.type);
        if (file.size > MAX_BYTES) return (this.error = messages.size);

        this.release();

        try {
            this.src = await workingCopy(URL.createObjectURL(file));
        } catch {
            // The browser couldn't decode it, so it isn't a usable image whatever its type says.
            return (this.error = messages.type);
        }

        this.$dispatch('modal-show', { name: 'adjust-photo' });
    },

    loaded() {
        const image = this.$refs.image;

        this.width = image.naturalWidth;
        this.height = image.naturalHeight;
        this.stage = this.$refs.stage.clientWidth;
        this.small = Math.min(this.width, this.height) < OUTPUT_SIZE;
        this.zoom = 1;
        this.x = 0;
        this.y = 0;
    },

    // Stage pixels per image pixel. At zoom 1 the photo just covers the circle.
    get scale() {
        return (this.stage / Math.min(this.width, this.height)) * this.zoom;
    },

    get box() {
        const width = this.width * this.scale;
        const height = this.height * this.scale;

        return { width, height, left: (this.stage - width) / 2 + this.x, top: (this.stage - height) / 2 + this.y };
    },

    // The photo keeps its zoom-1 size and moves and zooms by transform only, so dragging never re-lays out
    // the page; with transform-origin at the top left, this lands exactly on box.
    get imageStyle() {
        const { left, top } = this.box;
        const fit = this.stage / Math.min(this.width, this.height);

        return `width: ${this.width * fit}px; height: ${this.height * fit}px; transform: translate(${left}px, ${top}px) scale(${this.zoom})`;
    },

    // Keep the photo covering the whole circle, so no empty edge can end up in the crop.
    clamp() {
        const maxX = (this.width * this.scale - this.stage) / 2;
        const maxY = (this.height * this.scale - this.stage) / 2;

        this.x = Math.min(maxX, Math.max(-maxX, this.x));
        this.y = Math.min(maxY, Math.max(-maxY, this.y));
    },

    setZoom(value) {
        const zoom = Math.min(MAX_ZOOM, Math.max(1, value));
        const ratio = zoom / this.zoom;

        // Zoom around the middle of the circle, not the corner of the photo.
        this.zoom = zoom;
        this.x *= ratio;
        this.y *= ratio;
        this.clamp();
    },

    start(event) {
        if (! this.stage) return;

        this.$refs.stage.setPointerCapture(event.pointerId);
        this.drag = { pointerX: event.clientX, pointerY: event.clientY, x: this.x, y: this.y };
    },

    // Phones send pointer moves faster than the screen redraws, so only the latest one per frame is applied.
    move(event) {
        if (! this.drag) return;

        this.drag.clientX = event.clientX;
        this.drag.clientY = event.clientY;
        this.frame ??= requestAnimationFrame(() => {
            this.frame = null;

            if (! this.drag) return;

            this.x = this.drag.x + this.drag.clientX - this.drag.pointerX;
            this.y = this.drag.y + this.drag.clientY - this.drag.pointerY;
            this.clamp();
        });
    },

    // Zoom needs no keys of its own: the range slider already responds to arrow keys.
    key(event) {
        const step = event.shiftKey ? 16 : 4;
        const moves = { ArrowLeft: [step, 0], ArrowRight: [-step, 0], ArrowUp: [0, step], ArrowDown: [0, -step] };
        const [dx, dy] = moves[event.key] ?? [];

        if (dx === undefined) return;

        event.preventDefault();
        this.x += dx;
        this.y += dy;
        this.clamp();
    },

    async save() {
        this.saving = true;
        this.error = '';

        const { canvas, context } = whiteCanvas(OUTPUT_SIZE, OUTPUT_SIZE);
        const { left, top } = this.box;
        const perStagePixel = 1 / this.scale;

        context.drawImage(
            this.$refs.image,
            -left * perStagePixel,
            -top * perStagePixel,
            this.stage * perStagePixel,
            this.stage * perStagePixel,
            0,
            0,
            OUTPUT_SIZE,
            OUTPUT_SIZE,
        );

        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));

        this.$wire.upload(
            'photo',
            new File([blob], 'photo.jpg', { type: 'image/jpeg' }),
            () => {
                this.saving = false;
                this.$dispatch('modal-close', { name: 'adjust-photo' });
            },
            () => {
                this.saving = false;
                this.error = messages.failed;
            },
        );
    },

    release() {
        if (this.src) URL.revokeObjectURL(this.src);

        this.src = null;
        this.stage = 0;
    },
});
