<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarGroupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_daily_groups_start_open_and_the_rest_open_on_their_own_pages(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $defaults = ['overview-nav' => true, 'research-nav' => true, 'administration-nav' => false, 'filing-options-nav' => false, 'system-nav' => false];

        $this->assertSame($defaults, $this->openGroups('dashboard'));
        $this->assertSame([...$defaults, 'administration-nav' => true], $this->openGroups('users.index'));
        $this->assertSame([...$defaults, 'filing-options-nav' => true], $this->openGroups('categories.index'));
        $this->assertSame([...$defaults, 'system-nav' => true], $this->openGroups('backups.index'));
    }

    public function test_faculty_see_only_the_daily_groups_open(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertSame(['overview-nav' => true, 'research-nav' => true], $this->openGroups('dashboard'));
    }

    /** Whether each folding sidebar group renders open on the given page. */
    private function openGroups(string $route): array
    {
        preg_match_all('/<ui-disclosure [^>]*data-test="([^"]+)"([^>]*)>/', $this->get(route($route))->getContent(), $groups, PREG_SET_ORDER);

        return collect($groups)->mapWithKeys(fn ($group) => [$group[1] => str_contains($group[2], ' open ')])->all();
    }
}
