# Staff Role Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a third account role, `staff`, for the Research Office members who prepare the reports sent to the main campus in Echague: they see and export everything, tick "Presented in-house", and change nothing else.

**Architecture:** One new enum case and one additive migration. The codebase uses `isAdmin()` for two different things: "sees every project" and "may act as the Research Office". This plan splits them: `isResearchOffice()` (admin or staff) for seeing and exporting, `isAdmin()` kept for acting, and `isFaculty()` where the code really means "not an office account". Policies carry the rules; pages ask the policies or the helpers.

**Tech Stack:** Laravel 13, Fortify, Livewire 4 single-file pages, Flux, Pest running PHPUnit-style test classes, MySQL (production and local), SQLite in memory (tests).

**Spec:** No separate spec file. The requirements are below, from the professor's request and the choices made with the user on 2026-11-06.

## Requirements

The professor's words: "As much as possible may account xa na hindi lahat e magagawa din nya" (staff get their own account, but they cannot do everything) and "kasi si staff ang taga prepare ng lahat ng docs to submit s echague mga reports" (staff prepare all the documents and reports submitted to Echague).

| Ability | Admin | Staff | Faculty |
|---|---|---|---|
| See every project, its documents, and the Research Drive by college | yes | yes | own only |
| Dashboard office summary and its CSV export | yes | yes | no |
| Submissions list of every project and its CSV export | yes | yes | own only |
| Faculty Publications list, every profile and paper | yes | yes | own only |
| Tick "Presented in-house" on completed projects | yes | **yes** | no |
| Review concept proposals, change project dates | yes | no | no |
| Manage Users, sign-up approval, Activity Log | yes | no | no |
| Colleges, Categories, Google Drive | yes | no | no |
| Post, edit, delete announcements | yes | no | no |
| File projects and record publications | no | no | yes |
| Notices: completed and late projects | yes | **yes** | no |
| Notices: new and changed announcements | no | **yes** | yes |
| Notices: Google Drive problems, failed emails | yes | no | no |

## Global Constraints

- Enum case `Role::Staff`, stored value `staff`, label "Staff". This is the account role. It is not the proponent role `'Staff'` in `Proponent::ROLES`; never compare the two.
- `role` stays out of `#[Fillable]`. Only Manage Users sets it, with `forceFill()`. Sign-ups stay faculty.
- Admin-only routes stay in the existing `admin` middleware group. Add no new middleware.
- Helper meaning, used the same way everywhere: `isAdmin()` = may act for the Research Office; `isResearchOffice()` = admin or staff, sees everything; `isFaculty()` = proponent account.
- UI uses stock Flux components (`flux:button`, `flux:badge`, `flux:select.option`). No custom buttons.
- Messages and notices to members are full sentences, no short names or clipped dates.
- Tests follow the neighbouring files: PHPUnit-style classes in `tests/Feature/`, `RefreshDatabase`, users made with `User::factory()->create(['role' => 'staff'])`.
- Run tests with `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest`.
- Run `vendor/bin/pint` only on changed files under `app/`, `routes/`, `database/`, `tests/`. Never run Pint on `⚡` pages.
- Before calling the work done, run `composer ci:check`. If SQLite is not on in `php.ini`, run `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --memory-limit=1G`, and the Pest command above instead.
- The migration only widens the enum. Apply it with `php artisan migrate`. Never suggest `migrate:fresh`, `migrate:refresh`, `migrate:reset`, or `db:wipe`.
- No new Composer or npm packages.
- Commit messages have no `Co-Authored-By` trailer and no AI attribution.

## Review Focus

1. Staff typing an admin address (`/users`, `/reviews`, `/activity-log`, `/categories`, `/departments`, `/google-drive`) or a faculty address (`/submissions/create`, `/publications/create`) get the 403 page. Test in Task 2.
2. Staff calling a hidden Livewire action straight from the browser console (`editDates`, `saveDates`, `review`) get 403, even though no button shows it. Test in Task 5.
3. A faculty member switched to staff while still a proponent or author still sees those records, but can no longer edit them and sees no "needs revision" badge. Test in Task 2.
4. Staff stay out of faculty-only lists: the proponent and author pickers and the dashboard "Not yet submitted" count. Tests in Task 2 and Task 4.
5. An admin filtering Manage Users by "Staff" sees only staff, and a staff row shows a "Staff" badge, not "Faculty". Test in Task 3.

