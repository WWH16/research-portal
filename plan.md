# Plan: store submission PDFs on Google Drive

## Why

Laravel Cloud charges for the private bucket behind the `submissions` disk. Submission PDFs are the biggest thing in that bucket, and they grow every year. Google Drive storage under an ISU account costs the portal nothing, so the PDFs move there for good.

The database stays on Laravel Cloud MySQL. Nothing about projects, users, reviews, or relationships changes.

## How it works today

- Every project document is saved through one disk, `submissions`, in `Submission::attachDocument()` (`app/Models/Submission.php`).
- The `submissions` table stores only a file path per stage (`concept_path`, `detailed_path`, `midyear_path`, `terminal_path`), for example `submissions/abc123.pdf`.
- The route `submissions.document` (`routes/web.php`) checks `can:view,submission`, then streams the file with `Storage::disk('submissions')->response(...)`.
- `Submission::uploadTimes()` reads `lastModified()` from the same disk.
- On Laravel Cloud, a private bucket is attached under the disk name `submissions` and replaces the local driver.

Because all reads and writes go through the `submissions` disk, the switch is mostly a change of what sits behind that disk name.

## Goals

- Submission PDFs are stored on Google Drive, permanently.
- The database keeps storing the file path, exactly as now. Drive replaces the bucket behind the same disk.
- Faculty and admins upload and open documents exactly as they do today.
- The portal still decides who can open a document. Users need no Google Drive access of their own.
- An admin connects Google Drive from inside the portal, without a developer and without editing `.env`.

## Non-goals

- No year folders in Drive. `submissions.year` already holds the year, and the portal filters by it. All PDFs go into one flat folder with the random names `store()` gives them.
- No moving the database to Google Drive or Google Sheets.
- Profile photos stay on the `public` disk.
- Publications are not part of this. They store metadata and a link, not files.
- The existing in-portal "Drive" feature (`DriveItem`, `resources/views/pages/drive/`) is a different thing and is not touched.

## Anti-goals (a failure even if it works)

- Saving a public or "anyone with the link" Drive URL in the database. That would let anyone with the link open a PDF and skip the portal's permission checks.
- Asking Google for access to the admin's whole Drive. The portal asks only for the files it creates.
- Losing PDFs when the connection breaks or changes hands.

## Decisions made

1. **Connection lives in the portal (option 2).** An admin page connects a Google account through Google sign-in. The portal saves the refresh token, encrypted, in the database.
2. **Linkage is the file path, not a URL.** The `*_path` columns stay as they are.
3. **Permanent storage.** PDFs stay on Drive for good. There is no yearly cleanup.
4. **One central Google account for the whole portal.** The portal will have several admins, so storage must not depend on any one admin's personal account. The Research Office owns one ISU Google account for this, for example `research.office@isu.edu.ph`. The portal holds a single connection to it.
5. **The account cannot be switched once PDFs exist.** Reconnecting is allowed only with the same account, for example after the token is revoked. The portal rejects a sign-in with any other account while PDFs are stored, because `drive.file` would stop it from reading the old account's files.

## Design

### Admin page: Administration → Google Drive

- A Livewire page in `resources/views/pages/`, behind the `admin` middleware, next to Backup and Restore.
- Every admin sees the same status, for example "Connected as research.office@isu.edu.ph", or "Not connected".
- **Connect** sends the admin to Google sign-in. The admin signs in as the central account, not as themselves. This happens once. Other admins never need the central account's password.
- If the sign-in returns a different account than the one already saved, and any PDFs exist, the portal rejects it and keeps the old connection.
- **Disconnect** removes the saved token. It is meant for revoking access, not for switching accounts.
- Connecting asks the admin to confirm their password first, the same way Backup and Restore does.
- Connect and disconnect are recorded in the activity log.

### Google sign-in

- Use Laravel Socialite (first-party) with the Google driver. Request the `https://www.googleapis.com/auth/drive.file` scope, offline access, and `prompt=consent`, so Google returns a refresh token.
- `drive.file` lets the portal see only files and folders it created. On first connect the portal creates a "Research Portal" folder and saves that folder's ID.
- The Google Cloud project must be registered under ISU's Google Workspace with user type **Internal**. An app left in "Testing" under an external project gets refresh tokens that expire after 7 days.
- The client ID and secret go in `.env` once (`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`) and are read through `config/services.php`. This is the only developer step.

### Storing the connection

- A small table for the one connection: Google account email, refresh token (with the `encrypted` cast), Drive folder ID, and the admin who connected it.
- The connection belongs to the portal, not to the admin who clicked Connect. Every admin and faculty member uses it. Make the connecting-admin column nullable with `nullOnDelete()`, so removing that admin's portal account never removes or breaks the connection.
- Make it with `php artisan make:model GoogleDriveConnection -mf`.

### The disk

