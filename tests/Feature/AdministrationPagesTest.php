<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdministrationPagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function pages(): array
    {
        return [
            'activity log' => ['activity-log.index', 'Activity Log'],
            'backup and restore' => ['backups.index', 'Backup and Restore'],
        ];
    }

    #[DataProvider('pages')]
    public function test_guests_are_redirected_to_the_login_page(string $route): void
    {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    #[DataProvider('pages')]
    public function test_page_is_displayed_to_admins(string $route, string $heading): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route($route))->assertOk()->assertSee($heading);
    }

    #[DataProvider('pages')]
    public function test_faculty_are_forbidden(string $route): void
    {
        $this->actingAs(User::factory()->create(['role' => 'faculty']));

        $this->get(route($route))->assertForbidden();
    }

    public function test_only_admins_see_the_administration_links(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'faculty']));
        $this->get(route('dashboard'))->assertDontSee('Administration')->assertDontSee(route('activity-log.index'), escape: false);

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('dashboard'))->assertSee('Administration')->assertSee(route('activity-log.index'), escape: false);
    }
}
