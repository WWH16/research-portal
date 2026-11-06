<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_verified_admin_once(): void
    {
        $this->seed(AdminUserSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'admin@isu.edu.ph')->sole();

        $this->assertSame('Research Office', $admin->name);
        $this->assertTrue($admin->isAdmin());
        $this->assertNull($admin->department_id);
        $this->assertTrue($admin->hasVerifiedEmail());
    }

    public function test_production_needs_a_password_from_the_environment(): void
    {
        $this->app['env'] = 'production';
        config(['auth.admin_seed_password' => null]);

        // Run directly: on production db:seed first asks to confirm, and a test can't answer.
        $this->assertThrows(fn () => (new AdminUserSeeder)->run(), RuntimeException::class, 'ADMIN_SEED_PASSWORD');
        $this->assertSame(0, User::count());

        config(['auth.admin_seed_password' => 'a-long-chosen-password']);
        (new AdminUserSeeder)->run();

        $this->assertTrue(Hash::check('a-long-chosen-password', User::sole()->password));
    }

    public function test_seeding_again_keeps_the_password_the_admin_has(): void
    {
        $this->seed(AdminUserSeeder::class);
        User::sole()->update(['password' => 'changed-by-the-admin']);

        config(['auth.admin_seed_password' => 'another-password']);
        $this->seed(AdminUserSeeder::class);

        $this->assertTrue(Hash::check('changed-by-the-admin', User::sole()->password));
    }
}
