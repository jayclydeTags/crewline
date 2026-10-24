<?php

namespace App\Enums;

enum TeamRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    /**
     * Get the human readable label for the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::Owner => __('Owner'),
            self::Admin => __('Admin'),
            self::Member => __('Member'),
            self::Viewer => __('Viewer'),
        };
    }

    /**
     * Get a short description of what the role is allowed to do.
     */
    public function description(): string
    {
        return match ($this) {
            self::Owner => __('Everything, including deleting the team and transferring ownership.'),
            self::Admin => __('Manage members and invites, create and archive projects.'),
            self::Member => __('Create and edit tasks, comment, and update their own work.'),
            self::Viewer => __('Read-only access to projects and tasks.'),
        };
    }

    /**
     * Determine whether this role grants at least the permissions of the given role.
     */
    public function isAtLeast(self $role): bool
    {
        return $this->rank() >= $role->rank();
    }

    /**
     * Get the roles that may be granted through an invitation or role change.
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return [self::Admin, self::Member, self::Viewer];
    }

    /**
     * Get the numeric rank used to compare roles.
     */
    private function rank(): int
    {
        return match ($this) {
            self::Owner => 4,
            self::Admin => 3,
            self::Member => 2,
            self::Viewer => 1,
        };
    }
}
