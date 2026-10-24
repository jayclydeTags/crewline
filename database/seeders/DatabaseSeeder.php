<?php

namespace Database\Seeders;

use App\Enums\ProjectRole;
use App\Enums\ProjectVisibility;
use App\Enums\TaskStatus;
use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database with a demo team. Every user's password is "password".
     */
    public function run(): void
    {
        $owner = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $team = Team::factory()->ownedBy($owner)->create(['name' => 'Acme Studio']);

        $crew = collect([
            ['Ana Cruz', 'ana@example.com', TeamRole::Admin],
            ['Ben Okafor', 'ben@example.com', TeamRole::Member],
            ['Chloe Park', 'chloe@example.com', TeamRole::Member],
            ['Dev Shah', 'dev@example.com', TeamRole::Viewer],
        ])->map(function (array $person) use ($team) {
            [$name, $email, $role] = $person;
            $user = User::factory()->create(['name' => $name, 'email' => $email]);
            $team->addMember($user, $role);
            $user->switchTeam($team);

            return $user;
        });

        $assignees = $crew->take(3)->push($owner);
        $labels = collect(['Design' => 'violet', 'Bug' => 'red', 'Content' => 'amber', 'Backend' => 'blue'])
            ->map(fn (string $color, string $name) => $team->labels()->create(['name' => $name, 'color' => $color]));

        $projects = [
            ['Website Redesign', 'Refresh the marketing site with the new brand.', 'violet'],
            ['Mobile App Launch', 'Ship v1 of the iOS and Android apps.', 'teal'],
            ['Q4 Campaign', 'Plan and run the end-of-year campaign.', 'orange'],
            ['Payroll Revamp', 'Rework salary bands and payroll tooling.', 'red', ProjectVisibility::Private],
        ];

        $ben = $crew->firstWhere('email', 'ben@example.com');
        $dev = $crew->firstWhere('email', 'dev@example.com');

        foreach ($projects as $details) {
            [$name, $description, $color] = $details;

            $project = Project::factory()->for($team)->create([
                'name' => $name,
                'description' => $description,
                'color' => $color,
                'visibility' => $details[3] ?? ProjectVisibility::Team,
                'creator_id' => $owner->id,
            ]);
            $project->recordActivity(__('created the project'), $owner);

            if ($name === 'Website Redesign') {
                $project->members()->attach($dev->id, ['role' => ProjectRole::Contributor->value]);
            }

            if ($name === 'Payroll Revamp') {
                $project->members()->attach($ben->id, ['role' => ProjectRole::Manager->value]);
            }

            $projectAssignees = $project->isPrivate() ? collect([$owner, $ben]) : $assignees;

            foreach (TaskStatus::cases() as $status) {
                Task::factory()->for($project)->count(fake()->numberBetween(2, 4))->sequence(fn ($sequence) => [
                    'position' => $sequence->index,
                ])->create([
                    'status' => $status,
                    'completed_at' => $status === TaskStatus::Done ? now()->subDays(fake()->numberBetween(0, 20)) : null,
                    'creator_id' => $owner->id,
                ])->each(function (Task $task) use ($projectAssignees, $labels, $owner) {
                    $task->update([
                        'assignee_id' => $projectAssignees->random()->id,
                        'due_on' => now()->addDays(fake()->numberBetween(-4, 14)),
                    ]);
                    $task->labels()->attach($labels->random(fake()->numberBetween(0, 2))->pluck('id'));
                    $task->checklistItems()->createMany(
                        collect()->times(fake()->numberBetween(0, 3), fn (int $i) => [
                            'body' => fake()->sentence(3),
                            'is_completed' => fake()->boolean(),
                            'position' => $i,
                        ])->all(),
                    );
                    $task->recordActivity(__('created “:task”', ['task' => $task->title]), $owner);
                });
            }
        }
    }
}
