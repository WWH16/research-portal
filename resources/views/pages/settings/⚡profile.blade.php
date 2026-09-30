<?php

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('My Profile')] class extends Component {
    use ProfileValidationRules, WithFileUploads;

    public string $name = '';
    public string $email = '';
    public string $mobile = '';

    /** @var TemporaryUploadedFile|null */
    public $photo = null;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $user = Auth::user();

        $this->name = $user->name;
        $this->email = $user->email;
        $this->mobile = (string) $user->mobile;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $this->name = trim($this->name);
        $this->email = trim($this->email);
        $this->mobile = trim($this->mobile);

        $validated = $this->validate([
            ...$this->profileRules($user->id),
            'mobile' => ['nullable', 'string', 'max:20'],
        ]);

        $validated['mobile'] = $validated['mobile'] ?: null;

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    /**
     * Save a newly chosen profile photo as soon as it finishes uploading.
     */
    public function updatedPhoto(): void
    {
        $this->validate([
            'photo' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], attributes: ['photo' => __('photo')]);

        $user = Auth::user();
        $previous = $user->profile_image;

        $user->update(['profile_image' => $this->photo->store('profile-images', 'public')]);

        if ($previous) {
            Storage::disk('public')->delete($previous);
        }

        $this->reset('photo');

        Flux::toast(variant: 'success', text: __('Photo updated.'));
    }

    /**
     * Remove the current profile photo and fall back to initials.
     */
    public function removePhoto(): void
    {
        $user = Auth::user();

        if ($user->profile_image) {
            Storage::disk('public')->delete($user->profile_image);
            $user->update(['profile_image' => null]);
        }

        Flux::modal('remove-photo')->close();
        Flux::toast(variant: 'success', text: __('Photo removed.'));
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }
}; ?>

