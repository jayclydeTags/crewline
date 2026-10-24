<?php

namespace App\Models;

use App\Enums\ProjectRole;
use App\Enums\ProjectVisibility;
use App\Enums\TaskStatus;
use App\Enums\TeamRole;
use App\Models\Concerns\RecordsActivity;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $creator_id
 * @property string $name
 * @property string|null $description
 * @property string $color
 * @property ProjectVisibility $visibility
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property CarbonImmutable|null $archived_at
 * @property int|null $tasks_count
 * @property int|null $completed_tasks_count
 * @property Team $team
 * @property Collection<int, User> $members
 */
#[Fillable(['name', 'description', 'color', 'visibility', 'starts_on', 'ends_on', 'creator_id'])]
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
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'visibility' => 'team',
    ];

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
            'visibility' => ProjectVisibility::class,
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
     * Get the users given an explicit role on this project.
     *
     * @return BelongsToMany<User, $this, ProjectMembership, 'projectMembership'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(ProjectMembership::class)
            ->as('projectMembership')
            ->withPivot(['id', 'role'])
            ->withTimestamps();
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
     * Scope the query to projects the user can see: team-visible projects in
     * their teams, projects they were added to, and every project in teams they own or administer.
     *
     * @param  Builder<Project>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $memberships = $user->teams()->get(['teams.id']);
        $managedTeamIds = $memberships
            ->filter(fn (Team $team) => $team->membership?->role?->isAtLeast(TeamRole::Admin) ?? false)
            ->pluck('id');

        $query->whereIn('team_id', $memberships->pluck('id'))
            ->where(fn (Builder $visible) => $visible
                ->where('visibility', ProjectVisibility::Team)
                ->orWhereIn('team_id', $managedTeamIds)
                ->orWhereHas('members', fn (Builder $members) => $members->where('users.id', $user->id)));
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
     * Determine whether only project members and team admins can see the project.
     */
    public function isPrivate(): bool
    {
        return $this->visibility === ProjectVisibility::Private;
    }

    /**
     * Get the project role explicitly given to the user, if any.
     */
    public function explicitRoleOf(User $user): ?ProjectRole
    {
        $role = $this->members->firstWhere('id', $user->id)?->projectMembership?->role;

        return $role instanceof ProjectRole ? $role : null;
    }

    /**
     * Get the team members who can see this project, each with their effective
     * project role in an `effective_role` attribute.
     *
     * @return Collection<int, User>
     */
    public function usersWithAccess(): Collection
    {
        return $this->team->members()->orderBy('name')->get()
            ->each(function (User $user) {
                $user->setAttribute('effective_role', ProjectRole::resolve(
                    $user->membership?->role,
                    $this->explicitRoleOf($user),
                    $this->visibility,
                ));
            })
            ->filter(fn (User $user) => $user->getAttribute('effective_role') !== null)
            ->values();
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
