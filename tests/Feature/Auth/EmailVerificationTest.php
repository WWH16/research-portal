<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::emailVerification());
    }

    public function test_email_verification_screen_can_be_rendered(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get(route('verification.notice'));

        // Signing up already sent the link, so the page says so and only offers to resend it.
        $response->assertOk()
            ->assertSee('We sent a verification link to '.$user->email)
            ->assertSee('Resend verification link')
            ->assertSee('x-bind:disabled="submitting"', escape: false)
            ->assertSee('data-flux-loading-indicator', escape: false);
    }

    public function test_verification_email_is_worded_and_branded_for_the_portal(): void
    {
        $user = User::factory()->unverified()->create(['name' => 'Maria Santos']);

        $mail = (new VerifyEmail)->toMail($user);
        $html = (string) $mail->render();

        $this->assertSame('Verify your email for the Research Portal', $mail->subject);
        $this->assertSame('Verify email', $mail->actionText);
        $this->assertStringContainsString('Verify your email', $html);
        $this->assertStringContainsString('Confirm '.$user->email.' to activate your Research Portal account.', $html);
        $this->assertStringContainsString('images/mail/envelope.png', $html);
        $this->assertStringContainsString('Link expires in 60 minutes.', $html);
        $this->assertMatchesRegularExpression('/<a href="mailto:'.preg_quote(config('mail.from.address'), '/').'"[^>]*>Contact the Research Office<\/a>/', $html);
        $this->assertStringContainsString('18 Dacanay, Brgy. San Fermin, Cauayan City, Isabela', $html);
        $this->assertStringContainsString('Button not working? Paste this link into your browser:', $html);
        $this->assertStringContainsString('Isabela State University', $html);
        $this->assertStringNotContainsString('All rights reserved', $html);
        $this->assertStringContainsString('class="sheet"', $html);

        // The first line is the inbox preview, and the seal is not a nameless link.
        $this->assertMatchesRegularExpression('/<div style="[^"]*display: none;[^>]*>Confirm \S+ to activate/', $html);
        $this->assertDoesNotMatchRegularExpression('/<a [^>]*>\s*<img/', $html);
    }

    public function test_sent_verification_email_carries_the_seal_inside_it(): void
    {
        config(['mail.default' => 'array']);
        $user = User::factory()->unverified()->create();

        $user->sendEmailVerificationNotification();

        // Embedded (cid:), not linked, so the seal shows even before the portal has a public address.
        $email = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertStringContainsString('src="cid:', $email->getHtmlBody());
        $this->assertCount(2, $email->getAttachments());
    }

    public function test_unverified_users_are_redirected_to_the_email_verification_prompt(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertRedirect(route('verification.notice'));
    }

    public function test_email_can_be_verified(): void
    {
        $user = User::factory()->unverified()->create();

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        Event::assertDispatched(Verified::class);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
    }

    public function test_verifying_returns_to_the_page_they_tried_to_open_with_the_confirmation(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(route('submissions.index'))->assertRedirect(route('verification.notice'));

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        // The page they land on confirms it once, then not again on the next page.
        $this->get($verificationUrl)->assertRedirect(route('submissions.index'));
        $this->get(route('submissions.index'))->assertSee('Email verified. Welcome to the portal.');
        $this->get(route('submissions.index'))->assertDontSee('Email verified. Welcome to the portal.');
    }

    public function test_email_is_not_verified_with_invalid_hash(): void
    {
        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('wrong-email')],
        );

        $this->actingAs($user)->get($verificationUrl);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_already_verified_user_visiting_verification_link_is_redirected_without_firing_event_again(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $this->actingAs($user)->get($verificationUrl)
            ->assertRedirect(route('dashboard', absolute: false).'?verified=1');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Event::assertNotDispatched(Verified::class);
    }
}
