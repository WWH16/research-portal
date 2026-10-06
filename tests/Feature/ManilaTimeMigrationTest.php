<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManilaTimeMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_moves_utc_times_to_manila_time_and_back(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        DB::table('users')->update(['created_at' => '2026-10-01 06:00:00']);
        $migration = require database_path('migrations/2026_10_06_123439_shift_timestamps_from_utc_to_manila_time.php');

        $migration->up();
        $row = DB::table('users')->find($user->id);
        $this->assertSame('2026-10-01 14:00:00', $row->created_at);
        $this->assertNull($row->email_verified_at);

        $migration->down();
        $this->assertSame('2026-10-01 06:00:00', DB::table('users')->find($user->id)->created_at);
    }
}
