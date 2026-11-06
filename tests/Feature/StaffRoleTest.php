<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
