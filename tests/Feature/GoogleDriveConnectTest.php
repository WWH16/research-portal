<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Department;
use App\Models\GoogleDriveConnection;
use App\Models\User;
use App\Services\GoogleDrive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class GoogleDriveConnectTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'portal-client-id',
            'services.google.client_secret' => 'portal-secret',
            'services.google.redirect' => 'http://localhost/google-drive/callback',
            'services.google.drive_domain' => null,
        ]);
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /**
     * Google answering the callback as the given account, without any network call.
     */
    private function googleReturns(string $email, ?string $refreshToken = 'new-refresh-token'): void
    {
        $user = (new GoogleUser)->map(['email' => $email])->setToken('access-token')->setRefreshToken($refreshToken);
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->andReturn($user);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    private function projectWithDocument(): void
    {
        User::factory()->create()->submissions()->create([
            'category_id' => Category::firstOrCreate(['name' => 'Computing'])->id,
            'department_id' => Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies'])->id,
            'title' => 'Stored Study',
            'abstract' => 'An abstract.',
            'start_date' => now(),
            'target_date' => now()->addYear(),
            'concept_path' => 'submissions/concept.pdf',
        ]);
    }

    public function test_connect_asks_for_the_password_first(): void
    {
        $this->actingAs($this->admin)
            ->get(route('google-drive.connect'))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_connect_requests_only_drive_file_access_offline(): void
    {
        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()]);

        $location = $this->get(route('google-drive.connect'))->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith('https://accounts.google.com/', $location);
        $this->assertStringContainsString(urlencode(GoogleDrive::SCOPE), $location);
        $this->assertStringContainsString('access_type=offline', $location);
        $this->assertStringContainsString('prompt=consent', $location);
        $this->assertStringNotContainsString('hd=', $location);

        config(['services.google.drive_domain' => 'isu.edu.ph']);
        $this->assertStringContainsString('hd=isu.edu.ph', $this->get(route('google-drive.connect'))->headers->get('Location'));
    }

    public function test_faculty_cannot_connect(): void
    {
        $this->actingAs(User::factory()->create())->get(route('google-drive.callback'))->assertForbidden();
    }

    public function test_callback_saves_the_connection_and_creates_the_folder(): void
    {
        $this->googleReturns('Research.Office@isu.edu.ph');
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldReceive('createFolder')->once()->with('new-refresh-token')->andReturn('folder-123'));

        $this->actingAs($this->admin)->get(route('google-drive.callback'))->assertRedirect(route('google-drive.index'));

        $connection = GoogleDriveConnection::sole();
        $this->assertSame('research.office@isu.edu.ph', $connection->email);
        $this->assertSame('new-refresh-token', $connection->refresh_token);
        $this->assertSame('folder-123', $connection->folder_id);
        $this->assertSame($this->admin->id, $connection->connected_by);
        $this->assertSame('connected Google Drive', ActivityLog::where('action', 'drive.connected')->sole()->summary());
    }

    public function test_callback_refuses_an_account_outside_the_domain(): void
    {
        config(['services.google.drive_domain' => 'isu.edu.ph']);
        $this->googleReturns('someone@gmail.com');
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldNotReceive('createFolder'));

        $this->actingAs($this->admin)
            ->get(route('google-drive.callback'))
            ->assertRedirect(route('google-drive.index'))
            ->assertSessionHas('drive-error', 'Use a Google account ending in @isu.edu.ph.');

        $this->assertSame(0, GoogleDriveConnection::count());
    }

    public function test_callback_refuses_a_different_account_while_documents_exist(): void
    {
        $old = GoogleDriveConnection::factory()->create(['email' => 'research.office@isu.edu.ph', 'refresh_token' => 'old-token']);
        $this->projectWithDocument();
        $this->googleReturns('other.account@isu.edu.ph');
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldNotReceive('createFolder'));

        $this->actingAs($this->admin)
            ->get(route('google-drive.callback'))
            ->assertSessionHas('drive-error', 'Documents are stored on research.office@isu.edu.ph’s Drive. Connect that account again.');

        $this->assertSame('old-token', GoogleDriveConnection::sole()->refresh_token);
        $this->assertTrue($old->is(GoogleDriveConnection::sole()));
    }

    public function test_reconnecting_the_same_account_keeps_the_folder(): void
    {
        GoogleDriveConnection::factory()->create(['email' => 'research.office@isu.edu.ph', 'folder_id' => 'folder-123', 'refresh_token' => 'old-token']);
        $this->projectWithDocument();
        $this->googleReturns('research.office@isu.edu.ph');
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldNotReceive('createFolder'));

        $this->actingAs($this->admin)->get(route('google-drive.callback'))->assertSessionMissing('drive-error');

        $connection = GoogleDriveConnection::sole();
        $this->assertSame('folder-123', $connection->folder_id);
        $this->assertSame('new-refresh-token', $connection->refresh_token);
    }

    public function test_callback_without_a_refresh_token_asks_to_try_again(): void
    {
        $this->googleReturns('research.office@isu.edu.ph', refreshToken: null);
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldNotReceive('createFolder'));

        $this->actingAs($this->admin)
            ->get(route('google-drive.callback'))
            ->assertSessionHas('drive-error', 'Google didn’t give the portal lasting access. Click Connect again and allow access.');

        $this->assertSame(0, GoogleDriveConnection::count());
    }

    public function test_cancelling_on_google_changes_nothing(): void
    {
        $connection = GoogleDriveConnection::factory()->create();
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldNotReceive('createFolder'));

        $this->actingAs($this->admin)
            ->get(route('google-drive.callback', ['error' => 'access_denied']))
            ->assertRedirect(route('google-drive.index'))
            ->assertSessionHas('drive-error', 'Google sign-in was cancelled, so nothing changed.');

        $this->assertTrue($connection->is(GoogleDriveConnection::sole()));
    }

    public function test_the_page_offers_connect(): void
    {
        $this->actingAs($this->admin)
            ->get(route('google-drive.index'))
            ->assertSeeHtml('href="'.route('google-drive.connect').'"');
    }
}
