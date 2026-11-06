<?php

namespace Tests\Feature;

use App\Actions\Fortify\CreateNewUser;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ResearcherIdTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Have another account take the same researcher ID once, just as the next account is about to save.
     */
    private function loseTheNextIdOnce(): void
    {
        $raced = false;
        // Boots the model first, so this listener runs after the one that picks the ID.
        User::query();

        User::creating(function (User $user) use (&$raced) {
            if (! $raced) {
                $raced = true;
                DB::table('users')->insert(['name' => 'Other', 'email' => 'other@isu.edu.ph', 'password' => 'x', 'researcher_id' => $user->researcher_id]);
            }
        });
    }

    public function test_a_sign_up_that_loses_the_race_for_an_id_takes_the_next_one(): void
    {
        $college = Department::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);
        $this->loseTheNextIdOnce();

        $user = app(CreateNewUser::class)->create([
            'name' => 'Maria Santos',
            'email' => 'maria@isu.edu.ph',
            'department_id' => $college->id,
            'password' => 'Str0ng!pass-word',
            'password_confirmation' => 'Str0ng!pass-word',
        ]);

        $this->assertTrue($user->exists);
        $this->assertSame(2, User::distinct()->count('researcher_id'));
    }

    public function test_an_account_an_admin_adds_also_survives_losing_the_race(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->loseTheNextIdOnce();

        Livewire::test('pages::users.index')
            ->call('create')
            ->set('name', 'Maria Santos')
            ->set('email', 'maria@isu.edu.ph')
            ->set('password', 'Str0ng!pass-word')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(User::where('email', 'maria@isu.edu.ph')->exists());
    }

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
        $this->seed();

        $this->assertNotNull(User::where('email', 'admin@isu.edu.ph')->value('researcher_id'));
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
