<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Department;
use App\Models\GoogleDriveConnection;
use App\Models\User;
use App\Services\GoogleDrive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;
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

    public function test_only_admins_open_the_google_drive_page(): void
    {
        $this->actingAs(User::factory()->create())->get(route('google-drive.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('google-drive.index'))->assertOk()->assertSee('No storage account yet');
    }

    public function test_an_admin_saves_the_storage_account(): void
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

    public function test_the_account_can_change_while_no_documents_are_stored(): void
    {
        $connection = GoogleDriveConnection::factory()->create(['email' => 'old@gmail.com', 'refresh_token' => 'old-token']);
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldReceive('revoke')->once()->with('old-token'));

        $this->actingAs($this->admin);

        Livewire::test('pages::google-drive.index')
            ->set('email', 'isucyn.facultyresearchportal@gmail.com')
            ->call('saveAccount')
            ->assertHasNoErrors();

        $connection->refresh();
        $this->assertSame('isucyn.facultyresearchportal@gmail.com', $connection->email);
        $this->assertFalse($connection->isConnected());
        $this->assertNull($connection->folder_id);
    }

    public function test_the_account_cannot_change_once_documents_are_stored(): void
    {
        GoogleDriveConnection::factory()->create(['email' => 'isucyn.facultyresearchportal@gmail.com']);
        $owner = User::factory()->create();
        $owner->submissions()->create([
            'category_id' => Category::firstOrCreate(['name' => 'Computing'])->id,
            'department_id' => Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies'])->id,
            'title' => 'Stored Study',
            'abstract' => 'An abstract.',
            'start_date' => now(),
            'target_date' => now()->addYear(),
            'concept_path' => 'submissions/concept.pdf',
        ]);

        $this->actingAs($this->admin);

        Livewire::test('pages::google-drive.index')
            ->set('email', 'someone.else@gmail.com')
            ->call('saveAccount')
            ->assertHasErrors(['email']);

        $this->assertSame('isucyn.facultyresearchportal@gmail.com', GoogleDriveConnection::sole()->email);
    }

    public function test_the_page_shows_who_is_connected(): void
    {
        GoogleDriveConnection::factory()->create(['email' => 'research.office@isu.edu.ph', 'connected_by' => $this->admin->id]);

        $this->actingAs($this->admin)
            ->get(route('google-drive.index'))
            ->assertSee('Connected as research.office@isu.edu.ph')
            ->assertSee('Research Office')
            ->assertDontSee('Not connected');
    }

    public function test_the_page_warns_when_drive_storage_is_on_but_not_connected(): void
    {
        GoogleDriveConnection::factory()->unconnected()->create();
        $this->actingAs($this->admin);

        $this->get(route('google-drive.index'))->assertDontSeeHtml('data-test="drive-warning"');

        config(['filesystems.disks.submissions.driver' => 'google']);

        $this->get(route('google-drive.index'))->assertSeeHtml('data-test="drive-warning"');
    }

    public function test_disconnect_revokes_access_and_keeps_the_account(): void
    {
        $connection = GoogleDriveConnection::factory()->create(['refresh_token' => 'secret-refresh-token', 'folder_id' => 'folder-123']);
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldReceive('revoke')->once()->with('secret-refresh-token'));

        $this->actingAs($this->admin);

        Livewire::test('pages::google-drive.index')->call('disconnect');

        $connection->refresh();
        $this->assertFalse($connection->isConnected());
        $this->assertSame('folder-123', $connection->folder_id);
        $log = ActivityLog::where('action', 'drive.disconnected')->sole();
        $this->assertSame('research.office@isu.edu.ph', $log->subject_label);
        $this->assertSame('disconnected Google Drive', $log->summary());
    }

    public function test_the_system_menu_links_to_the_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertSeeHtml('href="'.route('google-drive.index').'"');
    }
}
