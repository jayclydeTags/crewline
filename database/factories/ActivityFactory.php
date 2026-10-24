<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\Task;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
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
            'subject_type' => Task::class,
            'subject_id' => Task::factory(),
            'description' => fake()->sentence(),
        ];
    }
}
