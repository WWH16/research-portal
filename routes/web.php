<?php

use App\Http\Controllers\GoogleDriveCallbackController;
use App\Models\Submission;
use App\Services\GoogleDrive;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;

Route::redirect('/', '/dashboard')->name('home');

// A verified sign-up waits here until an admin approves it; an approved account goes on to the portal.
Route::get('approval', fn () => request()->user()->isApproved()
    ? redirect()->route('dashboard')
    : view('pages::auth.approval-notice')
)->middleware(['auth', 'verified'])->name('approval.notice');

// Verified and approved only: anyone with an ISU email can sign up, so an account reaches proposals or admin pages
// only after it confirms its email and an admin lets it in.
Route::middleware(['auth', 'verified', 'approved'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

    Route::livewire('announcements', 'pages::announcements.index')->name('announcements.index');
    Route::livewire('notifications', 'pages::notifications.index')->name('notifications.index');
    Route::livewire('drive', 'pages::drive.index')->name('drive.index');
    Route::livewire('drive/projects/{submission}', 'pages::drive.show')->middleware('can:view,submission')->name('drive.show');

    Route::livewire('submissions', 'pages::submissions.index')->name('submissions.index');
    Route::livewire('submissions/create', 'pages::submissions.create')->middleware('faculty')->name('submissions.create');
    Route::livewire('submissions/{submission}/edit', 'pages::submissions.create')->middleware(['faculty', 'can:update,submission'])->name('submissions.edit');

    // "create" comes before {publication}, so the binding never catches the word.
    Route::livewire('publications/create', 'pages::publications.create')->middleware('faculty')->name('publications.create');
    Route::livewire('publications/{publication}', 'pages::publications.show')->middleware('can:view,publication')->name('publications.show');
    Route::livewire('publications/{publication}/edit', 'pages::publications.create')->middleware(['faculty', 'can:update,publication'])->name('publications.edit');
    Route::livewire('faculty/{user}', 'pages::faculty.show')->middleware('can:viewPublications,user')->name('faculty.show');

    // Documents live on the private "submissions" disk, so only the project's proponents and admins can open one.
    Route::get('submissions/{submission}/documents/{stage}', function (Submission $submission, string $stage) {
        $path = $submission->{$stage.'_path'};
        abort_unless($path && Storage::disk('submissions')->exists($path), 404);

        return Storage::disk('submissions')->response($path, Str::slug($submission->title.' '.$stage).'.'.pathinfo($path, PATHINFO_EXTENSION));
    })->whereIn('stage', array_keys(Submission::DOCUMENTS))->middleware('can:view,submission')->name('submissions.document');

    Route::middleware('admin')->group(function () {
        Route::livewire('reviews', 'pages::reviews.index')->name('reviews.index');

        Route::livewire('categories', 'pages::categories.index')->name('categories.index');
        Route::livewire('departments', 'pages::departments.index')->name('departments.index');

        Route::livewire('faculty', 'pages::faculty.index')->name('faculty.index');
        Route::livewire('users', 'pages::users.index')->name('users.index');
        Route::livewire('activity-log', 'pages::activity-log.index')->name('activity-log.index');
        Route::livewire('backups', 'pages::backups.index')->name('backups.index');
        Route::livewire('google-drive', 'pages::google-drive.index')->name('google-drive.index');

        // Only the files the portal creates, kept after the sign-in ends; prompt=consent makes Google send a refresh token
        // every time. Connecting hands over the documents' storage, so the admin confirms their password first.
        Route::get('google-drive/connect', fn () => Socialite::buildProvider(GoogleProvider::class, config('services.google'))
            ->scopes([GoogleDrive::SCOPE])
            ->with(array_filter(['access_type' => 'offline', 'prompt' => 'consent', 'hd' => config('services.google.drive_domain')]))
            ->redirect()
        )->middleware('password.confirm')->name('google-drive.connect');
        Route::get('google-drive/callback', GoogleDriveCallbackController::class)->name('google-drive.callback');
    });
});

require __DIR__.'/settings.php';
