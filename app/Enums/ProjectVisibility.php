<?php

namespace App\Enums;

enum ProjectVisibility: string
{
    case Team = 'team';
    case Private = 'private';

    /**
     * Get the human readable label for the visibility.
     */
    public function label(): string
    {
        return match ($this) {
            self::Team => __('Team-visible'),
            self::Private => __('Private'),
        };
    }

    /**
     * Describe who can see a project with this visibility.
     */
    public function description(): string
    {
        return match ($this) {
            self::Team => __('Everyone on the team can see it, using their team role.'),
            self::Private => __('Only project members and team owners and admins can see it.'),
        };
    }
}
