<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StaffRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_helpers_tell_the_three_roles_apart(): void
    {
        $admin = User::factory()->make(['role' => 'admin']);
        $staff = User::factory()->make(['role' => 'staff']);
        $faculty = User::factory()->make(['role' => 'faculty']);

        $this->assertSame([true, false, false], [$admin->isAdmin(), $staff->isAdmin(), $faculty->isAdmin()]);
        $this->assertSame([true, true, false], [$admin->isResearchOffice(), $staff->isResearchOffice(), $faculty->isResearchOffice()]);
        $this->assertSame([false, false, true], [$admin->isFaculty(), $staff->isFaculty(), $faculty->isFaculty()]);
    }

    public function test_the_users_table_stores_the_staff_role(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'faculty']);

        $this->assertSame(Role::Staff, $staff->fresh()->role);
        $this->assertSame(2, User::researchOffice()->count());
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

    public function test_staff_are_not_offered_as_proponents_or_authors(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $faculty = User::factory()->create(['role' => 'faculty']);
        $this->actingAs($faculty);

        foreach (['pages::submissions.create', 'pages::publications.create'] as $page) {
            $ids = Livewire::test($page)->instance()->faculty->pluck('id');

            $this->assertTrue($ids->contains($faculty->id));
            $this->assertFalse($ids->contains($staff->id));
        }
    }

    public function test_staff_see_their_role_in_the_menu_and_on_my_profile(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'staff']));

        $this->get(route('dashboard'))->assertSee('Research Office staff');
        $this->get(route('profile.edit'))->assertSeeInOrder(['Role', 'Staff']);
    }
}
