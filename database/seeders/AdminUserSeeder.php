<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Runs with model events on, so the admin gets a researcher ID like every other account.
 */
class AdminUserSeeder extends Seeder
{
    /**
     * Create the Research Office administrator account if it does not exist yet.
     *
     * The password comes from ADMIN_SEED_PASSWORD, so production never gets one that is written in the code. Other
     * environments fall back to a development default.
     */
    public function run(): void
    {
        $password = config('auth.admin_seed_password') ?: (app()->isProduction()
            ? throw new RuntimeException('Set ADMIN_SEED_PASSWORD before seeding on production.')
            : 'password');

        // The role isn't mass assignable, so the seeder lifts that guard for this one trusted account.
        $admin = User::unguarded(fn () => User::firstOrCreate(
            // Only a new account takes the password; an existing admin keeps theirs.
            ['email' => 'admin@isu.edu.ph'],
            [
                'name' => 'Research Office',
                'password' => $password,
                'role' => 'admin',
                'department_id' => null,
            ],
        ));

        if (! $admin->hasVerifiedEmail()) {
            $admin->markEmailAsVerified();
        }

        if (! $admin->isApproved()) {
            $admin->forceFill(['approved_at' => now()])->save();
        }
    }
}
