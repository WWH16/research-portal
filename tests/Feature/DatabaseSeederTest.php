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

        $this->assertSame(2, User::count());

        $admin = User::where('email', 'admin@test.com')->sole();
        $testUser = User::where('email', 'test@example.com')->sole();

        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($testUser->isAdmin());
        $this->assertTrue($admin->hasVerifiedEmail());
        $this->assertTrue($testUser->hasVerifiedEmail());
        $this->assertNotNull($testUser->researcher_id);
    }
}
