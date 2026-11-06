<?php

namespace Tests\Feature\Auth;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SignupApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_sign_ups_are_pending(): void
    {
        $department = Department::create(['code' => 'CCSICT', 'name' => 'College of Computing Studies']);

        $this->post(route('register.store'), [
            'name' => 'Juan Dela Cruz',
            'email' => 'juan.delacruz@isu.edu.ph',
            'department_id' => $department->id,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors();

        $user = User::where('email', 'juan.delacruz@isu.edu.ph')->sole();
        $this->assertFalse($user->isApproved());
        $this->assertFalse($user->isRejected());
    }

    public function test_pending_users_are_sent_to_the_approval_notice(): void
    {
        $this->actingAs(User::factory()->pending()->create())
            ->get(route('dashboard'))
            ->assertRedirect(route('approval.notice'));

        $this->get(route('approval.notice'))
            ->assertOk()
            ->assertSee('Waiting for approval')
            ->assertSee(route('logout'));
    }

    public function test_the_waiting_page_learns_of_the_decision_without_a_reload(): void
    {
        $user = User::factory()->pending()->create();
        $this->actingAs($user)->get(route('approval.notice'))->assertSeeHtml('data-test="approval-poll"');
        $this->getJson(route('approval.notice'))->assertExactJson(['waiting' => true]);

        $user->forceFill(['approved_at' => now()])->save();
        $this->getJson(route('approval.notice'))->assertExactJson(['waiting' => false]);
        $this->get(route('approval.notice'))->assertRedirect(route('dashboard'));

        // A rejected sign-up gets its answer the same way, and its page has nothing left to wait for.
        $rejected = User::factory()->rejected()->create();
        $this->actingAs($rejected)->getJson(route('approval.notice'))->assertExactJson(['waiting' => false]);
        $this->get(route('approval.notice'))->assertSee('Sign-up not approved')->assertDontSeeHtml('data-test="approval-poll"');
    }

    public function test_rejected_users_see_that_their_sign_up_was_not_approved(): void
    {
        $this->actingAs(User::factory()->rejected()->create())
            ->get(route('submissions.index'))
            ->assertRedirect(route('approval.notice'));

        $this->get(route('approval.notice'))
            ->assertOk()
            ->assertSee('Sign-up not approved')
            ->assertDontSee('Waiting for approval');
    }

    public function test_unverified_pending_users_verify_their_email_first(): void
    {
        $this->actingAs(User::factory()->pending()->unverified()->create())
            ->get(route('dashboard'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_approved_users_skip_the_notice(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('approval.notice'))
            ->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))->assertOk();
    }

    public function test_existing_accounts_are_approved_by_the_migration(): void
    {
        $migration = require database_path('migrations/2026_10_28_122731_add_approval_to_users_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', 'approved_at'));

        $id = DB::table('users')->insertGetId(['name' => 'Old Account', 'email' => 'old@isu.edu.ph', 'password' => 'x', 'role' => 'faculty']);
        $migration->up();

        $this->assertNotNull(DB::table('users')->find($id)->approved_at);
        $this->assertNull(DB::table('users')->find($id)->rejected_at);
    }
}
