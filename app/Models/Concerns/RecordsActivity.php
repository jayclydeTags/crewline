<?php

namespace App\Models\Concerns;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait RecordsActivity
{
    /**
     * Get the activity recorded directly against this model.
     *
     * @return MorphMany<Activity, $this>
     */
    public function subjectActivities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')->latest('id');
    }

    /**
     * Record an activity entry, such as "moved “Fix login” to Done".
     *
     * @param  array<string, mixed>  $properties
     */
    public function recordActivity(string $description, ?User $user, array $properties = []): Activity
    {
        return $this->subjectActivities()->create([
            'team_id' => $this->activityTeamId(),
            'project_id' => $this->activityProjectId(),
            'user_id' => $user?->id,
            'description' => $description,
            'properties' => $properties ?: null,
        ]);
    }

    /**
     * Get the team the activity belongs to.
     */
    abstract protected function activityTeamId(): int;

    /**
     * Get the project the activity belongs to.
     */
    abstract protected function activityProjectId(): ?int;
}
