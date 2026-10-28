# Sign-up Approval Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** New sign-ups wait for an admin to approve them before they can use the portal.

**Architecture:** Two nullable timestamps on `users`, `approved_at` and `rejected_at`, in the same style as `email_verified_at`. If neither is set, the account is pending. An `approved` route middleware sends pending and rejected users to one notice page. Admins approve and reject from Manage Users. Admins get a portal-only notification, and a rejected user gets one email.

**Tech Stack:** Laravel 13, Fortify, Livewire 4 single-file pages, Flux, Pest (PHPUnit-style classes).

**Spec:** Decided in chat on 2026-10-28; summarized under Global Constraints.

## Global Constraints

- Sign-ups start pending. Every account that exists when the migration runs becomes approved.
- Accounts an admin creates on Manage Users are approved at creation, the same way they skip email verification.
- Seeded accounts (`AdminUserSeeder`, `DatabaseSeeder`) are approved.
- Rejected accounts stay in the database, so the same email cannot sign up again. An admin can still delete them.
- `approved_at` and `rejected_at` are never fillable and never come from user input. The same rule applies to `role`.
- The admin sign-up alert is portal-only: `via()` returns `['database']`.
- The only email this feature sends is "Account rejected", and only to a verified address.
- UI uses stock Flux components. Never hand-style a button.
- Never run Pint on `⚡` pages.
- Commits have no `Co-Authored-By` trailer.
- Test command: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest`

## Review Focus

- An admin account somehow left unapproved would lock every admin out. Seeders and the migration must approve, and a test pins the seeded admin.
- A pending user who is also unverified must see the verify-email page first, not the approval notice.
- A user who is already approved and opens the notice page by URL is sent to the dashboard.
- Rejecting an unverified account sends no email (its address may be fake).
- Approving a rejected account by mistake works, and clears `rejected_at`.

---

### Task 1: Approval status and gate

**Files:**
- Create: `database/migrations/<timestamp>_add_approval_to_users_table.php` (via `php artisan make:migration add_approval_to_users_table`)
- Create: `app/Http/Middleware/EnsureUserIsApproved.php`
- Create: `resources/views/pages/auth/approval-notice.blade.php`
- Modify: `app/Models/User.php`, `database/factories/UserFactory.php`, `database/seeders/AdminUserSeeder.php`, `database/seeders/DatabaseSeeder.php`, `bootstrap/app.php`, `routes/web.php`, `routes/settings.php`, `resources/views/pages/users/⚡index.blade.php` (admin create approves)
- Test: `tests/Feature/Auth/SignupApprovalTest.php`

**Interfaces:**
- Produces: columns `users.approved_at`, `users.rejected_at` (nullable timestamps, cast `datetime`); `User::isApproved(): bool`, `User::isRejected(): bool`, `User::scopePendingApproval(Builder $query): void` (neither timestamp set); factory states `pending()`, `rejected()` (default state is approved); middleware alias `approved`; route `approval.notice` (GET `/approval`).

- [ ] **Step 1: Write failing tests** in `SignupApprovalTest`:
  - `test_new_sign_ups_are_pending`: register through `register.store`, then `assertNull($user->approved_at)` and `assertNull($user->rejected_at)`.
  - `test_pending_users_are_sent_to_the_approval_notice`: a `pending()` user getting `dashboard` is redirected to `approval.notice`, which shows "Waiting for approval".
  - `test_rejected_users_see_that_their_sign_up_was_not_approved`: a `rejected()` user getting `approval.notice` sees "Sign-up not approved".
  - `test_unverified_pending_users_verify_their_email_first`: `pending()->unverified()` getting `dashboard` is redirected to `verification.notice`.
  - `test_approved_users_skip_the_notice`: an approved user getting `approval.notice` is redirected to `dashboard`.
  - `test_pending_users_cannot_open_settings`: `pending()` getting `profile.edit` is redirected to `approval.notice`.
  - `test_existing_accounts_are_approved_by_the_migration`: insert a user row with `approved_at` null, run the migration's `up()`, and assert `approved_at` is set.
  - `test_accounts_an_admin_creates_are_approved`: extend the flow in `ManageUsersTest::test_admin_can_create_a_user` and assert `isApproved()`.
  - Seeder tests: the seeded admin `isApproved()`.
- [ ] **Step 2: Run, expect failures** (missing columns and route).
- [ ] **Step 3: Implement.** The migration adds both columns after `email_verified_at`, then sets `approved_at = now()` for every existing row. The middleware redirects to `approval.notice` unless `isApproved()`. Register the alias in `bootstrap/app.php`. Add `approved` after `verified` in both route groups. The notice route sits inside `['auth', 'verified']`, outside `approved`. It redirects approved users to the dashboard, and otherwise renders the notice with the auth layout: a title and description that change with `isRejected()`, plus a Sign out form copied from `verify-email.blade.php`. Seeders and admin create call `forceFill(['approved_at' => now()])->save()`, or set the attribute directly.
- [ ] **Step 4: Run the full suite. Expect it to pass.**
- [ ] **Step 5: Commit** `feat(auth): hold new sign-ups for admin approval`

### Task 2: Approve and reject on Manage Users

**Files:**
- Modify: `resources/views/pages/users/⚡index.blade.php`, `app/Models/ActivityLog.php` (summaries `user.approved` → "approved an account", `user.rejected` → "rejected an account"; `isWarning` unchanged)
- Test: `tests/Feature/ManageUsersTest.php`

**Interfaces:**
- Consumes: Task 1 columns and `pendingApproval` scope.
- Produces: Livewire actions `approve(int $id): void`, `confirmReject(int $id): void`, `reject(): void`; URL filter `status` (`''|'pending'|'approved'|'rejected'`); log actions `user.approved`, `user.rejected`.

- [ ] **Step 1: Write failing tests:**
  - `test_users_can_be_filtered_by_approval_status`: `?status=pending` shows only pending.
  - `test_pending_and_rejected_users_show_a_badge`: sees "Pending" and "Rejected".
  - `test_admin_can_approve_a_pending_user`: `approved_at` is set, `rejected_at` is null, and a `user.approved` entry is logged.
  - `test_admin_can_approve_a_rejected_user`: `rejected_at` is cleared.
  - `test_admin_can_reject_a_pending_user`: `rejected_at` is set, the row still exists, and a `user.rejected` entry is logged.
- [ ] **Step 2: Run, expect failures.**
- [ ] **Step 3: Implement.** Add a `status` select next to the role filter and include it in `updated()` and `clearFilters()`. Show a badge in the Role cell: yellow "Pending", red "Rejected". In the row menu, show "Approve" for any non-approved user and "Reject" for pending users only. Reject opens a confirm modal (`user-reject`), modeled on `user-delete`. Each change and its log entry save in one `DB::transaction`.
- [ ] **Step 4: Run the full suite. Expect it to pass.**
- [ ] **Step 5: Commit** `feat(users): approve and reject sign-ups`

### Task 3: Admin alert and Manage Users badge

**Files:**
- Create: `app/Notifications/SignupAwaitingApproval.php` (extends `PortalNotification`)
- Modify: `app/Providers/FortifyServiceProvider.php` (on `Verified`), `resources/views/layouts/app/sidebar.blade.php`
- Test: `tests/Feature/NotificationsTest.php`, `tests/Feature/SidebarBadgesTest.php`

**Interfaces:**
- Produces: `new SignupAwaitingApproval(User $user)`, `via()` returns `['database']`. Message: "New sign-up waiting for approval: :name (:email)." URL: `route('users.index', ['status' => 'pending'])`.

- [ ] **Step 1: Write failing tests:**
  - `test_admins_are_told_when_a_sign_up_verifies_its_email`: fire `Verified` for a pending user and check that each admin has one database notification and no mail (`Notification::fake()`, `assertSentTo` with channel `['database']`).
  - `test_no_alert_for_an_already_approved_user_verifying`: an approved user verifying sends nothing.
  - `test_admins_see_the_pending_sign_up_count`: 2 pending users show `title="2 sign-ups to approve"` on the dashboard, and the Manage Users link carries `status=pending`.
- [ ] **Step 2: Run, expect failures.**
- [ ] **Step 3: Implement.** On `Verified`, if the user `! isApproved() && ! isRejected()`, send to `User::where('role', 'admin')->get()`. In the sidebar, compute `$toApprove` for admins only and add a badge on Manage Users, following the Notifications item: a capped count, a `title`, and an sr-only sentence. Open the Administration group while `$toApprove` is above zero, and include it in the phone menu dot.
- [ ] **Step 4: Run the full suite. Expect it to pass.**
- [ ] **Step 5: Commit** `feat(users): alert admins to pending sign-ups`

### Task 4: Account rejected email

**Files:**
- Create: `app/Notifications/AccountRejected.php` (extends `PortalNotification`)
- Modify: `resources/views/pages/users/⚡index.blade.php` (`reject()` notifies)
- Test: `tests/Feature/ManageUsersTest.php`

**Interfaces:**
- Produces: `new AccountRejected()`, `via()` returns `['mail']` for a verified address and `[]` otherwise. Subject: "Your portal sign-up was not approved". Message: "Your sign-up for the Faculty Research Portal was not approved. Contact the Research Office if you think this is a mistake." URL: `route('login')`.

- [ ] **Step 1: Write failing tests:**
  - `test_rejecting_emails_the_user`: `Notification::fake()`, reject, then `assertSentTo($user, AccountRejected::class)` with the mail channel.
  - `test_rejecting_an_unverified_user_sends_no_email`: the channels list is empty.
- [ ] **Step 2: Run, expect failures.**
- [ ] **Step 3: Implement.** Notify after the transaction commits.
- [ ] **Step 4: Run the full suite, Pint on changed non-`⚡` PHP files, and PHPStan. Expect all to pass.**
- [ ] **Step 5: Commit** `feat(users): email users when a sign-up is rejected`
