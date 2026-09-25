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
            'manage users' => ['users.index', 'Manage Users'],
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
    public function test_page_is_displayed(string $route, string $heading): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route($route))->assertOk()->assertSee($heading);
    }
}
