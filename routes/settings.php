<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
});

Route::middleware(['auth', 'verified'])->group(function () {
    // Asks for the password at most every three hours (auth.password_timeout): this page turns two-factor off and
    // shows the recovery codes, which a passer-by at a signed-in computer must not reach.
    Route::livewire('settings/security', 'pages::settings.security')->middleware('password.confirm')->name('security.edit');
});

if (Features::canManagePasskeys()) {
    Route::get('.well-known/passkey-endpoints', function () {
        return response()->json([
            'enroll' => route('security.edit'),
            'manage' => route('security.edit'),
        ]);
    })->name('well-known.passkeys');
}
