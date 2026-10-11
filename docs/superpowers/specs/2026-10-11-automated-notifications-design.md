# Automated notifications

Date: 2026-10-11
Status: Approved design, awaiting spec review

## Goal

Faculty and the Research Office learn about events that need their attention without checking the portal. The system sends each notification on its own, either when an action happens or on a daily schedule. Nobody writes these messages by hand.

## Scope

In scope:

1. **Review decision.** A concept proposal is passed or returned. The project's people are told, and a return includes the remarks.
2. **New concept to review.** A concept proposal is uploaded and is waiting for review. Every admin is told.
3. **Deadline reminders.** Project people are reminded 30 days and 7 days before the target date, the day after the project goes overdue, and then every 7 days until the terminal report is uploaded.

Out of scope:

- Announcement notifications. These wait until the client is consulted.
- Per-user notification preferences.
- Real-time push. A notification appears on the user's next page load.
- Overdue notices or digests for admins. The dashboard already shows delayed projects through `Submission::scopeDelayed()`.

## Decisions

- **Channels:** each notification is stored in the database for the in-app list and also sent by email.
- **Project people:** the filer (`submissions.user_id`) plus every proponent with an account. This is the same set `Submission::scopeInvolving()` matches, so anyone who can see the project hears about it.
- **Approach:** Laravel Notification classes, sent directly from the code paths where each action already happens. Events and listeners or a model observer were rejected. They add indirection, and an observer would fire on seeders, tinker, and admin edits.
- **The acting user is never notified about their own action.**

## Components

### New

| File | Purpose |
|------|---------|
| Migration from `php artisan make:notifications-table` | Laravel's stock `notifications` table. |
| `app/Notifications/ConceptReviewed.php` | To project people. Payload: `submission_id`, `title`, `decision` (`passed` or `returned`), `remarks`. Mail subject and body: "Concept proposal passed. The detailed proposal is now open." or "Concept proposal returned for revision" followed by the remarks. Links to `drive.show`. |
| `app/Notifications/ConceptAwaitingReview.php` | To all admins. Payload: `submission_id`, `title`, uploader name. Links to `reviews.index`. |
| `app/Notifications/ProjectDeadline.php` | To project people. The constructor takes the submission and `int $days`. A positive number means "due in N days" and a negative number means "overdue by N days". Payload: `submission_id`, `title`, `target_date`, `days`. Links to `drive.show`. |
| `projects:remind` command | An `Artisan::command` closure in `routes/console.php`, scheduled `->dailyAt('07:00')`. |
| `resources/views/pages/notifications/⚡index.blade.php` | Livewire page at `notifications`, route name `notifications.index`, inside the `auth` + `verified` group. It lists the current user's notifications, newest first, and paginates them. Unread rows are shown with emphasis. Clicking a row marks it read and opens its link. A stock `flux:button` reads "Mark all read". |
| `Submission::people()` | Returns an Eloquent collection of the filer plus the proponent users, unique by id. |

Each notification class implements `ShouldQueue` and sets `$deleteWhenMissingModels = true`. `via()` returns `['database', 'mail']`, or only `['database']` when the notifiable's email is not verified.

### Changed

| File | Change |
|------|--------|
| `app/Concerns/ReviewsSubmissions.php` | After the review transaction commits, `saveReview` sends `ConceptReviewed` to `$submission->people()` without the reviewer. |
| `resources/views/pages/submissions/⚡create.blade.php` | After the save transaction commits, if a concept file was uploaded and the project is `awaiting_review`, send `ConceptAwaitingReview` to `User::where('role', 'admin')->get()` without the uploader. |
| `resources/views/layouts/app/sidebar.blade.php` | A "Notifications" item with a `bell` icon goes in the Overview group. Its badge shows the unread count, using the same badge pattern and 99+ cap as Reviews. The phone menu dot also shows when there are unread notifications. |
| `resources/views/pages/users/⚡index.blade.php` | Deleting a user also deletes that user's notifications. The `notifications` table is polymorphic with no foreign key, so the rows would otherwise be left behind. |

## Data flow

### Review decision

1. An admin saves a review in `saveReview`. The transaction commits.
2. `Notification::send($submission->people()->except($reviewerId), new ConceptReviewed(...))`.
3. The queue worker writes the database row and sends the email.
4. The list row reads "Concept proposal passed: <title>" or "Returned for revision: <title>". Clicking it opens `drive.show`.

### New concept to review

1. A faculty member saves the project form with a concept file. The transaction commits.
2. If `awaiting_review` is true, every admin except the uploader is notified. This covers a first upload and a re-upload after a return. A passed concept that is replaced does not go back for review, so no notification is sent for it.
3. The list row opens `reviews.index`.

### Deadline reminders

`projects:remind` runs daily at 07:00 in the app timezone (`Asia/Manila`).

1. Load candidates: `Submission::whereNotNull('target_date')->whereNull('terminal_uploaded_at')`.
2. For each candidate, `$days = today()->diffInDays($submission->target_date, false)`.
3. Send `ProjectDeadline($submission, $days)` to `$submission->people()` when:
   - `$days === 30` or `$days === 7`, or
   - `$days <= -1 && (-$days - 1) % 7 === 0`, which is overdue by 1, 8, 15, 22 days and so on.

The command keeps no state, so a changed `target_date` takes effect on the next run. The command carries a `ponytail:` comment for this limit: if cron misses a day, that day's reminder is skipped. The fix, if that ever matters, is to record each milestone as it is sent.

The candidate query loads every open project with a target date and filters in PHP. This is fine at the portal's size. A second `ponytail:` comment names the upgrade: compute the matching target dates and filter in SQL.

## Error handling and edge cases

- **Unverified email:** the notification goes to the database channel only.
- **Project with no proponent accounts:** `people()` still returns the filer.
- **Failed email delivery:** the queued job retries with Laravel's defaults. The database notification is written separately, so the in-app notice is not lost.
- **Model deleted before the job runs:** `$deleteWhenMissingModels` drops the job instead of failing it.
- **Privacy:** the list page reads only `auth()->user()->notifications()`. A user can't see or mark another user's notifications.
- **Activity log:** no entries are added. Every notification follows an action that is already logged.

## Testing

Pest feature tests in `tests/Feature/NotificationsTest.php`, run on in-memory SQLite with `Notification::fake()`:

- Passing a review sends `ConceptReviewed` to the filer and the proponents, and not to the reviewer.
- Returning a review carries the remarks in the payload.
- Uploading a concept sends `ConceptAwaitingReview` to every admin. Uploading only a later document sends nothing.
- `projects:remind`, using `travelTo()`:
  - Sends at 30 days, 7 days, overdue by 1, and overdue by 8.
  - Sends nothing at 29 days, overdue by 2, or once the terminal report is uploaded.
- An unverified user gets the database channel only.
- Notifications page:
  - Lists only the user's own notifications.
  - "Mark all read" clears the unread count.
  - Redirects guests to login.
- Deleting a user deletes their notifications.

Before done:

- `composer ci:check` passes. PHPStan can't see SFC pages, so check that new `app/` code used only from ⚡ pages does not trip unused-code rules.
- Run `vendor/bin/pint` on changed files under `app/`, `routes/` and `tests/` only. Do not run it on ⚡ pages.

## Deployment

Add a README section:

- Cron entry: `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`
- A queue worker, `php artisan queue:work`, kept running by supervisor or the host's process manager.
- SMTP settings from ISU IT: `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`. Until these are set, `MAIL_MAILER=log` keeps in-app notifications working and writes emails to the log.
