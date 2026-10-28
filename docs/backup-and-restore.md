# Backup and restore

The portal holds two kinds of data:

- **The database:** projects, users, reviews, filing options and the activity log.
- **Uploaded files:** project documents on the private `submissions` disk, and profile photos on the `public` disk.

There are two ways to get data back:

1. **The whole portal:** restore a portal backup from **Administration → Backup and Restore**.
2. **The database only:** restore a Laravel Cloud database backup from the Laravel Cloud dashboard.

## Setting up Laravel Cloud

Do this once, before the first deploy.

1. Attach a private bucket to the environment with the disk name `submissions`. It holds project documents and the portal backups.
2. Attach a public bucket with the disk name `public`. It holds profile photos.
3. Open the database cluster's **Backups** page and choose **Daily**. Laravel Cloud keeps backups for 7 days unless you change it.
4. Add a queue worker to the environment. Portal backups and restores run on the queue, so without a worker they never start.
5. Set `DB_QUEUE_RETRY_AFTER=2000` in the environment variables. A backup or restore can run for up to 30 minutes (1800 seconds). With the default of 90 seconds, a second worker picks up the same job while the first is still running.
6. Redeploy the environment.

Files uploaded before the move to buckets stay on the old server. Copy them into the buckets with a tool such as Cyberduck, keeping the same folders.

If the buckets are missing in production, the Backup and Restore page shows a red warning. Laravel Cloud clears the server disk on every deploy, so attach the buckets before anyone uploads.

## Storing documents on Google Drive

Once an admin saves a storage account, new project documents go to the Research Office's Google Drive instead of the `submissions` bucket. Each document's saved path starts with `drive-<account number>/`, so it always opens from the account that saved it. Documents saved before that stay on the `submissions` disk until the move command copies them.

### Setting it up

1. In Google Cloud Console, enable the **Google Drive API**. Then create an OAuth client of type **Web application**, with the redirect URI `<APP_URL>/google-drive/callback`. Use the **Internal** user type with an ISU Workspace account, or **External** and **Publish app** with a Gmail account: an app left in "Testing" loses its connection every 7 days.
2. Set these in the environment variables:
   - `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` from the OAuth client.
   - `GOOGLE_REDIRECT_URI`, the redirect URI above.
3. Run `php artisan migrate`.
4. Sign in as an admin and open **System → Google Drive**. Save the storage account, the one Google account whose Drive keeps every document. Then, straight away, choose **Connect Google Drive**, confirm your password, and sign in to Google as that account. Between saving and connecting, uploads fail, so do both in one sitting. The portal refuses any other account, and creates a "Research Portal" folder there, shared with no one.
5. Copy the older documents: `php artisan submissions:move-to-drive`. It copies every document still on the `submissions` disk to the storage account and updates each project's path. Run it until it reports `missing 0`. A second run skips documents already on Drive.
6. Open a few documents in the portal to check they load. Keep the `submissions` bucket attached until every document has moved.

### Keeping it working

- **One account for the whole portal.** Every admin uses the same connection. Removing the admin who connected it doesn't break it.
- **One saved account.** Every admin's Connect must sign in as the storage account saved on the page. Use **Reconnect** after the account's password changes.
- **Changing the account,** for example when its Drive is full: choose **Change account**, type the new address twice, and confirm your portal password. New uploads go to the new account and nothing moves. Documents already on the old account stay there and still open, because the portal keeps its access. The page lists those earlier accounts with how many documents each holds. **Keep those Google accounts; don't delete them or their folders.** Then connect the new account.
- **If the connection breaks,** uploads stop with a "Document storage is unavailable" message, and opening a document shows the same. The Google Drive page shows a red warning. Reconnect the same account to fix it. No files are lost.
- **Changing `APP_KEY`** makes the saved Google token unreadable. Reconnect after any key change.
- **Disconnect** revokes the portal's access at Google. The saved account and the files on Drive stay.
- **Replaced documents** go to the Drive trash, so they still count against storage for 30 days.

### Backups with Google Drive

- Portal backups must leave the `google_drive_connections` table out, so a downloaded zip never carries the Google token. After restoring a backup, reconnect Google Drive.
- Backups must read each document from the account that holds it (`App\Services\DocumentStorage::locate()`), so with Drive they download every document from Google and take longer.

## Portal backups

Portal backups are manual, from **Administration → Backup and Restore**. The page asks for your password again before it opens.

### Taking a backup

1. Choose **Back up now**.
2. Keep the page open. The download starts on its own when the backup is ready. Large portals can take several minutes.
3. Store the zip on a drive the university controls, outside Laravel Cloud.

Each backup is one zip:

- `database/<table>.jsonl`: every table, one JSON row per line. Sessions, cache, queued jobs, password reset tokens and the Google Drive connection are left out.
- `documents/...`: every file on the `submissions` disk.
- `photos/...`: every file on the `public` disk.

The portal keeps the 10 newest backups in the `backups/` folder of the `submissions` bucket and deletes older ones. Each download is recorded in the activity log.

### Restoring a backup

**A restore replaces all of the portal's data with the backup's.** Anything added or changed after the backup was taken is lost from the portal, except in the safety backup described below.

1. To restore a zip you kept outside the portal, choose it under **Upload a backup**, then **Upload**. It appears in the list of backups.
2. In the list, choose **Restore** on the backup, then confirm.
3. The portal first takes a safety backup named `portal-before-restore-...zip`, then restores. Keep the page open until it reports that the restore finished.
4. Tell users to sign out and back in. Accounts and passwords are now the ones in the backup.

What a restore does:

- Writes every file in the zip back to its disk. Files uploaded after the backup stay on the disk, but nothing in the restored data points to them.
- Empties every table except sessions, cache, queued jobs, password reset tokens and the migrations list, then fills them from the zip, in one transaction. If anything fails, the database is left exactly as it was.
- Records the restore in the activity log.

The portal refuses a backup made by a newer version of the portal than the one deployed. Deploy that version first, then restore. Older backups restore into the newer version, as long as every new column has a default.

Uploads go through Livewire and are limited to 500 MB. The server's PHP `upload_max_filesize` and `post_max_size` must also allow the file. For a larger zip, copy it into the `backups/` folder of the `submissions` bucket with a tool such as Cyberduck. It then appears in the list.

## Restoring a Laravel Cloud database backup

Laravel Cloud takes a database backup every day between 3 AM and 6 AM EDT and keeps it for 1 to 30 days. You can also take one by hand before a big change. It covers the database only, not the uploaded files.

1. In Laravel Cloud, open **Organization**, then **Resources**, then **Databases**.
2. Open the portal's database cluster, then **Backups**.
3. Choose **Restore backup** and pick the date. Cloud restores it into a new cluster and leaves the current data as it is.
4. Attach the restored database to the environment and redeploy.
