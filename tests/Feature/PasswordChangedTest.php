<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PasswordChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A member is emailed whenever their password changes: on the Security page, by the Research Office on
 * Manage Users, or through a reset link.
 */
class PasswordChangedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_changing_your_own_password_emails_you(): void
    {
        $maria = User::factory()->create(['password' => Hash::make('password')]);

        $this->actingAs($maria);
        Livewire::test('pages::settings.security')
            ->set('current_password', 'password')
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('updatePassword')
            ->assertHasNoErrors();

        Notification::assertSentTo($maria, PasswordChanged::class, fn (PasswordChanged $notice) => ! $notice->byResearchOffice);
    }

    public function test_the_email_names_the_account_and_the_time_and_how_to_recover(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 2)->setTime(15, 5));
        $maria = User::factory()->create(['email' => 'maria_santos@isu.edu.ph']);

        $own = (new PasswordChanged)->toMail($maria);
        $html = (string) $own->render();

        $this->assertSame('Your Faculty Research Portal password was changed', $own->subject);
        $this->assertMatchesRegularExpression('/<strong[^>]*>maria_santos@isu\.edu\.ph<\/strong>/', $html);
        $this->assertStringContainsString('November 2, 2026, at 3:05 PM', $html);
        $this->assertSame(route('password.request'), $own->actionUrl);

        $set = (new PasswordChanged(byResearchOffice: true))->toMail($maria);
        $this->assertStringContainsString('The Research Office has set a new password', (string) $set->render());
        $this->assertSame(route('login'), $set->actionUrl);
    }

    public function test_an_unconfirmed_address_gets_no_email(): void
    {
        $maria = User::factory()->unverified()->create();

        $this->assertSame([], (new PasswordChanged)->via($maria));
    }
}