<section class="w-full">
    <x-pages::settings.layout :heading="__('My Profile')" :subheading="__('Your photo, contact details, and portal account.')">
        @php($user = auth()->user()->loadMissing('department:id,code,name'))

        {{-- Who you are in the portal: photo, name, and the record the Research Office keeps --}}
        <div class="rounded-xl border border-line bg-surface">
            <div
                class="flex items-center gap-5 p-5 sm:p-6"
                x-data="avatarCropper(@js([
                    'type' => __('Choose a JPG, PNG, or WebP image.'),
                    'size' => __('Choose an image under 10 MB.'),
                    'failed' => __('The photo could not be saved. Please try again.'),
                ]))"
            >
                <flux:avatar
                    size="xl"
                    circle
                    :src="$user->profileImageUrl()"
                    :name="$user->name"
                    :initials="$user->initials()"
                    class="shrink-0"
                />

                <div class="min-w-0 flex-1">
                    <flux:heading size="lg" class="truncate">{{ $user->name }}</flux:heading>
                    <flux:text class="truncate">{{ $user->email }}</flux:text>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <input
                            type="file"
                            x-ref="photo"
                            x-on:change="pick($event)"
                            accept="image/png,image/jpeg,image/webp"
                            class="sr-only"
                            tabindex="-1"
                            aria-hidden="true"
                        />

                        <flux:button size="sm" icon="camera" x-on:click="$refs.photo.click()" wire:loading.attr="disabled" wire:target="photo" id="upload-photo-button" data-test="upload-photo-button">
                            {{ $user->profile_image ? __('Change photo') : __('Upload photo') }}
                        </flux:button>

                        @if ($user->profile_image)
                            <flux:modal.trigger name="remove-photo">
                                <flux:button size="sm" variant="ghost" wire:target="photo" wire:loading.attr="disabled" data-test="remove-photo-button">
                                    {{ __('Remove') }}
                                </flux:button>
                            </flux:modal.trigger>
                        @endif

                        <flux:text class="text-sm" wire:loading.remove wire:target="photo">{{ __('JPG, PNG, or WebP, up to 10 MB.') }}</flux:text>
                        <flux:text class="text-sm" wire:loading wire:target="photo">{{ __('Uploading…') }}</flux:text>
                    </div>

                    <flux:error name="photo" class="mt-2" />
                    <p class="mt-2 text-sm font-medium text-red-500" role="alert" x-show="error" x-text="error"></p>

                    <flux:modal name="adjust-photo" class="w-full md:w-[26rem]" aria-labelledby="adjust-photo-heading" x-on:close="release()">
                        <div class="flex flex-col gap-6">
                            <div>
                                <flux:heading size="lg" id="adjust-photo-heading">{{ __('Adjust your photo') }}</flux:heading>
                                <flux:text class="mt-2">{{ __('Drag or use the arrow keys to reposition.') }}</flux:text>
                            </div>

                            <div
                                x-ref="stage"
                                tabindex="0"
                                role="group"
                                aria-label="{{ __('Photo position') }}"
                                class="relative mx-auto aspect-square w-full max-w-72 cursor-grab touch-none select-none overflow-hidden rounded-lg bg-zinc-100 outline-accent focus-visible:outline-2 focus-visible:outline-offset-2 active:cursor-grabbing"
                                x-on:pointerdown="start($event)"
                                x-on:pointermove="move($event)"
                                x-on:lostpointercapture="drag = null"
                                x-on:wheel.prevent="setZoom(zoom - $event.deltaY * 0.002)"
                                x-on:keydown="key($event)"
                            >
                                <img x-ref="image" x-show="src" :src="src" x-on:load="loaded()" alt="" draggable="false" class="absolute top-0 left-0 max-w-none" :style="imageStyle" />
                                <div class="pointer-events-none absolute inset-0 rounded-full ring-[999px] ring-zinc-950/50"></div>
                            </div>

                            <div class="grid gap-2">
                                <div class="flex items-center gap-3">
                                    <label for="photo-zoom" class="text-sm font-medium text-zinc-800">{{ __('Zoom') }}</label>
                                    <input id="photo-zoom" type="range" min="1" max="3" step="0.01" :value="zoom" x-on:input="setZoom(+$event.target.value)" class="w-full accent-accent" />
                                </div>

                                <flux:text class="text-sm" x-show="small">{{ __('This photo is small and may look blurry.') }}</flux:text>
                                <p class="text-sm font-medium text-red-500" role="alert" x-show="error" x-text="error"></p>
                            </div>

                            <div class="flex justify-end gap-2">
                                <flux:modal.close>
                                    <flux:button variant="ghost" x-bind:disabled="saving">{{ __('Cancel') }}</flux:button>
                                </flux:modal.close>
                                <flux:button variant="primary" x-on:click="save()" x-bind:disabled="saving || ! stage" x-bind:class="saving && 'disabled:opacity-100!'" data-test="save-photo-button">
                                    <flux:icon.loading x-show="saving" class="size-4" />
                                    <span x-text="saving ? @js(__('Saving…')) : @js(__('Save photo'))">{{ __('Save photo') }}</span>
                                </flux:button>
                            </div>
                        </div>
                    </flux:modal>

                    {{-- Outside the button row and the photo check, so the dialog closes normally after a removal --}}
                    <flux:modal
                        name="remove-photo"
                        class="w-full md:w-96"
                        aria-labelledby="remove-photo-heading"
                        x-on:close="setTimeout(() => { if (! document.activeElement || document.activeElement === document.body) document.getElementById('upload-photo-button')?.focus() })"
                    >
                        <div class="flex flex-col gap-6">
                            <flux:avatar size="lg" circle :src="$user->profileImageUrl()" :name="$user->name" />

                            <div>
                                <flux:heading size="lg" id="remove-photo-heading">{{ __('Remove your photo?') }}</flux:heading>
                                <flux:text class="mt-2">{{ __('Your initials will show instead.') }}</flux:text>
                            </div>

                            <div class="flex justify-end gap-2">
                                <flux:modal.close>
                                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                                </flux:modal.close>
                                <flux:button variant="danger" wire:click="removePhoto" data-test="confirm-remove-photo-button">
                                    {{ __('Remove photo') }}
                                </flux:button>
                            </div>
                        </div>
                    </flux:modal>
                </div>
            </div>

            <dl class="grid grid-cols-2 gap-x-6 gap-y-4 border-t border-line px-5 py-4 sm:grid-cols-3 sm:px-6">
                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Researcher ID') }}</dt>
                    <dd class="mt-1 font-medium tabular-nums text-zinc-800">{{ $user->researcher_id ?? __('Not assigned') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Role') }}</dt>
                    <dd class="mt-1 font-medium text-zinc-800">{{ $user->isAdmin() ? __('Admin') : __('Faculty') }}</dd>
                </div>
                <div class="col-span-2 min-w-0 sm:col-span-1">
                    <dt class="text-sm text-zinc-500">{{ __('College') }}</dt>
                    <dd class="mt-1 truncate font-medium text-zinc-800">{{ $user->department?->code ?? __('Not assigned') }}</dd>
                </div>
                <p class="col-span-2 text-sm text-zinc-500 sm:col-span-3">{{ __('To change these, contact the Research Office.') }}</p>
            </dl>
        </div>

        <form wire:submit="updateProfileInformation">
            <x-pages::settings.section :heading="__('Contact details')" :description="__('How the Research Office reaches you.')">
                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:input wire:model="name" :label="__('Full name')" type="text" required autocomplete="name" class="sm:col-span-2" />

                    <div class="min-w-0">
                        <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                        @if ($this->hasUnverifiedEmail)
                            <flux:text class="mt-3">
                                {{ __('Your email address is unverified.') }}

                                <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                    {{ __('Click here to re-send the verification email.') }}
                                </flux:link>
                            </flux:text>

                            @if (session('status') === 'verification-link-sent')
                                <flux:text class="mt-2 font-medium !text-green-600">
                                    {{ __('A new verification link has been sent to your email address.') }}
                                </flux:text>
                            @endif
                        @endif
                    </div>

                    <flux:input wire:model="mobile" :label="__('Mobile')" :badge="__('Optional')" type="tel" maxlength="20" autocomplete="tel" />
                </div>

                <x-slot:footer>
                    <flux:button variant="primary" type="submit" data-test="update-profile-button">
                        {{ __('Save') }}
                    </flux:button>
                </x-slot:footer>
            </x-pages::settings.section>
        </form>
    </x-pages::settings.layout>
</section>
