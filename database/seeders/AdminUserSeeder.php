<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Runs with model events on, so the admin gets a researcher ID like every other account.
 */
class AdminUserSeeder extends Seeder
{
    /**
     * Create the Research Office administrator account if it does not exist yet.
     *
     * The password is a development default; change it after seeding any shared environment.
     */
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@test.com'],
            [
                'name' => 'Research Office',
                'password' => 'password',
                'role' => 'admin',
                'department_id' => null,
            ],
        );

        if (! $admin->hasVerifiedEmail()) {
            $admin->markEmailAsVerified();
        }
    }
}