---

### Task 1: Staff role in the enum, the database, and the User model

**Files:**
- Modify: `app/Enums/Role.php`
- Create: `database/migrations/<timestamp>_add_staff_to_users_role.php` (with `php artisan make:migration add_staff_to_users_role --table=users`)
- Modify: `app/Models/User.php` (helpers near `isAdmin()` at line 216, scope near `scopeAdmins()` at line 252, `openWork()` at line 97)
- Test: `tests/Feature/StaffRoleTest.php` (create with `php artisan make:test StaffRoleTest --phpunit`, to match its neighbours)

**Interfaces:**
- Produces: `Role::Staff`; `Role::label(): string` returning `'Admin'`, `'Staff'`, `'Faculty'`; `User::isResearchOffice(): bool`; `User::isFaculty(): bool`; local scope `User::researchOffice()` (role in admin, staff).

- [ ] **Step 1: Write the failing tests** in `StaffRoleTest`:

```php
public function test_role_helpers_tell_the_three_roles_apart(): void
{
    $admin = User::factory()->make(['role' => 'admin']);
    $staff = User::factory()->make(['role' => 'staff']);
    $faculty = User::factory()->make(['role' => 'faculty']);

    $this->assertSame([true, false, false], [$admin->isAdmin(), $staff->isAdmin(), $faculty->isAdmin()]);
    $this->assertSame([true, true, false], [$admin->isResearchOffice(), $staff->isResearchOffice(), $faculty->isResearchOffice()]);
    $this->assertSame([false, false, true], [$admin->isFaculty(), $staff->isFaculty(), $faculty->isFaculty()]);
    $this->assertSame('Staff', Role::Staff->label());
}

public function test_the_users_table_stores_the_staff_role(): void
{
    $staff = User::factory()->create(['role' => 'staff']);
    User::factory()->create(['role' => 'admin']);
    User::factory()->create(['role' => 'faculty']);

    $this->assertSame(Role::Staff, $staff->fresh()->role);
    $this->assertSame(2, User::researchOffice()->count());
}
```

- [ ] **Step 2: Run them and see them fail**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/StaffRoleTest.php`
Expected: FAIL. The first test fails on the undefined `isResearchOffice()`; the second fails on the SQLite check constraint for `role`.

- [ ] **Step 3: Implement**

- `Role`: add `case Staff = 'staff';` between Admin and Faculty, and `label()` with a `match`. Rewrite the class docblock: admins run the Research Office's portal; staff are Research Office members who prepare the reports sent to the main campus in Echague, so they see and export everything but change almost nothing; faculty are the proponents.
- Migration `up()`: `$table->enum('role', ['admin', 'staff', 'faculty'])->default('faculty')->change();`. `down()`: the same call with `['admin', 'faculty']`. On MySQL, `down()` fails while staff rows exist. That is the safe failure: it changes no one's role without asking. Say so in the docblock.
- `User`: `isResearchOffice()` and `isFaculty()` beside `isAdmin()`. Add `scopeResearchOffice()` with `whereIn('role', [Role::Admin, Role::Staff])` beside `scopeAdmins()`, with the docblock "The Research Office's accounts, admins and staff, who hear of finished and late projects."
- `User::openWork()`: change `$toRevise` from `$admin ? 0 : …` to `$this->isFaculty() ? … : 0`. A staff member who was once faculty then gets no revision badge.

- [ ] **Step 4: Run them and see them pass**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/StaffRoleTest.php tests/Feature/AdminMiddlewareTest.php`
Expected: PASS

- [ ] **Step 5: Apply the migration locally**

