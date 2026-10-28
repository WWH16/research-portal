<?php

namespace Database\Factories;

use App\Models\GoogleDriveConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GoogleDriveConnection>
 */
class GoogleDriveConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => 'research.office@isu.edu.ph',
            'refresh_token' => Str::random(40),
            'folder_id' => Str::random(28),
            'connected_by' => null,
        ];
    }
}
