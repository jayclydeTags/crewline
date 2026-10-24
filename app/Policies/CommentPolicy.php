<?php

namespace App\Policies;

use App\Enums\TeamRole;
use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    /**
     * Determine whether the user can delete the comment.
     */
    public function delete(User $user, Comment $comment): bool
    {
        return $comment->user_id === $user->id
            || $user->hasTeamRole($comment->task->project->team, TeamRole::Admin);
    }
}
