<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Runs with model events on, so each account gets a researcher ID like any other.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Create the portal's starting accounts if they do not exist yet.
     *
     * The password comes from SEED_PASSWORD, so production never gets one that is written in the code. Other
     * environments fall back to a development default.
     */
    public function run(): void
    {
        $password = config('auth.seed_password') ?: (app()->isProduction()
            ? throw new RuntimeException('Set SEED_PASSWORD before seeding on production.')
            : 'password');

        $accounts = [
            'admin@isu.edu.ph' => ['Research Office', 'admin'],
            'staff@isu.edu.ph' => ['Research Office Staff', 'staff'],
            'test.user@isu.edu.ph' => ['Test User', 'faculty'],
        ];

        foreach ($accounts as $email => [$name, $role]) {
            // The role isn't mass assignable, so the seeder lifts that guard for these trusted accounts. Only a new
            // account takes these values; an existing one keeps its password and role.
            User::unguarded(fn () => User::firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => $password, 'role' => $role, 'email_verified_at' => now(), 'approved_at' => now()],
            ));
        }
    }
}
