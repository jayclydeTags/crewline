<?php

namespace App\Models;

use App\Enums\TeamRole;
use Carbon\CarbonImmutable;
use Database\Factories\TeamInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $invited_by_id
 * @property string $email
 * @property TeamRole $role
 * @property string $token
 * @property CarbonImmutable $created_at
 * @property Team $team
 * @property User|null $invitedBy
 */
#[Fillable(['email', 'role', 'invited_by_id'])]
class TeamInvitation extends Model
{
    /** @use HasFactory<TeamInvitationFactory> */
    use HasFactory;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function booted(): void
    {
        static::creating(function (TeamInvitation $invitation) {
            $invitation->token ??= Str::random(64);
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => TeamRole::class,
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'token';
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
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    /**
     * Determine whether the invitation was sent to the given user.
     */
    public function isFor(User $user): bool
    {
        return Str::lower($this->email) === Str::lower($user->email);
    }

    /**
     * Add the user to the team with the invited role and remove the invitation.
     */
    public function accept(User $user): void
    {
        $this->team->addMember($user, $this->role);
        $user->switchTeam($this->team);

        $this->delete();
    }
}
