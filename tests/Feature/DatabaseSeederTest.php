<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_twice_creates_the_three_accounts_once(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame([
            'admin@isu.edu.ph' => 'admin',
            'staff@isu.edu.ph' => 'staff',
            'test.user@isu.edu.ph' => 'faculty',
        ], User::orderBy('email')->pluck('role', 'email')->map(fn (Role $role) => $role->value)->all());
        $this->assertSame(0, User::whereNull('email_verified_at')->orWhereNull('approved_at')->count());
    }

    public function test_production_needs_a_password_from_the_environment_and_all_three_share_it(): void
    {
        $this->app['env'] = 'production';
        config(['auth.seed_password' => null]);

        // Run directly: on production db:seed first asks to confirm, and a test can't answer.
        $this->assertThrows(fn () => (new DatabaseSeeder)->run(), RuntimeException::class, 'SEED_PASSWORD');
        $this->assertSame(0, User::count());

        config(['auth.seed_password' => 'a-long-chosen-password']);
        (new DatabaseSeeder)->run();

        $this->assertCount(3, User::all()->filter(fn (User $user) => Hash::check('a-long-chosen-password', $user->password)));
    }

    public function test_an_account_that_exists_keeps_its_password_and_role(): void
    {
        $this->seed();
        User::where('email', 'test.user@isu.edu.ph')->sole()->forceFill(['password' => 'changed-by-its-owner', 'role' => 'staff'])->save();

        config(['auth.seed_password' => 'another-password']);
        $this->seed();

        $user = User::where('email', 'test.user@isu.edu.ph')->sole();
        $this->assertTrue(Hash::check('changed-by-its-owner', $user->password));
        $this->assertSame(Role::Staff, $user->role);
    }
}
