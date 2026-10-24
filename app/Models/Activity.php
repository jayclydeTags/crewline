<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $project_id
 * @property int|null $user_id
 * @property string $subject_type
 * @property int $subject_id
 * @property string $description
 * @property array<string, mixed>|null $properties
 * @property CarbonImmutable $created_at
 * @property User|null $user
 */
#[Fillable(['team_id', 'project_id', 'user_id', 'description', 'properties'])]
class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the name of whoever caused the activity.
     */
    public function causerName(): string
    {
        return $this->user->name ?? __('Someone');
    }
}
