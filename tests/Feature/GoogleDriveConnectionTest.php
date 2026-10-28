<?php

namespace Tests\Feature;

use App\Models\GoogleDriveConnection;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GoogleDriveConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_refresh_token_is_stored_encrypted(): void
    {
        $connection = GoogleDriveConnection::factory()->create(['refresh_token' => 'secret-refresh-token']);

        $this->assertNotSame('secret-refresh-token', DB::table('google_drive_connections')->value('refresh_token'));
        $this->assertSame('secret-refresh-token', $connection->fresh()->refresh_token);
    }

    public function test_the_refresh_token_never_appears_in_output(): void
    {
        $connection = GoogleDriveConnection::factory()->create(['refresh_token' => 'secret-refresh-token']);

        $this->assertArrayNotHasKey('refresh_token', $connection->toArray());
        $this->assertStringNotContainsString('secret-refresh-token', $connection->toJson());
    }

    public function test_deleting_the_admin_who_connected_keeps_the_connection(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $connection = GoogleDriveConnection::factory()->create(['connected_by' => $admin->id]);

        $admin->delete();

        $this->assertNull($connection->fresh()->connected_by);
    }

    public function test_only_one_storage_account_can_be_current(): void
    {
        GoogleDriveConnection::factory()->create(['email' => 'email1@gmail.com', 'retired_at' => now()]);
        GoogleDriveConnection::factory()->create(['email' => 'email2@gmail.com', 'retired_at' => now()]);
        GoogleDriveConnection::factory()->create(['email' => 'email3@gmail.com']);

        $this->expectException(UniqueConstraintViolationException::class);

        GoogleDriveConnection::factory()->create(['email' => 'email4@gmail.com']);
    }
}
