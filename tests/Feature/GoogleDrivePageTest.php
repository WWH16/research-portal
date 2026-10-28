<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
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
        $this->actingAs($this->admin)->get(route('google-drive.index'))->assertOk()->assertSee('Not connected');
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
        $this->actingAs($this->admin);

        $this->get(route('google-drive.index'))->assertDontSeeHtml('data-test="drive-warning"');

        config(['filesystems.disks.submissions.driver' => 'google']);

        $this->get(route('google-drive.index'))->assertSeeHtml('data-test="drive-warning"');
    }

    public function test_disconnect_revokes_and_removes_the_connection(): void
    {
        $connection = GoogleDriveConnection::factory()->create(['refresh_token' => 'secret-refresh-token']);
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldReceive('revoke')->once()->with('secret-refresh-token'));

        $this->actingAs($this->admin);

        Livewire::test('pages::google-drive.index')->call('disconnect');

        $this->assertModelMissing($connection);
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
