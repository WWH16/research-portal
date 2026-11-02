# Notifications

The portal tells people about what needs them, in the portal (the bell in the sidebar) and by email. Emails come from the portal's own Gmail account, **isucyn.facultyresearchportal@gmail.com**.

Faculty hear about:

- **Account approved.** A welcome in the portal, and an email saying they can sign in.
- **Concept review decision.** When the Research Office passes or returns a concept proposal, the project's filer and proponents are told. A return includes the remarks.
- **Project dates changed.** When the Research Office moves a project's dates.
- **Completion date reminders.** Every morning at 07:00 (Asia/Manila), each open project's people are reminded 30 and 7 days before its completion date, the day after it passes, and then every 7 days until the terminal report is uploaded.

The Research Office hears about:

- **A project completed** (its first terminal report), in the portal only.
- **A project running late**, the day after its completion date passes without a terminal report, in the portal only.
- **Google Drive full or disconnected**, in the portal and by email.

Every member also gets, by email only:

- **Verify your email**, on sign-up, after changing their email address on My Profile, or when they press Resend.
- **Reset your password**, from Forgot password.
- **Password changed**, after they change it on the Security page or through a reset link, or when the Research Office sets a new one on Manage Users. It never shows the new password.

New concept proposals and sign-ups waiting for approval have no notice: the Concept Reviews and Manage Users badges count them live.

Members choose which faculty notices also come by email under My Profile → Email me about. Members with an unconfirmed email address get the notice in the portal only.

## Setting up Laravel Cloud

Notices in the portal are saved straight away. Emails go through the queue worker, and reminders and late notices need the scheduler; without them no email is sent and no reminder goes out, and nothing reports an error.

Do this once, in the Laravel Cloud environment:

1. Check that the environment has a queue worker. If not, add one.
2. Turn on the scheduler for the environment. It runs `projects:remind` every morning at 07:00.
3. Check that `APP_URL` is the portal's public address. The Open links in emails and in the bell are built from it, and the university seal at the top of each email loads from it.
4. Sign in to **isucyn.facultyresearchportal@gmail.com**, turn on 2-Step Verification, and create an **App Password** under Google Account → Security → App passwords. Gmail refuses the account's normal password over SMTP.
5. Set these environment variables, with the App Password (16 letters, spaces removed) as `MAIL_PASSWORD`:

   ```
   MAIL_MAILER=smtp
   MAIL_SCHEME=smtp
   MAIL_HOST=smtp.gmail.com
   MAIL_PORT=587
   MAIL_USERNAME=isucyn.facultyresearchportal@gmail.com
   MAIL_PASSWORD=
   MAIL_FROM_ADDRESS="isucyn.facultyresearchportal@gmail.com"
   MAIL_FROM_NAME="Faculty Research Portal"
   ```

   Gmail only sends as the account that signs in, so `MAIL_USERNAME` and `MAIL_FROM_ADDRESS` must be the same address.
6. Redeploy the environment.

A failed email is tried 3 times, a minute apart.

## Checking it by hand

- `php artisan projects:remind` sends today's reminders straight away.
- `php artisan schedule:list` shows `projects:remind` at 07:00.
- `php artisan queue:failed` lists emails that failed after their retries.
