<?php

namespace Database\Factories;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'posted_by' => User::factory()->state(['role' => 'admin']),
            'title' => fake()->sentence(6),
            'description' => fake()->paragraph(),
        ];
    }

    /**
     * A schedule of submissions, due $days from today; a negative number is one that has closed.
     */
    public function dueIn(int $days): static
    {
        return $this->state(fn (array $attributes) => ['due_on' => today()->addDays($days)]);
    }

    /**
     * A memo, with its file on the portal's Google Drive.
     */
    public function withMemo(): static
    {
        return $this->state(fn (array $attributes) => ['attachment_path' => 'drive-1/announcements/memo.pdf', 'attachment_name' => 'Memorandum No. 12, s. 2026.pdf']);
    }
}
