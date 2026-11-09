<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdministrationPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('activity-log.index'))->assertRedirect(route('login'));
    }

    public function test_page_is_displayed_to_admins(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('activity-log.index'))->assertOk()->assertSee('Activity log');
    }

    public function test_faculty_are_forbidden(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'faculty']));

        $this->get(route('activity-log.index'))->assertForbidden();
    }

    public function test_only_admins_see_the_administration_links(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'faculty']));
        $this->get(route('dashboard'))->assertDontSee('Administration')->assertDontSee(route('activity-log.index'), escape: false);

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('dashboard'))->assertSee('Administration')->assertSee(route('activity-log.index'), escape: false);
    }
}
