<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attachable_type' => Task::class,
            'attachable_id' => Task::factory(),
            'disk' => 'local',
            'path' => 'attachments/'.fake()->uuid().'.txt',
            'original_name' => 'notes.txt',
            'mime_type' => 'text/plain',
            'size' => 1024,
        ];
    }
}
