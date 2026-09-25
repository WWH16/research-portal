<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

Route::middleware(['auth'])->group(function () {
    Route::livewire('submissions', 'pages::submissions.index')->name('submissions.index');
    Route::livewire('submissions/create', 'pages::submissions.create')->name('submissions.create');

    Route::livewire('research-types', 'pages::research-types.index')->name('research-types.index');
    Route::livewire('categories', 'pages::categories.index')->name('categories.index');
    Route::livewire('departments', 'pages::departments.index')->name('departments.index');

    Route::livewire('users', 'pages::users.index')->middleware('admin')->name('users.index');
    Route::livewire('activity-log', 'pages::activity-log.index')->name('activity-log.index');
    Route::livewire('backups', 'pages::backups.index')->name('backups.index');
});

require __DIR__.'/settings.php';
