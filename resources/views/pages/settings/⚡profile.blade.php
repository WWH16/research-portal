<?php

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Exceptions\DocumentStorageUnavailable;
use App\Models\ActivityLog;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\EmailChanged;
use App\Services\DocumentStorage;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('My profile')] class extends Component {
    use PasswordValidationRules, ProfileValidationRules, WithFileUploads;

    public string $name = '';
    public string $email = '';
    public string $mobile = '';
    public string $current_password = '';

    /** @var TemporaryUploadedFile|null */
    public $photo = null;

    /** @var array<string, bool> Which kinds of notice also come by email, keyed like User::EMAIL_KINDS. */
    public array $emailMe = [];

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $user = Auth::user();

        $this->name = $user->name;
        $this->email = $user->email;
        $this->mobile = (string) $user->mobile;
        $this->emailMe = collect(User::EMAIL_KINDS)->mapWithKeys(fn ($label, string $kind) => [$kind => $user->wantsEmail($kind)])->all();
    }

    /**
     * Save a switch as soon as it flips. Only known kinds are kept, so nothing else can be set through it.
     */
    public function updatedEmailMe(): void
    {
        $user = Auth::user();
        $choices = collect($this->emailMe)->only(array_keys(User::EMAIL_KINDS))->map(fn ($on) => (bool) $on);

        $user->forceFill(['email_preferences' => $choices->all()])->save();
        $this->emailMe = $choices->all();

        Flux::toast(variant: 'success', text: __('Email settings saved.'));
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
            // A new email moves where reset links go, so it needs the password, as a new password does on Security.
            'current_password' => [Rule::excludeIf($this->email === $user->email), ...$this->currentPasswordRules()],
        ]);

        $validated['mobile'] = $validated['mobile'] ?: null;

        // Read before the change: only an address the member confirmed is told of it, since an unconfirmed one may not be theirs.
        $confirmed = $user->hasVerifiedEmail();
        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();
        $this->reset('current_password');

        if ($user->wasChanged('email')) {
            $previous = $user->getPrevious()['email'];
            ActivityLog::record('auth.email_changed', properties: ['from' => $previous, 'to' => $user->email]);

            // The new address's link comes back here, not to a page still held, such as a Security password prompt left unanswered.
            redirect()->setIntendedUrl(route('profile.edit'));

            if ($confirmed) {
                Notification::route('mail', $previous)->notify(new EmailChanged($previous, $user->email));
            }

            // As Fortify's own profile update does: the new address gets its link now, without pressing Resend.
            if (! $this->sendVerificationLink($user)) {
                Flux::toast(variant: 'warning', text: __('Your profile was updated, but too many verification emails were sent in the last minute. Please re-send the verification email in a minute.'));

                return;
            }
        }

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    /**
     * Save a newly chosen profile photo as soon as it finishes uploading.
     */
    public function updatedPhoto(): void
    {
        // Only accounts the Research Office let in store files on its Drive; a pending or rejected one has no button.
        abort_unless(Auth::user()->isApproved(), 403);

        $this->validate([
            'photo' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], attributes: ['photo' => __('photo')]);

        $user = Auth::user();
        $previous = $user->profile_image;

        try {
            $user->update(['profile_image' => app(DocumentStorage::class)->store($this->photo, 'profile-images')]);
        } catch (DocumentStorageUnavailable) {
            $this->addError('photo', __('Photo storage is unavailable right now. Try again later or contact the Research Office.'));

            return;
        } finally {
            $this->reset('photo');
        }

        if ($previous) {
            app(DocumentStorage::class)->delete($previous);
        }

        Flux::toast(variant: 'success', text: __('Photo updated.'));
    }

    /**
     * Remove the current profile photo and fall back to initials.
     */
    public function removePhoto(): void
    {
        $user = Auth::user();

        if ($user->profile_image) {
            app(DocumentStorage::class)->delete($user->profile_image);
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

        if (! $this->sendVerificationLink($user)) {
            Flux::toast(variant: 'danger', text: __('Too many verification emails were sent in the last minute. Please wait a minute and try again.'));

            return;
        }

        Session::flash('status', 'verification-link-sent');
    }

    /**
     * Send the verification link, at most 6 a minute per member as Fortify's own Resend allows, so repeated clicks
     * can't use up the portal's daily sending limit. False once the limit is reached.
     */
    private function sendVerificationLink(User $user): bool
    {
        return RateLimiter::attempt('verification-email:'.$user->id, 6, fn () => $user->sendEmailVerificationNotification()) !== false;
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }
}; ?>

