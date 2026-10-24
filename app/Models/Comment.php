<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $task_id
 * @property int $user_id
 * @property string $body
 * @property CarbonImmutable $created_at
 * @property Task $task
 * @property User $user
 */
#[Fillable(['body', 'user_id'])]
class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the given candidates that are @mentioned by name in the comment body.
     *
     * @param  Collection<int, User>  $candidates
     * @return Collection<int, User>
     */
    public function mentionedUsers(Collection $candidates): Collection
    {
        $body = Str::lower($this->body);

        return $candidates
            ->filter(fn (User $user) => Str::contains($body, '@'.Str::lower($user->name)))
            ->values();
    }

    /**
     * Get the escaped comment body with @mentions of the given users highlighted.
     *
     * @param  SupportCollection<int, User>  $members
     */
    public function renderedBody(SupportCollection $members): HtmlString
    {
        $html = nl2br(e($this->body));

        foreach ($members->sortByDesc(fn (User $user) => Str::length($user->name)) as $member) {
            $html = preg_replace(
                '/@'.preg_quote(e($member->name), '/').'/iu',
                '<span class="rounded bg-accent/15 px-1 font-medium text-accent-content">$0</span>',
                $html,
            );
        }

        return new HtmlString($html);
    }
}
