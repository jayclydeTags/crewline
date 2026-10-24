<?php

namespace App\Models;

use App\Enums\TeamRole;
use Carbon\CarbonImmutable;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string|null $logo_path
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property Membership|null $membership
 */
#[Fillable(['name', 'logo_path'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    /**
     * Get the users that belong to the team, with their role.
     *
     * @return BelongsToMany<User, $this, Membership, 'membership'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(Membership::class)
            ->as('membership')
            ->withPivot(['id', 'role'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return HasManyThrough<Task, Project, $this>
     */
    public function tasks(): HasManyThrough
    {
        return $this->hasManyThrough(Task::class, Project::class);
    }

    /**
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * @return HasMany<Label, $this>
     */
    public function labels(): HasMany
    {
        return $this->hasMany(Label::class);
    }

    /**
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * Get the team's owner.
     */
    public function owner(): ?User
    {
        return $this->members()->wherePivot('role', TeamRole::Owner->value)->first();
    }

    /**
     * Add a user to the team with the given role, or update their role.
     */
    public function addMember(User $user, TeamRole $role): void
    {
        $this->members()->syncWithoutDetaching([$user->id => ['role' => $role->value]]);
    }

    /**
     * Get the public URL of the team's logo, if it has one.
     */
    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    /**
     * Get the team's initials for avatar fallbacks.
     */
    public function initials(): string
    {
        return Str::upper(Str::substr(Str::initials($this->name), 0, 2));
    }
}