Run: `php artisan migrate`
Expected: the new migration shows `DONE`. Nothing else runs.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint app/Enums/Role.php app/Models/User.php database/migrations tests/Feature/StaffRoleTest.php
git add app/Enums/Role.php app/Models/User.php database/migrations tests/Feature/StaffRoleTest.php
git commit -m "feat(roles): add the staff role"
```

---

### Task 2: Policies, faculty middleware, and the Faculty Publications route

**Files:**
- Modify: `app/Policies/SubmissionPolicy.php`, `app/Policies/PublicationPolicy.php`, `app/Policies/UserPolicy.php`
- Modify: `app/Http/Middleware/EnsureUserIsFaculty.php`
- Modify: `routes/web.php:119` (move `faculty.index` out of the `admin` group)
- Modify: `tests/Feature/SubmissionTest.php:55` (the faculty middleware message changes)
- Test: `tests/Feature/DriveTest.php` (its `setUp()` already builds `$this->smart`, filed by `$this->navarro`), `tests/Feature/StaffRoleTest.php`

**Interfaces:**
- Consumes: `isResearchOffice()`, `isFaculty()` from Task 1.
- Produces: new ability `SubmissionPolicy::markPresented(User $user): bool`; new ability `UserPolicy::viewAnyPublications(User $viewer): bool`. Task 5 calls `markPresented`.

- [ ] **Step 1: Write the failing tests.** The first and third go in `DriveTest`, the others in `StaffRoleTest`.

```php
public function test_staff_see_and_export_but_do_not_act(): void
{
    $staff = User::factory()->create(['role' => 'staff']);
    $project = $this->smart;

    $this->assertTrue($staff->can('viewAny', Submission::class));
    $this->assertTrue($staff->can('view', $project));
    $this->assertTrue($staff->can('markPresented', Submission::class));
    $this->assertTrue($staff->can('viewAnyPublications', User::class));
    $this->assertFalse($staff->can('review', Submission::class));
    $this->assertFalse($staff->can('update', $project));
    $this->assertFalse($staff->can('create', Publication::class));
    $this->assertFalse($staff->can('create', Announcement::class));
}

public function test_staff_are_kept_off_admin_and_faculty_pages(): void
{
    $this->actingAs(User::factory()->create(['role' => 'staff']));

    foreach (['reviews.index', 'users.index', 'activity-log.index', 'categories.index', 'departments.index', 'google-drive.index', 'submissions.create', 'publications.create'] as $route) {
        $this->get(route($route))->assertForbidden();
    }

    $this->get(route('faculty.index'))->assertOk();
    $this->get(route('submissions.index'))->assertOk();
    $this->get(route('drive.index'))->assertOk();
}

public function test_faculty_moved_to_staff_keep_sight_of_their_project_but_cannot_edit_it(): void
{
    $this->navarro->forceFill(['role' => 'staff'])->save();

    $this->assertTrue($this->navarro->can('view', $this->smart));
    $this->assertFalse($this->navarro->can('update', $this->smart));
    $this->actingAs($this->navarro)->get(route('submissions.edit', $this->smart))->assertForbidden();
    $this->assertSame(0, $this->navarro->openWork()['toRevise']);
}

public function test_staff_are_not_offered_as_proponents_or_authors(): void
{
    // As a faculty member, Livewire::test('pages::submissions.create') and ('pages::publications.create'):
    // the faculty() computed list does not contain the staff member's id.
}
```

- [ ] **Step 2: Run them and see them fail**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/StaffRoleTest.php tests/Feature/DriveTest.php`
Expected: FAIL. `viewAny` and `view` are false for staff, `markPresented` and `viewAnyPublications` are undefined, and `faculty.index` returns 403.

- [ ] **Step 3: Implement**

