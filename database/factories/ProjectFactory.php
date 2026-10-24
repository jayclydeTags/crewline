<?php

namespace Database\Factories;

use App\Enums\ProjectVisibility;
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
            'visibility' => ProjectVisibility::Team,
            'starts_on' => now()->subWeek(),
            'ends_on' => now()->addMonth(),
        ];
    }

    /**
     * Indicate that only project members and team admins can see the project.
     */
    public function private(): static
    {
        return $this->state(fn (array $attributes) => [
            'visibility' => ProjectVisibility::Private,
        ]);
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
