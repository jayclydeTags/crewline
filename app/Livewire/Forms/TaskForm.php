<?php

namespace App\Livewire\Forms;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\Team;
use Illuminate\Validation\Rule;
use Livewire\Form;

class TaskForm extends Form
{
    public string $title = '';

    public string $description = '';

    public ?string $assignee_id = null;

    public string $status = 'todo';

    public string $priority = 'medium';

    public ?string $due_on = null;

    /**
     * Fill the form from an existing task.
     */
    public function setTask(Task $task): void
    {
        $this->title = $task->title;
        $this->description = (string) $task->description;
        $this->assignee_id = $task->assignee_id ? (string) $task->assignee_id : null;
        $this->status = $task->status->value;
        $this->priority = $task->priority->value;
        $this->due_on = $task->due_on?->toDateString();
    }

    /**
     * Validate the form for a task in the given team and return model attributes.
     *
     * @return array{title: string, description: ?string, assignee_id: ?int, status: TaskStatus, priority: TaskPriority, due_on: ?string}
     */
    public function attributesFor(Team $team): array
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:10000'],
            'assignee_id' => ['nullable', 'integer', Rule::exists('team_user', 'user_id')->where('team_id', $team->id)],
            'status' => ['required', Rule::enum(TaskStatus::class)],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'due_on' => ['nullable', 'date'],
        ], attributes: ['assignee_id' => 'assignee']);

        return [
            'title' => $validated['title'],
            'description' => $validated['description'] ?: null,
            'assignee_id' => $validated['assignee_id'] ? (int) $validated['assignee_id'] : null,
            'status' => TaskStatus::from($validated['status']),
            'priority' => TaskPriority::from($validated['priority']),
            'due_on' => $validated['due_on'] ?: null,
        ];
    }
}