- `SubmissionPolicy`: `viewAny` and `view` use `isResearchOffice()`. `review` keeps `isAdmin()`. Add `markPresented()` returning `isResearchOffice()`, with the docblock "Admins and staff tick whether a completed project presented in-house; staff prepare the certificates." `update` becomes `isFaculty() && involves()`.
- `PublicationPolicy`: `view` uses `isResearchOffice()`; `create` returns `isFaculty()`; `update` returns `isFaculty() && authoredBy()`.
- `UserPolicy`: `viewPublications` uses `isResearchOffice()`. Add `viewAnyPublications(User $viewer)` returning `isResearchOffice()`.
- `EnsureUserIsFaculty`: `abort_unless($request->user()?->isFaculty(), 403, __('The Research Office monitors submissions and does not submit proposals.'))`. Update its docblock and the assertion in `SubmissionTest.php:55`.
- `routes/web.php`: move the `faculty.index` line out of the `admin` group, beside `faculty.show`, with `->middleware('can:viewAnyPublications,'.User::class)`. Faculty still get 403 there (`FacultyPublicationsTest.php:125` keeps passing); only the message changes, from the admin-only sentence to Laravel's default.
- Change no proponent or author picker. They already filter `role = faculty`. The last test pins that behaviour.

- [ ] **Step 4: Run them and see them pass, with the suites these files touch**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/StaffRoleTest.php tests/Feature/SubmissionTest.php tests/Feature/PublicationTest.php tests/Feature/FacultyPublicationsTest.php tests/Feature/DriveTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint app/Policies app/Http/Middleware/EnsureUserIsFaculty.php routes/web.php tests/Feature/StaffRoleTest.php tests/Feature/SubmissionTest.php tests/Feature/DriveTest.php
git add app/Policies app/Http/Middleware/EnsureUserIsFaculty.php routes/web.php tests/Feature
git commit -m "feat(roles): let staff see every project and paper without editing"
```

---

### Task 3: Admins make and find staff accounts in Manage Users

**Files:**
- Modify: `resources/views/pages/users/⚡index.blade.php` (role filter at line 417, badge at lines 479–480, form select at lines 539–541)
- Modify: `resources/views/pages/settings/⚡profile.blade.php:346` (role label)
- Modify: `resources/views/components/desktop-user-menu.blade.php:14` (subtitle under the name)
- Test: `tests/Feature/ManageUsersTest.php`

**Interfaces:**
- Consumes: `Role::Staff`, `Role::label()` from Task 1.

- [ ] **Step 1: Write the failing tests** in `ManageUsersTest`:

```php
public function test_admin_can_create_a_staff_account(): void
{
    // Same steps as test_admin_can_create_a_user, with ->set('role', 'staff').
    // Assert: $user->role === Role::Staff, $user->isApproved(), and ! $user->isAdmin().
}

public function test_staff_can_be_filtered_and_show_a_staff_badge(): void
{
    // One admin, one staff, one faculty. As the admin:
    // Livewire::test('pages::users.index')->set('roleFilter', 'staff')
    //   ->assertSee($staff->name)->assertDontSee($faculty->name)->assertSeeHtml('>Staff<').
}
```

Add to `StaffRoleTest`:

```php
public function test_staff_see_their_role_in_the_menu_and_on_my_profile(): void
{
    $this->actingAs(User::factory()->create(['role' => 'staff']));

    $this->get(route('dashboard'))->assertSee('Research Office staff');
    $this->get(route('profile.edit'))->assertSeeInOrder(['Role', 'Staff']);
}
```

- [ ] **Step 2: Run them and see them fail**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/ManageUsersTest.php tests/Feature/StaffRoleTest.php`
Expected: FAIL. The filter has no Staff option, the badge reads "Faculty", and the menu reads "Faculty".

- [ ] **Step 3: Implement**

- Manage Users: add `<flux:select.option :value="Role::Staff->value">` labelled "Staff" to the role filter and to the form select, between Admin and Faculty in the filter and between Faculty and Admin in the form. The badge text becomes `$user->role->label()`, with colour `green` for admin, `blue` for staff, `zinc` for faculty, picked with `match`. The `save()` validation already uses `Rule::enum(Role::class)`, so leave it.
- Profile: `$user->role->label()`.
- User menu: add `@elseif (auth()->user()->role === \App\Enums\Role::Staff)` with the text "Research Office staff" after the Administrator branch.

