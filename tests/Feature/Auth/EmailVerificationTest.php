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
        // The address is set in bold, so the member sees at a glance which one they are confirming.
        $this->assertMatchesRegularExpression('/Please confirm that <strong[^>]*>'.preg_quote($user->email, '/').'<\/strong> is your email address to activate your Faculty Research Portal account\./', $html);
        $this->assertStringContainsString('This link expires in 24 hours.', $html);
        $this->assertMatchesRegularExpression('/<a href="mailto:'.preg_quote(config('mail.from.address'), '/').'"[^>]*>Contact the Research Office<\/a>/', $html);
        $this->assertStringContainsString('18 Dacanay, Brgy. San Fermin, Cauayan City, Isabela', $html);
        $this->assertStringNotContainsString('Button not working?', $html);
        $this->assertStringContainsString('Isabela State University', $html);
        $this->assertStringNotContainsString('All rights reserved', $html);
        $this->assertStringContainsString('class="sheet"', $html);

        // The first line is the inbox preview, and the seal is not a nameless link.
        $this->assertMatchesRegularExpression('/<div style="[^"]*display: none;[^>]*>Please confirm that [^*\\\\\s]+ is your email address/', $html);
        $this->assertDoesNotMatchRegularExpression('/<a [^>]*>\s*<img/', $html);
    }

    public function test_sent_verification_email_links_the_seal_instead_of_attaching_it(): void
    {
        config(['mail.default' => 'array']);
        $user = User::factory()->unverified()->create();

        $user->sendEmailVerificationNotification();

        // Loaded from the portal's public address, so the email carries no attachment.
        $email = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertStringContainsString('src="'.asset('images/isu_seal-128.png').'"', $email->getHtmlBody());
        $this->assertCount(0, $email->getAttachments());
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
        $response->assertRedirect(route('dashboard', absolute: false));
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

    public function test_an_expired_link_asks_for_a_new_one_instead_of_a_bare_403(): void
    {
        $user = User::factory()->unverified()->create();
        $expired = URL::temporarySignedRoute('verification.verify', now()->subMinute(), ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->actingAs($user)->get($expired)->assertRedirect(route('verification.notice'));
        $this->get(route('verification.notice'))
            ->assertSee('That verification link has expired or is no longer valid.')
            ->assertSee('Resend verification link');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_used_or_expired_link_tells_a_verified_member_so(): void
    {
        $user = User::factory()->pending()->create();
        $link = fn ($expires) => URL::temporarySignedRoute('verification.verify', $expires, ['id' => $user->id, 'hash' => sha1($user->email)]);

        // Opened again while still valid, and again once expired: both say it is done, on the page the member waits on.
        foreach ([now()->addHour(), now()->subMinute()] as $expires) {
            $this->actingAs($user)->followingRedirects()->get($link($expires))
                ->assertSee('Waiting for approval')
                ->assertSee('Your email address is already verified.');
        }
    }

    public function test_the_link_verifies_without_signing_in_and_never_signs_anyone_in(): void
    {
        $user = User::factory()->unverified()->pending()->create();
        $link = URL::temporarySignedRoute('verification.verify', now()->addDay(), ['id' => $user->id, 'hash' => sha1($user->email)]);

        // Opened on a phone that was never signed in: the address is verified, and signing in is still the member's step.
        $this->get($link)->assertRedirect(route('login'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertGuest();
        $this->get(route('login'))->assertSee('Your email address has been verified. Sign in to continue.');
    }

    public function test_a_link_lasts_a_day(): void
    {
        $user = User::factory()->unverified()->create();
        $link = (new VerifyEmail)->toMail($user)->actionUrl;

        $this->travel(23)->hours();
        $this->get($link)->assertRedirect(route('login'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_expired_link_opened_signed_out_says_to_sign_in_for_a_new_one(): void
    {
        $user = User::factory()->unverified()->create();
        $expired = URL::temporarySignedRoute('verification.verify', now()->subMinute(), ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->get($expired)->assertRedirect(route('login'));
        $this->get(route('login'))->assertSee('That verification link has expired or is no longer valid. Sign in to request a new link.');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_link_opened_while_signed_in_as_someone_else_verifies_its_own_address(): void
    {
        // A shared office computer, still signed in as a colleague.
        $colleague = User::factory()->create();
        $user = User::factory()->unverified()->create();
        $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->actingAs($colleague)->get($link)->assertRedirect(route('dashboard'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertAuthenticatedAs($colleague);
        $this->get(route('submissions.index'))->assertSee('The email address '.$user->email.' has been verified. To use that account, sign out and sign in with it.');
    }

    public function test_link_opens_are_limited_per_account_so_one_campus_network_is_not_turned_away(): void
    {
        $link = fn (User $user) => URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        [$first, $second] = User::factory()->unverified()->count(2)->create();

        foreach (range(1, 6) as $_) {
            $this->get($link($first))->assertRedirect();
        }
        $this->get($link($first))->assertTooManyRequests();

        // Same network address, another member's link.
        $this->get($link($second))->assertRedirect(route('login'));
        $this->assertTrue($second->fresh()->hasVerifiedEmail());
    }

    public function test_the_verify_page_moves_on_once_the_link_is_opened_elsewhere(): void
    {
        $user = User::factory()->unverified()->pending()->create();

        // The page asks the approval notice, which refuses until the address is verified and answers once it is.
        $this->actingAs($user)->get(route('verification.notice'))->assertSeeHtml('data-test="verified-poll"');
        $this->getJson(route('approval.notice'))->assertForbidden();

        $user->markEmailAsVerified();
        $this->getJson(route('approval.notice'))->assertOk();
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
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Event::assertNotDispatched(Verified::class);
    }
}
