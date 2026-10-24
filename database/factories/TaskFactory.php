<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'status' => TaskStatus::Todo,
            'priority' => fake()->randomElement(TaskPriority::cases()),
            'due_on' => now()->addDays(fake()->numberBetween(1, 30)),
            'position' => 0,
        ];
    }

    /**
     * Indicate that the task is done.
     */
    public function done(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskStatus::Done,
            'completed_at' => now(),
        ]);
    }

    /**
     * Indicate that the task was due yesterday.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'due_on' => now()->subDay(),
        ]);
    }
}
