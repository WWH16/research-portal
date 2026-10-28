<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
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
        ]);
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /**
     * Google answering the callback as the given account, without any network call.
     */
    private function googleReturns(string $email, ?string $refreshToken = 'new-refresh-token', array $scopes = ['openid', GoogleDrive::SCOPE]): void
    {
        $user = (new GoogleUser)->map(['email' => $email])->setToken('access-token')->setRefreshToken($refreshToken)->setApprovedScopes($scopes);
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->andReturn($user);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_connect_asks_for_the_password_first(): void
    {
        GoogleDriveConnection::factory()->unconnected()->create();

        $this->actingAs($this->admin)
            ->get(route('google-drive.connect'))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_connect_opens_google_on_the_storage_account_with_drive_file_access_only(): void
    {
        GoogleDriveConnection::factory()->unconnected()->create(['email' => 'isucyn.facultyresearchportal@gmail.com']);
        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()]);

        $location = $this->get(route('google-drive.connect'))->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith('https://accounts.google.com/', $location);
        $this->assertStringContainsString(urlencode(GoogleDrive::SCOPE), $location);
        $this->assertStringContainsString('access_type=offline', $location);
        $this->assertStringContainsString('prompt=consent', $location);
        $this->assertStringContainsString('login_hint='.urlencode('isucyn.facultyresearchportal@gmail.com'), $location);
    }

    public function test_connect_needs_a_storage_account_first(): void
    {
        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()]);

        $this->get(route('google-drive.connect'))
            ->assertRedirect(route('google-drive.index'))
            ->assertSessionHas('drive-error', 'Save the Google account that stores documents first.');
    }

    public function test_faculty_cannot_connect(): void
    {
        $this->actingAs(User::factory()->create())->get(route('google-drive.callback'))->assertForbidden();
    }

    public function test_callback_connects_the_storage_account_and_creates_the_folder(): void
    {
        GoogleDriveConnection::factory()->unconnected()->create(['email' => 'research.office@isu.edu.ph']);
        $this->googleReturns('Research.Office@isu.edu.ph');
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldReceive('createFolder')->once()->with('new-refresh-token')->andReturn('folder-123'));

        $this->actingAs($this->admin)->get(route('google-drive.callback'))->assertRedirect(route('google-drive.index'));

        $connection = GoogleDriveConnection::sole();
        $this->assertTrue($connection->isConnected());
        $this->assertSame('new-refresh-token', $connection->refresh_token);
        $this->assertSame('folder-123', $connection->folder_id);
        $this->assertSame($this->admin->id, $connection->connected_by);
        $this->assertSame('connected Google Drive', ActivityLog::where('action', 'drive.connected')->sole()->summary());
    }

    public function test_callback_refuses_any_other_account(): void
    {
        GoogleDriveConnection::factory()->unconnected()->create(['email' => 'isucyn.facultyresearchportal@gmail.com']);
        $this->googleReturns('someone.personal@gmail.com');
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldNotReceive('createFolder'));

        $this->actingAs($this->admin)
            ->get(route('google-drive.callback'))
            ->assertSessionHas('drive-error', 'Sign in as isucyn.facultyresearchportal@gmail.com to connect. You signed in as someone.personal@gmail.com.');

        $this->assertFalse(GoogleDriveConnection::sole()->isConnected());
    }

    public function test_reconnecting_keeps_the_folder(): void
    {
        GoogleDriveConnection::factory()->create(['email' => 'research.office@isu.edu.ph', 'folder_id' => 'folder-123', 'refresh_token' => 'old-token']);
        $this->googleReturns('research.office@isu.edu.ph');
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldNotReceive('createFolder'));

        $this->actingAs($this->admin)->get(route('google-drive.callback'))->assertSessionMissing('drive-error');

        $connection = GoogleDriveConnection::sole();
        $this->assertSame('folder-123', $connection->folder_id);
        $this->assertSame('new-refresh-token', $connection->refresh_token);
    }

    public function test_an_earlier_account_can_be_reconnected_so_its_documents_open_again(): void
    {
        // The current account is the older row, so nothing can pick the earlier account by row order alone.
        GoogleDriveConnection::factory()->create(['email' => 'email2@gmail.com', 'refresh_token' => 'email2-token']);
        $email1 = GoogleDriveConnection::factory()->unconnected()->create(['email' => 'email1@gmail.com', 'folder_id' => 'folder-1', 'retired_at' => now()]);
        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()]);

        $location = $this->get(route('google-drive.connect', ['account' => $email1->id]))->headers->get('Location');
        $this->assertStringContainsString('login_hint='.urlencode('email1@gmail.com'), $location);

        $this->googleReturns('email1@gmail.com');
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldNotReceive('createFolder'));

        $this->get(route('google-drive.callback'))->assertSessionMissing('drive-error');

        $email1->refresh();
        $this->assertSame('new-refresh-token', $email1->refresh_token);
        $this->assertSame('folder-1', $email1->folder_id);
        $this->assertNotNull($email1->retired_at);
        $this->assertSame('email2-token', GoogleDriveConnection::current()->refresh_token);
    }

    public function test_callback_refuses_when_the_google_drive_box_was_left_unticked(): void
    {
        GoogleDriveConnection::factory()->unconnected()->create(['email' => 'research.office@isu.edu.ph']);
        $this->googleReturns('research.office@isu.edu.ph', scopes: ['openid', 'https://www.googleapis.com/auth/userinfo.email']);
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldNotReceive('createFolder'));

        $this->actingAs($this->admin)
            ->get(route('google-drive.callback'))
            ->assertRedirect(route('google-drive.index'))
            ->assertSessionHas('drive-error', 'The portal didn’t get access to Google Drive. Click Connect again, and tick the Google Drive box on Google’s screen.');

        $this->assertFalse(GoogleDriveConnection::sole()->isConnected());
    }

    public function test_callback_without_a_refresh_token_asks_to_try_again(): void
    {
        GoogleDriveConnection::factory()->unconnected()->create(['email' => 'research.office@isu.edu.ph']);
        $this->googleReturns('research.office@isu.edu.ph', refreshToken: null);
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldNotReceive('createFolder'));

        $this->actingAs($this->admin)
            ->get(route('google-drive.callback'))
            ->assertSessionHas('drive-error', 'Google didn’t give the portal lasting access. Click Connect again and allow access.');

        $this->assertFalse(GoogleDriveConnection::sole()->isConnected());
    }

    public function test_cancelling_on_google_changes_nothing(): void
    {
        $connection = GoogleDriveConnection::factory()->create();
        $this->mock(GoogleDrive::class, fn (MockInterface $mock) => $mock->shouldNotReceive('createFolder'));

        $this->actingAs($this->admin)
            ->get(route('google-drive.callback', ['error' => 'access_denied']))
            ->assertRedirect(route('google-drive.index'))
            ->assertSessionHas('drive-error', 'Google sign-in was cancelled, so nothing changed.');

        $this->assertSame($connection->refresh_token, GoogleDriveConnection::sole()->refresh_token);
    }
}
