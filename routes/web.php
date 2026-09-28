<?php

use App\Models\Submission;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

Route::redirect('/', '/dashboard')->name('home');

// Verified only: anyone can sign up, so unconfirmed emails must not reach proposals or admin pages.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

    Route::livewire('submissions', 'pages::submissions.index')->name('submissions.index');
    Route::livewire('submissions/create', 'pages::submissions.create')->middleware('faculty')->name('submissions.create');

    // Proposals live on the private disk, so only their author and admins can open one.
    Route::get('submissions/{submission}/document', function (Submission $submission) {
        abort_unless(auth()->user()->isAdmin() || $submission->user_id === auth()->id(), 403);
        abort_unless(Storage::disk('local')->exists($submission->file_path), 404);

        return Storage::disk('local')->response($submission->file_path, Str::slug($submission->title).'.pdf');
    })->name('submissions.document');

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
