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
            ->assertSeeText('We sent a verification link to '.$user->email)
            ->assertSee('<strong class="font-medium text-zinc-800 wrap-anywhere">'.$user->email.'</strong>', escape: false)
            ->assertSee('Resend verification link')
            ->assertSee('x-bind:disabled="submitting"', escape: false)
            ->assertSee('data-flux-loading-indicator', escape: false)
            ->assertDontSee('New link sent');

        // A resend adds one short notice, announced to screen readers.
        $this->actingAs($user)->withSession(['status' => 'verification-link-sent'])->get(route('verification.notice'))
            ->assertSee('<p role="status" class="sr-only"', escape: false)
            ->assertSee('New link sent at');
    }

    public function test_verification_email_is_worded_and_branded_for_the_portal(): void
    {
        $user = User::factory()->unverified()->create(['name' => 'Maria Santos']);

        $mail = (new VerifyEmail)->toMail($user);
        $html = (string) $mail->render();

        $this->assertSame('Verify your email for the Faculty Research Portal', $mail->subject);
        $this->assertSame('Verify email', $mail->actionText);
        $this->assertStringContainsString('Verify your email', $html);
        $this->assertStringContainsString('Confirm '.$user->email.' to activate your Faculty Research Portal account.', $html);
        $this->assertStringContainsString('Link expires in 60 minutes.', $html);
        $this->assertMatchesRegularExpression('/<a href="mailto:'.preg_quote(config('mail.from.address'), '/').'"[^>]*>Contact the Research Office<\/a>/', $html);
        $this->assertStringContainsString('18 Dacanay, Brgy. San Fermin, Cauayan City, Isabela', $html);
        $this->assertStringNotContainsString('Button not working?', $html);
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
        $this->assertCount(1, $email->getAttachments());
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

    public function test_a_sign_up_waiting_for_review_is_told_its_email_is_verified_and_what_happens_next(): void
    {
        $user = User::factory()->unverified()->pending()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        // The link goes to the dashboard, which sends a pending sign-up on to the notice; the message survives the hop.
        $this->actingAs($user)->get($verificationUrl);
        $this->followingRedirects()->get(route('dashboard', ['verified' => 1]))
            ->assertSeeInOrder(['Waiting for approval', 'You will receive an email once your account has been approved', 'Your email address has been verified.'])
            ->assertDontSee('Welcome to the portal');

        $this->get(route('approval.notice'))->assertSee('Waiting for approval')->assertDontSee('Your email address has been verified.');
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
