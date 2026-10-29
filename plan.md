# Notifications, live counts and faster document opening

Working plan. Not committed. Each phase is one commit (Phase 4 is one per notice), with no AI attribution in any message. Every phase runs `composer ci:check` and is checked in the browser at laptop (1280px) and phone (375px) widths before its commit.

## Reminders for every phase

UI and code:
- Pages are Livewire 4 single-file components in `resources/views/pages/`, with names starting with `⚡`. Register them in `routes/` with `Route::livewire(...)`.
- UI uses Flux components (`<flux:button>`, `<flux:input>`, and so on) before any custom HTML or CSS. Buttons are stock `flux:button`: never hand-styled, never a custom button component.
- Auth goes through Fortify. Do not write your own login, registration, or password controllers.
- Roles are stored in the `role` column (`admin` or `faculty`). Never let user input set `role` directly. The column is fillable, so pass only the fields you intend to save.
- Docs: when Laravel Boost's `search-docs` tool is available, use it to check the current API for the installed versions before writing code.
- Create files with artisan generators (`make:notification`, `make:migration`, `make:command`, `make:test --pest`).

Checks and commits:
- Every phase adds Pest feature tests in `tests/Feature/` and passes `composer ci:check`.
- Run Pint only on changed PHP files, never on `⚡` page files.
- Migrations only add; never suggest `migrate:fresh`, `refresh`, `reset` or `db:wipe`.
- Data that can be rebuilt goes in the cache, not a new column.
- Commit only the phase's own files. Never commit `plan.md`. No `Co-Authored-By` or other AI attribution.

## What notifies today

Every notice is queued. Email goes only to confirmed addresses. The portal copy appears on Notifications and in the sidebar bell count.

