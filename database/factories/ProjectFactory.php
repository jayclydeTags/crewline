<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => Str::title(fake()->word().' '.fake()->word()),
            'description' => fake()->sentence(),
            'color' => fake()->randomElement(Project::COLORS),
            'starts_on' => now()->subWeek(),
            'ends_on' => now()->addMonth(),
        ];
    }

    /**
     * Indicate that the project has been archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'archived_at' => now(),
        ]);
    }
}
