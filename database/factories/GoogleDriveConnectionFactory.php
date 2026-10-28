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

    /**
     * The storage account is saved, but no one has connected it yet.
     */
    public function unconnected(): static
    {
        return $this->state(fn (array $attributes) => ['refresh_token' => null, 'folder_id' => null]);
    }
}
