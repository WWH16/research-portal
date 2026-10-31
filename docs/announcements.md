# Announcements

The Research Office uses Announcements for two things: **schedules of submissions** (deadlines) and **memos** (official memorandum files). Phases 1 to 4 below are built; **Later, if wanted** and the email steps are not.

## Plan

### Phase 1: Data

- Add three columns with `php artisan make:migration`:
  - `due_on`, a date, for a schedule;
  - `attachment_path` and `attachment_name`, for the memo file.
- Add a factory and the casts.
- There is no `kind` column. An announcement with a due date is a schedule and one with a file is a memo; it can be both.

### Phase 2: Admins post them

- **Post announcement** opens a Flux modal with:
  - a title;
  - a description;
  - an optional **Submission deadline**;
  - an optional **Memo** file, PDF or Word.
- Edit and delete are on each row's menu, and delete asks to confirm.
- The memo is stored on Google Drive through `DocumentStorage`, in an `announcements` folder. Deleting or replacing an announcement removes the old file.
- An `AnnouncementPolicy` (`php artisan make:policy`) lets only admins post, edit and delete.
- The Activity Log records each post, edit and delete.

### Phase 3: Everyone reads them

- **Upcoming deadlines** comes first, soonest first, for example: "Concept proposals are due on November 15, 2026, in 17 days."
- Every announcement follows, newest first and paginated, with the poster's full name and the full date.
- A memo has an **Open memo** button, streamed from Drive like project documents.
- Once its date passes, a schedule leaves Upcoming and reads "Closed on November 15, 2026".

### Phase 4: Tell the faculty, in the portal only

- An `AnnouncementPosted` notice appears in the portal's notifications, written in full sentences, when an announcement is posted. Each edit that changes something sends an `AnnouncementUpdated` notice the same way, and the announcement's byline then says when it was edited. The form says who will be told before the admin saves.
- **No email.** The notice sets `EMAILED = false`, as `ProjectCompleted` does, and My Profile gets no new email switch.

### Later, if wanted

- A "next deadline" card on the faculty dashboard.
- A reminder 3 days before a deadline, from the daily `projects:remind` command.

## Why announcements are not emailed

The portal sends mail from a free Gmail account (`isucyn.facultyresearchportal@gmail.com`), which allows about 500 recipients a day. A single announcement to every faculty member could use most of that, leaving too little for account approvals, verification links and deadline reminders.

Sending one email to everyone as BCC does not help: Gmail counts each recipient, not each message. Bulk BCC mail is also more likely to be flagged as spam.

## Turning email on later

Work through these in order, and stop once the daily limit is no longer a problem:

1. Add `announcements` to `User::EMAIL_KINDS`, give the notice `EMAIL_KIND = 'announcements'`, and remove `EMAILED = false`. Faculty can then turn these emails off under **Email me about** on My Profile, and only those who keep it on are emailed.
2. Send through a mail service Laravel supports out of the box: Amazon SES (about $0.10 per 1,000 emails), Mailgun, Postmark or Resend. This is an `.env` change, not new code. See `docs/notifications.md` for the current mail setup.
3. As a last resort, queue the emails at about 400 a day and send the rest the next morning. This is a poor fit for deadlines, because some faculty would hear a day late.