- [ ] **Step 4: Run them and see them pass**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/ManageUsersTest.php tests/Feature/StaffRoleTest.php tests/Feature/Settings/MyProfileTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint tests/Feature/ManageUsersTest.php tests/Feature/StaffRoleTest.php
git add resources/views/pages/users resources/views/pages/settings/⚡profile.blade.php resources/views/components/desktop-user-menu.blade.php tests/Feature
git commit -m "feat(users): let admins give an account the staff role"
```

---

### Task 4: Sidebar and dashboard for staff

**Files:**
- Modify: `resources/views/layouts/app/sidebar.blade.php` (lines 189, 208, 230)
- Modify: `resources/views/pages/⚡dashboard.blade.php` (computed `isAdmin()` at line 139 and its uses at lines 68, 98, 246, 313, 404; the callouts at lines 422–462)
- Test: `tests/Feature/SidebarGroupsTest.php`, `tests/Feature/DashboardTest.php`

**Interfaces:**
- Consumes: `isResearchOffice()`, `isFaculty()` from Task 1; `viewAny` for staff from Task 2.

- [ ] **Step 1: Write the failing tests**

In `SidebarGroupsTest`:

```php
public function test_staff_get_the_office_links_but_no_admin_groups(): void
{
    $this->actingAs(User::factory()->create(['role' => 'staff']));

    $this->get(route('dashboard'))
        ->assertSee(route('faculty.index'), escape: false)
        ->assertSee(route('drive.index'), escape: false)
        ->assertDontSee(route('reviews.index'), escape: false)
        ->assertDontSee('Administration')
        ->assertDontSee('Filing options')
        ->assertDontSee(route('google-drive.index'), escape: false);
}
```

In `DashboardTest`, using the data `test_admins_*` already builds:

```php
public function test_staff_see_the_office_summary_and_export_it(): void
{
    // As staff: Livewire::withQueryParams(['group' => 'completed'])->test('pages::dashboard')
    //   ->assertSee('Faculty progress')->call('export')->assertFileDownloaded(/* same filename as the admin test */).
    // The page shows no 'data-test="waiting-tile"', no 'data-test="setup-tile"', no 'data-test="no-department-tile"',
    // and no link to route('reviews.index').
}

public function test_staff_accounts_are_not_counted_as_faculty_yet_to_submit(): void
{
    // One verified faculty member on no project and one staff member. The admin dashboard's
    // "Not yet submitted" count is 1, and the staff member's name is not in the ?group=pending list.
}
```

- [ ] **Step 2: Run them and see them fail**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/SidebarGroupsTest.php tests/Feature/DashboardTest.php`
Expected: FAIL. Staff get the faculty sidebar and the faculty dashboard.

- [ ] **Step 3: Implement**

- Sidebar: the `:expandable` on Overview and Research and the Faculty Publications / My Publications choice use `isResearchOffice()`. Concept Reviews, Administration, Filing options, and System keep `isAdmin()`. Update the comment at the top of the nav so it says that staff get the office groups without the admin ones.
- Dashboard: rename the computed `isAdmin()` to `office()`, returning `Auth::user()->isResearchOffice()`. Replace every `$this->isAdmin` with `$this->office`. Wrap the setup callouts and the waiting tile (lines 422–462) in `@if (auth()->user()->isAdmin())`, because each one links to an admin page. Update the top-of-file comment and the `range()`/`groupLabel` docblocks that say "admin".

