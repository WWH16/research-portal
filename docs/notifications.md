# Notifications

The portal tells people about three things, in the portal (the bell in the sidebar) and by email:

- **Concept review decision.** When the Research Office passes or returns a concept proposal, the project's filer and proponents are told. A return includes the remarks.
- **Concept proposal to review.** When a concept proposal is uploaded and waits for a decision, every admin is told.
- **Target date reminders.** Every morning at 07:00 (Asia/Manila), each open project's people are reminded 30 and 7 days before its target date, the day after it passes, and then every 7 days until the terminal report is uploaded.

Members with an unconfirmed email address get the notice in the portal only.

## Setting up Laravel Cloud

Every notification, the in-app one included, is sent by the queue worker. Without a worker nothing arrives, and nothing reports an error. Reminders also need the scheduler.

Do this once, in the Laravel Cloud environment:

1. Check that the environment has a queue worker. If not, add one.
2. Turn on the scheduler for the environment. It runs `projects:remind` every morning at 07:00. If the scheduler is off, no reminders go out.
3. Check that `APP_URL` is the portal's public address. The Open links in emails and in the bell are built from it.
4. Ask ISU IT for SMTP details and set them in the environment variables:

   ```
   MAIL_MAILER=smtp
   MAIL_HOST=
   MAIL_PORT=
   MAIL_USERNAME=
   MAIL_PASSWORD=
   MAIL_FROM_ADDRESS=
   ```

   Until these are set, keep `MAIL_MAILER=log`. With a worker running, in-app notifications still arrive, and the emails go to the log instead of being sent.
5. Redeploy the environment.

A failed email is tried 3 times, a minute apart.

## Checking it by hand

- `php artisan projects:remind` sends today's reminders straight away.
- `php artisan schedule:list` shows `projects:remind` at 07:00.
- `php artisan queue:failed` lists emails that failed after their retries.
