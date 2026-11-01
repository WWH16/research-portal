<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
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
            // An empty page keeps its list, heading and all, rather than a box in its place.
            ->assertSeeInOrder(['General announcements', 'No announcements yet.'])
            ->assertDontSee('Upcoming deadlines');
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
            // While the memo uploads or the post saves, the form says so, and Cancel waits with the dialog's other ways out.
            ->assertSeeHtml('data-test="uploading-status"')
            ->assertSee('Stop upload')
            ->assertSeeHtml('data-test="posting-status"')
            ->assertSee('Posting… This closes once it’s done.')
            // The deadline field says where a schedule will show, before and after its date.
            ->assertSee('It shows under Upcoming deadlines until the date passes, then under Past deadlines.')
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

        // The current memo opens in the page's own viewer, to be checked without leaving the form.
        $page->call('edit', Announcement::sole()->id)
            ->assertSee('Current memo: first.pdf')
            ->assertSee('Preview memo: first.pdf')
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

    public function test_edit_and_delete_never_open_their_dialogs_together(): void
    {
        $announcement = Announcement::factory()->create();

        // Pressed one after the other before the first answers, as on a slow server, only the last dialog stays open.
        Livewire::actingAs($this->admin())->test('pages::announcements.index')
            ->call('edit', $announcement->id)
            ->assertDispatched('modal-close', name: 'announcement-delete')
            ->call('confirmDelete', $announcement->id)
            ->assertDispatched('modal-close', name: 'announcement-form')
            ->assertDispatched('modal-show', name: 'announcement-delete');
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

    public function test_a_deadline_reads_as_a_full_sentence(): void
    {
        $this->travelTo('2026-10-29 09:00');
        $sentence = fn (int $days) => Announcement::factory()->dueIn($days)->make()->deadline();

        $this->assertSame('Due on November 15, 2026, in 17 days.', $sentence(17));
        $this->assertSame('Due tomorrow, October 30, 2026.', $sentence(1));
        $this->assertSame('Due today, October 29, 2026.', $sentence(0));
        $this->assertSame('Closed on October 28, 2026.', $sentence(-1));
        $this->assertNull(Announcement::factory()->make()->deadline());
    }

    public function test_open_schedules_come_first_then_the_notices_and_closed_schedules_last(): void
    {
        $later = Announcement::factory()->dueIn(30)->create(['title' => 'Detailed proposals due']);
        $sooner = Announcement::factory()->dueIn(3)->create(['title' => 'Concept proposals due']);
        $closedFirst = Announcement::factory()->dueIn(-9)->create(['title' => 'Terminal reports were due']);
        $closed = Announcement::factory()->dueIn(-5)->create(['title' => 'Mid-year reports were due', 'created_at' => now()->subWeek()]);
        $memo = Announcement::factory()->withMemo()->create(['title' => 'Memo on the research agenda']);

        // Open ones soonest first, notices newest first, and closed ones in their own list, the latest deadline first.
        $this->actingAs(User::factory()->create())->get(route('announcements.index'))
            ->assertSeeInOrder(['Upcoming deadlines', $sooner->title, 'in 3 days', $later->title, 'General announcements', $memo->title, 'Show memo', 'Past deadlines', $closed->title, 'Closed on', $closedFirst->title], false);

        // With nothing open, the closed ones still keep to their own list.
        $sooner->delete();
        $later->delete();
        $this->get(route('announcements.index'))->assertDontSee('Upcoming deadlines')->assertSeeInOrder(['General announcements', $memo->title, 'Past deadlines', $closed->title]);

        // With only notices left, only their list shows, with no jumps to other sections.
        $closed->delete();
        $closedFirst->delete();
        $this->get(route('announcements.index'))->assertSee('General announcements')->assertDontSee('Past deadlines')->assertDontSee('data-test="section-jumps"', false);
    }

    public function test_members_open_a_memo_under_its_own_name(): void
    {
        $disk = $this->fakeDrive();
        Livewire::actingAs($this->admin())->test('pages::announcements.index')
            ->call('create')->set('title', 'Memo')->set('description', 'Read the memo.')->set('memo', $this->memo())->call('save');
        $announcement = Announcement::sole();

        $this->actingAs(User::factory()->create())->get(route('announcements.memo', $announcement))
            ->assertOk()
            ->assertHeader('Content-Disposition');
        $this->assertStringContainsString('Memorandum No. 12', $this->get(route('announcements.memo', $announcement))->headers->get('Content-Disposition'));

        // Without a memo there is nothing to open, and a sign-up still waiting for approval can't open one.
        $this->get(route('announcements.memo', Announcement::factory()->create()))->assertNotFound();
        $this->actingAs(User::factory()->pending()->create())->get(route('announcements.memo', $announcement))->assertRedirect(route('approval.notice'));
        $disk->assertExists(Str::after($announcement->attachment_path, '/'));
    }

    public function test_posting_tells_approved_faculty_in_the_portal_only(): void
    {
        $this->fakeDrive();
        Mail::fake();
        $faculty = User::factory()->create();
        $pending = User::factory()->pending()->create();
        $admin = $this->admin();
        $otherAdmin = $this->admin();

        $page = Livewire::actingAs($admin)->test('pages::announcements.index')
            ->call('create')
            ->set('title', 'Schedule of Submission of Concept Proposals')
            ->set('description', 'Concept proposals for 2027 are due on the date below.')
            ->set('dueOn', '2026-11-15')
            ->set('memo', $this->memo())
            ->call('save');

        $this->assertSame(
            'The Research Office has posted a new announcement: “Schedule of Submission of Concept Proposals”. The deadline is November 15, 2026. A memo is attached.',
            $faculty->notifications()->sole()->data['message'],
        );
        $link = route('announcements.index', ['show' => Announcement::sole()->id]).'#announcement-'.Announcement::sole()->id;
        $this->assertSame($link, $faculty->notifications()->sole()->data['url']);
        $this->assertSame(0, $pending->notifications()->count() + $admin->notifications()->count() + $otherAdmin->notifications()->count());
        Mail::assertNothingOutgoing();

        // The notice opens like a message, with its own heading and button.
        Livewire::actingAs($faculty)->test('pages::notifications.index')
            ->call('read', $faculty->notifications()->sole()->id)
            ->assertSee('New announcement')
            ->assertSee('Open Announcements')
            // Its button lands on the announcement itself, with the page loaded in full so the browser marks it.
            ->call('open', $faculty->notifications()->sole()->id)
            ->assertRedirect($link);

        $this->actingAs($faculty)->get(route('announcements.index'))->assertSee('id="announcement-'.Announcement::sole()->id.'"', false);
    }

    public function test_an_edit_tells_faculty_again_and_says_when_it_was_made(): void
    {
        $this->travelTo('2026-10-29 09:00');
        $faculty = User::factory()->create();
        $admin = $this->admin();
        $page = Livewire::actingAs($admin)->test('pages::announcements.index')
            ->call('create')
            ->set('title', 'Schedule of Submission of Concept Proposals')
            ->set('description', 'Concept proposals for 2027 are due on the date below.')
            ->set('dueOn', '2026-11-15')
            ->call('save');
        $announcement = Announcement::sole();
        $faculty->unreadNotifications->markAsRead();

        // Saving an edit that changed nothing tells no one, and the announcement isn't marked edited.
        $this->travelTo('2026-11-01 10:00');
        $page->call('edit', $announcement->id)
            ->call('save');
        $this->assertSame(1, $faculty->notifications()->count());
        $this->assertStringNotContainsString('edited on', $this->actingAs($faculty)->get(route('announcements.index'))->getContent());

        // A moved deadline tells every faculty member, in its own words, with the same link as the post.
        $this->actingAs($admin);
        $page->call('edit', $announcement->id)->set('dueOn', '2026-11-20')->call('save');
        $notice = $faculty->unreadNotifications()->sole();
        $this->assertSame('App\Notifications\AnnouncementUpdated', $notice->type);
        $this->assertSame('The Research Office has updated the announcement “Schedule of Submission of Concept Proposals”. The deadline is November 20, 2026.', $notice->data['message']);
        $this->assertSame(route('announcements.index', ['show' => $announcement->id]).'#announcement-'.$announcement->id, $notice->data['url']);
        $this->assertSame(0, $admin->notifications()->count());

        // The announcement is New again for them, and says when it was edited.
        $this->actingAs($faculty)->get(route('announcements.index'))
            ->assertSeeHtml('data-test="new-badge"')
            ->assertSee('on October 29, 2026, and edited on November 1, 2026');

        // Seeing it reads the notice, and the notice opens under its own heading.
        Livewire::actingAs($faculty)->test('pages::announcements.index')->call('seen', $announcement->id);
        $this->assertSame(0, $faculty->unreadNotifications()->count());
        Livewire::actingAs($faculty)->test('pages::notifications.index')
            ->call('read', $notice->id)
            ->assertSee('Announcement updated')
            ->assertSee('Open Announcements');
    }

    public function test_each_new_announcement_stays_new_until_the_member_has_seen_it(): void
    {
        $faculty = User::factory()->create();
        Announcement::factory()->create(['title' => 'Already seen']);
        $page = Livewire::actingAs($this->admin())->test('pages::announcements.index');
        $page->call('create')->set('title', 'First schedule')->set('description', 'Due soon.')->call('save');
        $page->call('create')->set('title', 'Second schedule')->set('description', 'Due later.')->call('save');
        $first = Announcement::firstWhere('title', 'First schedule');
        $other = $faculty->notifications()->create(['id' => Str::uuid(), 'type' => 'App\Notifications\ProjectCompleted', 'data' => ['message' => 'Done.', 'url' => '/']]);

        // Opening the page reads nothing by itself: both are New, and the bell still counts all three notices.
        $html = $this->actingAs($faculty)->get(route('announcements.index'))->assertSee('3 unread notifications')->getContent();
        $this->assertSame(2, substr_count($html, 'data-test="new-badge"'));
        // The mark says, under the pointer, what clears it.
        $this->assertStringContainsString('This announcement is marked read once it has been on your screen for a moment, or once you open its memo.', $html);

        // One that has been on screen is read on its own, and the bell is told to count again.
        Livewire::test('pages::announcements.index')->call('seen', $first->id)->assertDispatched('open-work-stale');
        $this->assertSame(2, $faculty->unreadNotifications()->count());
        $this->assertNull($other->fresh()->read_at);

        // So the next visit marks only the one not yet seen.
        $html = $this->get(route('announcements.index'))
            ->assertSeeInOrder(['Second schedule', 'data-test="new-badge"', 'First schedule'], false)
            ->getContent();
        $this->assertSame(1, substr_count($html, 'data-test="new-badge"'));
    }

    public function test_a_new_announcement_with_a_memo_is_read_when_its_memo_is_opened(): void
    {
        $this->fakeDrive();
        $faculty = User::factory()->create();
        $page = Livewire::actingAs($this->admin())->test('pages::announcements.index');
        $page->call('create')->set('title', 'Text only')->set('description', 'Nothing attached.')->call('save');
        $page->call('create')->set('title', 'With a poster')->set('description', 'See the poster.')
            ->set('memo', UploadedFile::fake()->create('poster.png', 50, 'image/png'))->call('save');
        $page->call('create')->set('title', 'With a PDF')->set('description', 'See the memo.')->set('memo', $this->memo())->call('save');

        // Only the one without a memo is read by being on screen; the others wait for their memo to be opened, by Show
        // image, Show memo, Preview memo, or downloading it.
        $html = $this->actingAs($faculty)->get(route('announcements.index'))->getContent();
        $this->assertSame(1, substr_count($html, 'x-intersect:enter'));
        $this->assertSame(2, substr_count($html, 'x-on:memo-opened="read()"'));
        $this->assertSame(2, substr_count($html, 'x-on:click="$dispatch(\'memo-opened\'); $dispatch(\'memo-viewer\''));
    }

    public function test_the_page_shows_new_announcements_when_the_sidebar_finds_them(): void
    {
        $this->travelTo('2026-10-29 09:00');
        $faculty = User::factory()->create();
        $page = Livewire::actingAs($faculty)->test('pages::announcements.index');
        $version = fn () => $this->actingAs($faculty)->getJson(route('open-work'))->json('announcements');
        $before = $version();

        $this->travel(1)->minute();
        Livewire::actingAs($this->admin())->test('pages::announcements.index')
            ->call('create')->set('title', 'Posted while the page was open')->set('description', 'Read me.')->call('save');

        // The sidebar's check every minute sees the version move, and the page draws the list again, the post marked New.
        $this->assertNotSame($before, $version());
        $this->actingAs($faculty);
        $page->dispatch('open-work-changed', changed: ['unread'])->assertDontSee('Posted while the page was open');
        $page->dispatch('open-work-changed', changed: ['unread', 'announcements'])
            ->assertSee('Posted while the page was open')
            ->assertSeeHtml('data-test="new-badge"');

        // An edit or a deletion moves it too.
        $this->travel(1)->minute();
        $edited = $version();
        Announcement::sole()->update(['title' => 'Posted, then corrected']);
        $this->assertNotSame($edited, $version());
        Announcement::sole()->delete();
        $this->assertNotSame($edited, $version());
    }

    public function test_a_plain_notice_is_announced_in_one_sentence(): void
    {
        $faculty = User::factory()->create();

        Livewire::actingAs($this->admin())->test('pages::announcements.index')
            ->call('create')->set('title', 'Research Office closed on November 2')->set('description', 'The office reopens on November 3.')->call('save');

        $this->assertSame('The Research Office has posted a new announcement: “Research Office closed on November 2”.', $faculty->notifications()->sole()->data['message']);
    }

    public function test_the_form_says_what_to_fix(): void
    {
        Livewire::actingAs($this->admin())->test('pages::announcements.index')
            ->call('create')
            ->set('title', '   ')
            ->set('memo', UploadedFile::fake()->create('memo.txt', 10, 'text/plain'))
            ->call('save')
            ->assertHasErrors(['title' => 'required', 'description' => 'required', 'memo' => 'mimes'])
            ->assertSee('Enter a title.')
            ->assertSee('The memo must be a PDF, a Word file or an image (JPG, PNG or WebP).');
    }

    public function test_each_kind_of_memo_previews_its_own_way(): void
    {
        $memo = fn (string $name) => Announcement::factory()->create(['title' => $name, 'attachment_path' => "drive-1/announcements/{$name}", 'attachment_name' => $name]);
        $image = $memo('Schedule.PNG');
        $pdf = $memo('Memorandum.pdf');
        $docx = $memo('Guidelines.docx');
        $doc = $memo('Old memo.doc');

        $this->assertSame(['image', 'pdf', 'docx', null], Announcement::orderBy('id')->get()->map->memoPreview()->all());

        // An image, a PDF and a .docx work alike: each unfolds on the page, starting folded, and opens full screen in the
        // memo viewer rather than a new tab. An old .doc only downloads.
        $this->actingAs(User::factory()->create())->get(route('announcements.index'))
            // Folded, the picture gets its address only when first unfolded, so it is never fetched before.
            ->assertSee(str_replace('/', '\/', $image->memoPreviewUrl()), false)
            ->assertDontSee('src="'.e($image->memoPreviewUrl()).'"', false)
            ->assertSee('Show image')
            ->assertSee('Show memo')
            ->assertSeeHtml('aria-controls="memo-fold-'.$pdf->id.'"')
            ->assertSeeHtml('aria-controls="memo-fold-'.$docx->id.'"')
            ->assertDontSeeHtml('aria-controls="memo-fold-'.$doc->id.'"')
            // While Google Drive sends the memo it says so, and if it never comes the memo still downloads.
            ->assertSeeHtml('data-test="memo-loading"')
            ->assertSeeHtml('data-test="memo-failed"')
            ->assertSee('The memo could not be shown here. Download the memo to see it.')
            ->assertSee('Preview memo: Schedule.PNG')
            ->assertSee('Preview memo: Memorandum.pdf')
            ->assertSee('Preview memo: Guidelines.docx')
            ->assertSee('Download memo: Old memo.doc')
            ->assertSee("\$dispatch('memo-viewer'", false)
            ->assertSee('data-test="memo-viewer"', false)
            ->assertSeeHtml('data-test="zoom-in"')
            ->assertSeeHtml('data-test="zoom-out"')
            // Share hands over the memo, or the announcement's own link.
            ->assertSeeHtml('data-test="share-memo"')
            ->assertSee('announcements?show='.$image->id.'#announcement-'.$image->id, false)
            ->assertDontSee('target="_blank" rel="noopener" class="block overflow-hidden', false);
    }

    public function test_posts_read_like_a_classroom_stream(): void
    {
        $this->travelTo('2026-10-29 09:00');
        $open = Announcement::factory()->dueIn(3)->create(['title' => 'Open schedule', 'created_at' => '2026-10-01']);
        Announcement::factory()->create(['title' => 'Plain notice', 'created_at' => '2026-10-02']);
        Announcement::factory()->dueIn(-5)->create(['title' => 'Closed schedule', 'created_at' => '2026-10-03']);

        // Under the header, a jump to each section says what the page holds. Due soon lists the open deadline and jumps
        // to its post. Each post leads with who posted it and when, then its title; no post carries a date tile.
        $this->actingAs(User::factory()->create())->get(route('announcements.index'))
            ->assertSeeInOrder([
                'data-test="section-jumps"',
                'href="#announcements-upcoming"', 'Upcoming deadlines',
                'href="#announcements-other"', 'General announcements',
                'href="#announcements-closed"', 'Past deadlines',
                'data-test="due-soon"', 'href="#announcement-'.$open->id.'"', 'Open schedule', 'in 3 days',
                'on October 1, 2026', 'Open schedule',
                'on October 2, 2026', 'Plain notice',
                'on October 3, 2026', 'Closed schedule',
            ], false)
            ->assertDontSee('data-test="date-tile"', false);

        // With nothing due, there is no Due soon list.
        $open->delete();
        $this->get(route('announcements.index'))->assertDontSee('data-test="due-soon"', false);
    }

    public function test_an_image_memo_is_read_out_by_its_description_or_its_name(): void
    {
        $this->fakeDrive();
        $admin = $this->admin();
        $page = Livewire::actingAs($admin)->test('pages::announcements.index')
            ->call('create')->set('title', 'Schedule')->set('description', 'See the poster.')
            ->set('memo', UploadedFile::fake()->create('poster.png', 50, 'image/png'))
            // The form no longer asks for a description.
            ->assertDontSee('Image description')
            ->call('save');

        // With none, a screen reader hears the memo's name.
        $announcement = Announcement::sole();
        $this->actingAs(User::factory()->create())->get(route('announcements.index'))
            ->assertSee('alt="Memo: poster.png (opens the memo viewer)"', false);

        // One given before the field was removed is still read out, and an edit that leaves the memo alone keeps it.
        $announcement->forceFill(['attachment_alt' => 'Concept proposals are due on November 15, 2026.'])->save();
        $this->get(route('announcements.index'))
            ->assertSee('alt="Concept proposals are due on November 15, 2026. (opens the memo viewer)"', false);
        $this->actingAs($admin);
        $page->call('edit', $announcement->id)->set('title', 'Schedule, revised')->call('save');
        $this->assertSame('Concept proposals are due on November 15, 2026.', $announcement->fresh()->attachment_alt);

        // A new memo in its place drops it, since it described the old one.
        $page->call('edit', $announcement->id)->set('memo', $this->memo())->call('save');
        $this->assertNull($announcement->fresh()->attachment_alt);
    }

    public function test_a_memo_link_naming_its_version_is_kept_by_the_browser(): void
    {
        $this->fakeDrive();
        Livewire::actingAs($this->admin())->test('pages::announcements.index')
            ->call('create')->set('title', 'Memo')->set('description', 'Read the memo.')->set('memo', $this->memo())->call('save');
        $announcement = Announcement::sole();
        $this->actingAs(User::factory()->create());

        $this->assertStringContainsString('max-age=604800', $this->get($announcement->memoUrl())->headers->get('Cache-Control'));
        // The preview falls back to the whole file while there is no smaller copy (always, on the tests' disk), and a
        // browser mustn't keep that in the copy's place.
        $this->assertStringNotContainsString('max-age', $this->get($announcement->memoPreviewUrl())->assertOk()->headers->get('Cache-Control'));

        // An old or bare link still opens the memo, but nothing keeps it.
        $this->assertStringNotContainsString('max-age', $this->get(route('announcements.memo', [$announcement, 'v' => 'old']))->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('max-age', $this->get(route('announcements.memo', $announcement))->headers->get('Cache-Control'));
    }

    public function test_a_notification_lands_on_its_announcement_even_on_a_later_page(): void
    {
        $this->travelTo('2026-10-29 09:00');
        $oldest = Announcement::factory()->create(['title' => 'Oldest memo', 'created_at' => now()->subDays(20)]);
        Announcement::factory()->count(10)->sequence(fn ($sequence) => ['created_at' => now()->subDays(10 - $sequence->index)])->create();
        $this->actingAs(User::factory()->create());

        // Ten newer ones fill the first page, so the link opens the second.
        Livewire::test('pages::announcements.index')->assertDontSee('Oldest memo');
        Livewire::withQueryParams(['show' => $oldest->id])->test('pages::announcements.index')->assertSee('Oldest memo')->assertDontSee('no longer on the portal');

        // A closed schedule is found on its own list's later page, past ten that closed after it.
        $oldestClosed = Announcement::factory()->dueIn(-30)->create(['title' => 'Oldest closed schedule']);
        Announcement::factory()->count(10)->sequence(fn ($sequence) => ['due_on' => today()->subDays(10 - $sequence->index)])->create();
        Livewire::test('pages::announcements.index')->assertDontSee('Oldest closed schedule');
        Livewire::withQueryParams(['show' => $oldestClosed->id])->test('pages::announcements.index')->assertSee('Oldest closed schedule');

        // One deleted since says so, rather than leaving the member to look for it.
        Livewire::withQueryParams(['show' => $oldest->id + 100])->test('pages::announcements.index')
            ->assertSee('The announcement you followed is no longer on the portal.');
    }

    public function test_a_new_schedule_cannot_open_already_closed_but_an_old_one_can_be_corrected(): void
    {
        $this->travelTo('2026-10-29 09:00');
        $closed = Announcement::factory()->dueIn(-5)->create();

        Livewire::actingAs($this->admin())->test('pages::announcements.index')
            ->call('create')->set('title', 'Late schedule')->set('description', 'Due yesterday.')->set('dueOn', '2026-10-28')->call('save')
            ->assertHasErrors(['dueOn' => 'after_or_equal'])
            ->assertSee('The deadline can’t be in the past. Enter today or a later date.')
            ->set('dueOn', '2026-10-29')->call('save')->assertHasNoErrors()
            ->call('edit', $closed->id)->set('title', 'Mid-year reports, corrected')->call('save')->assertHasNoErrors();

        $this->assertSame('Mid-year reports, corrected', $closed->fresh()->title);
    }
}
