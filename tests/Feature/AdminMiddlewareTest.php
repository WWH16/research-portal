<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'admin'])->get('admin-only-probe', fn () => 'You are an admin');
    }

    public function test_is_admin_reflects_the_role(): void
    {
        $this->assertTrue(User::factory()->make(['role' => 'admin'])->isAdmin());
        $this->assertFalse(User::factory()->make(['role' => 'faculty'])->isAdmin());
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('admin-only-probe')->assertRedirect(route('login'));
    }

    public function test_faculty_get_the_access_denied_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'faculty']));

        $this->get('admin-only-probe')
            ->assertForbidden()
            ->assertSee('You don’t have access to this page')
            ->assertSee('This page is for portal administrators only.');
    }

    public function test_admins_are_let_through(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get('admin-only-probe')->assertOk()->assertSeeText('You are an admin');
    }
}
