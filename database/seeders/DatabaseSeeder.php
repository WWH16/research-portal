<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(AdminUserSeeder::class);

        // User::factory(10)->create();

        $accounts = [
            'test.user@isu.edu.ph' => 'Test User',
            'maria.santos_cyn@isu.edu.ph' => 'Maria Santos',
            'jose.reyes_cyn@isu.edu.ph' => 'Jose Reyes',
            'ana.cruz_cyn@isu.edu.ph' => 'Ana Cruz',
            'mark.bautista_cyn@isu.edu.ph' => 'Mark Bautista',
            'liza.garcia_cyn@isu.edu.ph' => 'Liza Garcia',
        ];

        foreach ($accounts as $email => $name) {
            $user = User::firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => 'password'],
            );

            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            if (! $user->isApproved()) {
                $user->forceFill(['approved_at' => now()])->save();
            }
        }
    }
}
