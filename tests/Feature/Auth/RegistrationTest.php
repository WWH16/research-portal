<?php

namespace Tests\Feature\Auth;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        Department::create(['code' => 'CCSICT', 'name' => 'College of Computing Studies']);

        $this->get(route('register'))
            ->assertOk()
            ->assertSee('College of Computing Studies');
    }

    public function test_create_account_button_shows_a_loading_state_while_submitting(): void
    {
        $this->get(route('register'))
            ->assertSee('x-on:submit="submitting = true"', escape: false)
            ->assertSee('x-bind:disabled="submitting"', escape: false)
            ->assertSee('data-flux-loading-indicator', escape: false);
    }

    public function test_password_requirements_list_follows_the_default_password_rule(): void
    {
        $this->get(route('register'))
            ->assertSee('At least 8 characters')
            ->assertDontSee('Uppercase and lowercase letters')
            ->assertDontSee('known data breach');

        Password::defaults(fn () => Password::min(12)->mixedCase()->letters()->numbers()->symbols()->uncompromised());

        $this->get(route('register'))
            ->assertSeeInOrder([
                'At least 12 characters',
                'Uppercase and lowercase letters',
                'At least one number',
                'At least one symbol, like ! or #',
                'Not in a known data breach',
            ])
            ->assertDontSee('At least one letter')
            // JavaScript rejects PHP's \pN shorthand, which hides the row's icon.
            ->assertSee('/\p{N}/u.test', escape: false);
    }

    public function test_new_users_register_as_faculty_even_when_asking_for_admin(): void
    {
        $department = Department::create(['code' => 'CCSICT', 'name' => 'College of Computing Studies']);

        $response = $this->post(route('register.store'), [
            'name' => 'John Doe',
            'email' => 'test.user@isu.edu.ph',
            'department_id' => $department->id,
            'role' => 'admin',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();

        $user = User::where('email', 'test.user@isu.edu.ph')->sole();
        $this->assertSame('faculty', $user->role);
        $this->assertSame($department->id, $user->department_id);
        $this->assertNull($user->email_verified_at);
    }

    public function test_sign_ups_from_one_network_are_limited_and_told_why_on_the_form(): void
    {
        $department = Department::create(['code' => 'CCSICT', 'name' => 'College of Computing Studies']);
        $form = fn (string $email) => ['name' => 'John Doe', 'email' => $email, 'department_id' => $department->id, 'password' => 'password', 'password_confirmation' => 'password'];

        // A sign-up that fails validation sends no email, so it isn't counted.
        $this->post(route('register.store'), $form('john@gmail.com'))->assertSessionHasErrors('email');
        $this->assertSame(0, RateLimiter::attempts('sign-up:127.0.0.1'));

        foreach (range(1, 20) as $n) {
            $this->post(route('register.store'), $form("user{$n}@isu.edu.ph"))->assertSessionHasNoErrors();
            auth()->logout();
        }

        $this->post(route('register.store'), $form('user21@isu.edu.ph'))
            ->assertSessionHasErrors(['email' => 'Too many sign-ups have come from your network. Please try again within the hour.']);
        $this->assertDatabaseMissing('users', ['email' => 'user21@isu.edu.ph']);
    }

    public function test_only_isu_email_addresses_can_sign_up(): void
    {
        $department = Department::create(['code' => 'CCSICT', 'name' => 'College of Computing Studies']);
        $form = ['name' => 'John Doe', 'department_id' => $department->id, 'password' => 'password', 'password_confirmation' => 'password'];

        foreach (['john@gmail.com', 'john@isu.edu.ph.evil.com', 'john@notisu.edu.ph'] as $email) {
            $this->post(route('register.store'), [...$form, 'email' => $email])
                ->assertSessionHasErrors(['email' => 'Use your ISU email address, ending in @isu.edu.ph.']);
        }

        $this->assertGuest();

        $this->post(route('register.store'), [...$form, 'email' => 'John.Doe@ISU.edu.ph'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticated();
    }

    public function test_department_is_required(): void
    {
        $this->post(route('register.store'), [
            'name' => 'John Doe',
            'email' => 'test.user@isu.edu.ph',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('department_id');

        $this->assertGuest();
    }

    public function test_confirm_password_shows_a_live_match_check(): void
    {
        $this->get(route('register'))
            ->assertSee('x-model="confirmation"', escape: false)
            ->assertSee('Passwords match')
            ->assertSee('Passwords don’t match');
    }

    public function test_a_password_mismatch_is_reported_on_the_confirm_password_field(): void
    {
        $department = Department::create(['code' => 'CCSICT', 'name' => 'College of Computing Studies']);

        $this->post(route('register.store'), [
            'name' => 'John Doe',
            'email' => 'test.user@isu.edu.ph',
            'department_id' => $department->id,
            'password' => 'password',
            'password_confirmation' => 'passwrod',
        ])->assertSessionHasErrors('password_confirmation')
            ->assertSessionDoesntHaveErrors('password');

        $this->assertGuest();
    }

    public function test_sign_up_errors_say_what_to_fix(): void
    {
        User::factory()->create(['email' => 'taken@isu.edu.ph']);

        $this->post(route('register.store'), [])
            ->assertSessionHasErrors([
                'name' => 'Enter your full name.',
                'email' => 'Enter an email address.',
                'department_id' => 'Choose your college.',
            ]);

        $this->post(route('register.store'), ['email' => 'not-an-email', 'department_id' => 999])
            ->assertSessionHasErrors([
                'email' => 'Enter a valid email address.',
                'department_id' => 'Choose a college from the list.',
            ]);

        $this->post(route('register.store'), ['email' => 'taken@isu.edu.ph'])
            ->assertSessionHasErrors(['email' => 'An account with this email already exists.']);
    }

    public function test_password_errors_say_what_to_fix(): void
    {
        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers()->symbols());

        $this->post(route('register.store'), [
            'password' => 'short',
            'password_confirmation' => 'other',
        ])->assertSessionHasErrors([
            'password' => 'Use at least 12 characters.',
            'password_confirmation' => 'Passwords don’t match.',
        ]);

        $this->post(route('register.store'), [
            'password' => 'alllowercaseletters',
        ])->assertSessionHasErrors([
            'password' => 'Use both uppercase and lowercase letters.',
            'password_confirmation' => 'Confirm your password.',
        ]);

        $this->post(route('register.store'), [])
            ->assertSessionHasErrors(['password' => 'Enter a password.']);
    }

    public function test_unverified_users_cannot_open_submissions(): void
    {
        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('submissions.index'))
            ->assertRedirect(route('verification.notice'));
    }
}
