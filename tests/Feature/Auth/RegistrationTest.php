<?php

namespace Tests\Feature\Auth;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee('CCSICT');
    }

    public function test_create_account_button_shows_a_loading_state_while_submitting(): void
    {
        $this->get(route('register'))
            ->assertSee('x-on:submit="submitting = true"', escape: false)
            ->assertSee('x-bind:disabled="submitting"', escape: false)
            ->assertSee('data-flux-loading-indicator', escape: false);
    }

    public function test_new_users_register_as_faculty_even_when_asking_for_admin(): void
    {
        $department = Department::create(['code' => 'CCSICT', 'name' => 'College of Computing Studies']);

        $response = $this->post(route('register.store'), [
            'name' => 'John Doe',
            'email' => 'test@example.com',
            'department_id' => $department->id,
            'role' => 'admin',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();

        $user = User::where('email', 'test@example.com')->sole();
        $this->assertSame('faculty', $user->role);
        $this->assertSame($department->id, $user->department_id);
        $this->assertNull($user->email_verified_at);
    }

    public function test_department_is_required(): void
    {
        $this->post(route('register.store'), [
            'name' => 'John Doe',
            'email' => 'test@example.com',
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
            ->assertSee('Passwords don&#039;t match', escape: false);
    }

    public function test_a_password_mismatch_is_reported_on_the_confirm_password_field(): void
    {
        $department = Department::create(['code' => 'CCSICT', 'name' => 'College of Computing Studies']);

        $this->post(route('register.store'), [
            'name' => 'John Doe',
            'email' => 'test@example.com',
            'department_id' => $department->id,
            'password' => 'password',
            'password_confirmation' => 'passwrod',
        ])->assertSessionHasErrors('password_confirmation')
            ->assertSessionDoesntHaveErrors('password');

        $this->assertGuest();
    }

    public function test_sign_up_errors_say_what_to_fix(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

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

        $this->post(route('register.store'), ['email' => 'taken@example.com'])
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
            'password_confirmation' => 'Passwords don\'t match.',
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
