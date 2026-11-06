<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Department;
use App\Models\User;
use App\Notifications\AccountRejected;
use App\Services\DocumentStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ManageUsersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['name' => 'Research Office', 'role' => 'admin']);
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('users.index'))->assertRedirect(route('login'));
    }

    public function test_faculty_are_forbidden(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'faculty']));

        $this->get(route('users.index'))->assertForbidden();
        $this->get(route('dashboard'))->assertDontSee('Manage Users');
    }

    public function test_admins_see_the_user_list(): void
    {
        User::factory()->create(['name' => 'Maria Santos', 'email' => 'maria@isu.edu.ph']);

        $this->actingAs($this->admin);

        $this->get(route('users.index'))
            ->assertOk()
            ->assertSee('Manage Users')
            ->assertSee('Maria Santos')
            ->assertSee('maria@isu.edu.ph');
    }

    public function test_users_can_be_searched_and_filtered_by_role(): void
    {
        User::factory()->create(['name' => 'Maria Santos', 'researcher_id' => 'ISU-0042']);
        User::factory()->create(['name' => 'Jose Reyes']);

        $this->actingAs($this->admin);

        Livewire::test('pages::users.index')
            ->set('search', 'ISU-0042')
            ->assertSee('Maria Santos')
            ->assertDontSee('Jose Reyes')
            ->set('search', '')
            ->set('roleFilter', 'admin')
            ->assertSee('Research Office')
            ->assertDontSee('Maria Santos');
    }

    public function test_users_can_be_filtered_by_college(): void
    {
        $ccs = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);
        $cbm = Department::create(['code' => 'CBM', 'name' => 'College of Business and Management']);
        User::factory()->create(['name' => 'Maria Santos', 'department_id' => $ccs->id]);
        User::factory()->create(['name' => 'Jose Reyes', 'department_id' => $cbm->id]);
        User::factory()->create(['name' => 'Ana Unassigned', 'department_id' => null]);

        $this->actingAs($this->admin);

        Livewire::test('pages::users.index')
            ->assertSeeHtml('data-test="college-column"')
            ->set('college', 'CBM')
            ->assertSee('Jose Reyes')
            ->assertDontSee('Maria Santos')
            ->assertDontSee('Ana Unassigned')
            ->set('college', 'none')
            ->assertSee('Ana Unassigned')
            ->assertSee('Research Office') // admins have no college either
            ->assertDontSee('Jose Reyes')
            ->set('roleFilter', 'faculty')
            ->assertSee('Ana Unassigned')
            ->assertDontSee('Research Office')
            ->set('search', 'Nobody')
            ->assertSee('No users match these filters')
            ->call('clearFilters')
            ->assertSet('college', '')
            ->assertSee('Maria Santos')
            ->assertSee('Jose Reyes');

        // The dashboard's "no college" warning opens exactly those faculty.
        Livewire::withQueryParams(['college' => 'none', 'role' => 'faculty'])
            ->test('pages::users.index')
            ->assertSee('Ana Unassigned')
            ->assertDontSee('Maria Santos')
            ->assertDontSee('Research Office');
        $this->get(route('dashboard'))->assertSee(route('users.index', ['college' => 'none', 'role' => 'faculty']));
    }

    public function test_admin_can_create_a_user(): void
    {
        $department = Department::create(['code' => 'CCSICT', 'name' => 'College of Computing Studies']);

        $this->actingAs($this->admin);

        Livewire::test('pages::users.index')
            ->call('create')
            ->set('name', ' Maria Santos ')
            ->set('email', ' Maria@ISU.edu.ph ')
            ->set('department_id', $department->id)
            ->set('role', 'faculty')
            ->set('password', 'secret-password')
            ->call('save')
            ->assertHasNoErrors();

        $user = User::where('email', 'maria@isu.edu.ph')->sole();

        $this->assertSame('Maria Santos', $user->name);
        $this->assertMatchesRegularExpression('/^ISU-\d{4}-\d{4}$/', $user->researcher_id);
        $this->assertNull($user->mobile);
        $this->assertSame($department->id, $user->department_id);
        $this->assertFalse($user->isAdmin());
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertTrue($user->isApproved());
        $this->assertTrue(Hash::check('secret-password', $user->password));
    }

    public function test_admin_can_create_a_staff_account(): void
    {
        $department = Department::create(['code' => 'CCSICT', 'name' => 'College of Computing Studies']);

        $this->actingAs($this->admin);

        Livewire::test('pages::users.index')
            ->call('create')
            ->set('name', 'Pedro Reyes')
            ->set('email', 'pedro@isu.edu.ph')
            ->set('department_id', $department->id)
            ->set('role', 'staff')
            ->set('password', 'secret-password')
            ->call('save')
            ->assertHasNoErrors();

        $user = User::where('email', 'pedro@isu.edu.ph')->sole();

        $this->assertSame(Role::Staff, $user->role);
        $this->assertTrue($user->isApproved());
        $this->assertFalse($user->isAdmin());
    }

    public function test_staff_can_be_filtered_and_show_a_staff_badge(): void
    {
        $staff = User::factory()->create(['name' => 'Stella Staffer', 'role' => 'staff']);
        $faculty = User::factory()->create(['name' => 'Felix Facultyman', 'role' => 'faculty']);

        $this->actingAs($this->admin);

        $component = Livewire::test('pages::users.index')
            ->set('roleFilter', 'staff')
            ->assertSee($staff->name)
            ->assertDontSee($faculty->name);

        $this->assertMatchesRegularExpression(
            '/data-test="role-filter"(?:(?!<\/select>).)*?<option[^>]*value="staff"/s',
            $component->html(),
        );

        $this->assertMatchesRegularExpression(
            '/data-test="role-badge"[^>]*>\s*Staff\s*</',
            $component->html(),
        );
    }

    public function test_the_role_cannot_be_mass_assigned(): void
    {
        $user = User::factory()->create(['role' => 'faculty']);

        $user->update(['role' => 'admin']);

        $this->assertFalse($user->fresh()->isAdmin());
    }

    public function test_create_requires_a_password_and_a_unique_email(): void
    {
        User::factory()->create(['email' => 'taken@isu.edu.ph']);

        $this->actingAs($this->admin);

        Livewire::test('pages::users.index')
            ->call('create')
            ->call('save')
            ->assertHasErrors(['name' => 'required', 'email' => 'required', 'password' => 'required'])
            ->set('name', 'Someone')
            ->set('email', 'taken@isu.edu.ph')
            ->set('password', 'secret-password')
            ->call('save')
            ->assertHasErrors(['email' => 'unique'])
            ->set('email', 'someone@gmail.com')
            ->call('save')
            ->assertHasErrors(['email' => 'regex']);
    }

    public function test_edit_form_shows_no_department_for_unassigned_users(): void
    {
        Department::create(['code' => 'CCSICT', 'name' => 'College of Computing Studies']);
        $user = User::factory()->create(['department_id' => null]);

        $this->actingAs($this->admin);

        Livewire::test('pages::users.index')
            ->call('edit', $user->id)
            ->assertSet('department_id', null)
            ->assertSee('No college')
            ->assertDontSeeHtml('class="placeholder"');
    }

    public function test_admin_can_remove_a_users_department(): void
    {
        $department = Department::create(['code' => 'CCSICT', 'name' => 'College of Computing Studies']);
        $user = User::factory()->create(['department_id' => $department->id]);

        $this->actingAs($this->admin);

        Livewire::test('pages::users.index')
            ->call('edit', $user->id)
            ->assertSet('department_id', $department->id)
            ->set('department_id', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($user->fresh()->department_id);
    }

    public function test_admin_can_update_a_user_and_keep_their_password(): void
    {
        $user = User::factory()->create(['name' => 'Maria Santos', 'role' => 'staff']);
        $originalHash = $user->password;

        $this->actingAs($this->admin);

        Livewire::test('pages::users.index')
            ->call('edit', $user->id)
            ->assertSet('name', 'Maria Santos')
            ->set('name', 'Maria S. Santos')
            ->set('role', 'admin')
            ->call('save')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertSame('Maria S. Santos', $user->name);
        $this->assertTrue($user->isAdmin());
        $this->assertSame($originalHash, $user->password);
    }

    public function test_admin_can_reset_a_users_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin);

        Livewire::test('pages::users.index')
            ->call('edit', $user->id)
            ->set('password', 'brand-new-password')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
    }

    public function test_the_faculty_and_admins_scopes_split_accounts_by_role(): void
    {
        $faculty = User::factory()->count(2)->create(['role' => 'faculty']);

        $this->assertEqualsCanonicalizing($faculty->modelKeys(), User::faculty()->pluck('id')->all());
        $this->assertSame([$this->admin->id], User::admins()->pluck('id')->all());
    }

    public function test_only_known_roles_are_saved_or_filtered(): void
    {
        $maria = User::factory()->create(['name' => 'Maria Santos', 'role' => 'faculty']);
        $this->actingAs($this->admin);

        Livewire::test('pages::users.index')->call('edit', $maria->id)->set('role', 'superuser')->call('save')->assertHasErrors(['role']);
        $this->assertSame(Role::Faculty, $maria->fresh()->role);

        // A hand-edited ?role= in the address shows everyone instead of no one.
        Livewire::test('pages::users.index')->set('roleFilter', 'superuser')->assertSee('Maria Santos')->assertSee('Research Office');
    }

    public function test_admin_cannot_change_their_own_role(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::users.index')
            ->call('edit', $this->admin->id)
            ->set('role', 'faculty')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($this->admin->fresh()->isAdmin());
    }

    public function test_only_faculty_accounts_have_a_college(): void
    {
        $college = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);
        $this->actingAs($this->admin);

        Livewire::test('pages::users.index')
            ->call('create')
            ->assertSeeHtml('wire:model="department_id"')
            ->set('role', 'staff')
            ->assertDontSeeHtml('wire:model="department_id"')
            ->set('name', 'Pedro Reyes')
            ->set('email', 'pedro@isu.edu.ph')
            ->set('department_id', $college->id)
            ->set('password', 'secret-password')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(User::where('email', 'pedro@isu.edu.ph')->sole()->department_id);

        // An office account made before this rule loses its college the next time it is saved.
        $staff = User::factory()->create(['role' => 'staff', 'department_id' => $college->id]);

        Livewire::test('pages::users.index')
            ->call('edit', $staff->id)
            ->assertDontSeeHtml('wire:model="department_id"')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($staff->fresh()->department_id);

        // A faculty account keeps its college.
        $faculty = User::factory()->create(['department_id' => $college->id]);

        Livewire::test('pages::users.index')
            ->call('edit', $faculty->id)
            ->assertSeeHtml('wire:model="department_id"')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($college->id, $faculty->fresh()->department_id);
    }

    public function test_deleting_an_account_removes_its_photo_from_google_drive(): void
    {
        $drive = $this->fakeDrive();
        $path = app(DocumentStorage::class)->store(UploadedFile::fake()->create('me.jpg', 10, 'image/jpeg'), 'profile-images');
        $location = Str::after($path, '/');
        $jose = User::factory()->create();
        $jose->forceFill(['profile_image' => $path])->save();
        $drive->assertExists($location);

        $this->actingAs($this->admin);
        Livewire::test('pages::users.index')->call('confirmDelete', $jose->id)->call('delete');

        $this->assertNull($jose->fresh());
        $drive->assertMissing($location);
    }

    public function test_a_faculty_account_keeps_its_role(): void
    {
        $maria = User::factory()->create(['name' => 'Maria Santos', 'role' => 'faculty']);
        $this->actingAs($this->admin);

        $form = Livewire::test('pages::users.index')
            ->call('edit', $maria->id)
            ->assertSee('A faculty account’s role can’t be changed.');

        foreach (['staff', 'admin'] as $role) {
            $form->set('role', $role)->call('save')->assertHasErrors(['role']);
            $this->assertSame(Role::Faculty, $maria->fresh()->role);
        }

        // The rest of the account still saves.
        $form->set('role', 'faculty')->set('mobile', '09171234567')->call('save')->assertHasNoErrors();
        $this->assertSame('09171234567', $maria->fresh()->mobile);
    }

    public function test_admin_and_staff_accounts_swap_but_never_become_faculty(): void
    {
        $pedro = User::factory()->create(['name' => 'Pedro Reyes', 'role' => 'staff']);
        $this->actingAs($this->admin);
        $form = fn () => Livewire::test('pages::users.index')->call('edit', $pedro->id);

        $form()->set('role', 'faculty')->call('save')->assertHasErrors(['role']);
        $this->assertSame(Role::Staff, $pedro->fresh()->role);

        $form()->set('role', 'admin')->call('save')->assertHasNoErrors();
        $this->assertSame(Role::Admin, $pedro->fresh()->role);

        $form()->set('role', 'staff')->call('save')->assertHasNoErrors();
        $this->assertSame(Role::Staff, $pedro->fresh()->role);
    }

    public function test_a_page_past_the_end_shows_the_last_page_instead_of_claiming_no_one_matches(): void
    {
        // With the admin, 15 members fill page one; the 16th sits alone on page two.
        foreach (range(1, 14) as $i) {
            User::factory()->create(['name' => sprintf('Member %02d', $i)]);
        }
        $last = User::factory()->create(['name' => 'Zed Lastname']);

        $this->actingAs($this->admin);

        $this->get(route('users.index', ['page' => 9]))
            ->assertSee('Zed Lastname')
            ->assertDontSee('No users match these filters');

        // Deleting the only member on the last page lands on the page before, not on an empty one.
        Livewire::withQueryParams(['page' => 2])
            ->test('pages::users.index')
            ->call('confirmDelete', $last->id)
            ->call('delete')
            ->assertSet('paginators.page', 1)
            ->assertSee('Member 01')
            ->assertDontSee('No users match these filters');
    }

    public function test_the_list_redraws_when_the_live_count_finds_a_new_sign_up(): void
    {
        $this->actingAs($this->admin);
        $page = Livewire::test('pages::users.index', ['statusFilter' => 'pending'])->assertDontSee('Newly Signed Up');

        User::factory()->pending()->create(['name' => 'Newly Signed Up']);

        $page->dispatch('open-work-changed', changed: ['toApprove'])->assertSee('Newly Signed Up');
    }

    public function test_the_sign_up_badge_stays_in_the_page_hidden_so_it_can_appear_live(): void
    {
        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertSeeHtml('x-show="toApprove"')
            ->assertDontSee('sign-ups to approve');
    }

    public function test_unused_user_can_be_deleted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin);

        Livewire::test('pages::users.index')
            ->call('confirmDelete', $user->id)
            ->call('delete');

        $this->assertModelMissing($user);
    }

    public function test_closed_dialogs_and_the_college_list_add_no_queries_to_a_search(): void
    {
        $college = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);
        $user = User::factory()->create(['department_id' => $college->id]);

        $this->actingAs($this->admin);

        $component = Livewire::test('pages::users.index')
            ->assertSeeHtml('wire:close="$set(\'editingId\', null, false)"')
            ->assertSeeHtml('wire:close="$set(\'deletingId\', null, false)"')
            ->call('edit', $user->id)
            ->call('confirmDelete', $user->id);

        // Closing the dialogs clears both IDs in the browser; they reach the server with the next search.
        // That search loads the page of users and its total, their colleges, and one shared college list.
        $this->assertQueriesAtMost(4, fn () => $component->update(updates: ['editingId' => null, 'deletingId' => null, 'search' => 'a']));
    }

    public function test_admin_cannot_delete_themselves(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::users.index')
            ->call('confirmDelete', $this->admin->id)
            ->assertSee('You can’t delete your own account from here.')
            ->call('delete');

        $this->assertModelExists($this->admin);
    }

    public function test_user_with_submissions_cannot_be_deleted(): void
    {
        $department = Department::create(['code' => 'CCSICT', 'name' => 'College of Computing Studies']);
        $user = User::factory()->create(['name' => 'Maria Santos']);
        $user->submissions()->create([
            'category_id' => Category::create(['name' => 'Computing'])->id,
            'department_id' => $department->id,
            'title' => 'Sample Proposal',
            'concept_path' => 'drive-1/submissions/sample.pdf',
        ]);

        $this->actingAs($this->admin);

        Livewire::test('pages::users.index')
            ->call('confirmDelete', $user->id)
            ->assertSee('Maria Santos still has one submission')
            ->call('delete');

        $this->assertModelExists($user);
    }

    public function test_users_can_be_filtered_by_approval_status(): void
    {
        User::factory()->pending()->create(['name' => 'Pending Person']);
        User::factory()->rejected()->create(['name' => 'Rejected Person']);
        User::factory()->create(['name' => 'Approved Person']);

        $this->actingAs($this->admin);

        Livewire::withQueryParams(['status' => 'pending'])
            ->test('pages::users.index')
            ->assertSee('Pending Person')
            ->assertDontSee('Rejected Person')
            ->assertDontSee('Approved Person')
            ->set('statusFilter', 'rejected')
            ->assertSee('Rejected Person')
            ->assertDontSee('Pending Person')
            ->set('statusFilter', 'approved')
            ->assertSee('Approved Person')
            ->assertDontSee('Pending Person')
            ->assertDontSee('Rejected Person');
    }

    public function test_pending_and_rejected_users_show_a_badge(): void
    {
        User::factory()->pending()->create();
        User::factory()->rejected()->create();

        $this->actingAs($this->admin);

        $this->get(route('users.index'))
            ->assertSeeHtml('data-test="pending-badge"')
            ->assertSeeHtml('data-test="rejected-badge"');
    }

    public function test_admin_can_approve_a_pending_user(): void
    {
        $user = User::factory()->pending()->create();

        $this->actingAs($this->admin);

        // The sidebar's sign-up count drops at once, without waiting for its next check.
        Livewire::test('pages::users.index')->call('approve', $user->id)->assertDispatched('open-work-stale');

        $this->assertTrue($user->refresh()->isApproved());
        $this->assertFalse($user->isRejected());
        $this->assertSame($user->id, ActivityLog::where('action', 'user.approved')->sole()->subject_id);
    }

    public function test_admin_can_approve_a_rejected_user(): void
    {
        $user = User::factory()->rejected()->create();

        $this->actingAs($this->admin);

        Livewire::test('pages::users.index')->call('approve', $user->id);

        $this->assertTrue($user->refresh()->isApproved());
        $this->assertFalse($user->isRejected());
    }

    public function test_admin_can_reject_a_pending_user(): void
    {
        $user = User::factory()->pending()->create();

        $this->actingAs($this->admin);

        Livewire::test('pages::users.index')
            ->call('confirmReject', $user->id)
            ->call('reject');

        $this->assertModelExists($user);
        $this->assertTrue($user->refresh()->isRejected());
        $this->assertFalse($user->isApproved());
        $this->assertSame($user->id, ActivityLog::where('action', 'user.rejected')->sole()->subject_id);
    }

    public function test_rejecting_emails_the_user(): void
    {
        Notification::fake();
        $user = User::factory()->pending()->create();

        $this->actingAs($this->admin);

        Livewire::test('pages::users.index')
            ->call('confirmReject', $user->id)
            ->call('reject');

        Notification::assertSentTo($user, AccountRejected::class, function (AccountRejected $notification) use ($user) {
            $mail = $notification->toMail($user);

            return $notification->via($user) === ['mail']
                && $mail->subject === 'Your portal sign-up was not approved'
                && str_contains($mail->introLines[0], 'was **not approved** by the Research Office.');
        });
        Notification::assertNotSentTo($this->admin, AccountRejected::class);
    }

    public function test_rejecting_an_unverified_user_sends_no_email(): void
    {
        $user = User::factory()->pending()->unverified()->create();

        $this->assertSame([], (new AccountRejected)->via($user));
    }

    public function test_approved_users_cannot_be_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin);

        Livewire::test('pages::users.index')
            ->call('confirmReject', $user->id)
            ->call('reject');

        $this->assertTrue($user->refresh()->isApproved());
        $this->assertFalse($user->isRejected());
        $this->assertSame(0, ActivityLog::where('action', 'user.rejected')->count());
    }
}
