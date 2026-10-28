<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_can_read_the_privacy_policy(): void
    {
        $this->get(route('privacy'))
            ->assertOk()
            ->assertSee('Privacy Policy')
            ->assertSee('Data Privacy Act of 2012')
            ->assertSee('isucyn.facultyresearchportal@gmail.com')
            ->assertSee(route('terms'));
    }

    public function test_guests_can_read_the_terms_of_service(): void
    {
        $this->get(route('terms'))
            ->assertOk()
            ->assertSee('Terms of Service')
            ->assertSee('@isu.edu.ph')
            ->assertSee(route('privacy'));
    }

    public function test_signed_in_and_pending_members_can_read_them_too(): void
    {
        $this->actingAs(User::factory()->pending()->create());

        $this->get(route('privacy'))->assertOk();
        $this->get(route('terms'))->assertOk();
    }

    public function test_sign_in_and_sign_up_link_to_both(): void
    {
        foreach (['login', 'register'] as $page) {
            $this->get(route($page))
                ->assertSee('href="'.route('privacy').'"', escape: false)
                ->assertSee('href="'.route('terms').'"', escape: false);
        }
    }
}
