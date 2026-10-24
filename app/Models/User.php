<?php

namespace App\Models;

use App\Enums\TeamRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property int|null $current_team_id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property array<string, bool>|null $notification_preferences
 * @property Team|null $currentTeam
 * @property Membership|null $membership
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * The notification types a user can opt in or out of, with their defaults.
     *
     * @var array<string, bool>
     */
    public const NOTIFICATION_DEFAULTS = [
        'assignments' => true,
        'mentions' => true,
        'due_dates' => true,
        'email' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'notification_preferences' => 'array',
        ];
    }

    /**
     * Get the teams the user belongs to, with their role.
     *
     * @return BelongsToMany<Team, $this, Membership, 'membership'>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)
            ->using(Membership::class)
            ->as('membership')
            ->withPivot(['id', 'role'])
            ->withTimestamps()
            ->orderBy('name');
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_id');
    }

    /**
     * Determine whether the user is a member of the given team.
     */
    public function belongsToTeam(Team $team): bool
    {
        return $this->roleOn($team) !== null;
    }

    /**
     * Get the user's role on the given team, if they are a member.
     */
    public function roleOn(Team $team): ?TeamRole
    {
        $role = $this->teams()->whereKey($team->id)->first()?->membership?->role;

        return $role instanceof TeamRole ? $role : null;
    }

    /**
     * Determine whether the user has at least the given role on the team.
     */
    public function hasTeamRole(Team $team, TeamRole $role): bool
    {
        return $this->roleOn($team)?->isAtLeast($role) ?? false;
    }

    /**
     * Make the given team the user's current team.
     */
    public function switchTeam(Team $team): void
    {
        $this->forceFill(['current_team_id' => $team->id])->save();

        $this->setRelation('currentTeam', $team);
    }

    /**
     * Determine whether the user wants to receive the given type of notification.
     */
    public function wantsNotification(string $type): bool
    {
        return (bool) ($this->notification_preferences[$type] ?? self::NOTIFICATION_DEFAULTS[$type] ?? false);
    }

    /**
     * Get the channels a notification of the given type should be delivered on.
     *
     * @return list<string>
     */
    public function notificationChannelsFor(string $type): array
    {
        if (! $this->wantsNotification($type)) {
            return [];
        }

        return $this->wantsNotification('email') ? ['database', 'mail'] : ['database'];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