- Add a Flysystem v3 Google Drive adapter. Candidate: `masbug/flysystem-google-drive-ext`. Confirm it supports Laravel 13 before adding it.
- Register a `google` driver with `Storage::extend()` in `AppServiceProvider`. It builds a Google client from the saved refresh token and uses the saved folder ID as its root.
- Point the `submissions` disk at that driver in `config/filesystems.php`.
- `attachDocument()`, `uploadTimes()`, and the `submissions.document` route keep calling `Storage::disk('submissions')` and should need no change.

### When Drive is not connected or the token stops working

- `attachDocument()` already throws `RuntimeException('The document could not be stored.')` when a write fails. The upload page catches it and tells the faculty member that document storage is unavailable and to contact the Research Office.
- Admins see a red warning on the Google Drive page and on Backup and Restore, matching the existing missing-bucket warning.
- Opening a document returns the existing 404 path if the file cannot be read. Consider a clearer error page.

### Laravel Cloud

- Laravel Cloud replaces the `submissions` disk with the attached bucket. Detach the bucket from the `submissions` name, or the Drive driver will never be used.
- Before detaching, copy the existing PDFs to Drive (next section).

### Moving existing PDFs

- An artisan command, made with `php artisan make:command`, copies every file from the old bucket to Drive under the same path, then verifies each one exists on Drive.
- The bucket is attached for the run under a temporary disk name, for example `submissions-bucket`.
- The command must be safe to run again: it skips files already on Drive.
- Delete the bucket only after the command reports every file copied and someone has opened a few documents in the portal.

### Backups

- The planned portal backup (`docs/backup-and-restore.md`) zips every file on the `submissions` disk. It still works, but now downloads every PDF from Drive, so it gets slower. Update that document when this ships.

## Security

Already covered by the design above:

- PDFs are never shared by link. Every download goes through the `submissions.document` route and its `can:view,submission` check.
- The portal asks only for `drive.file`, so a stolen token reaches the portal's own files and nothing else in the account.
- The refresh token is stored with the `encrypted` cast.
- Only admins reach the Google Drive page, they confirm their password first, and every connect and disconnect is logged.

Must be added when building:

1. **Keep the OAuth state check.** Use Socialite's normal flow, never `stateless()`, so a forged callback cannot attach an attacker's Google account.
2. **Allow ISU accounts only.** Send `hd=isu.edu.ph` to Google, and also check the returned email's domain on the server. The `hd` hint alone can be bypassed.
3. **Never expose the token.** Add the token column to the model's `$hidden`. Never log it or put it in an exception message.
4. **Keep the token out of portal backups.** The planned backup zip exports every table. Leave the connection table out, so a downloaded zip never carries the token. After a restore, an admin reconnects.
5. **Revoke on disconnect.** Disconnect also revokes the token at Google, not only deletes it locally.
6. **Never change sharing on Drive files.** The portal creates its folder and files with General access set to **Restricted**, owned by the central account only, and never adds permissions. Files inherit their folder's sharing, so the portal's folder must sit at the top of the central account's My Drive, never inside a folder shared with "Isabela State University" or "Anyone with the link".
7. **Keep serving PDFs safely.** Keep the existing upload validation (file type and size). Serve downloads with the PDF content type, not one taken from Drive metadata.
8. **Changing `APP_KEY` breaks the saved token.** Note in the docs that an admin must reconnect after a key rotation.

Outside the code. This is an accepted risk that the project cannot control. ISU IT and the Research Office own it, and the items below are recommendations to them:

- Turn on 2-step verification for the central account.
- Keep its password with as few people as possible, in a password manager, never in chat or email.
- Anyone who signs in to the central account in a browser can read every PDF and skip the portal's permissions. Treat the account like the database password.
- Research documents may hold personal data under the Data Privacy Act of 2012. Use ISU's Google Workspace account, which falls under ISU's agreement with Google, not a personal Gmail account.

## Tests (Pest, `tests/Feature/`)

- Only admins can open the Google Drive page. Faculty get 403.
- The Socialite callback saves the email, folder ID, and an encrypted refresh token. Mock Socialite and the Drive client.
- Disconnect removes the connection and logs it.
- Signing in with a different account while PDFs exist is rejected, and the old connection stays.
- Signing in with a non-`isu.edu.ph` account is rejected.
- The refresh token never appears in the model's array or JSON output.
- An upload while Drive is not connected shows the friendly error and saves no path.
- Existing document upload and download tests keep passing with `Storage::fake('submissions')`.
- The migration command copies files, skips files already present, and reports missing ones.

## Open questions for the project owner

1. **The central account.** Get the Research Office account from ISU IT, and confirm that IT will not delete it when staff change.
2. **Storage limit.** Confirm how much Drive space that account has.

## Left to the builder

- The exact adapter package and how it maps paths to Drive file IDs.
- Table and column names for the connection.
- Page layout and wording, using Flux components.
- Whether to cache the Google access token between requests.
- Whether the adapter's `delete()` trashes or permanently deletes a replaced file. Trashed files still count against the account's storage for 30 days.
