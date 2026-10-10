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

    public function test_login_page_shows_the_portal_sign_in_with_a_sign_up_link(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Faculty Research Portal')
            ->assertSee('Sign in')
            ->assertSee(route('register'));
    }

    public function test_a_shared_link_previews_with_the_university_seal(): void
    {
        // Messenger, Facebook and X follow a shared link to the sign-in page and read these tags for the preview card.
        $this->assertFileExists(public_path('images/share-preview.jpg'));

        $this->get(route('login'))
            ->assertSee('<meta property="og:image" content="'.asset('images/share-preview.jpg').'">', escape: false)
            ->assertSee('<meta property="og:image:width" content="1200">', escape: false)
            ->assertSee('<meta property="og:image:height" content="630">', escape: false)
            ->assertSee('<meta property="og:title" content="Sign in - Faculty Research Portal - ISU - Cauayan">', escape: false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', escape: false);
    }

    public function test_sign_in_button_shows_a_loading_state_while_submitting(): void
    {
        $this->get(route('login'))
            ->assertSee('x-on:submit="submitting = true"', escape: false)
            ->assertSee('x-bind:disabled="submitting"', escape: false)
            ->assertSee('data-flux-loading-indicator', escape: false);
    }

    public function test_signed_in_users_skip_the_login_page(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('login'))->assertRedirect(route('dashboard'));
    }
}