- [ ] **Step 4: Run them and see them pass**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/SidebarGroupsTest.php tests/Feature/DashboardTest.php tests/Feature/SidebarBadgesTest.php tests/Feature/AdministrationPagesTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint tests/Feature/SidebarGroupsTest.php tests/Feature/DashboardTest.php
git add resources/views/layouts/app/sidebar.blade.php "resources/views/pages/⚡dashboard.blade.php" tests/Feature
git commit -m "feat(dashboard): show staff the office summary and its export"
```

---

### Task 5: Submissions, Research Drive, and publication pages for staff

**Files:**
- Modify: `resources/views/pages/submissions/⚡index.blade.php` (`monitoring()` at line 61, `setPresented()` at line 198, Dates column header at line 387, Dates cell at lines 498–510, dates modal at line 517)
- Modify: `resources/views/pages/drive/⚡index.blade.php` (computed `isAdmin()` at line 29 and its uses)
- Modify: `resources/views/pages/drive/⚡show.blade.php` (line 49 and the four `$admin` uses)
- Modify: `resources/views/pages/publications/⚡show.blade.php:88`
- Modify: `resources/views/pages/faculty/⚡show.blade.php:31`
- Modify: `resources/views/pages/settings/⚡profile.blade.php:398`
- Test: `tests/Feature/DriveTest.php`, `tests/Feature/FacultyPublicationsTest.php` (its `setUp()` has `$this->rivera` and a paper)

**Interfaces:**
- Consumes: `markPresented` and `review` abilities from Task 2; `isResearchOffice()`, `isFaculty()` from Task 1.

- [ ] **Step 1: Write the failing tests**

In `DriveTest`, beside the `setPresented` tests at line 195:

```php
public function test_staff_tick_presented_but_cannot_change_dates_or_review(): void
{
    // As staff. While $this->smart is at Detailed, ->call('setPresented', $this->smart->id, true) is forbidden.
    // ->call('editDates', $this->smart->id)->assertForbidden() and ->call('saveDates')->assertForbidden().
    // Then complete it as test_admins_tick_the_in_house_review_… does ($this->smart->update(['status' => 'Completed', …])):
    // ->call('setPresented', $this->smart->id, true)->assertHasNoErrors(); $this->smart->fresh()->presented is true.
    // The rendered list has 'data-test="presented-checkbox"' and no 'data-test="change-dates-button"'.
    // On pages::drive.show, ->call('review', $id) is forbidden, and the page has no 'data-test="drive-review-button"'.
}

public function test_staff_browse_the_drive_by_college_and_see_presented(): void
{
    // As staff: GET drive.index shows the college folders, as it does for admins.
    // GET drive.show for a completed project shows 'data-test="drive-presented"' and the college breadcrumb.
}

public function test_staff_download_the_submissions_report(): void
{
    // As staff: Livewire::test('pages::submissions.index')->call('export')
    //   ->assertFileDownloaded('submissions-'.today()->toDateString().'.csv').
}
```

In `FacultyPublicationsTest`:

```php
public function test_staff_open_any_paper_and_have_no_profile_of_their_own(): void
{
    // As staff: GET publications.show for a faculty paper is OK and shows the 'Faculty Publications' breadcrumb.
    // GET faculty.show for the staff account itself is 404.
    // GET profile.edit does not show the project email switches (User::EMAIL_KINDS labels).
}
```

- [ ] **Step 2: Run them and see them fail**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/DriveTest.php tests/Feature/FacultyPublicationsTest.php`
Expected: FAIL. Staff get the faculty list, and `setPresented` is forbidden for staff.

- [ ] **Step 3: Implement**

- Submissions list: `monitoring()` returns `isResearchOffice()`. `setPresented()` authorizes `markPresented`. Add `#[Computed] canChangeDates(): bool` returning `Auth::user()->can('review', Submission::class)`. Show the Dates header and cell only when `! $this->monitoring || $this->canChangeDates`. Show the dates modal only when `$this->canChangeDates`. `editDates()` and `saveDates()` keep `authorize('review')`. Leave the presented checkbox as it is: it already shows under `monitoring`.
- Drive index: rename the computed `isAdmin()` to `office()`, returning `isResearchOffice()`. Update every use.
- Drive show: add `$office = auth()->user()->isResearchOffice();`. The college breadcrumb and the "Presented in-house" item use `$office`. The review button and the review panel keep `$admin`.
- Publication show: `$admin` becomes `$office = auth()->user()->isResearchOffice()` (breadcrumb and owner).
- Faculty show: `abort_unless($user->isFaculty(), 404)`. Update the comment: only faculty record publications.
- Profile line 398: `@if ($user->isFaculty() || Submission::involving($user)->exists())`. Update the comment above it.

