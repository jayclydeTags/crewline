<?php

namespace App\Policies;

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProjectPolicy
{
    /**
     * Determine whether the user can view the project. Hidden projects respond
     * as missing so private projects don't reveal that they exist.
     */
    public function view(User $user, Project $project): Response
    {
        return $user->canOnProject($project, 'view')
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Determine whether the user can create projects in the team.
     */
    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamRole($team, TeamRole::Admin);
    }

    /**
     * Determine whether the user can edit the project's details and visibility.
     */
    public function update(User $user, Project $project): bool
    {
        return $user->canOnProject($project, 'update');
    }

    /**
     * Determine whether the user can add, remove, or change the role of project members.
     */
    public function manageMembers(User $user, Project $project): bool
    {
        return $user->canOnProject($project, 'manageMembers');
    }

    /**
     * Determine whether the user can archive or restore the project.
     */
    public function archive(User $user, Project $project): bool
    {
        return $user->canOnProject($project, 'archive');
    }

    /**
     * Determine whether the user can delete the project. Only team owners and admins can.
     */
    public function delete(User $user, Project $project): bool
    {
        return $user->hasTeamRole($project->team, TeamRole::Admin);
    }
}
