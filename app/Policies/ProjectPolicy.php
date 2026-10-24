<?php

namespace App\Policies;

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;

class ProjectPolicy
{
    /**
     * Determine whether the user can view the project.
     */
    public function view(User $user, Project $project): bool
    {
        return $user->belongsToTeam($project->team);
    }

    /**
     * Determine whether the user can create projects in the team.
     */
    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamRole($team, TeamRole::Admin);
    }

    /**
     * Determine whether the user can edit the project's details.
     */
    public function update(User $user, Project $project): bool
    {
        return $user->hasTeamRole($project->team, TeamRole::Admin);
    }

    /**
     * Determine whether the user can archive or restore the project.
     */
    public function archive(User $user, Project $project): bool
    {
        return $user->hasTeamRole($project->team, TeamRole::Admin);
    }

    /**
     * Determine whether the user can delete the project.
     */
    public function delete(User $user, Project $project): bool
    {
        return $user->hasTeamRole($project->team, TeamRole::Admin);
    }
}
