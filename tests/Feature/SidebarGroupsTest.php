<?php

namespace Tests\Feature;

use App\Models\GoogleDriveConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarGroupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_daily_groups_start_open_and_the_rest_open_on_their_own_pages(): void
    {
        // A working storage account, so System stays closed; a Google Drive problem opens it on every page.
        GoogleDriveConnection::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $defaults = ['overview-nav' => true, 'research-nav' => true, 'administration-nav' => false, 'filing-options-nav' => false, 'system-nav' => false];

        $this->assertSame($defaults, $this->openGroups('dashboard'));
        $this->assertSame([...$defaults, 'administration-nav' => true], $this->openGroups('users.index'));
        $this->assertSame([...$defaults, 'filing-options-nav' => true], $this->openGroups('categories.index'));
        $this->assertSame([...$defaults, 'system-nav' => true], $this->openGroups('google-drive.index'));
    }

    public function test_faculty_groups_do_not_fold(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertSame([], $this->openGroups('dashboard'));
        $this->get(route('dashboard'))->assertSee('data-test="overview-nav"', false)->assertSee('data-test="research-nav"', false);
    }

    public function test_the_seal_shows_in_the_phone_bar_and_beside_the_title_on_wide_screens(): void
    {
        $this->actingAs(User::factory()->create());

        $page = $this->get(route('announcements.index'))->assertSee('data-test="phone-brand"', false)->getContent();

        // The sidebar's brand, the phone bar's, and the title's, which phones keep for screen readers only.
        $this->assertSame(3, substr_count($page, 'isu_seal-128.png'));
        $this->assertMatchesRegularExpression('/<div class="[^"]*max-lg:sr-only">\s*<img src="[^"]*isu_seal-128\.png"/', $page);
    }

    /** Whether each folding sidebar group renders open on the given page. */
    private function openGroups(string $route): array
    {
        preg_match_all('/<ui-disclosure [^>]*data-test="([^"]+)"([^>]*)>/', $this->get(route($route))->getContent(), $groups, PREG_SET_ORDER);

        return collect($groups)->mapWithKeys(fn ($group) => [$group[1] => str_contains($group[2], ' open ')])->all();
    }
}
