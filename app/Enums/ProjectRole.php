<?php

namespace App\Enums;

enum ProjectRole: string
{
    case Manager = 'manager';
    case Contributor = 'contributor';
    case Viewer = 'viewer';

    /**
     * Resolve someone's effective role on a project from their team role, any
     * project role they were given, and the project's visibility. Team owners
     * and admins always manage; a project role overrides the team role; without
     * one, team members contribute and viewers view, but only on team-visible projects.
     */
    public static function resolve(?TeamRole $teamRole, ?self $projectRole, ProjectVisibility $visibility): ?self
    {
        if ($teamRole === null) {
            return null;
        }

        if ($teamRole->isAtLeast(TeamRole::Admin)) {
            return self::Manager;
        }

        if ($projectRole !== null) {
            return $projectRole;
        }

        if ($visibility === ProjectVisibility::Private) {
            return null;
        }

        return $teamRole === TeamRole::Member ? self::Contributor : self::Viewer;
    }

    /**
     * Determine whether the role grants the given project ability.
     */
    public function can(string $ability): bool
    {
        return in_array($ability, match ($this) {
            self::Manager => ['view', 'comment', 'editTasks', 'deleteTasks', 'update', 'manageMembers', 'archive'],
            self::Contributor => ['view', 'comment', 'editTasks', 'deleteOwnTasks'],
            self::Viewer => ['view', 'comment'],
        }, true);
    }

    /**
     * Get the human readable label for the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::Manager => __('Manager'),
            self::Contributor => __('Contributor'),
            self::Viewer => __('Viewer'),
        };
    }

    /**
     * Get a short description of what the role is allowed to do on a project.
     */
    public function description(): string
    {
        return match ($this) {
            self::Manager => __('Edit the project, manage its members and archive it.'),
            self::Contributor => __('Create and edit tasks, and delete their own.'),
            self::Viewer => __('See the project and comment on tasks.'),
        };
    }

    /**
     * Get the Flux badge color for the role.
     */
    public function color(): string
    {
        return match ($this) {
            self::Manager => 'violet',
            self::Contributor => 'blue',
            self::Viewer => 'zinc',
        };
    }
}
