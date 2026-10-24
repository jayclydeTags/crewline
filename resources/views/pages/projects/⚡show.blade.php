<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Livewire\Forms\ProjectForm;
use App\Livewire\Forms\TaskForm;
use App\Models\Project;
use App\Models\Task;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    public Project $project;

    public ProjectForm $projectForm;

    public TaskForm $taskForm;

    #[Url]
    public string $view = 'board';

    #[Url(as: 'assignee')]
    public string $assigneeFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'priority')]
    public string $priorityFilter = '';

    #[Url(as: 'due')]
    public string $dueFilter = '';

    #[Url(as: 'q')]
    public string $search = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('view', $this->project);

        $this->projectForm->setProject($this->project);

        if (! in_array($this->view, ['board', 'list', 'activity'], true)) {
            $this->view = 'board';
        }
    }

    /**
     * Render the component.
     */
    public function render(): \Illuminate\Contracts\View\View
    {
        return $this->view()->title($this->project->name);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\User>
     */
    #[Computed]
    public function members()
    {
        return $this->project->team->members()->orderBy('name')->get();
    }

    /**
     * Get the project's tasks grouped by status for the board.
     *
     * @return \Illuminate\Support\Collection<string, \Illuminate\Database\Eloquent\Collection<int, Task>>
     */
    #[Computed]
    public function columns()
    {
        $tasks = $this->taskQuery()->orderBy('position')->orderBy('id')->get()->groupBy(fn (Task $task) => $task->status->value);

        return collect(TaskStatus::cases())->mapWithKeys(fn (TaskStatus $status) => [$status->value => $tasks->get($status->value, collect())]);
    }

    /**
     * Get the filtered tasks for the list view.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Task>
     */
    #[Computed]
    public function tasks()
    {
        return $this->taskQuery()
            ->when($this->statusFilter !== '', fn (Builder $query) => $query->where('status', TaskStatus::tryFrom($this->statusFilter)))
            ->when($this->priorityFilter !== '', fn (Builder $query) => $query->where('priority', TaskPriority::tryFrom($this->priorityFilter)))
            ->when($this->assigneeFilter === 'none', fn (Builder $query) => $query->whereNull('assignee_id'))
            ->when($this->assigneeFilter === 'me', fn (Builder $query) => $query->where('assignee_id', Auth::id()))
            ->when(ctype_digit($this->assigneeFilter), fn (Builder $query) => $query->where('assignee_id', (int) $this->assigneeFilter))
            ->when($this->dueFilter === 'overdue', fn (Builder $query) => $query->open()->whereDate('due_on', '<', today()))
            ->when($this->dueFilter === 'today', fn (Builder $query) => $query->whereDate('due_on', today()))
            ->when($this->dueFilter === 'week', fn (Builder $query) => $query->whereDate('due_on', '>=', today())->whereDate('due_on', '<=', today()->addDays(7)))
            ->when($this->dueFilter === 'none', fn (Builder $query) => $query->whereNull('due_on'))
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->where('title', 'like', '%'.trim($this->search).'%'))
            ->orderByRaw("case status when 'done' then 1 else 0 end")
            ->orderByRaw('due_on is null')
            ->orderBy('due_on')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Activity>
     */
    #[Computed]
    public function activities()
    {
        return $this->project->activities()->with('user')->latest('id')->limit(50)->get();
    }

    /**
     * Determine whether the current user may change tasks in this project.
     */
    #[Computed]
    public function canEditTasks(): bool
    {
        return Auth::user()->can('create', [Task::class, $this->project]);
    }

    /**
     * Whether any list filter is active.
     */
    #[Computed]
    public function hasFilters(): bool
    {
        return collect([$this->assigneeFilter, $this->statusFilter, $this->priorityFilter, $this->dueFilter, trim($this->search)])->filter()->isNotEmpty();
    }

    /**
     * Clear all list filters.
     */
    public function clearFilters(): void
    {
        $this->reset('assigneeFilter', 'statusFilter', 'priorityFilter', 'dueFilter', 'search');
    }

    /**
     * Open the new task modal, optionally pre-selecting a status column.
     */
    public function newTask(string $status = 'todo'): void
    {
        $this->taskForm->reset();
        $this->taskForm->status = TaskStatus::tryFrom($status)?->value ?? TaskStatus::Todo->value;
        $this->resetValidation();

        Flux::modal('create-task')->show();
    }

    /**
     * Create a task in the project.
     */
    public function createTask(): void
    {
        $this->authorize('create', [Task::class, $this->project]);

        $attributes = $this->taskForm->attributesFor($this->project->team);

        $task = $this->project->tasks()->create([
            ...$attributes,
            'creator_id' => Auth::id(),
            'position' => Task::nextPosition($this->project, $attributes['status']),
        ]);

        if ($task->status === TaskStatus::Done) {
            $task->forceFill(['completed_at' => now()])->save();
        }

        $task->recordActivity(__('created “:task”', ['task' => $task->title]), Auth::user());
        $task->notifyAssigneeIfChanged(null, Auth::user());

        $this->taskForm->reset();
        Flux::modal('create-task')->close();
        Flux::toast(variant: 'success', text: __('Task created.'));
    }

    /**
     * Handle a task being dragged (or moved from its menu) to a column and position.
     */
    public function moveTask(int $id, int $position, string $status): void
    {
        $task = $this->project->tasks()->findOrFail($id);

        $this->authorize('update', $task);

        $task->setRelation('project', $this->project);
        $task->moveTo(TaskStatus::from($status), $position, Auth::user());

        unset($this->columns, $this->activities);
    }

    /**
     * Update the project's details.
     */
    public function updateProject(): void
    {
        $this->authorize('update', $this->project);

        $this->project->update($this->projectForm->attributes());
        $this->project->recordActivity(__('updated the project details'), Auth::user());

        Flux::modal('edit-project')->close();
        Flux::toast(variant: 'success', text: __('Project updated.'));
    }

    /**
     * Archive or restore the project.
     */
    public function toggleArchive(): void
    {
        $this->authorize('archive', $this->project);

        $archiving = ! $this->project->isArchived();

        $this->project->forceFill(['archived_at' => $archiving ? now() : null])->save();
        $this->project->recordActivity($archiving ? __('archived the project') : __('restored the project'), Auth::user());

        unset($this->canEditTasks);

        Flux::toast(text: $archiving ? __('Project archived.') : __('Project restored.'));
    }

    /**
     * Permanently delete the project.
     */
    public function deleteProject(): void
    {
        $this->authorize('delete', $this->project);

        $this->project->tasks()->with('attachments')->get()
            ->each(fn (Task $task) => $task->attachments->each->delete());

        $this->project->delete();

        Flux::toast(text: __('Project deleted.'));

        $this->redirectRoute('projects.index', navigate: true);
    }

    /**
     * Get the base query for tasks with everything the views display.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<Task, Project>
     */
    private function taskQuery()
    {
        return $this->project->tasks()
            ->with(['assignee', 'labels'])
            ->withCount([
                'comments',
                'checklistItems',
                'checklistItems as completed_checklist_items_count' => fn (Builder $query) => $query->where('is_completed', true),
            ]);
    }
}; ?>

