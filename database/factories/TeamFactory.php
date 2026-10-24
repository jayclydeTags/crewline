<?php

namespace Database\Factories;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
        ];
    }

    /**
     * Make the given user the team's owner and switch them to it.
     */
    public function ownedBy(User $user): static
    {
        return $this->afterCreating(function (Team $team) use ($user) {
            $team->addMember($user, TeamRole::Owner);
            $user->switchTeam($team);
        });
    }
}
