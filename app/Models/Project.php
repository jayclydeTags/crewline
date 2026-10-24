<?php

namespace App\Models;

use App\Enums\TaskStatus;
use App\Models\Concerns\RecordsActivity;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $creator_id
 * @property string $name
 * @property string|null $description
 * @property string $color
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property CarbonImmutable|null $archived_at
 * @property int|null $tasks_count
 * @property int|null $completed_tasks_count
 * @property Team $team
 */
#[Fillable(['name', 'description', 'color', 'starts_on', 'ends_on', 'creator_id'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, RecordsActivity;

    /**
     * The colors a project may use, matching Flux's palette.
     *
     * @var list<string>
     */
    public const COLORS = ['blue', 'indigo', 'violet', 'pink', 'red', 'orange', 'amber', 'green', 'teal', 'cyan'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Get every activity recorded inside the project, including its tasks.
     *
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * Scope the query to projects that have not been archived.
     *
     * @param  Builder<Project>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * Scope the query to include total and completed task counts.
     *
     * @param  Builder<Project>  $query
     */
    #[Scope]
    protected function withProgress(Builder $query): void
    {
        $query->withCount([
            'tasks',
            'tasks as completed_tasks_count' => fn (Builder $tasks) => $tasks->where('status', TaskStatus::Done),
        ]);
    }

    /**
     * Determine whether the project has been archived.
     */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Get the percentage of the project's tasks that are done.
     */
    public function progress(): int
    {
        $total = $this->tasks_count ?? $this->tasks()->count();
        $completed = $this->completed_tasks_count ?? $this->tasks()->where('status', TaskStatus::Done)->count();

        return $total === 0 ? 0 : (int) round($completed / $total * 100);
    }

    /**
     * Get the team the project's activity belongs to.
     */
    protected function activityTeamId(): int
    {
        return $this->team_id;
    }

    /**
     * Get the project the project's activity belongs to.
     */
    protected function activityProjectId(): int
    {
        return $this->id;
    }
}
