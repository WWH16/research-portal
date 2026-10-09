<?php

namespace Database\Factories;

use App\Models\Citation;
use App\Models\Publication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Citation>
 */
class CitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'publication_id' => Publication::factory(),
            'link' => 'https://doi.org/10.2000/'.fake()->unique()->bothify('????-####'),
            'year' => now()->year,
        ];
    }
}
