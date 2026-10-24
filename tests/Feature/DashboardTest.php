<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('users without a team are sent to create one', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertRedirect(route('teams.create'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    Team::factory()->ownedBy($user)->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('the dashboard lists my open and overdue tasks from the current team only', function () {
    $team = Team::factory()->create();
    $user = teamUser($team, TeamRole::Member);
    $project = Project::factory()->for($team)->create();

    Task::factory()->for($project)->overdue()->create(['assignee_id' => $user->id, 'title' => 'Late invoice']);
    Task::factory()->for($project)->done()->create(['assignee_id' => $user->id, 'title' => 'Shipped already']);
    Task::factory()->for(Project::factory())->create(['assignee_id' => $user->id, 'title' => 'Other team work']);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Late invoice')
        ->assertDontSee('Shipped already')
        ->assertDontSee('Other team work');
});
