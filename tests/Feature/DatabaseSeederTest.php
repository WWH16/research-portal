<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_twice_creates_each_account_once_and_verified(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame(7, User::count());
        $this->assertSame(0, User::whereNull('email_verified_at')->count());
        $this->assertSame(6, User::where('role', 'faculty')->whereNotNull('researcher_id')->count());

        $admin = User::where('email', 'admin@isu.edu.ph')->sole();
        $testUser = User::where('email', 'test.user@isu.edu.ph')->sole();

        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($testUser->isAdmin());
        $this->assertTrue($admin->hasVerifiedEmail());
        $this->assertTrue($testUser->hasVerifiedEmail());
        $this->assertNotNull($testUser->researcher_id);
    }
}
