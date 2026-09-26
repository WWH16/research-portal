<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_on_the_home_page_are_sent_to_sign_in(): void
    {
        $this->get('/')->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_login_page_shows_the_portal_sign_in_without_a_sign_up_link(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('ISU Research Portal')
            ->assertSee('Sign in')
            ->assertSee('Contact the Research Office.')
            ->assertDontSee('Sign up');
    }

    public function test_sign_in_button_shows_a_loading_state_while_submitting(): void
    {
        $this->get(route('login'))
            ->assertSee('x-on:submit="submitting = true"', escape: false)
            ->assertSee('x-bind:disabled="submitting"', escape: false)
            ->assertSee('data-flux-loading-indicator', escape: false);
    }

    public function test_public_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Someone',
            'email' => 'someone@isu.edu.ph',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertGuest();
    }

    public function test_signed_in_users_skip_the_login_page(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('login'))->assertRedirect(route('dashboard'));
    }
}
