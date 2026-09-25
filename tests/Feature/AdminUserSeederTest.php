<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_verified_admin_once(): void
    {
        $this->seed(AdminUserSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'admin@test.com')->sole();

        $this->assertSame('Research Office', $admin->name);
        $this->assertTrue($admin->isAdmin());
        $this->assertNull($admin->department_id);
        $this->assertTrue($admin->hasVerifiedEmail());
    }
}
