<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Notifications\QueuedVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $user->refresh();

        $this->assertEquals('Test User', $user->name);
        $this->assertEquals('test.user@isu.edu.ph', $user->email);
        $this->assertNull($user->email_verified_at);
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

        Livewire::test('pages::settings.profile')->set('email', 'new.address@isu.edu.ph')->call('updateProfileInformation')->assertHasNoErrors();
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
        $profile->set('email', 'new.address@isu.edu.ph')->call('updateProfileInformation')->assertHasNoErrors();
        $this->assertSame('new.address@isu.edu.ph', $user->refresh()->email);
        Notification::assertSentToTimes($user, QueuedVerifyEmail::class, 6);

        $this->travel(61)->seconds();
        $profile->call('resendVerificationNotification');
        Notification::assertSentToTimes($user, QueuedVerifyEmail::class, 7);
    }

    public function test_members_cannot_delete_their_own_account_from_the_profile(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('profile.edit'))
            ->assertOk()
            ->assertDontSee('Delete account');
    }
}
