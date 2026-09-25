<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResearcherIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_users_get_sequential_ids_for_the_current_year(): void
    {
        $this->travelTo('2026-09-25');

        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->assertSame('ISU-2026-0001', $first->researcher_id);
        $this->assertSame('ISU-2026-0002', $second->researcher_id);
    }

    public function test_numbering_restarts_each_year(): void
    {
        $this->travelTo('2026-12-31');
        User::factory()->create();

        $this->travelTo('2027-01-01');

        $this->assertSame('ISU-2027-0001', User::factory()->create()->researcher_id);
    }

    public function test_numbering_continues_past_four_digits(): void
    {
        $this->travelTo('2026-09-25');
        User::factory()->create(['researcher_id' => 'ISU-2026-9999']);

        $this->assertSame('ISU-2026-10000', User::factory()->create()->researcher_id);
        $this->assertSame('ISU-2026-10001', User::factory()->create()->researcher_id);
    }

    public function test_an_explicit_id_is_kept(): void
    {
        $this->assertSame('LEGACY-7', User::factory()->create(['researcher_id' => 'LEGACY-7'])->researcher_id);
    }

    public function test_the_seeded_admin_gets_an_id(): void
    {
        $this->seed(AdminUserSeeder::class);

        $this->assertNotNull(User::where('email', 'admin@test.com')->value('researcher_id'));
    }

    public function test_the_backfill_migration_numbers_existing_accounts_by_sign_up_order(): void
    {
        $older = User::factory()->create(['created_at' => '2025-03-01']);
        $newer = User::factory()->create(['created_at' => '2025-06-01']);
        $thisYear = User::factory()->create(['created_at' => '2026-02-01']);
        DB::table('users')->update(['researcher_id' => null]);

        $migration = require database_path('migrations/2026_09_25_154001_backfill_researcher_ids_on_users_table.php');
        $migration->up();

        $this->assertSame('ISU-2025-0001', $older->fresh()->researcher_id);
        $this->assertSame('ISU-2025-0002', $newer->fresh()->researcher_id);
        $this->assertSame('ISU-2026-0001', $thisYear->fresh()->researcher_id);
    }
}
