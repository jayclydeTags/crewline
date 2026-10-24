<?php

namespace App\Livewire\Forms;

use App\Enums\ProjectVisibility;
use App\Models\Project;
use Illuminate\Validation\Rule;
use Livewire\Form;

class ProjectForm extends Form
{
    public string $name = '';

    public string $description = '';

    public string $color = 'blue';

    public string $visibility = 'team';

    public ?string $starts_on = null;

    public ?string $ends_on = null;

    /**
     * Fill the form from an existing project.
     */
    public function setProject(Project $project): void
    {
        $this->name = $project->name;
        $this->description = (string) $project->description;
        $this->color = $project->color;
        $this->visibility = $project->visibility->value;
        $this->starts_on = $project->starts_on?->toDateString();
        $this->ends_on = $project->ends_on?->toDateString();
    }

    /**
     * Get the validation rules for the form.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'color' => ['required', Rule::in(Project::COLORS)],
            'visibility' => ['required', Rule::enum(ProjectVisibility::class)],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ];
    }

    /**
     * Validate the form and return attributes ready for the model.
     *
     * @return array{name: string, description: ?string, color: string, visibility: ProjectVisibility, starts_on: ?string, ends_on: ?string}
     */
    public function attributes(): array
    {
        $validated = $this->validate();

        return [
            'name' => $validated['name'],
            'description' => $validated['description'] ?: null,
            'color' => $validated['color'],
            'visibility' => ProjectVisibility::from($validated['visibility']),
            'starts_on' => $validated['starts_on'] ?: null,
            'ends_on' => $validated['ends_on'] ?: null,
        ];
    }
}
