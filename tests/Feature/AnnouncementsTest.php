<?php

namespace Tests\Feature;

use App\Models\Announcement;
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

    public function test_an_announcement_keeps_its_deadline_and_memo(): void
    {
        $announcement = Announcement::factory()->dueIn(17)->withMemo()->create()->fresh();

        $this->assertTrue($announcement->due_on->equalTo(today()->addDays(17)));
        $this->assertSame('Memorandum No. 12, s. 2026.pdf', $announcement->attachment_name);
        $this->assertTrue($announcement->poster->isAdmin());

        // A plain notice has neither.
        $plain = Announcement::factory()->create()->fresh();
        $this->assertNull($plain->due_on);
        $this->assertNull($plain->attachment_path);
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
