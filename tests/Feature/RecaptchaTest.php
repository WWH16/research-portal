<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RecaptchaTest extends TestCase
{
    use RefreshDatabase;

    private function keys(): void
    {
        config(['services.recaptcha.site_key' => 'site-key', 'services.recaptcha.secret_key' => 'secret-key']);
    }

    private function google(float $score, string $action = 'login'): void
    {
        Http::fake(['www.google.com/recaptcha/*' => Http::response(['success' => true, 'score' => $score, 'action' => $action])]);
    }

    public function test_without_keys_sign_in_works_and_no_google_script_loads(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();

        $this->get(route('login'))->assertDontSee('recaptcha/api.js');
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_with_keys_the_forms_load_the_script_for_their_action(): void
    {
        $this->keys();

        $this->get(route('login'))->assertSee('recaptcha/api.js?render=site-key', false)->assertSee("action: 'login'", false);
        $this->get(route('register'))->assertSee("action: 'register'", false);
    }

    public function test_a_human_score_signs_in(): void
    {
        $this->keys();
        $this->google(0.9);
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'recaptcha_token' => 'token']);

        $this->assertAuthenticatedAs($user);
        Http::assertSent(fn ($request) => $request['secret'] === 'secret-key' && $request['response'] === 'token');
    }

    public function test_a_bot_score_or_another_forms_token_is_turned_away(): void
    {
        $this->keys();
        $user = User::factory()->create();

        foreach ([[0.1, 'login'], [0.9, 'register']] as [$score, $action]) {
            $this->google($score, $action);

            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'recaptcha_token' => 'token'])
                ->assertSessionHasErrors('email');
            $this->assertGuest();
        }
    }

    public function test_a_bot_cannot_sign_up(): void
    {
        $this->keys();
        $this->google(0.1, 'register');

        $this->post(route('register.store'), ['name' => 'Bot', 'email' => 'bot_cyn@isu.edu.ph', 'recaptcha_token' => 'token'])
            ->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('users', ['email' => 'bot_cyn@isu.edu.ph']);
    }
}
