<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_reach_announcements_from_the_sidebar(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))
            ->assertSee(route('announcements.index'), escape: false)
            ->assertDontSee('laravel.com/docs')
            ->assertDontSee('github.com/laravel');

        $this->get(route('announcements.index'))
            ->assertOk()
            ->assertSee('No announcements yet.');
    }

    public function test_members_reach_the_research_drive_from_the_sidebar(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))->assertSee(route('drive.index'), escape: false);

        $this->get(route('drive.index'))
            ->assertOk()
            ->assertSee('You’re not on any projects yet');
    }
}
