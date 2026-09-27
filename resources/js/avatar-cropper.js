/*
 * Lets a member frame their profile photo before it is saved. The crop happens in the
 * browser because the server has no GD or Imagick, and redrawing the photo onto a canvas
 * also drops its EXIF data (camera details, GPS location) before it is uploaded.
 */
const OUTPUT_SIZE = 512;
const MAX_BYTES = 10 * 1024 * 1024;
const TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const MAX_ZOOM = 3;

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

    pick(event) {
        const file = event.target.files[0];

        // Clear the input so choosing the same file again still opens the dialog.
        event.target.value = '';
        this.error = '';

        if (! file) return;
        if (! TYPES.includes(file.type)) return (this.error = messages.type);
        if (file.size > MAX_BYTES) return (this.error = messages.size);

        this.release();
        this.src = URL.createObjectURL(file);
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

    get imageStyle() {
        const { width, height, left, top } = this.box;

        return `width: ${width}px; height: ${height}px; transform: translate(${left}px, ${top}px)`;
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

    move(event) {
        if (! this.drag) return;

        this.x = this.drag.x + event.clientX - this.drag.pointerX;
        this.y = this.drag.y + event.clientY - this.drag.pointerY;
        this.clamp();
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

        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d');
        const { left, top } = this.box;
        const perStagePixel = 1 / this.scale;

        canvas.width = canvas.height = OUTPUT_SIZE;

        // Transparent PNGs would otherwise turn black in the JPEG.
        context.fillStyle = '#fff';
        context.fillRect(0, 0, OUTPUT_SIZE, OUTPUT_SIZE);
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
