<?php

namespace App\Policies;

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    /**
     * Determine whether the user can view the task.
     */
    public function view(User $user, Task $task): bool
    {
        return $user->belongsToTeam($task->project->team);
    }

    /**
     * Determine whether the user can create tasks in the project.
     */
    public function create(User $user, Project $project): bool
    {
        return ! $project->isArchived() && $user->hasTeamRole($project->team, TeamRole::Member);
    }

    /**
     * Determine whether the user can edit the task, move it, or change its checklist, labels and files.
     */
    public function update(User $user, Task $task): bool
    {
        return ! $task->project->isArchived() && $user->hasTeamRole($task->project->team, TeamRole::Member);
    }

    /**
     * Determine whether the user can comment on the task.
     */
    public function comment(User $user, Task $task): bool
    {
        return $user->hasTeamRole($task->project->team, TeamRole::Member);
    }

    /**
     * Determine whether the user can delete the task. Members may only delete tasks they created.
     */
    public function delete(User $user, Task $task): bool
    {
        $team = $task->project->team;

        return $user->hasTeamRole($team, TeamRole::Admin)
            || ($task->creator_id === $user->id && $user->hasTeamRole($team, TeamRole::Member));
    }
}
