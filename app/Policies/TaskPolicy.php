<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TaskPolicy
{
    /**
     * Determine whether the user can view the task. Tasks in hidden projects respond as missing.
     */
    public function view(User $user, Task $task): Response
    {
        return $user->canOnProject($task->project, 'view')
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Determine whether the user can create tasks in the project.
     */
    public function create(User $user, Project $project): bool
    {
        return ! $project->isArchived() && $user->canOnProject($project, 'editTasks');
    }

    /**
     * Determine whether the user can edit the task, move it, or change its checklist, labels and files.
     */
    public function update(User $user, Task $task): bool
    {
        return ! $task->project->isArchived() && $user->canOnProject($task->project, 'editTasks');
    }

    /**
     * Determine whether the user can comment on the task.
     */
    public function comment(User $user, Task $task): bool
    {
        return $user->canOnProject($task->project, 'comment');
    }

    /**
     * Determine whether the user can delete the task. Contributors may only delete tasks they created.
     */
    public function delete(User $user, Task $task): bool
    {
        $role = $user->effectiveProjectRole($task->project);

        return (bool) $role?->can('deleteTasks')
            || ($task->creator_id === $user->id && (bool) $role?->can('deleteOwnTasks'));
    }
}
