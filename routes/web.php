<?php

use App\Exceptions\DocumentStorageUnavailable;
use App\Http\Controllers\GoogleDriveCallbackController;
use App\Http\Controllers\VerifyEmailController;
use App\Http\Middleware\VerifyRecaptcha;
use App\Models\Announcement;
use App\Models\GoogleDriveConnection;
use App\Models\Submission;
use App\Models\User;
use App\Services\DocumentStorage;
use App\Services\GoogleDrive;
use Google\Exception as GoogleException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;

Route::redirect('/', '/dashboard')->name('home');

// Public, no sign-in: Google's consent screen and the sign-in pages link to these.
Route::view('privacy', 'pages::legal.privacy')->name('privacy');
Route::view('terms', 'pages::legal.terms')->name('terms');

// Takes the place of Fortify's route of the same address and name, which asks the member to sign in first.
Route::get('email/verify/{id}/{hash}', VerifyEmailController::class)
    ->middleware('throttle:verify-email')
    ->name('verification.verify');

// Takes the place of Fortify's route of the same address and name, which has no limit: each request emails a member,
// and the portal's Gmail account sends only so many a day.
Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware(['guest', 'throttle:password-email', VerifyRecaptcha::class])
    ->name('password.email');

// A verified sign-up waits here until an admin approves it; an approved account goes on to the portal. The page asks
// for the JSON form while it waits, so it reloads into the decision as soon as one is made.
Route::get('approval', fn () => match (true) {
    request()->wantsJson() => ['waiting' => ! request()->user()->isApproved() && ! request()->user()->isRejected()],
    request()->user()->isApproved() => redirect()->route('dashboard'),
    default => view('pages::auth.approval-notice'),
})->middleware(['auth', 'verified'])->name('approval.notice');

// Verified and approved only: anyone with an ISU email can sign up, so an account reaches proposals or admin pages
// only after it confirms its email and an admin lets it in.
Route::middleware(['auth', 'verified', 'approved'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

    // The sidebar asks for its counts again every minute while the tab is open, so badges move without a reload.
    Route::get('open-work', fn (Request $request) => $request->user()->openWork())->name('open-work');

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

    // Documents live on the portal's private Google Drive, so only the project's proponents and admins can open one.
    Route::get('submissions/{submission}/documents/{stage}', function (Submission $submission, string $stage) {
        $path = $submission->{$stage.'_path'} ?? abort(404);

        return app(DocumentStorage::class)->respond($path, Str::slug($submission->title.' '.$stage).'.'.pathinfo($path, PATHINFO_EXTENSION));
    })->whereIn('stage', array_keys(Submission::DOCUMENTS))->middleware('can:view,submission')->name('submissions.document');

    // Profile photos sit on the same private Google Drive, so any signed-in member's page shows them through here.
    // Each one asks Google, so the browser keeps it: a new photo has a new name in the URL.
    Route::get('profile-photo/{user}', function (User $user) {
        abort_if($user->profile_image === null, 404);

        try {
            $response = app(DocumentStorage::class)->open($user->profile_image, basename($user->profile_image)) ?? abort(404);
            $response->headers->set('Cache-Control', 'private, max-age=31536000, immutable');

            return $response;
        } catch (DocumentStorageUnavailable|GoogleException|GuzzleException $e) {
            app(DocumentStorage::class)->noticeFailure($user->profile_image, $e);
            abort(503);
        }
    })->name('profile-photo');

    // A memo is for every member, so any approved account opens it, under the name it was uploaded with.
    Route::get('announcements/{announcement}/memo', function (Request $request, Announcement $announcement) {
        $response = app(DocumentStorage::class)->respond($announcement->attachment_path ?? abort(404), (string) $announcement->attachment_name);

        return $announcement->keepIfCurrent($response, $request->string('v')->value());
    })->name('announcements.memo');

    // An image memo as the Announcements page shows it: at most 1000px on its longest side, enough for a sharp
    // picture at the page's size, so a 2 MB poster isn't downloaded whole just to be drawn small.
    Route::get('announcements/{announcement}/memo/preview', function (Request $request, Announcement $announcement) {
        $storage = app(DocumentStorage::class);
        $path = $announcement->attachment_path ?? abort(404);
        $copy = $storage->thumbnail($path, (string) $announcement->attachment_name, 1000);

        // Only the small copy is kept. Until Drive has made it, the whole picture comes uncached, so the next visit
        // gets the copy instead of a week of the full file.
        return $copy ? $announcement->keepIfCurrent($copy, $request->string('v')->value()) : $storage->respond($path, (string) $announcement->attachment_name);
    })->name('announcements.memo.preview');

    Route::middleware('admin')->group(function () {
        Route::livewire('reviews', 'pages::reviews.index')->name('reviews.index');

        Route::livewire('categories', 'pages::categories.index')->name('categories.index');
        Route::livewire('departments', 'pages::departments.index')->name('departments.index');

        Route::livewire('faculty', 'pages::faculty.index')->name('faculty.index');
        Route::livewire('users', 'pages::users.index')->name('users.index');
        Route::livewire('activity-log', 'pages::activity-log.index')->name('activity-log.index');
        Route::livewire('google-drive', 'pages::google-drive.index')->name('google-drive.index');

        // Only the files the portal creates, kept after the sign-in ends; prompt=consent makes Google send a refresh token
        // every time, and login_hint opens Google's picker on the saved storage account. Connecting hands over the
        // documents' storage, so the admin confirms their password first.
        // ?account= reconnects an earlier storage account, so the documents it holds open again.
        Route::get('google-drive/connect', function (Request $request) {
            $account = $request->filled('account')
                ? GoogleDriveConnection::find($request->integer('account'))
                : GoogleDriveConnection::current();

            if (! $account) {
                return redirect()->route('google-drive.index')->with('drive-error', __('Save the Google account that stores documents first.'));
            }

            // The callback connects this account and no other.
            session(['google-drive.connecting' => $account->id]);

            return Socialite::buildProvider(GoogleProvider::class, config('services.google'))
                ->scopes([GoogleDrive::SCOPE])
                ->with(['access_type' => 'offline', 'prompt' => 'consent', 'login_hint' => $account->email])
                ->redirect();
        })->middleware('password.confirm')->name('google-drive.connect');
        Route::get('google-drive/callback', GoogleDriveCallbackController::class)->name('google-drive.callback');
    });
});

require __DIR__.'/settings.php';
