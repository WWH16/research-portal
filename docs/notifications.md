# Notifications

The portal tells people about three things, in the portal (the bell in the sidebar) and by email:

- **Concept review decision.** When the Research Office passes or returns a concept proposal, the project's filer and proponents are told. A return includes the remarks.
- **Concept proposal to review.** When a concept proposal is uploaded and waits for a decision, every admin is told.
- **Target date reminders.** Every morning at 07:00 (Asia/Manila), each open project's people are reminded 30 and 7 days before its target date, the day after it passes, and then every 7 days until the terminal report is uploaded.

Members with an unconfirmed email address get the notice in the portal only.

## What the server needs

Notifications are queued, and reminders run on the scheduler, so production needs both of these running.

1. **The scheduler.** Add one cron entry for the web server user:

   ```
   * * * * * cd /path/to/research-portal && php artisan schedule:run >> /dev/null 2>&1
   ```

2. **A queue worker.** Keep `php artisan queue:work` running with supervisor or the host's process manager. Restart it after each deploy with `php artisan queue:restart`.

3. **Mail settings.** Ask ISU IT for SMTP details and set them in `.env`:

   ```
   MAIL_MAILER=smtp
   MAIL_HOST=
   MAIL_PORT=
   MAIL_USERNAME=
   MAIL_PASSWORD=
   MAIL_FROM_ADDRESS=
   ```

   Until these are set, keep `MAIL_MAILER=log`. In-app notifications still work, and the emails are written to `storage/logs/laravel.log`.

## Checking it by hand

- `php artisan projects:remind` sends today's reminders straight away.
- `php artisan schedule:list` shows `projects:remind` at 07:00.
- `php artisan queue:failed` lists emails that failed after their retries.
