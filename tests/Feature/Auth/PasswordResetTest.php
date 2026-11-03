<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\QueuedResetPassword;
use App\Notifications\QueuedVerifyEmail;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Laravel\Fortify\Features;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::resetPasswords());
    }

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get(route('password.request'));

        $response->assertOk();
    }

    public function test_reset_password_link_screen_suggests_an_isu_email(): void
    {
        $this->get(route('password.request'))
            ->assertSee('placeholder="name_cyn@isu.edu.ph"', escape: false)
            ->assertDontSee('example.com');
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, QueuedResetPassword::class);
    }

    public function test_an_unknown_email_gets_the_same_reply_as_a_registered_one(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $known = $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();
        $unknown = $this->post(route('password.email'), ['email' => 'nobody@isu.edu.ph'])->assertSessionHasNoErrors();

        $this->assertSame(__('passwords.sent'), $known->getSession()->get('status'));
        $this->assertSame(__('passwords.sent'), $unknown->getSession()->get('status'));
    }

    public function test_a_second_request_within_a_minute_gets_the_same_reply(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        // The broker refuses another link for a minute; saying so would show the address has an account.
        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('passwords.sent'));
    }

    public function test_one_address_gets_five_requests_an_hour(): void
    {
        Notification::fake();

        foreach (range(1, 5) as $request) {
            $this->post(route('password.email'), ['email' => 'nobody@isu.edu.ph'])->assertRedirect();
        }

        $this->post(route('password.email'), ['email' => 'NOBODY@isu.edu.ph'])->assertTooManyRequests();
        $this->post(route('password.email'), ['email' => 'someone_else@isu.edu.ph'])->assertRedirect();
    }

    public function test_one_network_gets_ten_requests_an_hour(): void
    {
        Notification::fake();

        foreach (range(1, 10) as $request) {
            $this->post(route('password.email'), ['email' => "member{$request}@isu.edu.ph"])->assertRedirect();
        }

        $this->post(route('password.email'), ['email' => 'member11@isu.edu.ph'])->assertTooManyRequests();
    }

    public function test_both_reset_buttons_show_a_loading_state_while_submitting(): void
    {
        foreach ([route('password.request'), route('password.reset', 'reset-token')] as $page) {
            $this->get($page)
                ->assertSee('x-on:submit="submitting = true"', escape: false)
                ->assertSee('x-bind:disabled="submitting"', escape: false)
                ->assertSee('data-flux-loading-indicator', escape: false);
        }
    }

    public function test_expired_reset_tokens_are_cleared_every_day(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('auth:clear-resets')->assertSuccessful();

        Password::createToken(User::factory()->create());
        $this->travel(2)->days();

        $this->artisan('auth:clear-resets')->assertSuccessful();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_reset_and_verification_emails_go_through_the_queue(): void
    {
        Queue::fake();
        $user = User::factory()->unverified()->create();

        // Forgot password and Resend only add a job, so neither page waits on the mail server.
        $this->post(route('password.request'), ['email' => $user->email]);
        $user->sendEmailVerificationNotification();

        Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job) => $job->notification instanceof QueuedResetPassword);
        Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job) => $job->notification instanceof QueuedVerifyEmail);
    }

    public function test_reset_email_is_worded_and_branded_for_the_portal(): void
    {
        $user = User::factory()->create(['name' => 'Maria Santos']);

        $mail = (new ResetPassword('reset-token'))->toMail($user);
        $html = (string) $mail->render();

        $this->assertSame('Reset your Faculty Research Portal password', $mail->subject);
        $this->assertStringContainsString(route('password.reset', ['token' => 'reset-token', 'email' => $user->email]), $mail->actionUrl);
        $this->assertStringContainsString('Reset your password', $html);
        $this->assertMatchesRegularExpression('/reset the password of the Faculty Research Portal account for <strong[^>]*>'.preg_quote($user->email, '/').'<\/strong>\./', $html);
        $this->assertStringContainsString('Isabela State University', $html);
        $this->assertStringNotContainsString('All rights reserved', $html);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, QueuedResetPassword::class, function ($notification) {
            $response = $this->get(route('password.reset', $notification->token));

            $response->assertOk();

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, QueuedResetPassword::class, function ($notification) use ($user) {
            $response = $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login', absolute: false));

            return true;
        });
    }

    public function test_a_password_mismatch_is_reported_on_the_confirm_password_field(): void
    {
        $user = User::factory()->create();

        $this->post(route('password.update'), [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-passwrod',
        ])->assertSessionHasErrors('password_confirmation')
            ->assertSessionDoesntHaveErrors('password');
    }
}
