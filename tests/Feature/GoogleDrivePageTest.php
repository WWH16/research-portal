<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Department;
use App\Models\GoogleDriveConnection;
use App\Models\Submission;
use App\Models\User;
use App\Services\GoogleDrive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class GoogleDrivePageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['name' => 'Research Office', 'role' => 'admin']);
    }

    /**
     * A project with documents saved on the given storage account.
     */
    private function documentsOn(GoogleDriveConnection $account, int $count): void
    {
        $department = Department::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computer Studies']);
        $paths = array_slice(['concept_path', 'detailed_path', 'midyear_path', 'terminal_path'], 0, $count);

        User::factory()->create()->submissions()->create([
            'category_id' => Category::firstOrCreate(['name' => 'Computing'])->id,
            'department_id' => $department->id,
            'title' => 'Stored Study',
            'abstract' => 'An abstract.',
            'start_date' => now(),
            'target_date' => now()->addYear(),
        ] + array_fill_keys($paths, "drive-{$account->id}/submissions/file.pdf"));
    }

    public function test_only_admins_open_the_google_drive_page(): void
    {
        $this->actingAs(User::factory()->create())->get(route('google-drive.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('google-drive.index'))->assertOk()->assertSee('No storage account yet');
    }

    public function test_an_admin_saves_the_first_storage_account(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::google-drive.index')
            ->set('email', ' IsuCyn.FacultyResearchPortal@Gmail.com ')
            ->call('saveAccount')
            ->assertHasNoErrors();

        $connection = GoogleDriveConnection::sole();
        $this->assertSame('isucyn.facultyresearchportal@gmail.com', $connection->email);
        $this->assertFalse($connection->isConnected());
        $this->assertSame('set the Google Drive storage account', ActivityLog::where('action', 'drive.account_set')->sole()->summary());

        $this->get(route('google-drive.index'))
            ->assertSee('isucyn.facultyresearchportal@gmail.com')
            ->assertSee('Not connected')
            ->assertSeeHtml('href="'.route('google-drive.connect').'"');
    }

    public function test_the_storage_account_must_be_an_email(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::google-drive.index')
            ->set('email', 'not-an-email')
            ->call('saveAccount')
            ->assertHasErrors(['email' => 'email']);

        $this->assertSame(0, GoogleDriveConnection::count());
    }

    public function test_changing_the_account_needs_the_current_account_typed_the_new_one_twice_and_the_password(): void
    {
        GoogleDriveConnection::factory()->create(['email' => 'email1@gmail.com']);
        $this->actingAs($this->admin);

        Livewire::test('pages::google-drive.index')
            ->set('confirmEmail', 'email1@gmail.co')
            ->set('newEmail', 'email2@gmail.com')
            ->set('newEmail_confirmation', 'email3@gmail.com')
            ->set('password', 'wrong-password')
            ->call('changeAccount')
            ->assertHasErrors(['confirmEmail' => 'in', 'newEmail' => 'confirmed', 'password' => 'current_password']);

        $this->assertSame('email1@gmail.com', GoogleDriveConnection::current()->email);
    }

    public function test_changing_the_account_sends_new_uploads_to_it_and_keeps_the_old_one_open(): void
    {
        $email1 = GoogleDriveConnection::factory()->create(['email' => 'email1@gmail.com', 'refresh_token' => 'email1-token']);
        $this->documentsOn($email1, 1);
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldNotReceive('revoke'));
        $this->actingAs($this->admin);

        Livewire::test('pages::google-drive.index')
            ->set('confirmEmail', 'email1@gmail.com')
            ->set('newEmail', 'Email2@gmail.com')
            ->set('newEmail_confirmation', 'email2@gmail.com')
            ->set('password', 'password')
            ->call('changeAccount')
            ->assertHasNoErrors();

        $email1->refresh();
        $this->assertNotNull($email1->retired_at);
        $this->assertSame('email1-token', $email1->refresh_token);
        $current = GoogleDriveConnection::current();
        $this->assertSame('email2@gmail.com', $current->email);
        $this->assertFalse($current->isConnected());

        $log = ActivityLog::where('action', 'drive.account_changed')->sole();
        $this->assertSame('changed the Google Drive storage account', $log->summary());
        $this->assertSame(['from' => 'email1@gmail.com', 'to' => 'email2@gmail.com'], $log->properties);
    }

    public function test_an_admin_who_loses_the_role_with_the_page_open_can_no_longer_use_it(): void
    {
        $html = $this->actingAs($this->admin)->get(route('google-drive.index'))->assertOk()->getContent();
        preg_match('/wire:snapshot="([^"]+)"/', $html, $match);
        $this->admin->forceFill(['role' => 'faculty'])->save();

        $this->withHeaders(['X-Livewire' => 'true'])->postJson(app(HandleRequests::class)->getUpdateUri(), ['components' => [[
            'snapshot' => html_entity_decode($match[1]),
            'updates' => ['email' => 'someone@gmail.com'],
            'calls' => [['path' => '', 'method' => 'saveAccount', 'params' => []]],
        ]]])->assertForbidden();

        $this->assertNull(GoogleDriveConnection::current());
    }

    public function test_changing_away_from_an_account_with_no_documents_drops_it_and_gives_back_its_access(): void
    {
        $email1 = GoogleDriveConnection::factory()->create(['email' => 'email1@gmail.com', 'refresh_token' => 'email1-token']);
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldReceive('revoke')->once()->with('email1-token'));
        $this->actingAs($this->admin);

        Livewire::test('pages::google-drive.index')
            ->set('confirmEmail', 'email1@gmail.com')
            ->set('newEmail', 'email2@gmail.com')
            ->set('newEmail_confirmation', 'email2@gmail.com')
            ->set('password', 'password')
            ->call('changeAccount')
            ->assertHasNoErrors();

        $this->assertNull($email1->fresh());
        $this->assertSame('email2@gmail.com', GoogleDriveConnection::current()->email);
    }

    public function test_switching_back_to_an_earlier_account_reuses_it(): void
    {
        $email1 = GoogleDriveConnection::factory()->create(['email' => 'email1@gmail.com', 'folder_id' => 'folder-1', 'retired_at' => now()]);
        $email2 = GoogleDriveConnection::factory()->create(['email' => 'email2@gmail.com']);
        $this->documentsOn($email2, 1);
        $this->actingAs($this->admin);

        Livewire::test('pages::google-drive.index')
            ->set('confirmEmail', 'email2@gmail.com')
            ->set('newEmail', 'email1@gmail.com')
            ->set('newEmail_confirmation', 'email1@gmail.com')
            ->set('password', 'password')
            ->call('changeAccount')
            ->assertHasNoErrors();

        $this->assertSame(2, GoogleDriveConnection::count());
        $this->assertTrue($email1->fresh()->is(GoogleDriveConnection::current()));
        $this->assertSame('folder-1', $email1->fresh()->folder_id);
        $this->assertNotNull($email2->fresh()->retired_at);
    }

    public function test_switching_accounts_shows_the_new_accounts_storage_at_once(): void
    {
        $email1 = GoogleDriveConnection::factory()->create(['email' => 'email1@gmail.com', 'retired_at' => now()]);
        $email2 = GoogleDriveConnection::factory()->create(['email' => 'email2@gmail.com']);
        $this->documentsOn($email2, 1);
        $storage = fn (int $used) => ['used' => $used, 'limit' => 100, 'drive' => $used, 'portal' => 0];
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldReceive('usage')
            ->andReturnUsing(fn (GoogleDriveConnection $account) => $storage($account->is($email1) ? 10 : 20)));
        $this->actingAs($this->admin);

        Livewire::test('pages::google-drive.index')
            ->call('loadUsage')
            ->assertSet('usage', $storage(20))
            ->set('confirmEmail', 'email2@gmail.com')
            ->set('newEmail', 'email1@gmail.com')
            ->set('newEmail_confirmation', 'email1@gmail.com')
            ->set('password', 'password')
            ->call('changeAccount')
            ->assertSet('usage', $storage(10))
            // Still connected, so there is nothing to connect.
            ->assertDispatched('toast-show', fn ($name, $params) => $params['slots']['text'] === 'Storage account changed. New uploads go to email1@gmail.com.');
    }

    public function test_the_change_warning_counts_the_documents_on_the_current_account(): void
    {
        $email1 = GoogleDriveConnection::factory()->create(['email' => 'email1@gmail.com']);
        $this->documentsOn($email1, 3);

        $this->actingAs($this->admin)
            ->get(route('google-drive.index'))
            ->assertSee('The 3 documents on email1@gmail.com stay there');
    }

    public function test_the_page_names_earlier_accounts_that_still_hold_documents(): void
    {
        $email1 = GoogleDriveConnection::factory()->unconnected()->create(['email' => 'email1@gmail.com', 'folder_id' => 'folder-1', 'retired_at' => now()]);
        $this->documentsOn($email1, 2);
        GoogleDriveConnection::factory()->create(['email' => 'email2@gmail.com']);

        $this->actingAs($this->admin)
            ->get(route('google-drive.index'))
            ->assertSee('Connected as email2@gmail.com')
            ->assertSee('Storage accounts')
            ->assertSeeInOrder(['Current', 'Connected'])
            ->assertSeeInOrder(['Earlier', 'Not connected'])
            ->assertSee('email1@gmail.com')
            ->assertSeeInOrder(['email2@gmail.com', 'email1@gmail.com'])
            ->assertSee('delete these Google accounts')
            ->assertSeeHtml('href="'.route('google-drive.connect', ['account' => $email1->id]).'"');
    }

    public function test_each_account_row_offers_its_own_fix_named_for_its_account(): void
    {
        $email1 = GoogleDriveConnection::factory()->unconnected()->create(['email' => 'email1@gmail.com', 'retired_at' => now()]);
        $this->documentsOn($email1, 2);
        GoogleDriveConnection::factory()->create(['email' => 'email3@gmail.com', 'retired_at' => now()]);
        $email2 = GoogleDriveConnection::factory()->unconnected()->create(['email' => 'email2@gmail.com']);

        $this->actingAs($this->admin)
            ->get(route('google-drive.index'))
            ->assertSeeHtml('href="'.route('google-drive.connect', ['account' => $email2->id]).'"')
            ->assertSeeHtml('aria-label="Connect email2@gmail.com"')
            ->assertSeeHtml('aria-label="Reconnect email1@gmail.com"')
            ->assertSeeHtml('aria-label="Remove email3@gmail.com"');
    }

    public function test_the_page_shows_who_is_connected(): void
    {
        GoogleDriveConnection::factory()->create(['email' => 'research.office@isu.edu.ph', 'connected_by' => $this->admin->id]);

        $this->actingAs($this->admin)
            ->get(route('google-drive.index'))
            ->assertSee('Connected as research.office@isu.edu.ph')
            ->assertSee('Research Office')
            ->assertSee('its “Faculty Research Portal” folder')
            ->assertDontSee('Not connected')
            // Nothing is wrong, so there is nothing to reconnect.
            ->assertDontSee('Reconnect');
    }

    public function test_the_page_warns_while_the_saved_account_is_not_connected(): void
    {
        $this->actingAs($this->admin);

        $this->get(route('google-drive.index'))->assertDontSeeHtml('data-test="drive-warning"');

        GoogleDriveConnection::factory()->unconnected()->create();

        $this->get(route('google-drive.index'))->assertSeeHtml('data-test="drive-warning"');
    }

    public function test_disconnect_revokes_access_and_keeps_the_account(): void
    {
        $connection = GoogleDriveConnection::factory()->create(['refresh_token' => 'secret-refresh-token', 'folder_id' => 'folder-123']);
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldReceive('revoke')->once()->with('secret-refresh-token'));

        $this->actingAs($this->admin);

        Livewire::test('pages::google-drive.index')
            ->set('confirmEmail', 'Research.Office@isu.edu.ph')
            ->set('password', 'password')
            ->call('disconnect')
            ->assertHasNoErrors();

        $connection->refresh();
        $this->assertFalse($connection->isConnected());
        $this->assertSame('folder-123', $connection->folder_id);
        $log = ActivityLog::where('action', 'drive.disconnected')->sole();
        $this->assertSame('research.office@isu.edu.ph', $log->subject_label);
        $this->assertSame('disconnected Google Drive', $log->summary());
    }

    public function test_disconnect_needs_the_account_typed_and_the_password(): void
    {
        $connection = GoogleDriveConnection::factory()->create(['email' => 'email1@gmail.com']);
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldNotReceive('revoke'));
        $this->actingAs($this->admin);

        Livewire::test('pages::google-drive.index')
            ->call('disconnect')
            ->assertHasErrors(['confirmEmail' => 'required', 'password' => 'required'])
            ->set('confirmEmail', 'someone@gmail.com')
            ->set('password', 'wrong-password')
            ->call('disconnect')
            ->assertHasErrors(['confirmEmail' => 'in', 'password' => 'current_password']);

        $this->assertTrue($connection->fresh()->isConnected());
    }

    public function test_the_disconnect_warning_counts_the_documents_that_stop_opening(): void
    {
        $email1 = GoogleDriveConnection::factory()->create(['email' => 'email1@gmail.com']);
        $this->documentsOn($email1, 2);

        $this->actingAs($this->admin)
            ->get(route('google-drive.index'))
            ->assertSee('2 documents on email1@gmail.com can’t be opened until you connect again');
    }

    public function test_the_system_menu_links_to_the_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertSeeHtml('href="'.route('google-drive.index').'"');
    }

    public function test_the_page_shows_how_full_the_drive_is_and_warns_when_nearly_full(): void
    {
        GoogleDriveConnection::factory()->create(['email' => 'email1@gmail.com']);
        $gib = 1024 ** 3;
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldReceive('usage')->once()->andReturn(['used' => (int) (14.5 * $gib), 'limit' => 15 * $gib, 'drive' => 13 * $gib, 'portal' => 2 * $gib]));
        $this->actingAs($this->admin);

        Livewire::test('pages::google-drive.index')
            ->assertSee('Checking storage…')
            ->call('loadUsage')
            ->assertSee('14.5 GB')
            ->assertSee('of 15 GB used')
            ->assertSeeInOrder(['Research portal documents', '2 GB', 'Other Google Drive files', '11 GB', 'Gmail, Google Photos and other', '1.5 GB'])
            ->assertSee('Google Drive is almost full');
    }

    public function test_the_usage_stays_hidden_when_google_cannot_answer(): void
    {
        GoogleDriveConnection::factory()->create();
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldReceive('usage')->andThrow(new RuntimeException('Google is down')));
        $this->actingAs($this->admin);

        Livewire::test('pages::google-drive.index')
            ->call('loadUsage')
            ->assertSet('usage', false)
            ->assertSee('Storage use unavailable right now.')
            ->assertDontSeeHtml('data-test="drive-usage"');
    }

    public function test_the_page_asks_to_connect_again_when_the_folder_is_gone_from_google_drive(): void
    {
        GoogleDriveConnection::factory()->create(['folder_id' => 'folder-123']);
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldReceive('usage')->andThrow(new RuntimeException('{"error": {"code": 404, "message": "File not found: folder-123."}}')));
        $this->actingAs($this->admin);

        Livewire::test('pages::google-drive.index')
            ->call('loadUsage')
            ->assertRedirect(route('google-drive.index'));

        $this->get(route('google-drive.index'))
            ->assertSee('folder is gone from Google Drive')
            ->assertSeeHtml('data-test="connect-drive-button"');
    }

    public function test_enter_in_the_confirmation_forms_moves_to_the_next_field(): void
    {
        GoogleDriveConnection::factory()->create();
        $this->actingAs($this->admin);

        $html = Livewire::test('pages::google-drive.index')->set('step', 2)->html();

        // Change account and Disconnect: their buttons stay disabled until the address matches, so Enter can't submit.
        $this->assertSame(2, substr_count($html, 'x-on:keydown.enter='));
    }

    public function test_the_page_links_to_the_drive_folder(): void
    {
        GoogleDriveConnection::factory()->create(['folder_id' => 'folder-123']);

        $this->actingAs($this->admin)
            ->get(route('google-drive.index'))
            ->assertSeeHtml('href="https://drive.google.com/drive/folders/folder-123"');
    }

    public function test_the_page_lists_recent_drive_changes(): void
    {
        $this->actingAs($this->admin);
        ActivityLog::record('drive.connected', GoogleDriveConnection::factory()->create(['email' => 'email1@gmail.com']));
        ActivityLog::record('auth.login');

        $this->get(route('google-drive.index'))
            ->assertSee('Recent changes')
            ->assertSee('connected Google Drive')
            ->assertDontSee('signed in')
            ->assertSeeHtml('href="'.route('activity-log.index', ['group' => 'drive']).'"');
    }

    public function test_an_earlier_account_with_no_documents_left_can_be_removed(): void
    {
        $email1 = GoogleDriveConnection::factory()->create(['email' => 'email1@gmail.com', 'refresh_token' => 'email1-token', 'retired_at' => now()]);
        GoogleDriveConnection::factory()->create(['email' => 'email2@gmail.com']);
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldReceive('revoke')->once()->with('email1-token'));
        $this->actingAs($this->admin);

        Livewire::test('pages::google-drive.index')
            ->assertSeeHtml('wire:loading.attr="data-flux-loading" wire:click="removeEarlier('.$email1->id.')"')
            ->call('removeEarlier', $email1->id)
            ->assertDontSeeHtml('data-test="remove-earlier-button"');

        $this->assertNull($email1->fresh());
        $this->assertSame(1, ActivityLog::where('action', 'drive.account_removed')->count());
    }

    public function test_an_earlier_account_that_holds_documents_is_never_removed(): void
    {
        $email1 = GoogleDriveConnection::factory()->create(['email' => 'email1@gmail.com', 'retired_at' => now()]);
        $this->documentsOn($email1, 1);
        GoogleDriveConnection::factory()->create(['email' => 'email2@gmail.com']);
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldNotReceive('revoke'));
        $this->actingAs($this->admin);

        Livewire::test('pages::google-drive.index')->call('removeEarlier', $email1->id);

        $this->assertNotNull($email1->fresh());
    }

    public function test_a_failed_connect_shows_in_the_uploads_failing_callout_alone(): void
    {
        GoogleDriveConnection::factory()->unconnected()->create();

        $this->actingAs($this->admin)
            ->withSession(['drive-error' => 'Google sign-in was cancelled, so nothing changed.'])
            ->get(route('google-drive.index'))
            ->assertSee('Document uploads are failing')
            ->assertSee('Google sign-in was cancelled, so nothing changed.')
            ->assertDontSeeHtml('data-test="drive-error"');
    }

    public function test_recent_changes_name_only_an_account_other_than_the_current_one(): void
    {
        $this->actingAs($this->admin);
        $email1 = GoogleDriveConnection::factory()->create(['email' => 'email1@gmail.com', 'retired_at' => now()]);
        $this->documentsOn($email1, 1);
        $email2 = GoogleDriveConnection::factory()->create(['email' => 'email2@gmail.com']);
        ActivityLog::record('drive.connected', $email1);
        ActivityLog::record('drive.connected', $email2);

        $html = $this->get(route('google-drive.index'))->getContent();
        $recent = Str::before(Str::after($html, 'data-test="drive-recent"'), '</ul>');

        $this->assertStringContainsString('email1@gmail.com', $recent);
        $this->assertStringNotContainsString('email2@gmail.com', $recent);
    }

    public function test_a_large_plan_reads_in_terabytes_without_a_warning(): void
    {
        GoogleDriveConnection::factory()->create();
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldReceive('usage')->andReturn(['used' => (int) (24.9 * 1024 ** 3), 'limit' => 5 * 1024 ** 4, 'drive' => (int) (20 * 1024 ** 3), 'portal' => (int) (1.2 * 1024 ** 3)]));
        $this->actingAs($this->admin);

        Livewire::test('pages::google-drive.index')
            ->call('loadUsage')
            ->assertSee('24.9 GB')
            ->assertSee('of 5 TB used')
            ->assertSeeHtml('aria-valuetext="24.9 GB of 5 TB used"')
            ->assertSee('1.2 GB')
            ->assertDontSee('Google Drive is almost full');
    }

    public function test_each_section_is_a_real_heading_and_documents_are_counted_in_one_query(): void
    {
        $email1 = GoogleDriveConnection::factory()->create(['email' => 'email1@gmail.com', 'retired_at' => now()]);
        $this->documentsOn($email1, 2);
        $email2 = GoogleDriveConnection::factory()->create(['email' => 'email2@gmail.com']);
        $this->documentsOn($email2, 3);
        ActivityLog::record('drive.connected', $email2, user: $this->admin);

        $this->actingAs($this->admin)
            ->get(route('google-drive.index'))
            ->assertSeeHtml('<h2')
            ->assertSeeInOrder(['Storage accounts', 'Recent changes']);

        $this->assertSame([$email1->id => 2, $email2->id => 3], Submission::documentsPerAccount()->sortKeys()->all());
        $this->assertQueriesAtMost(1, fn () => Submission::documentsPerAccount());
    }
}