<section class="w-full">
    <x-pages::settings.layout :heading="__('My profile')" :subheading="__('Your photo, contact details, and portal account.')">
        @php($user = auth()->user()->loadMissing('department:id,code'))

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

                    {{-- Photos are stored on the Research Office's Drive, so only an approved account can add one --}}
                    @if ($user->isApproved())
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

                            <flux:button size="sm" icon="camera" x-on:click="$refs.photo.click()" wire:loading.attr="disabled" wire:target="photo" id="upload-photo-button" data-test="upload-photo-button" class="max-sm:h-11 max-sm:px-4">
                                {{ $user->profile_image ? __('Change photo') : __('Upload photo') }}
                            </flux:button>

                            @if ($user->profile_image)
                                <flux:modal.trigger name="remove-photo">
                                    <flux:button size="sm" variant="ghost" wire:target="photo" wire:loading.attr="disabled" data-test="remove-photo-button" class="max-sm:h-11 max-sm:px-4">
                                        {{ __('Remove photo') }}
                                    </flux:button>
                                </flux:modal.trigger>
                            @endif

                            <flux:text class="text-sm" wire:loading.remove wire:target="photo">{{ __('JPG, PNG, or WebP, up to 10 MB.') }}</flux:text>
                            <flux:text class="text-sm" wire:loading wire:target="photo">{{ __('Uploading…') }}</flux:text>
                        </div>
                    @else
                        <flux:text class="mt-3 text-sm" data-test="photo-after-approval">{{ __('You can add a photo once the Research Office approves your account.') }}</flux:text>
                    @endif

                    <flux:error name="photo" class="mt-2" />
                    <p class="mt-2 text-sm font-medium text-red-500" role="alert" x-show="error" x-text="error"></p>

                    <flux:modal name="adjust-photo" class="w-full sm:w-[26rem]" aria-labelledby="adjust-photo-heading" x-on:close="release()">
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
                                <img x-ref="image" x-show="src" :src="src" x-on:load="loaded()" alt="" draggable="false" class="absolute top-0 left-0 max-w-none origin-top-left will-change-transform" :style="imageStyle" />
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
                        class="w-full sm:w-96"
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
                    <dd class="mt-1 font-medium text-zinc-800">{{ __($user->role->name) }}</dd>
                </div>
                @if ($user->isFaculty())
                    <div class="col-span-2 min-w-0 sm:col-span-1">
                        <dt class="text-sm text-zinc-500">{{ __('College') }}</dt>
                        <dd class="mt-1 truncate font-medium text-zinc-800">{{ $user->department?->code ?? __('Not assigned') }}</dd>
                    </div>
                @endif
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
                                    {{ __('Resend verification email') }}
                                </flux:link>
                            </flux:text>

                            @if (session('status') === 'verification-link-sent')
                                <flux:text class="mt-2 font-medium !text-green-600">
                                    {{ __('A new verification link has been sent to your email address.') }}
                                </flux:text>
                            @endif
                        @endif

                        {{-- Only while the address is being changed: a new one moves where reset links go --}}
                        <div x-show="$wire.email.trim() !== @js($user->email)" x-cloak class="mt-4">
                            <flux:input wire:model="current_password" :label="__('Current password')" :description:trailing="__('Needed to change your email address.')" type="password" autocomplete="current-password" viewable data-test="email-change-password" />
                        </div>
                    </div>

                    <flux:input wire:model="mobile" :label="__('Mobile')" :badge="__('Optional')" type="tel" maxlength="20" autocomplete="tel" />
                </div>

                <x-slot:footer>
                    <flux:button variant="primary" type="submit" data-test="update-profile-button">
                        {{ __('Save changes') }}
                    </flux:button>
                </x-slot:footer>
            </x-pages::settings.section>
        </form>

        {{-- These notices go to a project's people, and only an office account that was once faculty can still be on a project --}}
        @if ($user->isFaculty() || Submission::involving($user)->exists())
            {{-- Each switch saves as it flips, so this section needs no Save button --}}
            <x-pages::settings.section :heading="__('Email me about')" :description="__('Notices always show in the portal. These choose which also come by email. Account and Google Drive notices always do.')">
                <div class="flex flex-col gap-4" data-test="email-preferences">
                    @foreach (User::EMAIL_KINDS as $kind => $label)
                        <flux:switch wire:model.live="emailMe.{{ $kind }}" :label="__($label)" align="left" />
                    @endforeach
                </div>
            </x-pages::settings.section>
        @endif
    </x-pages::settings.layout>
</section>
