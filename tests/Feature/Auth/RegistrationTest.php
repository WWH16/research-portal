<?php

namespace Tests\Feature\Auth;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_unverified_users_cannot_open_submissions(): void
    {
        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('submissions.index'))
            ->assertRedirect(route('verification.notice'));
    }
}
