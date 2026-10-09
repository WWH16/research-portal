<?php

namespace Database\Factories;

use App\Models\Publication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Publication>
 */
class PublicationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(6),
            'authors' => fake()->name().'; '.fake()->name(),
            'journal' => fake()->lastName().' Journal of Research',
            'volume' => (string) fake()->numberBetween(1, 40),
            'issue' => (string) fake()->numberBetween(1, 12),
            'pages' => '1-12',
            'published_on' => fake()->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
            'link' => 'https://doi.org/10.1000/'.fake()->unique()->bothify('????-####'),
        ];
    }
}