- [ ] **Step 4: Run them and see them pass, with the suites these pages have**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/DriveTest.php tests/Feature/StaffRoleTest.php tests/Feature/SubmissionTest.php tests/Feature/SubmissionEdgeTest.php tests/Feature/PublicationTest.php tests/Feature/FacultyPublicationsTest.php tests/Feature/Settings/MyProfileTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint tests/Feature/DriveTest.php tests/Feature/FacultyPublicationsTest.php
git add resources/views/pages tests/Feature
git commit -m "feat(roles): open the project and paper pages to staff, read-only"
```

---

### Task 6: Notices for staff

**Files:**
- Modify: `routes/console.php:35` (ProjectLate)
- Modify: `resources/views/pages/submissions/⚡create.blade.php:251` (ProjectCompleted)
- Modify: `resources/views/pages/announcements/⚡index.blade.php:298` (AnnouncementPosted / AnnouncementUpdated)
- Modify: `resources/views/pages/notifications/⚡index.blade.php:234` (empty-state text)
- Test: `tests/Feature/NotificationsTest.php`, `tests/Feature/AnnouncementsTest.php`

**Interfaces:**
- Consumes: `User::researchOffice()` scope from Task 1.

- [ ] **Step 1: Write the failing tests**

In `NotificationsTest`, copy the set-up of the two tests at lines 260 and 648:

```php
public function test_staff_hear_once_the_day_after_a_project_runs_late(): void
{
    // As test_admins_hear_once_…, with $staff = User::factory()->create(['role' => 'staff']).
    // Notification::assertSentToTimes($staff, ProjectLate::class, 1).
}

public function test_the_first_terminal_report_tells_staff_the_project_is_complete(): void
{
    // As test_the_first_terminal_report_tells_admins_…: Notification::assertSentTo($staff, ProjectCompleted::class).
}

public function test_staff_get_no_google_drive_or_failed_email_notices(): void
{
    // Trigger EmailFailed as EmailFailedTest does. Notification::assertNotSentTo($staff, EmailFailed::class).
}

public function test_staff_see_what_their_notices_will_be(): void
{
    // As staff with no notices, GET notifications.index shows 'Completed and late projects will appear here.'
    // and does not show 'Google Drive'.
}
```

In `AnnouncementsTest`:

```php
public function test_staff_hear_of_a_new_announcement_and_the_admin_who_posted_it_does_not(): void
{
    // Notification::fake(). As an admin, post an announcement through pages::announcements.index.
    // Notification::assertSentTo($staff, AnnouncementPosted::class); assertNotSentTo($admin, AnnouncementPosted::class).
}
```

- [ ] **Step 2: Run them and see them fail**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/NotificationsTest.php tests/Feature/AnnouncementsTest.php`
Expected: FAIL. The late, completed, and announcement assertions for staff fail, and the empty-state text names Google Drive.

- [ ] **Step 3: Implement**

- `routes/console.php`: `User::researchOffice()->get()` for ProjectLate. Comment: "The Research Office, admins and staff, hears once, the day after a project runs late."
- Submissions create: `User::researchOffice()->whereKeyNot(Auth::id())->get()` for ProjectCompleted.
- Announcements: `User::whereNot('role', Role::Admin)->whereNotNull('approved_at')->get()`. Comment: everyone who does not post announcements hears of them.
- Notifications empty state: a `match` on the role. Admin keeps its current text. Staff get "Completed and late projects will appear here." Faculty keep their current text.
- `DriveFull`, `DriveDisconnected`, and `EmailFailed` keep `User::admins()`. Leave them unchanged.

- [ ] **Step 4: Run them and see them pass**

Run: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/pest tests/Feature/NotificationsTest.php tests/Feature/AnnouncementsTest.php tests/Feature/EmailFailedTest.php`
Expected: PASS

- [ ] **Step 5: Run the whole check**

Run: `composer ci:check` (or the three fallback commands in Global Constraints)
Expected: Pint clean, PHPStan no errors, every test passes.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint routes/console.php tests/Feature/NotificationsTest.php tests/Feature/AnnouncementsTest.php
git add routes/console.php resources/views/pages tests/Feature
git commit -m "feat(notifications): tell staff of finished and late projects and of announcements"
```
