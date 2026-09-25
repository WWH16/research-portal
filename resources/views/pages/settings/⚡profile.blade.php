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

    #[Computed]
    public function showDeleteUser(): bool
    {
        return ! Auth::user() instanceof MustVerifyEmail
            || (Auth::user() instanceof MustVerifyEmail && Auth::user()->hasVerifiedEmail());
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('My Profile') }}</flux:heading>

    <x-pages::settings.layout :heading="__('My Profile')" :subheading="__('Your photo, contact details, and portal account.')">
        @php($user = auth()->user()->loadMissing('department:id,code,name'))

        {{-- Photo --}}
        <div class="mt-6 flex items-center gap-5" x-data>
            <flux:avatar
                size="xl"
                circle
                :src="$user->profileImageUrl()"
                :name="$user->name"
                :initials="$user->initials()"
                class="shrink-0"
            />

            <div class="flex min-w-0 flex-col gap-2">
                <div class="flex flex-wrap items-center gap-2">
                    <input
                        type="file"
                        x-ref="photo"
                        wire:model="photo"
                        accept="image/png,image/jpeg,image/webp"
                        class="sr-only"
                        tabindex="-1"
                        aria-hidden="true"
                    />

                    <flux:button size="sm" icon="camera" x-on:click="$refs.photo.click()" wire:loading.attr="disabled" wire:target="photo" data-test="upload-photo-button">
                        {{ $user->profile_image ? __('Change photo') : __('Upload photo') }}
                    </flux:button>

                    @if ($user->profile_image)
                        <flux:button size="sm" variant="ghost" wire:click="removePhoto" wire:target="photo,removePhoto" wire:loading.attr="disabled">
                            {{ __('Remove') }}
                        </flux:button>
                    @endif
                </div>

                <flux:text class="text-sm" wire:loading.remove wire:target="photo">{{ __('JPG, PNG, or WebP, up to 2 MB.') }}</flux:text>
                <flux:text class="text-sm" wire:loading wire:target="photo">{{ __('Uploading…') }}</flux:text>
                <flux:error name="photo" />
            </div>
        </div>

        {{-- Details the Research Office manages --}}
        <dl class="mt-8 grid gap-x-6 gap-y-4 border-y border-line py-5 sm:grid-cols-3">
            <div>
                <dt class="text-sm text-zinc-500">{{ __('Researcher ID') }}</dt>
                <dd class="mt-1 font-medium tabular-nums text-zinc-800">{{ $user->researcher_id ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500">{{ __('Role') }}</dt>
                <dd class="mt-1 font-medium text-zinc-800">{{ $user->isAdmin() ? __('Admin') : __('Faculty') }}</dd>
            </div>
            <div class="min-w-0">
                <dt class="text-sm text-zinc-500">{{ __('Department') }}</dt>
                <dd class="mt-1 truncate font-medium text-zinc-800" title="{{ $user->department?->name }}">{{ $user->department?->code ?? __('Not assigned') }}</dd>
            </div>
            <p class="text-sm text-zinc-500 sm:col-span-3">{{ __('To change these, contact the Research Office.') }}</p>
        </dl>

        {{-- Editable details --}}
        <form wire:submit="updateProfileInformation" class="mt-8 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Full name')" type="text" required autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                @if ($this->hasUnverifiedEmail)
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your email address is unverified.') }}

                            <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                {{ __('Click here to re-send the verification email.') }}
                            </flux:link>
                        </flux:text>

                        @if (session('status') === 'verification-link-sent')
                            <flux:text class="mt-2 font-medium !dark:text-green-400 !text-green-600">
                                {{ __('A new verification link has been sent to your email address.') }}
                            </flux:text>
                        @endif
                    </div>
                @endif
            </div>

            <flux:input wire:model="mobile" :label="__('Mobile')" :badge="__('Optional')" type="tel" maxlength="20" autocomplete="tel" />

            <flux:button variant="primary" type="submit" data-test="update-profile-button">
                {{ __('Save') }}
            </flux:button>
        </form>

        @if ($this->showDeleteUser)
            <livewire:pages::settings.delete-user-form />
        @endif
    </x-pages::settings.layout>
</section>
