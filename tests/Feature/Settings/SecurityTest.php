<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Features;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
    }

    public function test_security_settings_page_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'));

        $response->assertOk();

        $response->assertSee('Two-factor authentication');
        $response->assertSee('Enable 2FA');
    }

    public function test_turning_off_two_factor_asks_first_with_cancel_focused(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $html = $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk()
            ->assertSee('Turn off two-factor authentication?')
            ->getContent();

        // The page's Disable 2FA button only opens the dialog; the dialog's own button turns it off
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*wire:click="disable"[^>]*>\s*(<[^>]+>\s*)*Disable 2FA/', $html);
        $this->assertMatchesRegularExpression('/wire:click="disable"[^>]*data-test="confirm-disable-two-factor"/', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*autofocus[^>]*>\s*(<[^>]+>\s*)*Cancel/', $html);
    }

    public function test_security_settings_page_asks_for_the_password_first(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('security.edit'))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_a_click_on_a_page_left_open_past_the_password_timeout_asks_for_the_password_again(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $html = $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk()
            ->getContent();
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
        $snapshot = collect($matches[1])->map(fn ($found) => html_entity_decode($found))->first(fn ($found) => str_contains($found, 'settings.security'));
        $disable = fn () => $this->json('POST', app(HandleRequests::class)->getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => 'disable', 'params' => []]],
        ]]], ['X-Livewire' => 'true', 'Accept' => 'text/html']);

        $this->withSession(['auth.password_confirmed_at' => 0]);
        $disable()->assertRedirect(route('password.confirm'));

        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertSame(route('security.edit'), session('url.intended'));

        $this->withSession(['auth.password_confirmed_at' => time()]);
        $disable()->assertOk();

        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_security_settings_page_renders_without_two_factor_when_feature_is_disabled(): void
    {
        config(['fortify.features' => []]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk()
            ->assertSee('Update password')
            ->assertDontSee('Manage your passkeys for passwordless sign-in')
            ->assertDontSee('Two-factor authentication');
    }

    public function test_two_factor_authentication_disabled_when_confirmation_abandoned_between_requests(): void
    {
        $user = User::factory()->create();

        $user->forceFill([
            'two_factor_secret' => encrypt('test-secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->actingAs($user);

        $component = Livewire::test('pages::settings.security');

        $component->assertSet('twoFactorEnabled', false);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
        ]);
    }

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.security')
            ->set('current_password', 'password')
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('updatePassword');

        $response->assertHasNoErrors();

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.security')
            ->set('current_password', 'wrong-password')
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('updatePassword');

        $response->assertHasErrors(['current_password']);
    }

    public function test_a_password_mismatch_is_reported_on_the_confirm_password_field(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user);

        Livewire::test('pages::settings.security')
            ->set('current_password', 'password')
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-passwrod')
            ->call('updatePassword')
            ->assertHasErrors(['password_confirmation'])
            ->assertHasNoErrors(['password']);

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_removing_a_passkey_tells_the_password_manager_which_ones_still_work(): void
    {
        $user = User::factory()->create();
        $removed = $user->passkeys()->create(['name' => 'Old phone', 'credential_id' => 'b2xkLXBob25l', 'credential' => []]);
        $user->passkeys()->create(['name' => 'Laptop', 'credential_id' => 'bGFwdG9w', 'credential' => []]);
        $this->actingAs($user);

        $page = Livewire::test('pages::settings.security');
        $account = $page->instance()->passkeyAccount;

        // The browser checks both against what the passkey was saved under, so they must be exact.
        $this->assertSame(parse_url(config('app.url'), PHP_URL_HOST), $account['rpId']);
        $this->assertDoesNotMatchRegularExpression('~[+/=]~', $account['userId']);
        $this->assertSame($user->getPasskeyUserHandle(), Base64UrlSafe::decodeNoPadding($account['userId']));

        $page->call('confirmDelete', $removed->id)
            ->call('deletePasskey')
            ->assertDispatched('passkeys-changed', ids: ['bGFwdG9w']);
    }

    public function test_the_setup_code_sends_itself_once_all_six_digits_are_in(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::settings.two-factor-setup-modal', ['requiresConfirmation' => true])
            ->dispatch('start-two-factor-setup')
            ->call('showVerificationIfNecessary')
            ->assertSet('showVerificationStep', true)
            ->assertSeeHtml('wire:submit="confirmTwoFactor"')
            ->assertSeeHtml('submit="auto"');
    }

    public function test_the_setup_key_copy_button_is_named_and_reports_its_result(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::settings.two-factor-setup-modal', ['requiresConfirmation' => true])
            ->dispatch('start-two-factor-setup')
            ->assertSeeHtml('aria-label="Setup key"')
            ->assertSeeHtml('aria-label="Copy setup key"')
            ->assertSeeHtml('data-flux-tooltip')
            ->assertSeeHtml('role="status"')
            ->assertSee('Setup key copied.')
            ->assertSee('The key could not be copied. Select the key and copy it instead.');
    }
}
