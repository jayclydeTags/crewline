<?php

use App\Enums\ProjectRole;
use App\Enums\ProjectVisibility;
use App\Enums\TaskPriority;
use App\Enums\TeamRole;
use App\Enums\TaskStatus;
use App\Livewire\Forms\ProjectForm;
use App\Livewire\Forms\TaskForm;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

    public string $newMemberId = '';

    public string $newMemberRole = 'contributor';

    /**
     * Bumped when a role change is rejected, so the role selects re-render with the saved value.
     */
    public int $memberSelectsVersion = 0;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('view', $this->project);

        $this->projectForm->setProject($this->project);

        if (! in_array($this->view, ['board', 'list', 'activity', 'members'], true)) {
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
        return $this->project->usersWithAccess();
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
     * Get the current user's effective role on the project.
     */
    #[Computed]
    public function myRole(): ?ProjectRole
    {
        return Auth::user()->effectiveProjectRole($this->project);
    }

    /**
     * Get team members who can still be given a project role. Team owners and
     * admins already manage every project, so they are never listed.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    #[Computed]
    public function addableMembers()
    {
        $explicitIds = $this->project->members->modelKeys();

        return $this->project->team->members()->orderBy('name')->get()
            ->reject(fn (User $user) => $user->membership->role->isAtLeast(TeamRole::Admin) || in_array($user->id, $explicitIds, true))
            ->values();
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

        $attributes = $this->taskForm->attributesFor($this->project);

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

        $previousVisibility = $this->project->visibility;

        $this->project->update($this->projectForm->attributes());
        $this->project->recordActivity(__('updated the project details'), Auth::user());

        if ($this->project->visibility !== $previousVisibility) {
            $this->recordVisibilityChange();
        }

        $this->refreshMembers();

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
     * Give a team member an explicit role on this project.
     */
    public function addMember(): void
    {
        $this->authorize('manageMembers', $this->project);

        $validated = $this->validate([
            'newMemberId' => ['required', 'integer', Rule::in($this->addableMembers->modelKeys())],
            'newMemberRole' => ['required', Rule::enum(ProjectRole::class)],
        ], ['newMemberId.in' => __('Choose a team member who isn’t already on this project.')], ['newMemberId' => 'member', 'newMemberRole' => 'role']);

        $member = $this->addableMembers->firstWhere('id', (int) $validated['newMemberId']);
        $role = ProjectRole::from($validated['newMemberRole']);

        $this->project->members()->attach($member->id, ['role' => $role->value]);
        $this->project->recordActivity(
            __('added :name to “:project” as :role', ['name' => $member->name, 'project' => $this->project->name, 'role' => $role->label()]),
            Auth::user(),
        );

        $this->reset('newMemberId', 'newMemberRole');
        $this->refreshMembers();

        Flux::toast(variant: 'success', text: __(':name was added to the project.', ['name' => $member->name]));
    }

    /**
     * Change a project member's explicit role.
     */
    public function changeMemberRole(int $userId, string $role): void
    {
        $this->authorize('manageMembers', $this->project);

        $member = $this->project->members()->whereKey($userId)->firstOrFail();
        $newRole = ProjectRole::tryFrom($role) ?? abort(422);

        if ($member->projectMembership->role === $newRole) {
            return;
        }

        if ($newRole !== ProjectRole::Manager) {
            $this->ensureManagerRemains($member);
        }

        $this->project->members()->updateExistingPivot($member->id, ['role' => $newRole->value]);
        $this->project->recordActivity(
            __('made :name a :role on “:project”', ['name' => $member->name, 'role' => $newRole->label(), 'project' => $this->project->name]),
            Auth::user(),
        );

        $this->refreshMembers();

        Flux::toast(variant: 'success', text: __(':name is now a :role on this project.', ['name' => $member->name, 'role' => $newRole->label()]));
    }

    /**
     * Remove a member's explicit project role, so their team role applies again.
     */
    public function removeMember(int $userId): void
    {
        $this->authorize('manageMembers', $this->project);

        $member = $this->project->members()->whereKey($userId)->firstOrFail();

        $this->ensureManagerRemains($member);

        $this->project->members()->detach($member->id);
        $this->project->recordActivity(
            __('removed :name from “:project”', ['name' => $member->name, 'project' => $this->project->name]),
            Auth::user(),
        );

        $this->refreshMembers();

        Flux::toast(text: __(':name was removed from the project.', ['name' => $member->name]));
    }

    /**
     * Switch the project between team-visible and private.
     */
    public function setVisibility(string $visibility): void
    {
        $this->authorize('update', $this->project);

        $newVisibility = ProjectVisibility::tryFrom($visibility) ?? abort(422);

        if ($newVisibility === $this->project->visibility) {
            return;
        }

        $this->project->update(['visibility' => $newVisibility]);
        $this->projectForm->visibility = $newVisibility->value;
        $this->recordVisibilityChange();
        $this->refreshMembers();
    }

    /**
     * Stop a project manager from leaving a private project without any manager,
     * unless a team owner or admin is making the change.
     */
    private function ensureManagerRemains(User $member): void
    {
        if (! $this->project->isPrivate()
            || $member->projectMembership->role !== ProjectRole::Manager
            || Auth::user()->hasTeamRole($this->project->team, TeamRole::Admin)) {
            return;
        }

        $otherManagers = $this->project->members()
            ->wherePivot('role', ProjectRole::Manager->value)
            ->whereKeyNot($member->id)
            ->exists();

        if (! $otherManagers) {
            $this->memberSelectsVersion++;

            Flux::toast(variant: 'danger', text: __('A private project needs at least one manager.'));

            throw ValidationException::withMessages(['members' => __('A private project needs at least one manager.')]);
        }
    }

    /**
     * Log a change to the project's visibility.
     */
    private function recordVisibilityChange(): void
    {
        $this->project->recordActivity(
            $this->project->isPrivate()
                ? __('made “:project” private', ['project' => $this->project->name])
                : __('made “:project” visible to the team', ['project' => $this->project->name]),
            Auth::user(),
        );
    }

    /**
     * Reload project membership so roles and permission checks reflect the latest change.
     */
    private function refreshMembers(): void
    {
        $this->project->load('members');

        unset($this->members, $this->addableMembers, $this->myRole, $this->canEditTasks, $this->activities);
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
                    @if ($project->isPrivate())
                        <flux:badge size="sm" icon="lock-closed">{{ __('Private') }}</flux:badge>
                    @endif
                    @if ($this->myRole)
                        <flux:badge size="sm" :color="$this->myRole->color()" data-test="my-project-role">{{ __('You: :role', ['role' => $this->myRole->label()]) }}</flux:badge>
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

                @canany(['update', 'archive', 'delete'], $project)
                    <flux:dropdown align="end">
                        <flux:button icon="ellipsis-horizontal" aria-label="{{ __('Project actions') }}" />
                        <flux:menu>
                            @can('update', $project)
                                <flux:modal.trigger name="edit-project">
                                    <flux:menu.item icon="pencil-square">{{ __('Edit project') }}</flux:menu.item>
                                </flux:modal.trigger>
                            @endcan
                            @can('archive', $project)
                                <flux:menu.item icon="archive-box" wire:click="toggleArchive">
                                    {{ $project->isArchived() ? __('Restore project') : __('Archive project') }}
                                </flux:menu.item>
                            @endcan
                            @can('delete', $project)
                                <flux:menu.separator />
                                <flux:menu.item icon="trash" variant="danger" wire:click="deleteProject"
                                    wire:confirm="{{ __('Delete “:name” and all of its tasks? This cannot be undone.', ['name' => $project->name]) }}">
                                    {{ __('Delete project') }}
                                </flux:menu.item>
                            @endcan
                        </flux:menu>
                    </flux:dropdown>
                @endcanany
            </div>
        </div>

        @php($progress = $project->progress())
        <div class="flex max-w-md items-center gap-3">
            <flux:progress :value="$progress" :color="$project->color" class="flex-1" aria-label="{{ __('Project progress') }}" />
            <span class="text-sm text-zinc-500 tabular-nums">{{ __(':percent% complete', ['percent' => $progress]) }}</span>
        </div>

        <nav aria-label="{{ __('Project views') }}">
            <flux:button.group>
                @foreach (['board' => ['view-columns', __('Board')], 'list' => ['list-bullet', __('List')], 'activity' => ['clock', __('Activity')], 'members' => ['users', __('Members')]] as $key => [$icon, $label])
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
    @elseif ($view === 'activity')
        <div wire:poll.30s.visible>
            <x-activity-feed :activities="$this->activities" />
        </div>
    @else
        @php($canManageMembers = auth()->user()->can('manageMembers', $project))
        <div class="max-w-3xl space-y-8">
            <section class="space-y-3" aria-labelledby="access-heading">
                <flux:heading size="lg" level="2" id="access-heading">{{ __('Access') }}</flux:heading>

                @can('update', $project)
                    <div role="radiogroup" aria-labelledby="access-heading" class="grid gap-3 sm:grid-cols-2">
                        @foreach (\App\Enums\ProjectVisibility::cases() as $visibility)
                            @php($selected = $project->visibility === $visibility)
                            <button type="button" role="radio" aria-checked="{{ $selected ? 'true' : 'false' }}" wire:click="setVisibility('{{ $visibility->value }}')"
                                @class([
                                    'rounded-lg border p-4 text-start transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
                                    'border-zinc-900 dark:border-white' => $selected,
                                    'border-zinc-200 hover:border-zinc-400 dark:border-zinc-700 dark:hover:border-zinc-500' => ! $selected,
                                ])>
                                <span class="flex items-center gap-2 text-sm font-medium">
                                    <flux:icon :name="$visibility === \App\Enums\ProjectVisibility::Private ? 'lock-closed' : 'users'" variant="micro" />
                                    {{ $visibility->label() }}
                                    @if ($selected)
                                        <flux:icon.check-circle variant="micro" class="ms-auto" />
                                    @endif
                                </span>
                                <span class="mt-1 block text-sm text-zinc-500">{{ $visibility->description() }}</span>
                            </button>
                        @endforeach
                    </div>
                @else
                    <flux:text class="flex items-center gap-2 text-sm">
                        <flux:icon :name="$project->isPrivate() ? 'lock-closed' : 'users'" variant="micro" />
                        <span><span class="font-medium">{{ $project->visibility->label() }}</span> · {{ $project->visibility->description() }}</span>
                    </flux:text>
                @endcan
            </section>

            <section class="space-y-3" aria-labelledby="project-members-heading">
                <div>
                    <flux:heading size="lg" level="2" id="project-members-heading">{{ __('People with access') }}</flux:heading>
                    <flux:text class="text-sm">{{ __('A project role overrides someone’s team role on this project. Team owners and admins always manage every project.') }}</flux:text>
                </div>

                @error('members')
                    <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" />
                @enderror

                <ul class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($this->members as $member)
                        @php($explicitRole = $project->explicitRoleOf($member))
                        @php($isTeamAdmin = $member->membership->role->isAtLeast(\App\Enums\TeamRole::Admin))
                        <li class="flex flex-wrap items-center gap-3 p-3" wire:key="project-member-{{ $member->id }}">
                            <flux:avatar size="sm" :name="$member->name" :initials="$member->initials()" />
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium">
                                    {{ $member->name }}
                                    @if ($member->id === auth()->id())
                                        <span class="text-zinc-500">({{ __('you') }})</span>
                                    @endif
                                </div>
                                <div class="truncate text-xs text-zinc-500">
                                    @if ($isTeamAdmin)
                                        {{ __('Team :role · full access', ['role' => $member->membership->role->label()]) }}
                                    @elseif ($explicitRole)
                                        {{ __('Project role · team :role', ['role' => $member->membership->role->label()]) }}
                                    @else
                                        {{ __('From team role (:role)', ['role' => $member->membership->role->label()]) }}
                                    @endif
                                </div>
                            </div>

                            @if ($canManageMembers && $explicitRole && ! $isTeamAdmin)
                                <div class="w-36" wire:key="project-role-{{ $member->id }}-{{ $explicitRole->value }}-{{ $memberSelectsVersion }}">
                                    <flux:select size="sm" aria-label="{{ __('Project role for :name', ['name' => $member->name]) }}" wire:change="changeMemberRole({{ $member->id }}, $event.target.value)">
                                        @foreach (\App\Enums\ProjectRole::cases() as $role)
                                            <flux:select.option value="{{ $role->value }}" :selected="$explicitRole === $role">{{ $role->label() }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </div>
                                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeMember({{ $member->id }})"
                                    wire:confirm="{{ $project->isPrivate() ? __('Remove :name from this private project? They will lose access.', ['name' => $member->name]) : __('Remove :name’s project role? Their team role will apply again.', ['name' => $member->name]) }}"
                                    aria-label="{{ __('Remove :name from the project', ['name' => $member->name]) }}" />
                            @else
                                <flux:badge size="sm" :color="$member->effective_role->color()">{{ $member->effective_role->label() }}</flux:badge>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>

            @if ($canManageMembers)
                <section class="space-y-3" aria-labelledby="add-member-heading">
                    <flux:heading size="lg" level="2" id="add-member-heading">{{ __('Add someone to this project') }}</flux:heading>

                    @if ($this->addableMembers->isEmpty())
                        <flux:text class="text-sm">{{ __('Everyone on the team already has a project role or full access. Invite people from team settings first.') }}</flux:text>
                    @else
                        <form wire:submit="addMember" class="flex flex-col gap-3 sm:flex-row sm:items-start">
                            <div class="flex-1">
                                <flux:select wire:model="newMemberId" :label="__('Team member')" :placeholder="__('Choose a member…')">
                                    @foreach ($this->addableMembers as $candidate)
                                        <flux:select.option value="{{ $candidate->id }}">{{ $candidate->name }} ({{ $candidate->membership->role->label() }})</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                            <div class="sm:w-40">
                                <flux:select wire:model="newMemberRole" :label="__('Project role')">
                                    @foreach (\App\Enums\ProjectRole::cases() as $role)
                                        <flux:select.option value="{{ $role->value }}">{{ $role->label() }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                            <flux:button variant="primary" type="submit" class="sm:mt-6" data-test="add-project-member">{{ __('Add') }}</flux:button>
                        </form>
                    @endif

                    <dl class="grid gap-2 text-sm sm:grid-cols-3">
                        @foreach (\App\Enums\ProjectRole::cases() as $role)
                            <div class="rounded-md bg-zinc-50 p-3 dark:bg-zinc-900">
                                <dt class="font-medium">{{ $role->label() }}</dt>
                                <dd class="text-zinc-500">{{ $role->description() }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            @endif
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
