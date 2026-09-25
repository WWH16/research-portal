<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\ResearchType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
        $this->assertTrue(Hash::check('secret-password', $user->password));
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
            ->assertHasErrors(['email' => 'unique']);
    }

    public function test_admin_can_update_a_user_and_keep_their_password(): void
    {
        $user = User::factory()->create(['name' => 'Maria Santos', 'role' => 'faculty']);
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

    public function test_unused_user_can_be_deleted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin);

        Livewire::test('pages::users.index')
            ->call('confirmDelete', $user->id)
            ->call('delete');

        $this->assertModelMissing($user);
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
            'research_type_id' => ResearchType::create(['name' => 'Thesis'])->id,
            'category_id' => Category::create(['name' => 'Computing'])->id,
            'department_id' => $department->id,
            'title' => 'Sample Proposal',
            'file_path' => 'submissions/sample.pdf',
        ]);

        $this->actingAs($this->admin);

        Livewire::test('pages::users.index')
            ->call('confirmDelete', $user->id)
            ->assertSee('Maria Santos still has one submission')
            ->call('delete');

        $this->assertModelExists($user);
    }
}
