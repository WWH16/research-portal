<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A changed password ends the account's other sessions and "Remember me" cookies; the session that changed it carries on.
 */
class SessionsEndOnPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Event::fake([OtherDeviceLogout::class]);
    }

    public function test_a_password_changed_elsewhere_signs_this_session_out(): void
    {
        $maria = User::factory()->create();

        $this->actingAs($maria)->get(route('dashboard'))->assertOk();

        // As when the Research Office sets a new one, or Maria changes it on another device.
        $maria->forceFill(['password' => 'brand-new-password'])->save();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_changing_your_own_password_signs_out_your_other_sessions_only(): void
    {
        $maria = User::factory()->create(['password' => Hash::make('password')]);

        $this->actingAs($maria);
        Livewire::test('pages::settings.security')
            ->set('current_password', 'password')
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('updatePassword')
            ->assertHasNoErrors();

        Event::assertDispatched(OtherDeviceLogout::class, fn (OtherDeviceLogout $event) => $event->user->is($maria));
        $this->assertTrue(Hash::check('new-password', $maria->fresh()->password));
    }

    public function test_an_admin_changing_their_own_password_on_manage_users_keeps_this_session(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin);
        Livewire::test('pages::users.index')->call('edit', $admin->id)->set('password', 'brand-new-password')->call('save')->assertHasNoErrors();

        Event::assertDispatched(OtherDeviceLogout::class, fn (OtherDeviceLogout $event) => $event->user->is($admin));
        $this->assertTrue(Hash::check('brand-new-password', auth()->user()->getAuthPassword()));
    }

    public function test_setting_someone_elses_password_leaves_the_admins_sessions_alone(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $maria = User::factory()->create();

        $this->actingAs($admin);
        Livewire::test('pages::users.index')->call('edit', $maria->id)->set('password', 'brand-new-password')->call('save')->assertHasNoErrors();

        Event::assertNotDispatched(OtherDeviceLogout::class);
        $this->assertTrue(auth()->user()->is($admin));
    }
}
