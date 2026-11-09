<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class TwoFactorChallengeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
    }

    public function test_two_factor_challenge_redirects_to_login_when_not_authenticated(): void
    {
        $response = $this->get(route('two-factor.login'));

        $response->assertRedirect(route('login'));
    }

    public function test_two_factor_challenge_can_be_rendered(): void
    {
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $user = User::factory()->withTwoFactor()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.login'));
    }

    public function test_the_code_type_toggles_are_buttons_that_do_not_submit_the_form(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $page = $this->get(route('two-factor.login'))->assertOk()->getContent();

        foreach (['Use a recovery code', 'Use an authentication code'] as $label) {
            $this->assertMatchesRegularExpression('~<button\b[^>]*\btype="button"[^>]*>(?:\s*<[^>]+>)*\s*'.$label.'~', $page);
        }
    }
}