<div class="flex h-full flex-col gap-6">
    <header class="space-y-4">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('projects.index')" wire:navigate>{{ __('Projects') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $project->name }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0 space-y-1">
                <div class="flex items-center gap-3">
                    <span class="size-3 shrink-0 rounded-full bg-{{ $project->color }}-500" aria-hidden="true"></span>
                    <flux:heading size="xl" level="1" class="truncate">{{ $project->name }}</flux:heading>
                    @if ($project->isArchived())
                        <flux:badge size="sm" icon="archive-box">{{ __('Archived') }}</flux:badge>
                    @endif
                </div>
                @if ($project->description)
                    <flux:text class="max-w-2xl">{{ $project->description }}</flux:text>
                @endif
                @if ($project->starts_on || $project->ends_on)
                    <flux:text class="flex items-center gap-1 text-sm">
                        <flux:icon.calendar variant="micro" />
                        {{ $project->starts_on?->toFormattedDateString() ?? '…' }} – {{ $project->ends_on?->toFormattedDateString() ?? '…' }}
                    </flux:text>
                @endif
            </div>

            <div class="flex items-center gap-2">
                @if ($this->canEditTasks)
                    <flux:button variant="primary" icon="plus" wire:click="newTask" data-test="new-task">{{ __('New task') }}</flux:button>
                @endif

                @can('update', $project)
                    <flux:dropdown align="end">
                        <flux:button icon="ellipsis-horizontal" aria-label="{{ __('Project actions') }}" />
                        <flux:menu>
                            <flux:modal.trigger name="edit-project">
                                <flux:menu.item icon="pencil-square">{{ __('Edit project') }}</flux:menu.item>
                            </flux:modal.trigger>
                            <flux:menu.item icon="archive-box" wire:click="toggleArchive">
                                {{ $project->isArchived() ? __('Restore project') : __('Archive project') }}
                            </flux:menu.item>
                            <flux:menu.separator />
                            <flux:menu.item icon="trash" variant="danger" wire:click="deleteProject"
                                wire:confirm="{{ __('Delete “:name” and all of its tasks? This cannot be undone.', ['name' => $project->name]) }}">
                                {{ __('Delete project') }}
                            </flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                @endcan
            </div>
        </div>

        @php($progress = $project->progress())
        <div class="flex max-w-md items-center gap-3">
            <flux:progress :value="$progress" :color="$project->color" class="flex-1" aria-label="{{ __('Project progress') }}" />
            <span class="text-sm text-zinc-500 tabular-nums">{{ __(':percent% complete', ['percent' => $progress]) }}</span>
        </div>

        <nav aria-label="{{ __('Project views') }}">
            <flux:button.group>
                @foreach (['board' => ['view-columns', __('Board')], 'list' => ['list-bullet', __('List')], 'activity' => ['clock', __('Activity')]] as $key => [$icon, $label])
                    <flux:button size="sm" :icon="$icon" :variant="$view === $key ? 'filled' : 'ghost'" wire:click="$set('view', '{{ $key }}')" :aria-current="$view === $key ? 'page' : null">
                        {{ $label }}
                    </flux:button>
                @endforeach
            </flux:button.group>
        </nav>
    </header>

    @if ($view === 'board')
        <div class="-mx-2 flex flex-1 gap-4 overflow-x-auto px-2 pb-4" wire:poll.30s.visible>
            @foreach ($this->columns as $status => $tasks)
                @php($statusEnum = \App\Enums\TaskStatus::from($status))
                <section class="flex w-72 shrink-0 flex-col rounded-xl bg-zinc-100 p-3 dark:bg-zinc-900" aria-labelledby="column-{{ $status }}" wire:key="column-{{ $status }}">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 id="column-{{ $status }}" class="flex items-center gap-2 text-sm font-semibold">
                            <flux:icon :name="$statusEnum->icon()" variant="micro" class="text-{{ $statusEnum->color() }}-500" />
                            {{ $statusEnum->label() }}
                            <span class="font-normal text-zinc-500 tabular-nums">{{ $tasks->count() }}</span>
                        </h2>
                        @if ($this->canEditTasks)
                            <flux:button size="xs" variant="subtle" icon="plus" wire:click="newTask('{{ $status }}')" aria-label="{{ __('Add task to :status', ['status' => $statusEnum->label()]) }}" />
                        @endif
                    </div>

                    <ul class="flex min-h-24 flex-1 flex-col gap-2"
                        @if ($this->canEditTasks)
                            wire:sort="moveTask"
                            wire:sort:group="tasks"
                            wire:sort:group-id="{{ $status }}"
                        @endif
                    >
                        @foreach ($tasks as $task)
                            <li wire:key="task-{{ $task->id }}" @if ($this->canEditTasks) wire:sort:item="{{ $task->id }}" @endif>
                                <x-task.card :task="$task" :can-update="$this->canEditTasks" @class(['cursor-grab active:cursor-grabbing' => $this->canEditTasks]) />
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @elseif ($view === 'list')
        <div class="space-y-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :label="__('Search')" :placeholder="__('Task title…')" type="search" />
                <flux:select wire:model.live="assigneeFilter" :label="__('Assignee')">
                    <flux:select.option value="">{{ __('Anyone') }}</flux:select.option>
                    <flux:select.option value="me">{{ __('Me') }}</flux:select.option>
                    <flux:select.option value="none">{{ __('Unassigned') }}</flux:select.option>
                    @foreach ($this->members as $member)
                        <flux:select.option value="{{ $member->id }}">{{ $member->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="statusFilter" :label="__('Status')">
                    <flux:select.option value="">{{ __('Any status') }}</flux:select.option>
                    @foreach (\App\Enums\TaskStatus::cases() as $status)
                        <flux:select.option value="{{ $status->value }}">{{ $status->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="priorityFilter" :label="__('Priority')">
                    <flux:select.option value="">{{ __('Any priority') }}</flux:select.option>
                    @foreach (\App\Enums\TaskPriority::cases() as $priority)
                        <flux:select.option value="{{ $priority->value }}">{{ $priority->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="dueFilter" :label="__('Due date')">
                    <flux:select.option value="">{{ __('Any time') }}</flux:select.option>
                    <flux:select.option value="overdue">{{ __('Overdue') }}</flux:select.option>
                    <flux:select.option value="today">{{ __('Due today') }}</flux:select.option>
                    <flux:select.option value="week">{{ __('Next 7 days') }}</flux:select.option>
                    <flux:select.option value="none">{{ __('No due date') }}</flux:select.option>
                </flux:select>
            </div>

            @if ($this->hasFilters)
                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
            @endif

            @if ($this->tasks->isEmpty())
                <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-12 text-center dark:border-zinc-600">
                    <flux:heading>{{ $this->hasFilters ? __('No tasks match these filters') : __('No tasks yet') }}</flux:heading>
                    <flux:text class="mt-1">{{ $this->hasFilters ? __('Try widening your filters.') : __('Tasks you create will appear here.') }}</flux:text>
                </div>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Task') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column>{{ __('Priority') }}</flux:table.column>
                        <flux:table.column>{{ __('Assignee') }}</flux:table.column>
                        <flux:table.column>{{ __('Due') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->tasks as $task)
                            <flux:table.row wire:key="row-{{ $task->id }}">
                                <flux:table.cell class="max-w-sm">
                                    <a href="{{ route('tasks.show', $task) }}" wire:navigate class="font-medium hover:underline">{{ $task->title }}</a>
                                    @if ($task->labels->isNotEmpty())
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            @foreach ($task->labels as $label)
                                                <flux:badge size="sm" :color="$label->color" wire:key="row-label-{{ $task->id }}-{{ $label->id }}">{{ $label->name }}</flux:badge>
                                            @endforeach
                                        </div>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" :color="$task->status->color()" :icon="$task->status->icon()">{{ $task->status->label() }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" :color="$task->priority->color()">{{ $task->priority->label() }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if ($task->assignee)
                                        <span class="flex items-center gap-2">
                                            <flux:avatar size="xs" circle :name="$task->assignee->name" :initials="$task->assignee->initials()" />
                                            {{ $task->assignee->name }}
                                        </span>
                                    @else
                                        <span class="text-zinc-400">{{ __('Unassigned') }}</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="tabular-nums">
                                    @if ($task->due_on)
                                        <span @class(['font-medium text-red-600 dark:text-red-400' => $task->isOverdue()])>
                                            {{ $task->due_on->toFormattedDateString() }}
                                            @if ($task->isOverdue()) · {{ __('Overdue') }} @endif
                                        </span>
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </div>
    @else
        <div wire:poll.30s.visible>
            <x-activity-feed :activities="$this->activities" />
        </div>
    @endif

    <flux:modal name="create-task" class="w-full md:w-[36rem]">
        <form wire:submit="createTask" class="space-y-6">
            <flux:heading size="lg">{{ __('New task') }}</flux:heading>
            <x-task.form-fields :members="$this->members" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Create task') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    @can('update', $project)
        <flux:modal name="edit-project" class="w-full md:w-[32rem]">
            <form wire:submit="updateProject" class="space-y-6">
                <flux:heading size="lg">{{ __('Edit project') }}</flux:heading>
                <x-project.form-fields prefix="projectForm" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan
</div>
