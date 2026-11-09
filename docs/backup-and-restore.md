# Backup and restore

The portal holds two kinds of data:

- **The database:** projects, users, reviews, filing options and the activity log.
- **Uploaded files:** project documents on the Research Office's Google Drive, and profile photos on the `public` disk. The portal uses no bucket for documents.

The portal has no backup page of its own. Laravel Cloud backs up the database, and Google Drive keeps the documents.

## Setting up Laravel Cloud

Do this once, before the first deploy.

1. In Laravel Cloud, open **Organization**, then **Resources**, then **Databases**, and open the portal's database cluster.
2. In its **Backups** section, choose **Settings**, turn on **Backups**, and keep them for at least 7 days.
3. Add a queue worker to the environment, as described in [Notifications](notifications.md). Every notification email goes through the queue.
4. Redeploy the environment.

Don't attach a bucket for documents. They go to Google Drive, which replaced the bucket to save its cost.

## Storing documents on Google Drive

Project documents are stored on the Research Office's Google Drive in `Research Drive/<college code>/<project title> (<code>)/`, one folder per project. Memos are in `announcements` and profile photos in `profile-images`. Documents uploaded before these folders existed stay in `submissions`. Each saved path starts with `drive-<account number>/`, so a file always opens from the account that saved it. Until an admin saves and connects a storage account, uploads are refused.

### Setting it up

1. In Google Cloud Console, enable the **Google Drive API**. Then create an OAuth client of type **Web application**, with the redirect URI `<APP_URL>/google-drive/callback`. Use the **Internal** user type with an ISU Workspace account, or **External** and **Publish app** with a Gmail account: an app left in "Testing" loses its connection every 7 days.
2. Set these in the environment variables:
   - `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` from the OAuth client.
   - `GOOGLE_REDIRECT_URI`, the redirect URI above.
3. Run `php artisan migrate`.
4. Sign in as an admin and open **System → Google Drive**. Save the storage account, the one Google account whose Drive keeps every document. Then, straight away, choose **Connect Google Drive**, confirm your password, and sign in to Google as that account. Between saving and connecting, uploads fail, so do both in one sitting. The portal refuses any other account, and creates a "Faculty Research Portal" folder there, shared with no one.
5. Upload a test document to a project and open it, to check that uploads reach Google Drive.

### Keeping it working

- **One account for the whole portal.** Every admin uses the same connection. Removing the admin who connected it doesn't break it.
- **One saved account.** Every admin's Connect must sign in as the storage account saved on the page. Use **Reconnect** after the account's password changes.
- **Changing the account,** for example when its Drive is full: choose **Change account**, type the new address twice, and confirm your portal password. New uploads go to the new account and nothing moves. Project documents, memos, and profile photos already on the old account stay there and still open, because the portal keeps its access. The page lists those earlier accounts with how many of each they hold. **Keep those Google accounts; don't delete them or their folders.** Then connect the new account.
- **If the connection breaks,** uploads stop with a "Document storage is unavailable" message, and opening a document shows the same. The Google Drive page shows a red warning. Reconnect the same account to fix it. No files are lost.
- **Changing `APP_KEY`** makes the saved Google token unreadable. Reconnect after any key change.
- **Disconnect** revokes the portal's access at Google. The saved account and the files on Drive stay.
- **Replaced documents** go to the Drive trash, so they still count against storage for 30 days.
- **Leave the folders as they are.** The portal finds each document by its saved path, so don't move or rename files or folders by hand. A renamed project or college keeps its old folder name on Drive, and its documents still open.

### Moving older documents into folders

Documents in `submissions` keep working where they are. To file them into project folders, run `php artisan drive:organize` from the environment's **Commands** tab in Laravel Cloud. On its own it only lists what would move. Add `--force` to move the files and update their saved paths, and add `--project=<id>` to try one project first. Run it when nobody is uploading or reviewing. Laravel Cloud stops a command after 30 minutes. Running it again continues the work, because documents already in a folder are left alone.

Take a database backup first, but only as protection against a database problem. Restoring a backup from before the run does not undo the run: the files stay in their new folders while the restored paths point to the old place, so every moved document stops opening.

The command refuses `--force` when its database is on the same computer. Never get around that: it would move the files on Google Drive but update only that computer's paths, and production would lose those documents.

## Database backups on Laravel Cloud

Laravel Cloud takes a database backup every day between 3 AM and 6 AM EDT and keeps it for 1 to 30 days. The backups cover the database only, not the documents on Google Drive.

### Taking a backup by hand

Before a migration, a large import or any deploy that changes data, open the cluster's **Backups** section and choose **New backup**. Give it a name that says why, such as "Before the 2027 categories import".

### Restoring a backup

1. In Laravel Cloud, open **Organization**, then **Resources**, then **Databases**, and open the portal's database cluster.
2. In the **Backups** section, open the backup's **...** menu and choose **Restore**.
3. Name the new cluster and confirm. Cloud restores the backup into the new cluster and leaves the current data as it is.
4. Attach the restored database to the environment and redeploy. Delete the cluster you no longer need once you're sure.

### Keeping a copy outside Laravel Cloud

Cloud backups live only in the Laravel Cloud account. To keep a copy the university controls, restore a backup into a new cluster, turn on that cluster's public endpoint, and export it with TablePlus or `mysqldump`. Then turn the endpoint off and delete the temporary cluster.
