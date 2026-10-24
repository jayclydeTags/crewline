<?php

namespace App\Policies;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    /**
     * Determine whether the user can view the team.
     */
    public function view(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user can create teams.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the team's name and logo.
     */
    public function update(User $user, Team $team): bool
    {
        return $user->hasTeamRole($team, TeamRole::Admin);
    }

    /**
     * Determine whether the user can invite, remove and change the role of members.
     */
    public function manageMembers(User $user, Team $team): bool
    {
        return $user->hasTeamRole($team, TeamRole::Admin);
    }

    /**
     * Determine whether the user can hand ownership of the team to someone else.
     */
    public function transferOwnership(User $user, Team $team): bool
    {
        return $user->roleOn($team) === TeamRole::Owner;
    }

    /**
     * Determine whether the user can leave the team. Owners must transfer ownership first.
     */
    public function leave(User $user, Team $team): bool
    {
        $role = $user->roleOn($team);

        return $role !== null && $role !== TeamRole::Owner;
    }

    /**
     * Determine whether the user can delete the team.
     */
    public function delete(User $user, Team $team): bool
    {
        return $user->roleOn($team) === TeamRole::Owner;
    }
}
