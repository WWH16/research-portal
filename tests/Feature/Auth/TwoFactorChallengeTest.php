<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
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

    public function test_the_code_type_toggle_is_a_button_that_does_not_submit_the_form(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $page = $this->get(route('two-factor.login'))->assertOk()->assertSee('Use an authentication code')->getContent();

        $this->assertMatchesRegularExpression('~<button\b[^>]*\btype="button"[^>]*>(?:\s*<[^>]+>)*\s*Use a recovery code~', $page);
    }

    public function test_the_code_sends_itself_once_all_six_digits_are_in(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->get(route('two-factor.login'))
            ->assertOk()
            ->assertSee('submit="auto"', false)
            ->assertSee('x-bind:disabled="submitting"', false);
    }

    public function test_a_wrong_code_is_said_once(): void
    {
        // A real secret, since the factory's placeholder is too short for the code check
        $user = User::factory()->withTwoFactor()->create([
            'two_factor_secret' => encrypt(app(TwoFactorAuthenticationProvider::class)->generateSecretKey()),
        ]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $page = $this->from(route('two-factor.login'))
            ->followingRedirects()
            ->post(route('two-factor.login.store'), ['code' => '000000'])
            ->getContent();

        $this->assertSame(1, substr_count($page, __('The provided two factor authentication code was invalid.')));

        $page = $this->from(route('two-factor.login'))
            ->followingRedirects()
            ->post(route('two-factor.login.store'), ['recovery_code' => 'not-a-code'])
            ->getContent();

        $this->assertSame(1, substr_count($page, __('The provided two factor recovery code was invalid.')));
    }
}
