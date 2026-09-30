<?php

use App\Models\Submission;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

Route::redirect('/', '/dashboard')->name('home');

// Verified only: anyone can sign up, so unconfirmed emails must not reach proposals or admin pages.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

    Route::livewire('announcements', 'pages::announcements.index')->name('announcements.index');
    Route::livewire('drive', 'pages::drive.index')->name('drive.index');

    Route::livewire('submissions', 'pages::submissions.index')->name('submissions.index');
    Route::livewire('submissions/create', 'pages::submissions.create')->middleware('faculty')->name('submissions.create');
    Route::livewire('submissions/{submission}/edit', 'pages::submissions.create')->middleware(['faculty', 'can:update,submission'])->name('submissions.edit');

    // Documents live on the private disk, so only the project's proponents and admins can open one.
    Route::get('submissions/{submission}/documents/{stage}', function (Submission $submission, string $stage) {
        $path = $submission->{$stage.'_path'};
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, Str::slug($submission->title.' '.$stage).'.'.pathinfo($path, PATHINFO_EXTENSION));
    })->whereIn('stage', array_keys(Submission::DOCUMENTS))->middleware('can:view,submission')->name('submissions.document');

    Route::middleware('admin')->group(function () {
        Route::livewire('research-types', 'pages::named-records.index')->defaults('type', 'research-types')->name('research-types.index');
        Route::livewire('categories', 'pages::named-records.index')->defaults('type', 'categories')->name('categories.index');
        Route::livewire('departments', 'pages::departments.index')->name('departments.index');

        Route::livewire('users', 'pages::users.index')->name('users.index');
        Route::livewire('activity-log', 'pages::activity-log.index')->name('activity-log.index');
        Route::livewire('backups', 'pages::backups.index')->name('backups.index');
    });
});

require __DIR__.'/settings.php';
