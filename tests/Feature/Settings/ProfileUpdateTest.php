<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Notifications\EmailChanged;
use App\Notifications\QueuedVerifyEmail;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->get(route('profile.edit'))->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.profile')
            ->set('name', 'Test User')
            ->set('email', 'test.user@isu.edu.ph')
            ->set('current_password', 'password')
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $user->refresh();

        $this->assertEquals('Test User', $user->name);
        $this->assertEquals('test.user@isu.edu.ph', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_the_new_email_link_returns_to_my_profile_even_after_an_unfinished_security_prompt(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        // Security asks for the password and remembers the page; the member leaves without answering.
        $this->get(route('security.edit'))->assertRedirect(route('password.confirm'));

        Livewire::test('pages::settings.profile')
            ->set('email', 'test.user@isu.edu.ph')
            ->set('current_password', 'password')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $link = (new VerifyEmail)->toMail($user->refresh())->actionUrl;

        $this->get($link)->assertRedirect(route('profile.edit', absolute: false));
    }

    public function test_email_can_only_change_to_an_isu_address(): void
    {
        $user = User::factory()->create(['email' => 'old@isu.edu.ph']);

        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('email', 'someone@gmail.com')
            ->call('updateProfileInformation')
            ->assertHasErrors(['email' => 'regex']);

        $this->assertSame('old@isu.edu.ph', $user->refresh()->email);
    }

    public function test_email_verification_status_is_unchanged_when_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.profile')
            ->set('name', 'Test User')
            ->set('email', $user->email)
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_a_new_email_address_is_sent_its_verification_link_right_away(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::settings.profile')->set('email', 'new.address@isu.edu.ph')->set('current_password', 'password')->call('updateProfileInformation')->assertHasNoErrors();
        Notification::assertSentTo($user, QueuedVerifyEmail::class);

        // Saving again with the same address sends nothing more.
        Livewire::test('pages::settings.profile')->set('name', 'Test User')->call('updateProfileInformation')->assertHasNoErrors();
        Notification::assertSentToTimes($user, QueuedVerifyEmail::class, 1);
    }

    public function test_verification_emails_are_limited_to_six_a_minute(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);

        $profile = Livewire::test('pages::settings.profile');
        foreach (range(1, 7) as $click) {
            $profile->call('resendVerificationNotification');
        }
        Notification::assertSentToTimes($user, QueuedVerifyEmail::class, 6);

        // A new address still saves at the limit; its link waits for a re-send.
        $profile->set('email', 'new.address@isu.edu.ph')->set('current_password', 'password')->call('updateProfileInformation')->assertHasNoErrors();
        $this->assertSame('new.address@isu.edu.ph', $user->refresh()->email);
        Notification::assertSentToTimes($user, QueuedVerifyEmail::class, 6);

        $this->travel(61)->seconds();
        $profile->call('resendVerificationNotification');
        Notification::assertSentToTimes($user, QueuedVerifyEmail::class, 7);
    }

    public function test_a_new_email_address_needs_the_current_password(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'old@isu.edu.ph']);
        $this->actingAs($user);

        foreach (['' => 'required', 'wrong-password' => 'current_password'] as $password => $rule) {
            Livewire::test('pages::settings.profile')
                ->set('email', 'new.address@isu.edu.ph')
                ->set('current_password', $password)
                ->call('updateProfileInformation')
                ->assertHasErrors(['current_password' => $rule]);
        }

        $this->assertSame('old@isu.edu.ph', $user->refresh()->email);
        Notification::assertNothingSent();
    }

    public function test_the_old_address_is_told_when_the_email_changes(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'old@isu.edu.ph']);
        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('email', 'new.address@isu.edu.ph')
            ->set('current_password', 'password')
            ->call('updateProfileInformation')
            ->assertHasNoErrors()
            ->assertSet('current_password', '');

        Notification::assertSentOnDemand(EmailChanged::class, function (EmailChanged $notification, array $channels, object $notifiable) {
            return $notifiable->routes['mail'] === 'old@isu.edu.ph' && $notification->to === 'new.address@isu.edu.ph';
        });
    }

    public function test_an_unconfirmed_old_address_is_not_told(): void
    {
        Notification::fake();
        $this->actingAs(User::factory()->unverified()->create(['email' => 'typo@isu.edu.ph']));

        Livewire::test('pages::settings.profile')
            ->set('email', 'right.address@isu.edu.ph')
            ->set('current_password', 'password')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        Notification::assertSentOnDemandTimes(EmailChanged::class, 0);
    }

    public function test_the_email_change_notice_is_worded_for_the_old_address(): void
    {
        $mail = (new EmailChanged('first_last@isu.edu.ph', 'new.address@isu.edu.ph'))->toMail(new AnonymousNotifiable);
        $html = (string) $mail->render();

        $this->assertSame('Your Faculty Research Portal email address was changed', $mail->subject);
        $this->assertStringContainsString('first_last@isu.edu.ph', $html);
        $this->assertStringContainsString('new.address@isu.edu.ph', $html);
        $this->assertStringContainsString('contact the Research Office right away', $html);
    }

    public function test_members_cannot_delete_their_own_account_from_the_profile(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('profile.edit'))
            ->assertOk()
            ->assertDontSee('Delete account');
    }
}
