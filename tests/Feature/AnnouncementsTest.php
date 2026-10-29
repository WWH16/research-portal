<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Livewire;
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

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function memo(string $name = 'Memorandum No. 12, s. 2026.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 10, 'application/pdf');
    }

    public function test_an_admin_posts_a_schedule_with_its_memo(): void
    {
        $disk = $this->fakeDrive();
        $admin = $this->admin();

        Livewire::actingAs($admin)->test('pages::announcements.index')
            ->assertSeeHtml('data-test="post-announcement"')
            ->call('create')
            ->set('title', '  Schedule of Submission of Concept Proposals  ')
            ->set('description', 'Concept proposals for 2027 are due on the date below.')
            ->set('dueOn', '2026-11-15')
            ->set('memo', $this->memo())
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Schedule of Submission of Concept Proposals');

        $announcement = Announcement::sole();
        $this->assertSame('Schedule of Submission of Concept Proposals', $announcement->title);
        $this->assertSame('2026-11-15', $announcement->due_on->format('Y-m-d'));
        $this->assertSame('Memorandum No. 12, s. 2026.pdf', $announcement->attachment_name);
        $this->assertTrue($announcement->poster->is($admin));
        $disk->assertExists(Str::after($announcement->attachment_path, '/'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'announcement.posted', 'subject_label' => $announcement->title]);
    }

    public function test_faculty_cannot_post_edit_or_delete(): void
    {
        $announcement = Announcement::factory()->create();

        // A fresh page for each try, since a refused call leaves the test component unusable.
        $page = fn () => Livewire::actingAs(User::factory()->create())->test('pages::announcements.index');

        $page()->assertDontSeeHtml('data-test="post-announcement"')->assertDontSee('Actions for');
        $page()->call('create')->assertForbidden();
        $page()->call('edit', $announcement->id)->assertForbidden();
        $page()->call('confirmDelete', $announcement->id)->assertForbidden();
        $page()->set('title', 'Mine')->set('description', 'Mine')->call('save')->assertForbidden();
        $this->assertSame(1, Announcement::count());
    }

    public function test_editing_replaces_or_removes_the_memo_and_its_file(): void
    {
        $disk = $this->fakeDrive();
        $page = Livewire::actingAs($this->admin())->test('pages::announcements.index')
            ->call('create')->set('title', 'Memo')->set('description', 'Read the memo.')->set('memo', $this->memo('first.pdf'))->call('save');
        $first = Announcement::sole()->attachment_path;

        $page->call('edit', Announcement::sole()->id)
            ->assertSee('Current memo: first.pdf')
            ->set('memo', $this->memo('second.pdf'))
            ->call('save')
            ->assertHasNoErrors();
        $second = Announcement::sole()->attachment_path;
        $disk->assertMissing(Str::after($first, '/'));
        $disk->assertExists(Str::after($second, '/'));
        $this->assertSame('second.pdf', Announcement::sole()->attachment_name);

        $page->call('edit', Announcement::sole()->id)->set('removeMemo', true)->call('save')->assertHasNoErrors();
        $this->assertNull(Announcement::sole()->attachment_path);
        $this->assertNull(Announcement::sole()->attachment_name);
        $disk->assertMissing(Str::after($second, '/'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'announcement.updated']);
    }

    public function test_deleting_removes_the_announcement_and_its_memo(): void
    {
        $disk = $this->fakeDrive();
        $page = Livewire::actingAs($this->admin())->test('pages::announcements.index')
            ->call('create')->set('title', 'Old memo')->set('description', 'No longer needed.')->set('memo', $this->memo())->call('save');
        $announcement = Announcement::sole();

        $page->call('confirmDelete', $announcement->id)
            ->assertSee('Delete “Old memo”?')
            ->assertSee('its memo will be removed from Google Drive')
            ->call('delete');

        $this->assertSame(0, Announcement::count());
        $disk->assertMissing(Str::after($announcement->attachment_path, '/'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'announcement.deleted', 'subject_label' => 'Old memo']);
    }

    public function test_a_memo_that_cannot_be_stored_saves_nothing(): void
    {
        // No Google Drive storage account is connected.
        Livewire::actingAs($this->admin())->test('pages::announcements.index')
            ->call('create')->set('title', 'Memo')->set('description', 'Read the memo.')->set('memo', $this->memo())
            ->call('save')
            ->assertHasErrors(['memo']);

        $this->assertSame(0, Announcement::count());
    }

    public function test_the_form_says_what_to_fix(): void
    {
        Livewire::actingAs($this->admin())->test('pages::announcements.index')
            ->call('create')
            ->set('title', '   ')
            ->set('memo', UploadedFile::fake()->create('memo.png', 10, 'image/png'))
            ->call('save')
            ->assertHasErrors(['title' => 'required', 'description' => 'required', 'memo' => 'mimes'])
            ->assertSee('Enter a title.')
            ->assertSee('The memo must be a PDF or Word file.');
    }
}
