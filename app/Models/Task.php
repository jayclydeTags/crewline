<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Concerns\RecordsActivity;
use App\Notifications\TaskAssigned;
use Carbon\CarbonImmutable;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $creator_id
 * @property int|null $assignee_id
 * @property string $title
 * @property string|null $description
 * @property TaskStatus $status
 * @property TaskPriority $priority
 * @property CarbonImmutable|null $due_on
 * @property int $position
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property Project $project
 * @property User|null $assignee
 */
#[Fillable(['title', 'description', 'status', 'priority', 'due_on', 'assignee_id', 'creator_id', 'position'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory, RecordsActivity;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_on' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * @return HasMany<ChecklistItem, $this>
     */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return BelongsToMany<Label, $this>
     */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest('id');
    }

    /**
     * Scope the query to tasks that are not done.
     *
     * @param  Builder<Task>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->where('status', '!=', TaskStatus::Done);
    }

    /**
     * Scope the query to tasks in the given team's active projects.
     *
     * @param  Builder<Task>  $query
     */
    #[Scope]
    protected function inTeam(Builder $query, Team $team): void
    {
        $query->whereHas('project', fn (Builder $projects) => $projects->where('team_id', $team->id)->whereNull('archived_at'));
    }

    /**
     * Determine whether the task is past its due date and still open.
     */
    public function isOverdue(): bool
    {
        return $this->due_on !== null
            && $this->status !== TaskStatus::Done
            && $this->due_on->isBefore(today());
    }

    /**
     * Move the task to a status column at the given position, recording the change.
     */
    public function moveTo(TaskStatus $status, int $position, ?User $user): void
    {
        $previousStatus = $this->status;

        DB::transaction(function () use ($status, $position) {
            $ids = $this->project->tasks()
                ->where('status', $status)
                ->whereKeyNot($this->id)
                ->orderBy('position')
                ->orderBy('id')
                ->pluck('id')
                ->all();

            array_splice($ids, max(0, min($position, count($ids))), 0, [$this->id]);

            $this->status = $status;
            $this->completed_at = $status === TaskStatus::Done ? ($this->completed_at ?? now()) : null;
            $this->save();

            foreach ($ids as $index => $id) {
                Task::whereKey($id)->update(['position' => $index]);
            }
        });

        $this->position = (int) $this->fresh()?->position;

        if ($previousStatus !== $status) {
            $this->recordActivity(
                __('moved “:task” to :status', ['task' => $this->title, 'status' => $status->label()]),
                $user,
                ['from' => $previousStatus->value, 'to' => $status->value],
            );
        }
    }

    /**
     * Notify the assignee when the task was just assigned to them by someone else.
     */
    public function notifyAssigneeIfChanged(?int $previousAssigneeId, User $actor): void
    {
        if ($this->assignee_id === null || $this->assignee_id === $previousAssigneeId || $this->assignee_id === $actor->id) {
            return;
        }

        $this->assignee?->notify(new TaskAssigned($this, $actor));
    }

    /**
     * Get the next free position at the end of a status column.
     */
    public static function nextPosition(Project $project, TaskStatus $status): int
    {
        return (int) $project->tasks()->where('status', $status)->max('position') + 1;
    }

    /**
     * Get the team the task's activity belongs to.
     */
    protected function activityTeamId(): int
    {
        return $this->project->team_id;
    }

    /**
     * Get the project the task's activity belongs to.
     */
    protected function activityProjectId(): int
    {
        return $this->project_id;
    }
}
