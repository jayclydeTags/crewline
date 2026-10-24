<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;

test('users cannot open projects or tasks from teams they do not belong to', function () {
    $user = teamUser(Team::factory()->create(), TeamRole::Owner);
    $otherProject = Project::factory()->create();
    $otherTask = Task::factory()->for($otherProject)->create();

    $this->actingAs($user)->get(route('projects.show', $otherProject))->assertNotFound();
    $this->actingAs($user)->get(route('tasks.show', $otherTask))->assertNotFound();
});

test('opening a project from another of my teams switches my current team', function () {
    $firstTeam = Team::factory()->create();
    $secondTeam = Team::factory()->create();
    $user = teamUser($firstTeam, TeamRole::Member);
    $secondTeam->addMember($user, TeamRole::Viewer);
    $project = Project::factory()->for($secondTeam)->create();

    $this->actingAs($user)->get(route('projects.show', $project))->assertOk();

    expect($user->fresh()->current_team_id)->toBe($secondTeam->id);
});

test('users can switch to teams they belong to but not to others', function () {
    $user = teamUser(Team::factory()->create(), TeamRole::Owner);
    $otherTeam = Team::factory()->create();
    $joinedTeam = Team::factory()->create();
    $joinedTeam->addMember($user, TeamRole::Member);

    $this->actingAs($user)->post(route('teams.switch', $joinedTeam))->assertRedirect(route('dashboard'));
    expect($user->fresh()->current_team_id)->toBe($joinedTeam->id);

    $this->actingAs($user)->post(route('teams.switch', $otherTeam))->assertNotFound();
    expect($user->fresh()->current_team_id)->toBe($joinedTeam->id);
});

test('registering creates a personal team owned by the new user', function () {
    $this->post(route('register.store'), [
        'name' => 'Jay Rivera',
        'email' => 'jay@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = auth()->user();

    expect($user->currentTeam->name)->toBe("Jay's Team")
        ->and($user->roleOn($user->currentTeam))->toBe(TeamRole::Owner);
});
