import avatarCropper from './avatar-cropper';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('avatarCropper', avatarCropper);
});