| Event | Who hears | In the portal | Email | Where it's sent |
|---|---|---|---|---|
| New concept proposal, or one re-uploaded while waiting | Every admin except the uploader | Yes | Yes | `submissions/⚡create.blade.php`, `ConceptAwaitingReview` |
| Concept proposal passed or returned (with remarks) | The project's filer and proponents, except the reviewer | Yes | Yes | `Concerns/ReviewsSubmissions.php`, `ConceptReviewed` |
| Target date in 30 days, in 7 days, the day after it passes, then weekly until the terminal report | The project's filer and proponents | Yes | Yes | `routes/console.php` `projects:remind`, daily at 07:00, one server, `ProjectDeadline` |
| A sign-up confirmed their email and waits for approval | Every admin | Yes | No | `FortifyServiceProvider`, `SignupAwaitingApproval` |
| A sign-up was rejected | That person | No (they can't sign in) | Yes | `users/⚡index.blade.php`, `AccountRejected` |
| Google refused an upload because the Drive is full (first refusal only) | Every admin | Yes | Yes | `Services/GoogleDrive.php`, `DriveFull` |
| Confirm your email address | The person signing up | No | Yes | Fortify |
| Reset your password | The person asking | No | Yes | Fortify |

## What doesn't notify yet

Marked **(this plan)** where a phase below builds it. The rest stay as they are for now.

| Event | Today | |
|---|---|---|
| **Account approved** | Nothing; a new member only finds out by trying to sign in | **(this plan)** |
| **Project dates changed by the Research Office** | Nothing; reminders silently move | **(this plan)** |
| **Google Drive disconnected, access removed, or folder deleted** | Shows on the Google Drive page only; uploads fail meanwhile | **(this plan)** |
| Added as a proponent on someone else's project | Nothing | later |
| New announcement posted | Dashboard and Announcements only | later |
| Password changed, email changed, or two-factor turned off | Activity Log only | later |
| Terminal report uploaded (project completed) | Nothing | later |
| A concept proposal has waited more than N days | Nothing | later |

## Phases

### Phase 1: Notifications read right
- New notices name people short ("JA Rocel", not "JOHN ANSLEY ROCEL"). Titles already use `displayTitle()`.
- A concept re-uploaded while waiting says "Corrected concept proposal to review: …", so it doesn't look like a duplicate.
- A gold dot marks unread rows; screen readers keep hearing "Unread:".
- The "Showing 1 to 2 of 2 results" footer hides until there's a second page.
- The page gets the ISU seal header, like every other page.
- Tests: short names in each sender, the re-upload wording, the hidden pager.
- Commit: `fix(notifications): short names, distinct re-upload notice and an unread dot`

### Phase 2: Tidy notices already sent
- A one-time command, `php artisan notifications:tidy-names`, rewrites existing notices that name someone in all caps to the short form, matching each name against the member it belongs to. `--dry-run` lists what it would change, without changing anything.
- Run once on production after the deploy. Notices only change their wording; read state and links stay.
- Tests: rewrites an all-caps name, leaves already-short names and other text alone, `--dry-run` changes nothing.
- Commit: `feat(notifications): add a command to shorten names in notices already sent`

### Phase 3: Delete, undo and mark as unread, all by choice
- Nothing is ever deleted automatically.
- Each notice gets a menu: **Mark as unread** (or **Mark as read**) and **Delete**.
- The header gets **Delete all read**, behind a confirm dialog that says how many.
- After any delete, a toast offers **Undo** for 10 seconds. Undo puts the notices back exactly as they were (same text, link, read state and time). Deleted notices wait in the cache until the undo window closes; nothing new in the database.
- A member can act only on their own notices.
- Tests: delete and undo restore the same row, can't touch another member's, Delete all read keeps unread ones, mark as unread raises the sidebar count.
- Commit: `feat(notifications): let members delete, undo and mark notices unread`

### Phase 4: Missing notices
One commit each, through the existing `PortalNotification` (portal plus email to confirmed addresses), each with a test.
1. **Account approved:** to the member. "Your account is approved. You can now sign in." Links to the dashboard. Sent from `approve()` in Manage Users.
   Commit: `feat(notifications): tell new members when their account is approved`
2. **Dates changed:** to the project's filer and proponents, when the Research Office saves new dates. "New dates for :title: starts :start, due :due." Links to the project. Not sent when nothing actually changed.
   Commit: `feat(notifications): tell proponents when the Research Office changes their dates`
3. **Google Drive disconnected:** to every admin, the first time the portal marks the storage account not connected (access removed, password changed, or folder deleted). "Google Drive :email is disconnected, so documents can't be uploaded or opened. Connect it again on System → Google Drive." Once per incident: reconnecting resets it.
   Commit: `feat(notifications): tell admins when Google Drive disconnects`

### Phase 5: Email preferences
- My Profile gets an **Email me about** section with switches. Portal notices always arrive; the switches only affect email.
  - Deadline reminders (on by default)
  - Concept proposal decisions (on by default)
  - Project date changes (on by default)
  - For admins: new concept proposals to review (on by default)
- Always emailed, no switch: account approved or rejected, Google Drive full or disconnected, Fortify's confirm-email and password-reset emails.
- Stored as one `email_preferences` JSON column on `users` (new migration, default: everything on). This is lasting data, so a column, not the cache.
- `PortalNotification::via()` checks the member's switch for that kind of notice.
- Tests: switching a kind off stops its email but keeps the portal notice; the always-sent kinds ignore the switches.
- Commit: `feat(notifications): let members choose which notices also come by email`

### Phase 6: Live count on Concept Reviews
- One small JSON route, for signed-in members only, returns the sidebar counts: concept reviews, waiting sign-ups, unread notices and projects needing revision. Admin-only counts come back as zero for faculty.
- The sidebar's existing Alpine state asks for it every 60 seconds, only while the tab is visible, and right away when the tab comes back into view.
- This phase wires up the **Concept Reviews** badge and the open Concept Reviews list (it refreshes when the count changes).
- Tests: the route's counts, faculty get no admin counts, guests are refused.
- Commit: `feat(reviews): keep the concept review count live without a reload`

### Phase 7: Live count on Manage Users
- The same route's waiting sign-up count updates the **Manage Users** badge, and an open Manage Users list refreshes when it changes.
- Commit: `feat(users): keep the sign-up approval count live without a reload`

### Phase 8: Live count on Notifications and the phone dot
- The unread count updates the **Notifications** bell badge and the phone menu's dot; an open Notifications page shows new notices when the count rises.
- Commit: `feat(notifications): keep the unread count and phone dot live without a reload`

### Phase 9: Faster document opening
- Measured on the current account: checking the file exists takes 3.1s, then opening it 1.4s, about 4.5s before the first byte.
- Drop the separate `exists()` check; a "not found" from the open becomes the same 404.
- Remember each document's Google file ID in the cache after its first open, so later opens skip finding it by name. A replaced or deleted document drops its entry; a stale ID falls back to finding it by name.
- Measure before and after on the real account. Keep the 404 and "storage unavailable" behaviour tested.
- Commit: `perf(documents): open documents with fewer Google round trips`

### Phase 10: Notices arrive without a queue worker (done)
- Found: every notice was queued whole, and no worker was running, so 22 notices from Oct 28 never reached anyone.
- The portal copy now saves straight away (`viaConnections()`); only the email waits for the queue. The Drive-full email gets its own subject.
- Commit: `fix(notifications): save the portal copy at once and queue only the email`

### Phase 11: Faster review panel (done)
- Found: opening a review asked Google for each document's modified time, about 950ms every time.
- Each upload time is now kept in the cache: new uploads record it as they save, older ones ask Google once. Later opens take about 1ms.
- Commit: `perf(reviews): open the review panel without asking Google for upload times`

## Not in this plan
- Checking production can send email at all: a queue worker on Laravel Cloud, and a real mailer instead of `MAIL_MAILER=log`.
- Opening an email's link marking the portal notice read.
- The "later" notices in the table above.
